<?php

namespace Tests\Feature;

use App\Mail\InvoiceMail;
use App\Models\Invoice;
use App\Models\MailLog;
use App\Models\User;
use App\Services\InvoicePacketBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CollectionsEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_index_filters_outstanding_and_sorts_by_balance(): void
    {
        $this->seed();
        $user = User::first();

        $first = Invoice::with('lease.unit')->get()->first(fn ($i) => $i->lease?->unit?->label === '1st');
        $fifth = Invoice::with('lease.unit')->get()->first(fn ($i) => $i->lease?->unit?->label === '5th');
        $this->assertNotNull($first);
        $this->assertNotNull($fifth);

        $first->update(['status' => 'issued', 'paid_amount' => 0, 'issued_at' => now()]);
        $fifth->update(['status' => 'partial', 'paid_amount' => 1000, 'issued_at' => now()]);

        Invoice::whereNotIn('id', [$first->id, $fifth->id])->update(['status' => 'paid', 'paid_amount' => 1]);

        $this->actingAs($user)
            ->get(route('invoices.index', ['status' => 'outstanding']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Invoices/Index')
                ->has('items.data', 2)
                ->where('items.data.0.id', $first->id)
                ->where('items.data.1.id', $fifth->id)
            );
    }

    public function test_invoice_index_filters_by_unit(): void
    {
        $this->seed();
        $user = User::first();
        $invoice = Invoice::with('lease.unit')->first();
        $unitId = $invoice->lease->unit_id;

        $response = $this->actingAs($user)
            ->get(route('invoices.index', ['unit_id' => $unitId]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Invoices/Index')
                ->where('filters.unit_id', $unitId)
            );

        $items = $response->original->getData()['page']['props']['items']['data'];
        $this->assertNotEmpty($items);
        foreach ($items as $row) {
            $this->assertSame((int) $unitId, (int) $row['lease']['unit']['id']);
        }
    }

    public function test_email_uses_packet_builder_and_flashes_success(): void
    {
        Mail::fake();
        Storage::fake('local');
        $this->seed();

        $user = User::first();
        $invoice = Invoice::with('lease.tenant')->first();
        $invoice->lease->tenant->update(['email' => 'tenant@example.com']);

        $this->actingAs($user)
            ->from(route('invoices.show', $invoice))
            ->post(route('invoices.email', $invoice))
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSent(InvoiceMail::class, function (InvoiceMail $mail) use ($invoice) {
            return $mail->hasTo('tenant@example.com')
                && $mail->invoices->contains(fn ($row) => $row->is($invoice));
        });

        $this->assertDatabaseHas('mail_logs', [
            'invoice_id' => $invoice->id,
            'to_email' => 'tenant@example.com',
            'status' => 'sent',
        ]);

        $invoice->refresh();
        $this->assertNotNull($invoice->pdf_path);
        $this->assertTrue(Storage::disk('local')->exists($invoice->pdf_path));
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($invoice->pdf_path));
    }

    public function test_email_sends_to_comma_separated_addresses(): void
    {
        Mail::fake();
        Storage::fake('local');
        $this->seed();

        $user = User::first();
        $invoice = Invoice::with('lease.tenant')->first();
        $invoice->lease->tenant->update([
            'email' => 'one@example.com, two@example.com',
        ]);

        $this->actingAs($user)
            ->from(route('invoices.show', $invoice))
            ->post(route('invoices.email', $invoice))
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSent(InvoiceMail::class, function (InvoiceMail $mail) {
            return $mail->hasTo('one@example.com')
                && $mail->hasTo('two@example.com');
        });

        $this->assertDatabaseHas('mail_logs', [
            'invoice_id' => $invoice->id,
            'to_email' => 'one@example.com, two@example.com',
            'status' => 'sent',
        ]);
    }

    public function test_tenant_accepts_comma_separated_emails(): void
    {
        $this->seed();
        $user = User::first();

        $this->actingAs($user)->post(route('tenants.store'), [
            'name' => 'Multi Mail Tenant',
            'email' => ' a@example.com,b@example.com ,a@example.com ',
            'phone' => null,
            'notes' => null,
        ])->assertRedirect();

        $this->assertDatabaseHas('tenants', [
            'name' => 'Multi Mail Tenant',
            'email' => 'a@example.com, b@example.com',
        ]);
    }

    public function test_tenant_rejects_invalid_address_in_list(): void
    {
        $this->seed();
        $user = User::first();

        $this->actingAs($user)->post(route('tenants.store'), [
            'name' => 'Bad Mail Tenant',
            'email' => 'good@example.com, not-an-email',
        ])->assertSessionHasErrors('email');
    }

    public function test_email_without_tenant_address_flashes_error(): void
    {
        Mail::fake();
        $this->seed();

        $user = User::first();
        $invoice = Invoice::with('lease.tenant')->first();
        $invoice->lease->tenant->update(['email' => null]);

        $this->actingAs($user)
            ->from(route('invoices.show', $invoice))
            ->post(route('invoices.email', $invoice))
            ->assertRedirect()
            ->assertSessionHas('error');

        Mail::assertNothingSent();
        $this->assertSame(0, MailLog::count());
    }

    public function test_packet_builder_is_used_for_email_attachment_bytes(): void
    {
        $this->seed();
        $invoice = Invoice::first();
        $packet = app(InvoicePacketBuilder::class)->build($invoice);

        $this->assertArrayHasKey('contents', $packet);
        $this->assertStringStartsWith('%PDF', $packet['contents']);
    }

    public function test_single_invoice_email_body_includes_address_period_and_unit(): void
    {
        $this->seed();
        $invoice = Invoice::with(['lease.tenant', 'lease.unit', 'property', 'billingPeriod'])->first();
        $mailable = InvoiceMail::forSingleInvoice($invoice, 'invoices/test.pdf');
        $html = $mailable->render();

        $this->assertStringContainsString($invoice->property->address, $html);
        $this->assertStringContainsString($invoice->billingPeriod->label, $html);
        $this->assertStringContainsString($invoice->lease->unit->label, $html);
        $this->assertStringNotContainsString('for <strong>'.$invoice->property->name.'</strong>', $html);

        $invoice->lease->tenant->refresh();
        $this->assertTrue((bool) $invoice->lease->tenant->portal_enabled);
        $this->assertNotEmpty($invoice->lease->tenant->portal_url);
        $this->assertStringContainsString($invoice->lease->tenant->portal_url, $html);
        $this->assertStringContainsString('tenant portal', $html);

        $expectedSubject = 'Office Rent '.$invoice->billingPeriod->label.' — '.$invoice->lease->tenant->name.' — '.$invoice->lease->unit->label;
        $this->assertSame($expectedSubject, $mailable->envelope()->subject);
    }

    public function test_period_bundle_email_requires_finalized_status(): void
    {
        $this->seed();
        $user = User::first();
        $period = \App\Models\BillingPeriod::where('year', 2026)->where('month', 8)->firstOrFail();
        $period->update(['status' => 'draft']);

        $this->actingAs($user)
            ->post(route('billing.email-invoices', $period))
            ->assertStatus(422);
    }

    public function test_period_bundle_emails_same_tenant_with_multiple_attachments(): void
    {
        Mail::fake();
        Storage::fake('local');
        $this->seed();

        $user = User::first();
        $period = \App\Models\BillingPeriod::where('year', 2026)->where('month', 8)->firstOrFail();
        $period->update(['status' => 'finalized']);

        $invoices = Invoice::with('lease.tenant')
            ->where('billing_period_id', $period->id)
            ->orderBy('id')
            ->take(2)
            ->get();
        $this->assertCount(2, $invoices);

        $tenant = $invoices[0]->lease->tenant;
        $tenant->update(['email' => 'bundle@example.com']);
        $invoices[1]->lease->update(['tenant_id' => $tenant->id]);

        \App\Models\Tenant::where('id', '!=', $tenant->id)->update(['email' => null]);

        $this->actingAs($user)
            ->from(route('billing.show', $period))
            ->post(route('billing.email-invoices', $period))
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSentCount(1);
        Mail::assertSent(InvoiceMail::class, function (InvoiceMail $mail) use ($invoices) {
            return $mail->hasTo('bundle@example.com')
                && $mail->invoices->count() === 2
                && count($mail->pdfFiles) === 2
                && $mail->invoices->pluck('id')->sort()->values()->all()
                    === $invoices->pluck('id')->sort()->values()->all();
        });
    }
}
