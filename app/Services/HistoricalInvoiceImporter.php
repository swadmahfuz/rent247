<?php

namespace App\Services;

use App\Models\BillingPeriod;
use App\Models\ChargeType;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Lease;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\PeriodChargeInput;
use App\Models\PeriodMeterInput;
use App\Models\Property;
use App\Models\Unit;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class HistoricalInvoiceImporter
{
    private const FIXED_HEADERS = ['year', 'month', 'bill_date', 'unit'];

    /** Charge codes that live on meters / are allocated from meters — not building charge inputs. */
    private const METER_CHARGE_CODES = ['electricity', 'electricity_common'];

    /** Charge codes shown as per-lease inputs on the billing period page. */
    private const PER_LEASE_CHARGE_CODES = ['arrears', 'other'];

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

        /** @var array<int, true> $touchedPeriodIds */
        $touchedPeriodIds = [];

        for ($i = 1; $i < count($rows); $i++) {
            $excelRow = $i + 1;
            $raw = $rows[$i] ?? [];
            if ($this->rowIsEmpty($raw)) {
                continue;
            }

            try {
                $outcome = DB::transaction(function () use ($property, $raw, $headerMap, $chargeTypes, &$touchedPeriodIds) {
                    $outcome = $this->importRow($property, $raw, $headerMap, $chargeTypes);
                    if (! empty($outcome['period_id'])) {
                        $touchedPeriodIds[(int) $outcome['period_id']] = true;
                    }

                    return $outcome;
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

        foreach (array_keys($touchedPeriodIds) as $periodId) {
            $period = BillingPeriod::query()->find($periodId);
            if ($period) {
                $this->syncPeriodInputsFromInvoices($period);
            }
        }

        return $result;
    }

    /**
     * Rebuild meter + charge working inputs for a period from its invoices.
     * Unit electricity maps to the unit's sole meter; 2nd / owner-occupied / multi-meter units are skipped.
     * Common meter amounts cannot be recovered from allocated invoice shares and are left unchanged.
     */
    public function syncPeriodInputsFromInvoices(BillingPeriod $period): void
    {
        $period->load([
            'invoices.lines.chargeType',
            'invoices.lease.unit.meters',
        ]);

        $chargeTypes = ChargeType::query()
            ->where('property_id', $period->property_id)
            ->where('is_active', true)
            ->get()
            ->keyBy(fn (ChargeType $type) => strtolower($type->code));

        $servicePeriod = Carbon::createFromDate($period->year, $period->month, 1)
            ->subMonth()
            ->endOfMonth()
            ->toDateString();

        foreach ($period->invoices as $invoice) {
            $unit = $invoice->lease?->unit;
            if (! $unit) {
                continue;
            }

            foreach ($invoice->lines as $line) {
                $code = strtolower((string) ($line->chargeType?->code ?? ''));
                if ($code === '') {
                    continue;
                }

                $amount = round((float) $line->amount, 2);

                if ($code === 'electricity') {
                    $this->syncUnitMeterFromAmount($period, $unit, $amount, $servicePeriod);
                    continue;
                }

                if (in_array($code, self::METER_CHARGE_CODES, true)) {
                    continue;
                }

                if (in_array($code, self::PER_LEASE_CHARGE_CODES, true) || ($line->chargeType?->category === 'arrears') || ($line->chargeType?->category === 'other')) {
                    PeriodChargeInput::updateOrCreate(
                        [
                            'billing_period_id' => $period->id,
                            'charge_type_id' => $line->charge_type_id,
                            'lease_id' => $invoice->lease_id,
                        ],
                        ['amount' => $amount]
                    );
                }
            }
        }

        $this->syncBuildingChargeInputsFromInvoices($period, $chargeTypes);
    }

    /**
     * Backfill period working inputs for all periods on a property that have invoices.
     *
     * @return array{periods: int}
     */
    public function backfillProperty(Property $property): array
    {
        $periods = BillingPeriod::query()
            ->where('property_id', $property->id)
            ->whereHas('invoices')
            ->get();

        foreach ($periods as $period) {
            $this->syncPeriodInputsFromInvoices($period);
        }

        return ['periods' => $periods->count()];
    }

    /**
     * @param  array<int, mixed>  $raw
     * @param  array<string, int>  $headerMap
     * @param  Collection<string, ChargeType>  $chargeTypes
     * @return array{status: string, message: string, period_id?: int}
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
                'code' => strtolower($chargeType->code),
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
            // Keep period inputs in sync even when the invoice already exists.
            $this->syncPeriodInputsFromInvoices($period->fresh());

            return [
                'status' => 'skipped',
                'message' => 'Invoice already exists for '.$unitLabel.' · '.$period->period_key.'.',
                'period_id' => $period->id,
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
                'charge_type_id' => $line['charge_type_id'],
                'description' => $line['description'],
                'period_label' => $line['period_label'],
                'amount' => $line['amount'],
                'sort_order' => $line['sort_order'],
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
            'period_id' => $period->id,
        ];
    }

    private function syncUnitMeterFromAmount(BillingPeriod $period, Unit $unit, float $amount, string $servicePeriod): void
    {
        if ($amount <= 0 || $this->shouldSkipUnitWorkingInputs($unit)) {
            return;
        }

        $meters = Meter::query()
            ->where('property_id', $period->property_id)
            ->where('unit_id', $unit->id)
            ->where('kind', 'unit')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        if ($meters->count() !== 1) {
            return;
        }

        PeriodMeterInput::updateOrCreate(
            [
                'billing_period_id' => $period->id,
                'meter_id' => $meters->first()->id,
            ],
            [
                'amount' => $amount,
                'service_period' => $servicePeriod,
            ]
        );
    }

    /**
     * @param  Collection<string, ChargeType>  $chargeTypes
     */
    private function syncBuildingChargeInputsFromInvoices(BillingPeriod $period, Collection $chargeTypes): void
    {
        $lines = InvoiceLine::query()
            ->whereHas('invoice', fn ($q) => $q->where('billing_period_id', $period->id))
            ->with('chargeType')
            ->get();

        $byCode = $lines->groupBy(fn (InvoiceLine $line) => strtolower((string) ($line->chargeType?->code ?? '')));

        foreach ($chargeTypes as $code => $chargeType) {
            if (in_array($code, self::METER_CHARGE_CODES, true)) {
                continue;
            }
            if (in_array($code, self::PER_LEASE_CHARGE_CODES, true)) {
                continue;
            }
            if (in_array($code, ['office_rent', 'rent'], true)) {
                continue;
            }
            if (! in_array($chargeType->category, ['utility', 'fixed'], true)) {
                continue;
            }

            $group = $byCode->get($code, collect());
            if ($group->isEmpty()) {
                continue;
            }

            if ($code === 'water') {
                $amount = round((float) $group->sum('amount'), 2);
                PeriodChargeInput::updateOrCreate(
                    [
                        'billing_period_id' => $period->id,
                        'charge_type_id' => $chargeType->id,
                        'lease_id' => null,
                    ],
                    [
                        'amount' => $amount,
                        // Units are not in the Excel template; leave existing units if any.
                        'units' => PeriodChargeInput::query()
                            ->where('billing_period_id', $period->id)
                            ->where('charge_type_id', $chargeType->id)
                            ->whereNull('lease_id')
                            ->value('units'),
                    ]
                );
                continue;
            }

            // Fixed / shared charges: use a representative per-lease amount (usually identical).
            $amount = round((float) $group->avg('amount'), 2);
            PeriodChargeInput::updateOrCreate(
                [
                    'billing_period_id' => $period->id,
                    'charge_type_id' => $chargeType->id,
                    'lease_id' => null,
                ],
                ['amount' => $amount]
            );
        }
    }

    private function shouldSkipUnitWorkingInputs(Unit $unit): bool
    {
        return $this->unitIsOwnerOccupiedOrSecond($unit);
    }

    private function unitIsOwnerOccupiedOrSecond(Unit $unit): bool
    {
        if (($unit->type ?? '') === 'owner_occupied') {
            return true;
        }

        return strcasecmp(trim((string) $unit->label), '2nd') === 0;
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
