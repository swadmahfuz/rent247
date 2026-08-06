<?php

namespace Tests\Feature;

use App\Models\BillingPeriod;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PeriodChargeInput;
use App\Models\PeriodMeterInput;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class OpsPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_from_invoice_updates_status_and_receipt_downloads(): void
    {
        $this->seed();
        $user = User::first();
        $invoice = Invoice::first();
        $invoice->update([
            'status' => 'issued',
            'issued_at' => now(),
            'paid_amount' => 0,
        ]);

        $this->actingAs($user)->post(route('payments.store'), [
            'invoice_id' => $invoice->id,
            'amount' => 1000,
            'paid_on' => now()->toDateString(),
            'method' => 'cash',
            'note' => 'Partial',
        ])->assertRedirect();

        $invoice->refresh();
        $this->assertSame('partial', $invoice->status);
        $this->assertSame('1000.00', number_format((float) $invoice->paid_amount, 2, '.', ''));

        $payment = Payment::where('invoice_id', $invoice->id)->firstOrFail();
        $this->actingAs($user)
            ->get(route('payments.receipt', $payment))
            ->assertOk();
    }

    public function test_copy_prior_copies_meter_and_non_arrears_charges(): void
    {
        $this->seed();
        $user = User::first();
        $august = BillingPeriod::where('year', 2026)->where('month', 8)->firstOrFail();

        $september = BillingPeriod::create([
            'property_id' => $august->property_id,
            'year' => 2026,
            'month' => 9,
            'bill_date' => now()->toDateString(),
            'status' => 'draft',
        ]);

        $this->actingAs($user)
            ->post(route('billing.copy-prior', $september))
            ->assertRedirect();

        $this->assertSame(
            $august->meterInputs()->count(),
            PeriodMeterInput::where('billing_period_id', $september->id)->count()
        );

        $nonArrearsPrior = $august->chargeInputs()
            ->whereHas('chargeType', fn ($q) => $q->where('code', '!=', 'arrears')->where('category', '!=', 'arrears'))
            ->count();

        $nonArrearsNext = PeriodChargeInput::where('billing_period_id', $september->id)
            ->whereHas('chargeType', fn ($q) => $q->where('code', '!=', 'arrears')->where('category', '!=', 'arrears'))
            ->count();

        $this->assertSame($nonArrearsPrior, $nonArrearsNext);
    }

    public function test_period_invoices_zip_contains_pdfs(): void
    {
        $this->seed();
        $user = User::first();
        $period = BillingPeriod::where('year', 2026)->where('month', 8)->firstOrFail();
        $count = $period->invoices()->count();
        $this->assertGreaterThan(0, $count);

        $response = $this->actingAs($user)->get(route('billing.invoices-zip', $period));
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($tmp, $response->streamedContent());

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tmp) === true);
        $this->assertSame($count, $zip->numFiles);
        $zip->close();
        @unlink($tmp);
    }

    public function test_tenant_portal_share_link_and_invoice_scope(): void
    {
        $this->seed();
        $user = User::first();
        $tenant = Tenant::first();
        $ownInvoice = Invoice::whereHas('lease', fn ($q) => $q->where('tenant_id', $tenant->id))->firstOrFail();
        $ownInvoice->update(['status' => 'issued', 'issued_at' => now()]);

        $otherTenant = Tenant::where('id', '!=', $tenant->id)->firstOrFail();
        $otherInvoice = Invoice::whereHas('lease', fn ($q) => $q->where('tenant_id', $otherTenant->id))->firstOrFail();
        $otherInvoice->update(['status' => 'issued', 'issued_at' => now()]);

        $this->actingAs($user)->post(route('tenants.portal.enable', $tenant))->assertRedirect();
        $tenant->refresh();
        $this->assertTrue($tenant->portal_enabled);
        $this->assertNotEmpty($tenant->portal_token);

        $this->get(route('portal.show', $tenant->portal_token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/Show')
                ->where('tenant.name', $tenant->name)
            );

        $this->get(route('portal.invoice.pdf', [$tenant->portal_token, $ownInvoice->id]))
            ->assertOk();

        $this->get(route('portal.invoice.pdf', [$tenant->portal_token, $otherInvoice->id]))
            ->assertForbidden();

        $this->get(route('portal.show', 'missing-token'))->assertNotFound();
    }
}
