<?php

namespace Tests\Feature;

use App\Models\BillingPeriod;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\PeriodChargeInput;
use App\Models\Property;
use App\Models\User;
use App\Services\BillingCalculator;
use App\Services\PdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoicePolishTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_billing_period_defaults_invoice_date_to_today(): void
    {
        $this->seed();
        $user = User::first();
        $property = Property::first();

        $response = $this->actingAs($user)->post(route('billing.store'), [
            'year' => 2026,
            'month' => 9,
        ]);

        $response->assertRedirect();
        $period = BillingPeriod::where('property_id', $property->id)
            ->where('year', 2026)
            ->where('month', 9)
            ->first();

        $this->assertNotNull($period);
        $this->assertSame(now()->toDateString(), $period->bill_date->toDateString());
    }

    public function test_arrears_are_seeded_from_unpaid_prior_invoices(): void
    {
        $this->seed();

        $lease = Lease::whereHas('unit', fn ($q) => $q->where('label', '1st'))->firstOrFail();
        $august = BillingPeriod::where('year', 2026)->where('month', 8)->firstOrFail();
        $invoice = Invoice::where('billing_period_id', $august->id)->where('lease_id', $lease->id)->firstOrFail();
        $invoice->update([
            'status' => 'partial',
            'paid_amount' => 40000,
            'issued_at' => now(),
        ]);

        $september = BillingPeriod::create([
            'property_id' => $august->property_id,
            'year' => 2026,
            'month' => 9,
            'bill_date' => now()->toDateString(),
            'status' => 'draft',
        ]);

        app(BillingCalculator::class)->seedArrearsInputs($september);

        $arrearsTypeId = $august->property->chargeTypes()->where('code', 'arrears')->value('id');
        $input = PeriodChargeInput::where('billing_period_id', $september->id)
            ->where('lease_id', $lease->id)
            ->where('charge_type_id', $arrearsTypeId)
            ->first();

        $this->assertNotNull($input);
        $this->assertSame('102285.06', number_format((float) $input->amount, 2, '.', ''));
    }

    public function test_signature_upload_appears_in_pdf_payload(): void
    {
        Storage::fake('public');
        $this->seed();

        $user = User::first();
        $property = Property::first();
        $file = UploadedFile::fake()->image('sign.png', 200, 80);

        $this->actingAs($user)->post(route('properties.signature', $property), [
            'signature' => $file,
        ])->assertRedirect();

        $property->refresh();
        $this->assertNotNull($property->setting('signature_path'));

        // Real disk path needed for Dompdf path helper in PdfGenerator — use local public disk write
        Storage::disk('public')->put(
            $property->setting('signature_path'),
            file_get_contents($file->getRealPath()) ?: 'fake'
        );

        $invoice = Invoice::first();
        $pdf = app(PdfGenerator::class)->invoice($invoice);
        $this->assertStringStartsWith('%PDF', $pdf->output());
    }

    public function test_uploaded_signature_is_normalized_to_a_standard_height(): void
    {
        $this->seed();

        $user = User::first();
        $property = Property::first();

        $this->actingAs($user)->post(route('properties.signature', $property), [
            'signature' => UploadedFile::fake()->image('sign.png', 600, 400),
        ])->assertRedirect();

        $property->refresh();
        $stored = Storage::disk('public')->path($property->setting('signature_path'));
        [$width, $height] = getimagesize($stored);

        $this->assertSame(120, $height);
        $this->assertLessThanOrEqual(420, $width);

        Storage::disk('public')->deleteDirectory('signatures/'.$property->id);
    }

    public function test_split_and_combined_pdfs_include_bill_date_and_long_labels(): void
    {
        $this->seed();

        $period = BillingPeriod::where('year', 2026)->where('month', 8)->firstOrFail();
        $period->update(['bill_date' => '2026-08-05']);
        app(BillingCalculator::class)->generate($period);

        $lease = Lease::whereHas('unit', fn ($q) => $q->where('label', '1st'))->firstOrFail();

        $lease->update(['invoice_mode' => 'combined']);
        $combined = Invoice::where('billing_period_id', $period->id)->where('lease_id', $lease->id)->firstOrFail();
        $combinedPdf = app(PdfGenerator::class)->invoice($combined->fresh(['lines.chargeType', 'lease.tenant', 'lease.unit', 'property', 'billingPeriod']));
        $this->assertStringStartsWith('%PDF', $combinedPdf->output());
        $this->assertSame('2026-08-05', optional($combined->billingPeriod->bill_date)->toDateString());

        $lease->update(['invoice_mode' => 'split']);
        $split = $combined->fresh(['lines.chargeType', 'lease.tenant', 'lease.unit', 'property', 'billingPeriod']);
        $this->assertTrue(
            $split->lines->contains(fn ($line) => str_contains($line->description, 'Care Taker')
                || str_contains($line->description, 'Security'))
        );
        $splitPdf = app(PdfGenerator::class)->invoice($split);
        $this->assertStringStartsWith('%PDF', $splitPdf->output());
    }

    public function test_split_invoice_puts_each_part_on_its_own_page(): void
    {
        $this->seed();

        $lease = Lease::whereHas('unit', fn ($q) => $q->where('label', '1st'))->firstOrFail();
        $period = BillingPeriod::where('year', 2026)->where('month', 8)->firstOrFail();
        $invoice = Invoice::where('billing_period_id', $period->id)->where('lease_id', $lease->id)->firstOrFail();

        $lease->update(['invoice_mode' => 'combined']);
        $this->assertSame(1, $this->pageCount(app(PdfGenerator::class)->invoice($invoice->fresh())->output()));

        $lease->update(['invoice_mode' => 'split']);
        $this->assertSame(2, $this->pageCount(app(PdfGenerator::class)->invoice($invoice->fresh())->output()));
    }

    public function test_generate_carries_arrears_into_invoice_lines(): void
    {
        $this->seed();

        $lease = Lease::whereHas('unit', fn ($q) => $q->where('label', '1st'))->firstOrFail();
        $august = BillingPeriod::where('year', 2026)->where('month', 8)->firstOrFail();
        $invoice = Invoice::where('billing_period_id', $august->id)->where('lease_id', $lease->id)->firstOrFail();
        $invoice->update([
            'status' => 'partial',
            'paid_amount' => 40000,
            'issued_at' => now(),
        ]);

        $september = BillingPeriod::create([
            'property_id' => $august->property_id,
            'year' => 2026,
            'month' => 9,
            'bill_date' => now()->toDateString(),
            'status' => 'draft',
        ]);

        // Copy meter/charge building inputs so generate can run
        foreach ($august->meterInputs as $input) {
            $september->meterInputs()->create([
                'meter_id' => $input->meter_id,
                'amount' => $input->amount,
                'service_period' => $input->service_period,
            ]);
        }
        foreach ($august->chargeInputs->whereNull('lease_id') as $input) {
            $september->chargeInputs()->create([
                'charge_type_id' => $input->charge_type_id,
                'lease_id' => null,
                'amount' => $input->amount,
                'units' => $input->units,
            ]);
        }

        app(BillingCalculator::class)->generate($september);

        $newInvoice = Invoice::where('billing_period_id', $september->id)
            ->where('lease_id', $lease->id)
            ->firstOrFail();

        $arrearsLine = $newInvoice->lines()
            ->whereHas('chargeType', fn ($q) => $q->where('code', 'arrears'))
            ->first();

        $this->assertNotNull($arrearsLine);
        $this->assertSame('102285.06', number_format((float) $arrearsLine->amount, 2, '.', ''));
    }

    private function pageCount(string $pdf): int
    {
        preg_match_all('/\/Type\s*\/Page[^s]/', $pdf, $matches);

        return count($matches[0]);
    }
}
