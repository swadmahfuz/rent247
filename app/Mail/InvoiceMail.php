<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, Invoice>|list<Invoice>  $invoices
     * @param  list<array{path: string, as: string}>  $pdfFiles
     */
    public function __construct(
        public Collection|array $invoices,
        public array $pdfFiles,
        public ?Property $property = null,
        public ?Tenant $tenant = null,
        public ?string $periodLabel = null,
    ) {
        $this->invoices = collect($invoices);
    }

    public static function forSingleInvoice(Invoice $invoice, string $pdfPath): self
    {
        $invoice->loadMissing(['lease.tenant', 'lease.unit', 'property', 'billingPeriod']);

        return new self(
            invoices: [$invoice],
            pdfFiles: [[
                'path' => $pdfPath,
                'as' => ($invoice->number ?: 'invoice').'.pdf',
            ]],
            property: $invoice->property,
            tenant: $invoice->lease?->tenant,
            periodLabel: $invoice->billingPeriod?->label,
        );
    }

    public function envelope(): Envelope
    {
        $period = $this->periodLabel ?: '';
        $propertyName = $this->property?->name ?? config('app.name');

        return new Envelope(
            subject: trim('Rent Invoice '.$period.' — '.$propertyName),
        );
    }

    public function content(): Content
    {
        $lines = $this->invoices->map(function (Invoice $invoice) {
            return [
                'unit' => $invoice->lease?->unit?->label ?: '—',
                'amount' => (float) $invoice->total_amount,
            ];
        })->values()->all();

        $grandTotal = collect($lines)->sum('amount');
        $property = $this->property;
        $address = trim((string) ($property?->address ?: ''));
        if ($address === '') {
            $address = (string) ($property?->name ?: config('app.name'));
        }

        $portalUrl = $this->tenant?->ensurePortalUrl();

        return new Content(
            markdown: 'emails.invoice',
            with: [
                'tenant' => $this->tenant,
                'periodLabel' => $this->periodLabel ?: 'this period',
                'propertyAddress' => $address,
                'lines' => $lines,
                'grandTotal' => $grandTotal,
                'currency' => $property?->currency ?: 'BDT',
                'ownerName' => $property?->owner_display_name ?? config('app.name'),
                'portalUrl' => $portalUrl,
            ],
        );
    }

    public function attachments(): array
    {
        return collect($this->pdfFiles)->map(function (array $file) {
            return Attachment::fromPath(Storage::disk('local')->path($file['path']))
                ->as($file['as'])
                ->withMime('application/pdf');
        })->all();
    }
}
