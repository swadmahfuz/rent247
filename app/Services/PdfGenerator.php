<?php

namespace App\Services;

use App\Models\BillingPeriod;
use App\Models\Invoice;
use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class PdfGenerator
{
    public function __construct(private NumberToWords $numberToWords)
    {
    }

    public function invoice(Invoice $invoice): \Barryvdh\DomPDF\PDF
    {
        $invoice->load(['lines.chargeType', 'lease.tenant', 'lease.unit', 'property', 'billingPeriod']);

        $currency = $invoice->property->currency ?? 'BDT';
        $splitLayout = $invoice->lease->invoice_mode === 'split';

        $sections = [
            $this->section(
                $invoice->property->setting('invoice_title', 'House Rent and Utility Bill'),
                $invoice->lines,
                $currency
            ),
        ];

        if ($splitLayout) {
            $rentLines = $invoice->lines
                ->filter(fn ($line) => $line->chargeType?->category === 'rent')
                ->values();
            $chargeLines = $invoice->lines
                ->reject(fn ($line) => $line->chargeType?->category === 'rent')
                ->values();

            // Each section becomes its own page, so drop empty ones to avoid a blank page.
            $split = array_values(array_filter(
                [
                    $this->section('House Rent Bill', $rentLines, $currency),
                    $this->section('Utility & Other Charges Bill', $chargeLines, $currency),
                ],
                fn (array $section) => $section['lines']->isNotEmpty()
            ));

            if ($split !== []) {
                $sections = $split;
            }
        }

        return Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'property' => $invoice->property,
            'lease' => $invoice->lease,
            'sections' => $sections,
            'billDate' => $invoice->billingPeriod?->bill_date,
            'signatureDataUri' => $this->signatureDataUri($invoice->property),
        ])->setPaper('a4', 'portrait');
    }

    public function summary(BillingPeriod $period): \Barryvdh\DomPDF\PDF
    {
        $period->load([
            'property',
            'invoices.lease.tenant',
            'invoices.lease.unit',
            'invoices.lines',
            'meterInputs.meter',
            'chargeInputs.chargeType',
        ]);

        return Pdf::loadView('pdf.summary', [
            'period' => $period,
            'property' => $period->property,
        ])->setPaper('a4', 'portrait');
    }

    public function receipt(Payment $payment): \Barryvdh\DomPDF\PDF
    {
        $payment->load(['invoice.billingPeriod', 'invoice.lease.tenant', 'invoice.lease.unit', 'invoice.property']);

        $invoice = $payment->invoice;
        $currency = $invoice->property->currency ?? 'BDT';

        return Pdf::loadView('pdf.receipt', [
            'payment' => $payment,
            'invoice' => $invoice,
            'lease' => $invoice->lease,
            'property' => $invoice->property,
            'currency' => $currency,
            'amountInWords' => $this->numberToWords->convert((float) $payment->amount, $currency),
        ])->setPaper('a4', 'portrait');
    }

    public function storeInvoice(Invoice $invoice): string
    {
        $pdf = $this->invoice($invoice);
        $path = sprintf('invoices/%d/%s.pdf', $invoice->property_id, $invoice->number ?: $invoice->id);
        Storage::disk('local')->put($path, $pdf->output());
        $invoice->update(['pdf_path' => $path]);

        return $path;
    }

    private function section(string $title, $lines, string $currency): array
    {
        $total = round((float) $lines->sum('amount'), 2);

        return [
            'title' => $title,
            'lines' => $lines,
            'total' => $total,
            'amount_in_words' => $this->numberToWords->convert($total, $currency),
        ];
    }

    private function signatureDataUri($property): ?string
    {
        $path = $property->setting('signature_path');
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $absolute = Storage::disk('public')->path($path);
        $mime = mime_content_type($absolute) ?: 'image/png';
        $contents = file_get_contents($absolute);

        if ($contents === false) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }
}
