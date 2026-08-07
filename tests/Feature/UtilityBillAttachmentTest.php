<?php

namespace Tests\Feature;

use App\Models\BillingPeriod;
use App\Models\BillingPeriodDocument;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Meter;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UtilityBillAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_lease_attachment_flags_persist(): void
    {
        $this->seed();
        $user = User::first();
        $lease = Lease::first();

        $this->actingAs($user)->put(route('leases.update', $lease), [
            'unit_id' => $lease->unit_id,
            'tenant_id' => $lease->tenant_id,
            'rent_amount' => $lease->rent_amount,
            'rent_label' => $lease->rent_label,
            'invoice_mode' => $lease->invoice_mode ?: 'combined',
            'attach_water_bill' => true,
            'attach_electricity_bill' => true,
            'fee_tier' => $lease->fee_tier,
            'is_active' => true,
            'charge_type_ids' => $lease->chargeAssignments()->pluck('charge_type_id')->all(),
        ])->assertRedirect();

        $lease->refresh();
        $this->assertTrue($lease->attach_water_bill);
        $this->assertTrue($lease->attach_electricity_bill);
    }

    public function test_invoice_pdf_includes_utility_bills_as_extra_pages(): void
    {
        Storage::fake('public');
        $this->seed();
        $user = User::first();

        $lease = Lease::whereHas('unit', fn ($q) => $q->where('label', '1st'))->firstOrFail();
        $period = BillingPeriod::create([
            'property_id' => $lease->property_id,
            'year' => 2026,
            'month' => 10,
            'bill_date' => now()->toDateString(),
            'status' => 'draft',
        ]);

        $lease->update([
            'attach_water_bill' => true,
            'attach_electricity_bill' => true,
        ]);

        app(BillingCalculator::class)->generate($period);
        $invoice = Invoice::where('billing_period_id', $period->id)->where('lease_id', $lease->id)->firstOrFail();

        $this->actingAs($user)->post(route('billing.documents.store', $period), [
            'kind' => 'water',
            'file' => UploadedFile::fake()->image('water.jpg'),
        ])->assertRedirect();

        $this->actingAs($user)->post(route('billing.documents.store', $period), [
            'kind' => 'electricity',
            'unit_id' => $lease->unit_id,
            'file' => UploadedFile::fake()->image('elec.jpg'),
        ])->assertRedirect();

        $withoutBills = app(\App\Services\PdfGenerator::class)->invoice($invoice->fresh())->output();
        $response = $this->actingAs($user)->get(route('invoices.pdf', $invoice));
        $response->assertOk();
        $this->assertStringContainsString('pdf', strtolower($response->headers->get('content-type') ?? ''));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $withBills = $response->getContent();
        $pdf = app(\App\Services\PdfGenerator::class);
        $this->assertGreaterThan(
            $pdf->pageCount($withoutBills),
            $pdf->pageCount($withBills)
        );
        $this->assertGreaterThanOrEqual(3, $pdf->pageCount($withBills));
    }

    public function test_building_electricity_is_not_used_when_unit_bill_required(): void
    {
        Storage::fake('public');
        $this->seed();
        $user = User::first();
        $lease = Lease::whereHas('unit', fn ($q) => $q->where('label', '1st'))->firstOrFail();
        $period = BillingPeriod::create([
            'property_id' => $lease->property_id,
            'year' => 2026,
            'month' => 9,
            'bill_date' => now()->toDateString(),
            'status' => 'draft',
        ]);
        $lease->update([
            'attach_water_bill' => false,
            'attach_electricity_bill' => true,
        ]);
        app(BillingCalculator::class)->generate($period);
        $invoice = Invoice::where('billing_period_id', $period->id)->where('lease_id', $lease->id)->firstOrFail();

        $this->actingAs($user)->post(route('billing.documents.store', $period), [
            'kind' => 'electricity',
            'file' => UploadedFile::fake()->image('building-elec.jpg'),
        ])->assertRedirect();

        $status = app(\App\Services\InvoicePacketBuilder::class)->attachmentStatus($invoice->fresh(['lease', 'billingPeriod.documents']));
        $this->assertFalse($status['has_attachments']);
        $this->assertContains('unit electricity', $status['missing']);

        $this->actingAs($user)->post(route('billing.documents.store', $period), [
            'kind' => 'electricity',
            'unit_id' => $lease->unit_id,
            'file' => UploadedFile::fake()->create('unit-elec.jpeg', 80, 'image/jpeg'),
        ])->assertRedirect();

        $status = app(\App\Services\InvoicePacketBuilder::class)->attachmentStatus($invoice->fresh(['lease', 'billingPeriod.documents']));
        $this->assertTrue($status['has_attachments']);
        $this->assertSame([], $status['missing']);
    }

    public function test_multiple_meter_electricity_bills_can_be_uploaded_and_replaced_together(): void
    {
        Storage::fake('public');
        $this->seed();
        $user = User::first();
        $meters = Meter::where('kind', 'unit')->where('is_active', true)->take(3)->get();
        $period = BillingPeriod::create([
            'property_id' => $meters->first()->property_id,
            'year' => 2027,
            'month' => 1,
            'bill_date' => now()->toDateString(),
            'status' => 'draft',
        ]);

        $files = [];
        foreach ($meters as $meter) {
            $files[$meter->id] = UploadedFile::fake()->image('electricity-'.$meter->id.'.jpg');
        }

        $this->actingAs($user)->post(route('billing.documents.unit-electricity.store', $period), [
            'files' => $files,
        ])->assertRedirect();

        $this->assertSame(3, BillingPeriodDocument::where('billing_period_id', $period->id)->where('kind', 'electricity')->count());

        $meter = $meters->first();
        $this->actingAs($user)->post(route('billing.documents.unit-electricity.store', $period), [
            'files' => [
                $meter->id => UploadedFile::fake()->create('replacement.jpeg', 80, 'image/jpeg'),
            ],
        ])->assertRedirect();

        $this->assertSame(3, BillingPeriodDocument::where('billing_period_id', $period->id)->where('kind', 'electricity')->count());
        $this->assertDatabaseHas('billing_period_documents', [
            'billing_period_id' => $period->id,
            'unit_id' => $meter->unit_id,
            'meter_id' => $meter->id,
            'original_name' => 'replacement.jpeg',
        ]);
    }

    public function test_unit_with_several_meters_gets_every_meter_bill_page(): void
    {
        Storage::fake('public');
        $this->seed();
        $user = User::first();

        $lease = Lease::with('unit')->whereHas('unit', fn ($q) => $q->where('label', '1st'))->firstOrFail();
        $lease->update(['attach_water_bill' => false, 'attach_electricity_bill' => true]);

        Meter::create([
            'property_id' => $lease->property_id,
            'unit_id' => $lease->unit_id,
            'kind' => 'unit',
            'code' => '999',
            'name' => '1st Floor B',
            'is_active' => true,
            'sort_order' => 99,
        ]);
        $meters = Meter::where('kind', 'unit')->where('unit_id', $lease->unit_id)->get();
        $this->assertGreaterThan(1, $meters->count());

        $period = BillingPeriod::create([
            'property_id' => $lease->property_id,
            'year' => 2027,
            'month' => 3,
            'bill_date' => now()->toDateString(),
            'status' => 'draft',
        ]);
        app(BillingCalculator::class)->generate($period);
        $invoice = Invoice::where('billing_period_id', $period->id)->where('lease_id', $lease->id)->firstOrFail();

        $this->actingAs($user)->post(route('billing.documents.unit-electricity.store', $period), [
            'files' => $meters->mapWithKeys(fn (Meter $meter) => [
                $meter->id => UploadedFile::fake()->image('meter-'.$meter->code.'.jpg'),
            ])->all(),
        ])->assertRedirect();

        $packet = app(\App\Services\InvoicePacketBuilder::class)->build($invoice->fresh());
        $this->assertStringStartsWith('%PDF', $packet['contents']);
        $this->assertTrue($packet['has_attachments']);

        $pdf = app(\App\Services\PdfGenerator::class);
        $basePages = $pdf->pageCount($pdf->invoice($invoice->fresh())->output());
        $this->assertSame($basePages + $meters->count(), $pdf->pageCount($packet['contents']));
    }

    public function test_invoice_download_still_works_when_selected_bill_is_not_uploaded(): void
    {
        $this->seed();
        $user = User::first();
        $lease = Lease::whereHas('unit', fn ($q) => $q->where('label', '1st'))->firstOrFail();
        $lease->update(['attach_electricity_bill' => true]);
        $period = BillingPeriod::create([
            'property_id' => $lease->property_id,
            'year' => 2027,
            'month' => 2,
            'bill_date' => now()->toDateString(),
            'status' => 'draft',
        ]);
        app(BillingCalculator::class)->generate($period);
        $invoice = Invoice::where('billing_period_id', $period->id)->where('lease_id', $lease->id)->firstOrFail();

        $response = $this->actingAs($user)->get(route('invoices.pdf', $invoice));

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_invoice_without_attachment_flags_returns_pdf(): void
    {
        $this->seed();
        $user = User::first();
        $lease = Lease::whereHas('unit', fn ($q) => $q->where('label', '1st'))->firstOrFail();
        $period = BillingPeriod::create([
            'property_id' => $lease->property_id,
            'year' => 2026,
            'month' => 11,
            'bill_date' => now()->toDateString(),
            'status' => 'draft',
        ]);
        $lease->update([
            'attach_water_bill' => false,
            'attach_electricity_bill' => false,
        ]);
        app(BillingCalculator::class)->generate($period);
        $invoice = Invoice::where('billing_period_id', $period->id)->where('lease_id', $lease->id)->firstOrFail();

        $response = $this->actingAs($user)->get(route('invoices.pdf', $invoice));
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_portal_cannot_download_another_tenants_invoice(): void
    {
        Storage::fake('public');
        $this->seed();
        $user = User::first();
        $tenant = Tenant::first();
        $other = Tenant::where('id', '!=', $tenant->id)->firstOrFail();

        $period = BillingPeriod::create([
            'property_id' => $tenant->leases()->first()->property_id,
            'year' => 2026,
            'month' => 12,
            'bill_date' => now()->toDateString(),
            'status' => 'draft',
        ]);
        app(BillingCalculator::class)->generate($period);

        $own = Invoice::whereHas('lease', fn ($q) => $q->where('tenant_id', $tenant->id))->firstOrFail();
        $foreign = Invoice::whereHas('lease', fn ($q) => $q->where('tenant_id', $other->id))->firstOrFail();
        $own->update(['status' => 'issued', 'issued_at' => now()]);
        $foreign->update(['status' => 'issued', 'issued_at' => now()]);

        $this->actingAs($user)->post(route('tenants.portal.enable', $tenant))->assertRedirect();
        $tenant->refresh();

        $this->get(route('portal.invoice.pdf', [$tenant->portal_token, $own->id]))->assertOk();
        $this->get(route('portal.invoice.pdf', [$tenant->portal_token, $foreign->id]))->assertForbidden();
    }
}
