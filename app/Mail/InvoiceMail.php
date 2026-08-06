<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice, public string $pdfPath)
    {
    }

    public function envelope(): Envelope
    {
        $period = $this->invoice->billingPeriod?->label ?? '';

        return new Envelope(
            subject: 'Rent Invoice '.$period.' — '.$this->invoice->property?->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.invoice',
            with: [
                'invoice' => $this->invoice,
                'tenant' => $this->invoice->lease?->tenant,
                'property' => $this->invoice->property,
            ],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromPath(Storage::disk('local')->path($this->pdfPath))
                ->as(($this->invoice->number ?: 'invoice').'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
