<?php

namespace App\Services;

use App\Models\BillingPeriodDocument;
use App\Models\Invoice;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class InvoicePacketBuilder
{
    public function __construct(private PdfGenerator $pdfGenerator)
    {
    }

    /**
     * @return array{
     *   has_attachments: bool,
     *   missing: list<string>,
     *   contents: string,
     *   download_name: string,
     *   base: string
     * }
     */
    public function build(Invoice $invoice): array
    {
        $invoice->loadMissing(['lease.unit', 'lease.tenant', 'billingPeriod.documents.unit', 'billingPeriod.documents.meter']);

        $resolved = $this->resolveAttachments($invoice);
        $lease = $invoice->lease;

        $unit = Str::slug($lease?->unit?->label ?: 'unit', '_');
        $tenant = Str::slug($lease?->tenant?->name ?: 'tenant', '_');
        $base = trim($unit.'-'.$tenant, '-') ?: 'invoice';

        $contents = $this->pdfGenerator->invoiceWithAttachments($invoice, $resolved['attachments']);

        return [
            'has_attachments' => $resolved['attachments']->isNotEmpty(),
            'missing' => $resolved['missing'],
            'contents' => $contents,
            'download_name' => ($invoice->number ?: $base).'.pdf',
            'base' => $base,
        ];
    }

    public function downloadResponse(Invoice $invoice)
    {
        $packet = $this->build($invoice);

        return response($packet['contents'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$packet['download_name'].'"',
        ]);
    }

    /**
     * @param  Collection<int, BillingPeriodDocument>  $documents
     */
    public function resolveWater(Collection $documents): ?BillingPeriodDocument
    {
        return $documents->first(fn (BillingPeriodDocument $doc) => $doc->kind === 'water' && $doc->unit_id === null);
    }

    /**
     * Unit electricity bills only — building-wide electricity is never used as a fallback.
     * A unit can have several meters, so every uploaded meter bill is returned.
     *
     * @param  Collection<int, BillingPeriodDocument>  $documents
     * @return Collection<int, BillingPeriodDocument>
     */
    public function resolveElectricity(Collection $documents, ?int $unitId): Collection
    {
        if (! $unitId) {
            return collect();
        }

        return $documents
            ->filter(fn (BillingPeriodDocument $doc) => $doc->kind === 'electricity' && (int) $doc->unit_id === (int) $unitId)
            ->values();
    }

    public function attachmentStatus(Invoice $invoice): array
    {
        $invoice->loadMissing(['lease', 'billingPeriod.documents']);
        $resolved = $this->resolveAttachments($invoice);
        $hasAttachments = $resolved['attachments']->isNotEmpty();
        $wantsPackage = (bool) ($invoice->lease?->attach_water_bill || $invoice->lease?->attach_electricity_bill);

        return [
            'wants_package' => $wantsPackage,
            'has_attachments' => $hasAttachments,
            'missing' => $resolved['missing'],
            'label' => 'Download PDF',
        ];
    }

    /**
     * @return array{attachments: Collection<int, BillingPeriodDocument>, missing: list<string>}
     */
    private function resolveAttachments(Invoice $invoice): array
    {
        $lease = $invoice->lease;
        $documents = $invoice->billingPeriod?->documents ?? collect();
        $attachments = collect();
        $missing = [];

        if ($lease?->attach_water_bill) {
            $water = $this->resolveWater($documents);
            if ($water && $water->absolutePath()) {
                $attachments->push($water);
            } else {
                $missing[] = 'water';
            }
        }

        if ($lease?->attach_electricity_bill) {
            $electricity = $this->resolveElectricity($documents, $lease->unit_id)
                ->filter(fn (BillingPeriodDocument $doc) => (bool) $doc->absolutePath());

            if ($electricity->isNotEmpty()) {
                $attachments = $attachments->merge($electricity);
            } else {
                $missing[] = 'unit electricity';
            }
        }

        return [
            'attachments' => $attachments,
            'missing' => $missing,
        ];
    }
}
