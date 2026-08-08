<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\PeriodChargeInput;
use App\Models\PeriodMeterInput;
use App\Models\User;
use App\Services\HistoricalInvoiceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class HistoricalImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_download_includes_charge_codes(): void
    {
        $this->seed();
        $user = User::first();

        $response = $this->actingAs($user)->get(route('import.template'));

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            strtolower($response->headers->get('content-type') ?? '')
        );
    }

    public function test_import_creates_paid_invoice_lines_and_payment(): void
    {
        $this->seed();
        $user = User::first();
        $lease = Lease::whereHas('unit', fn ($q) => $q->where('label', '1st'))->firstOrFail();

        $file = $this->makeSpreadsheet([
            ['year', 'month', 'bill_date', 'unit', 'office_rent', 'gas', 'water'],
            [2024, 3, '2024-03-05', '1st', 100000, 1080, 2500],
        ]);

        $response = $this->actingAs($user)->post(route('import.store'), [
            'file' => $file,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('import_result');

        $invoice = Invoice::where('lease_id', $lease->id)
            ->whereHas('billingPeriod', fn ($q) => $q->where('year', 2024)->where('month', 3))
            ->first();

        $this->assertNotNull($invoice);
        $this->assertSame('paid', $invoice->status);
        $this->assertEquals(103580.0, (float) $invoice->total_amount);
        $this->assertEquals(103580.0, (float) $invoice->paid_amount);
        $this->assertSame(3, $invoice->lines()->count());
        $this->assertTrue($invoice->issued_at->isSameDay('2024-03-05'));

        $payment = Payment::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($payment);
        $this->assertEquals(103580.0, (float) $payment->amount);
        $this->assertSame('2024-03-05', $payment->paid_on->toDateString());
        $this->assertSame('import', $payment->method);

        $period = $invoice->billingPeriod;
        $gas = PeriodChargeInput::query()
            ->where('billing_period_id', $period->id)
            ->whereNull('lease_id')
            ->whereHas('chargeType', fn ($q) => $q->where('code', 'gas'))
            ->first();
        $this->assertNotNull($gas);
        $this->assertEquals(1080.0, (float) $gas->amount);

        $water = PeriodChargeInput::query()
            ->where('billing_period_id', $period->id)
            ->whereNull('lease_id')
            ->whereHas('chargeType', fn ($q) => $q->where('code', 'water'))
            ->first();
        $this->assertNotNull($water);
        $this->assertEquals(2500.0, (float) $water->amount);
    }

    public function test_import_maps_unit_electricity_to_sole_unit_meter_and_skips_second_floor(): void
    {
        $this->seed();
        $user = User::first();

        $firstMeter = Meter::query()->where('kind', 'unit')->where('code', '563')->firstOrFail();
        $secondMeters = Meter::query()
            ->where('kind', 'unit')
            ->whereHas('unit', fn ($q) => $q->where('label', '2nd'))
            ->pluck('id');

        $file = $this->makeSpreadsheet([
            ['year', 'month', 'bill_date', 'unit', 'office_rent', 'electricity', 'gas'],
            [2024, 6, '2024-06-03', '1st', 100000, 12345, 1080],
            [2024, 6, '2024-06-03', '3rd', 90000, 22222, 1080],
        ]);

        $this->actingAs($user)->post(route('import.store'), ['file' => $file])->assertRedirect();

        $firstInput = PeriodMeterInput::query()
            ->where('meter_id', $firstMeter->id)
            ->whereHas('billingPeriod', fn ($q) => $q->where('year', 2024)->where('month', 6))
            ->first();
        $this->assertNotNull($firstInput);
        $this->assertEquals(12345.0, (float) $firstInput->amount);

        $thirdMeter = Meter::query()->where('kind', 'unit')->where('code', '237')->firstOrFail();
        $thirdInput = PeriodMeterInput::query()
            ->where('meter_id', $thirdMeter->id)
            ->whereHas('billingPeriod', fn ($q) => $q->where('year', 2024)->where('month', 6))
            ->first();
        $this->assertNotNull($thirdInput);
        $this->assertEquals(22222.0, (float) $thirdInput->amount);

        $this->assertSame(
            0,
            PeriodMeterInput::query()
                ->whereIn('meter_id', $secondMeters)
                ->whereHas('billingPeriod', fn ($q) => $q->where('year', 2024)->where('month', 6))
                ->count()
        );
    }

    public function test_backfill_rebuilds_period_inputs_from_existing_invoices(): void
    {
        $this->seed();
        $user = User::first();
        $lease = Lease::whereHas('unit', fn ($q) => $q->where('label', '1st'))->firstOrFail();

        $file = $this->makeSpreadsheet([
            ['year', 'month', 'bill_date', 'unit', 'office_rent', 'electricity', 'water'],
            [2024, 7, '2024-07-02', '1st', 100000, 5555, 3000],
        ]);
        $this->actingAs($user)->post(route('import.store'), ['file' => $file])->assertRedirect();

        $invoice = Invoice::where('lease_id', $lease->id)
            ->whereHas('billingPeriod', fn ($q) => $q->where('year', 2024)->where('month', 7))
            ->firstOrFail();
        $period = $invoice->billingPeriod;

        PeriodMeterInput::where('billing_period_id', $period->id)->delete();
        PeriodChargeInput::where('billing_period_id', $period->id)->delete();
        $this->assertSame(0, PeriodMeterInput::where('billing_period_id', $period->id)->count());

        $result = app(HistoricalInvoiceImporter::class)->backfillProperty($period->property);
        $this->assertGreaterThan(0, $result['periods']);

        $meter = Meter::query()->where('kind', 'unit')->where('code', '563')->firstOrFail();
        $meterInput = PeriodMeterInput::query()
            ->where('billing_period_id', $period->id)
            ->where('meter_id', $meter->id)
            ->first();
        $this->assertNotNull($meterInput);
        $this->assertEquals(5555.0, (float) $meterInput->amount);

        $water = PeriodChargeInput::query()
            ->where('billing_period_id', $period->id)
            ->whereNull('lease_id')
            ->whereHas('chargeType', fn ($q) => $q->where('code', 'water'))
            ->first();
        $this->assertNotNull($water);
        $this->assertEquals(3000.0, (float) $water->amount);
    }

    public function test_import_fails_unknown_unit(): void
    {
        $this->seed();
        $user = User::first();

        $file = $this->makeSpreadsheet([
            ['year', 'month', 'bill_date', 'unit', 'office_rent'],
            [2024, 4, '2024-04-01', 'NoSuchFloor', 5000],
        ]);

        $response = $this->actingAs($user)->post(route('import.store'), [
            'file' => $file,
        ]);

        $response->assertRedirect();
        $result = session('import_result');
        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['failed']);
        $this->assertStringContainsString('No unit found', $result['rows'][0]['message']);
        $this->assertSame(0, Invoice::whereHas('billingPeriod', fn ($q) => $q->where('year', 2024)->where('month', 4))->count());
    }

    public function test_import_skips_existing_invoice_for_period_and_lease(): void
    {
        $this->seed();
        $user = User::first();

        $file = $this->makeSpreadsheet([
            ['year', 'month', 'bill_date', 'unit', 'office_rent', 'gas'],
            [2024, 5, '2024-05-02', '1st', 100000, 1080],
        ]);

        $this->actingAs($user)->post(route('import.store'), ['file' => $file])->assertRedirect();
        $this->assertSame(1, Invoice::whereHas('billingPeriod', fn ($q) => $q->where('year', 2024)->where('month', 5))->count());

        $again = $this->makeSpreadsheet([
            ['year', 'month', 'bill_date', 'unit', 'office_rent', 'gas'],
            [2024, 5, '2024-05-02', '1st', 99999, 1],
        ]);

        $response = $this->actingAs($user)->post(route('import.store'), ['file' => $again]);
        $response->assertRedirect();

        $result = session('import_result');
        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, Invoice::whereHas('billingPeriod', fn ($q) => $q->where('year', 2024)->where('month', 5))->count());
        $this->assertEquals(101080.0, (float) Invoice::whereHas('billingPeriod', fn ($q) => $q->where('year', 2024)->where('month', 5))->first()->total_amount);
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function makeSpreadsheet(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                $sheet->setCellValue([$c + 1, $r + 1], $value);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'histimp').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'historical.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
