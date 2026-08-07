<?php

namespace App\Services;

use App\Models\BillingPeriod;
use App\Models\ChargeType;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Unit;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class HistoricalInvoiceImporter
{
    private const FIXED_HEADERS = ['year', 'month', 'bill_date', 'unit'];

    /**
     * @return list<string>
     */
    public function templateHeaders(Property $property): array
    {
        $codes = ChargeType::query()
            ->where('property_id', $property->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->pluck('code')
            ->all();

        return array_merge(self::FIXED_HEADERS, $codes);
    }

    public function downloadTemplate(Property $property): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $headers = $this->templateHeaders($property);
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Historical invoices');

        foreach ($headers as $index => $header) {
            $sheet->setCellValue([$index + 1, 1], $header);
        }

        // Sample rows for guidance (optional; importer ignores empty charge cells).
        $examples = [
            [2024, 1, '2024-01-05', '1st'],
            [2024, 1, '2024-01-05', '3rd'],
        ];
        foreach ($examples as $rowIndex => $example) {
            foreach ($example as $colIndex => $value) {
                $sheet->setCellValue([$colIndex + 1, $rowIndex + 2], $value);
            }
        }

        $filename = 'historical-invoice-import-template.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @return array{
     *   imported: int,
     *   skipped: int,
     *   failed: int,
     *   rows: list<array{row: int, status: string, message: string}>
     * }
     */
    public function import(Property $property, UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if (! $path) {
            throw new RuntimeException('Unable to read uploaded file.');
        }

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);

        if ($rows === [] || $rows[0] === null) {
            throw new RuntimeException('The spreadsheet is empty.');
        }

        $headerRow = array_map(fn ($value) => $this->normalizeHeader((string) $value), $rows[0]);
        $headerMap = $this->mapHeaders($headerRow);
        $this->assertRequiredHeaders($headerMap);

        $chargeTypes = ChargeType::query()
            ->where('property_id', $property->id)
            ->where('is_active', true)
            ->get()
            ->keyBy(fn (ChargeType $type) => strtolower($type->code));

        $result = [
            'imported' => 0,
            'skipped' => 0,
            'failed' => 0,
            'rows' => [],
        ];

        for ($i = 1; $i < count($rows); $i++) {
            $excelRow = $i + 1;
            $raw = $rows[$i] ?? [];
            if ($this->rowIsEmpty($raw)) {
                continue;
            }

            try {
                $outcome = DB::transaction(function () use ($property, $raw, $headerMap, $chargeTypes) {
                    return $this->importRow($property, $raw, $headerMap, $chargeTypes);
                });

                $result[$outcome['status'] === 'imported' ? 'imported' : 'skipped']++;
                $result['rows'][] = [
                    'row' => $excelRow,
                    'status' => $outcome['status'],
                    'message' => $outcome['message'],
                ];
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['rows'][] = [
                    'row' => $excelRow,
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    /**
     * @param  array<int, mixed>  $raw
     * @param  array<string, int>  $headerMap
     * @param  \Illuminate\Support\Collection<string, ChargeType>  $chargeTypes
     * @return array{status: string, message: string}
     */
    private function importRow(Property $property, array $raw, array $headerMap, $chargeTypes): array
    {
        $year = (int) $this->cell($raw, $headerMap, 'year');
        $month = (int) $this->cell($raw, $headerMap, 'month');
        $unitLabel = trim((string) $this->cell($raw, $headerMap, 'unit'));
        $billDate = $this->parseDate($this->cell($raw, $headerMap, 'bill_date'));

        if ($year < 2000 || $year > 2100) {
            throw new RuntimeException('Invalid year.');
        }
        if ($month < 1 || $month > 12) {
            throw new RuntimeException('Invalid month (must be 1–12).');
        }
        if ($unitLabel === '') {
            throw new RuntimeException('Unit is required.');
        }
        if (! $billDate) {
            throw new RuntimeException('bill_date is required (YYYY-MM-DD).');
        }

        $unit = Unit::query()
            ->where('property_id', $property->id)
            ->where('label', $unitLabel)
            ->first();
        if (! $unit) {
            throw new RuntimeException('No unit found with label "'.$unitLabel.'".');
        }

        $lease = Lease::query()
            ->where('property_id', $property->id)
            ->where('unit_id', $unit->id)
            ->where('is_active', true)
            ->first();
        if (! $lease) {
            throw new RuntimeException('No active lease for unit "'.$unitLabel.'".');
        }

        $lines = [];
        $sort = 1;
        foreach ($headerMap as $header => $colIndex) {
            if (in_array($header, self::FIXED_HEADERS, true)) {
                continue;
            }
            if (! $chargeTypes->has($header)) {
                continue;
            }

            $amount = $this->parseAmount($this->cell($raw, $headerMap, $header));
            if ($amount === null || $amount <= 0) {
                continue;
            }

            /** @var ChargeType $chargeType */
            $chargeType = $chargeTypes->get($header);
            $lines[] = [
                'charge_type_id' => $chargeType->id,
                'description' => $chargeType->label,
                'period_label' => $this->periodLabel($year, $month, $chargeType),
                'amount' => round($amount, 2),
                'sort_order' => $sort++,
            ];
        }

        if ($lines === []) {
            throw new RuntimeException('At least one charge amount greater than 0 is required.');
        }

        $period = BillingPeriod::firstOrCreate(
            [
                'property_id' => $property->id,
                'year' => $year,
                'month' => $month,
            ],
            [
                'bill_date' => $billDate,
                'status' => 'finalized',
                'notes' => 'Created by historical import',
            ]
        );

        if ($period->wasRecentlyCreated === false) {
            $period->fill([
                'bill_date' => $period->bill_date ?: $billDate,
                'status' => 'finalized',
            ]);
            if ($period->isDirty()) {
                $period->save();
            }
        }

        $existing = Invoice::query()
            ->where('billing_period_id', $period->id)
            ->where('lease_id', $lease->id)
            ->first();
        if ($existing) {
            return [
                'status' => 'skipped',
                'message' => 'Invoice already exists for '.$unitLabel.' · '.$period->period_key.'.',
            ];
        }

        $total = round(collect($lines)->sum('amount'), 2);
        $invoice = Invoice::create([
            'property_id' => $property->id,
            'billing_period_id' => $period->id,
            'lease_id' => $lease->id,
            'number' => sprintf('%s-%s-%d', $property->id, $period->period_key, $lease->id),
            'status' => 'paid',
            'total_amount' => $total,
            'paid_amount' => $total,
            'issued_at' => $billDate->copy()->startOfDay(),
        ]);

        foreach ($lines as $line) {
            InvoiceLine::create([
                'invoice_id' => $invoice->id,
                ...$line,
            ]);
        }

        Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => $total,
            'paid_on' => $billDate->toDateString(),
            'method' => 'import',
            'note' => 'Historical import',
        ]);

        return [
            'status' => 'imported',
            'message' => 'Imported '.$unitLabel.' · '.$period->period_key.' ('.number_format($total, 2).').',
        ];
    }

    /**
     * @param  list<string>  $headerRow
     * @return array<string, int>
     */
    private function mapHeaders(array $headerRow): array
    {
        $map = [];
        foreach ($headerRow as $index => $header) {
            if ($header === '') {
                continue;
            }
            if (isset($map[$header])) {
                continue;
            }
            $map[$header] = $index;
        }

        return $map;
    }

    /**
     * @param  array<string, int>  $headerMap
     */
    private function assertRequiredHeaders(array $headerMap): void
    {
        foreach (self::FIXED_HEADERS as $required) {
            if (! array_key_exists($required, $headerMap)) {
                throw new RuntimeException('Missing required column: '.$required);
            }
        }
    }

    private function normalizeHeader(string $value): string
    {
        return strtolower(trim($value));
    }

    /**
     * @param  array<int, mixed>  $raw
     * @param  array<string, int>  $headerMap
     */
    private function cell(array $raw, array $headerMap, string $header): mixed
    {
        $index = $headerMap[$header] ?? null;
        if ($index === null) {
            return null;
        }

        return $raw[$index] ?? null;
    }

    /**
     * @param  array<int, mixed>  $raw
     */
    private function rowIsEmpty(array $raw): bool
    {
        foreach ($raw as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value));
            } catch (\Throwable) {
                // fall through
            }
        }

        try {
            return Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseAmount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace([',', ' '], '', $value);
        }

        if (! is_numeric($value)) {
            throw new RuntimeException('Invalid amount: '.$value);
        }

        return (float) $value;
    }

    private function periodLabel(int $year, int $month, ChargeType $chargeType): string
    {
        $offset = $chargeType->period_offset_months ?? 0;

        return Carbon::createFromDate($year, $month, 1)->addMonths($offset)->format('M-y');
    }
}
