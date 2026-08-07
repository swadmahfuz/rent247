<?php

namespace App\Services;

use App\Models\BillingPeriodDocument;
use App\Models\BillingPeriod;
use App\Models\Invoice;
use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

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

    private function attachmentImagePdf(BillingPeriodDocument $doc): \Barryvdh\DomPDF\PDF
    {
        $pages = $this->imageAttachmentPages(collect([$doc]));

        return Pdf::loadView('pdf.invoice-attachment', [
            'page' => $pages[0] ?? ['title' => $this->attachmentTitle($doc), 'data_uri' => ''],
        ])->setPaper('a4', 'portrait');
    }

    /**
     * Invoice PDF with utility bills as extra pages (images embedded; PDF bills appended).
     * Attachments keep resolve order (water, then each meter bill).
     */
    public function invoiceWithAttachments(Invoice $invoice, Collection $attachments): string
    {
        $binary = $this->invoice($invoice)->output();

        if ($attachments->isEmpty()) {
            return $binary;
        }

        $pdf = new Fpdi();
        $this->importAllPages($pdf, StreamReader::createByString($binary));

        foreach ($attachments as $doc) {
            if ($this->isImageDocument($doc)) {
                $pageBinary = $this->attachmentImagePdf($doc)->output();
                $this->importAllPages($pdf, StreamReader::createByString($pageBinary));
                continue;
            }

            if ($this->isPdfDocument($doc)) {
                $path = $doc->absolutePath();
                if (! $path) {
                    continue;
                }
                try {
                    $this->importAllPages($pdf, $path);
                } catch (\Throwable) {
                    // Skip unreadable utility PDFs rather than failing the whole invoice download.
                    continue;
                }
            }
        }

        return $pdf->Output('S');
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

    public function storeInvoice(Invoice $invoice, ?Collection $attachments = null): string
    {
        $contents = $attachments === null
            ? $this->invoice($invoice)->output()
            : $this->invoiceWithAttachments($invoice, $attachments);

        $path = sprintf('invoices/%d/%s.pdf', $invoice->property_id, $invoice->number ?: $invoice->id);
        Storage::disk('local')->put($path, $contents);
        $invoice->update(['pdf_path' => $path]);

        return $path;
    }

    public function pageCount(string $pdfBinary): int
    {
        $pdf = new Fpdi();
        $pageCount = $pdf->setSourceFile(StreamReader::createByString($pdfBinary));

        return (int) $pageCount;
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

    /**
     * @param  Collection<int, BillingPeriodDocument>  $attachments
     * @return list<array{title: string, data_uri: string}>
     */
    private function imageAttachmentPages(Collection $attachments): array
    {
        $pages = [];

        foreach ($attachments as $doc) {
            if (! $this->isImageDocument($doc)) {
                continue;
            }

            $absolute = $doc->absolutePath();
            if (! $absolute) {
                continue;
            }

            $contents = file_get_contents($absolute);
            if ($contents === false) {
                continue;
            }

            $mime = $doc->mime ?: (mime_content_type($absolute) ?: 'image/jpeg');
            if (! str_starts_with($mime, 'image/')) {
                $ext = strtolower(pathinfo($doc->original_name, PATHINFO_EXTENSION));
                $mime = match ($ext) {
                    'png' => 'image/png',
                    'jpg', 'jpeg' => 'image/jpeg',
                    default => 'image/jpeg',
                };
            }

            $pages[] = [
                'title' => $this->attachmentTitle($doc),
                'data_uri' => 'data:'.$mime.';base64,'.base64_encode($contents),
            ];
        }

        return $pages;
    }

    private function attachmentTitle(BillingPeriodDocument $doc): string
    {
        if ($doc->kind === 'water') {
            return 'Water Bill';
        }

        $title = 'Electricity Bill';
        if ($meter = $doc->meterNumber()) {
            $title .= ' · Meter '.$meter;
        }

        return $title;
    }

    private function isImageDocument(BillingPeriodDocument $doc): bool
    {
        $mime = strtolower((string) $doc->mime);
        if (str_starts_with($mime, 'image/')) {
            return true;
        }

        $ext = strtolower(pathinfo($doc->original_name ?: '', PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png'], true);
    }

    private function isPdfDocument(BillingPeriodDocument $doc): bool
    {
        $mime = strtolower((string) $doc->mime);
        if ($mime === 'application/pdf') {
            return true;
        }

        return strtolower(pathinfo($doc->original_name ?: '', PATHINFO_EXTENSION)) === 'pdf';
    }

    private function importAllPages(Fpdi $pdf, $source): void
    {
        $pageCount = $pdf->setSourceFile($source);
        for ($page = 1; $page <= $pageCount; $page++) {
            $template = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($template);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);
        }
    }
}
