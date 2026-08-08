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
            return $mail->invoice->is($invoice)
                && $mail->hasTo('tenant@example.com');
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
}
