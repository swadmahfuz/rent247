<?php

namespace App\Services;

use App\Models\BillingPeriodDocument;
use App\Models\Invoice;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ZipArchive;

class InvoicePacketBuilder
{
    public function __construct(private PdfGenerator $pdfGenerator)
    {
    }

    /**
     * @return array{
     *   has_attachments: bool,
     *   missing: list<string>,
     *   files: list<array{name: string, contents: string, mime: string}>,
     *   download_name: string,
     *   is_zip: bool,
     *   base: string
     * }
     */
    public function build(Invoice $invoice): array
    {
        $invoice->loadMissing(['lease.unit', 'lease.tenant', 'billingPeriod.documents.unit', 'billingPeriod.documents.meter']);

        $resolved = $this->resolveAttachments($invoice);
        $lease = $invoice->lease;

        $invoicePdf = $this->pdfGenerator->invoice($invoice)->output();
        $unit = Str::slug($lease?->unit?->label ?: 'unit', '_');
        $tenant = Str::slug($lease?->tenant?->name ?: 'tenant', '_');
        $base = trim($unit.'-'.$tenant, '-');

        $files = [
            [
                'name' => 'invoice.pdf',
                'contents' => $invoicePdf,
                'mime' => 'application/pdf',
            ],
        ];

        $usedNames = [];
        foreach ($resolved['attachments'] as $doc) {
            $absolute = $doc->absolutePath();
            if (! $absolute) {
                continue;
            }
            $ext = strtolower(pathinfo($doc->original_name, PATHINFO_EXTENSION) ?: pathinfo($absolute, PATHINFO_EXTENSION) ?: 'bin');
            $prefix = $doc->kind === 'water' ? 'water-bill' : 'electricity-bill';
            if ($doc->kind === 'electricity' && $meterNumber = $doc->meterNumber()) {
                $prefix .= '-'.Str::slug($meterNumber, '_');
            }

            $name = $prefix.'.'.$ext;
            $suffix = 2;
            while (isset($usedNames[$name])) {
                $name = $prefix.'-'.$suffix++.'.'.$ext;
            }
            $usedNames[$name] = true;

            $files[] = [
                'name' => $name,
                'contents' => file_get_contents($absolute),
                'mime' => $doc->mime ?: 'application/octet-stream',
            ];
        }

        $hasAttachments = count($files) > 1;

        return [
            'has_attachments' => $hasAttachments,
            'missing' => $resolved['missing'],
            'files' => $files,
            'download_name' => $hasAttachments
                ? ($base ?: 'invoice').'-package.zip'
                : (($invoice->number ?: $base ?: 'invoice').'.pdf'),
            'is_zip' => $hasAttachments,
            'base' => $base ?: 'invoice',
        ];
    }

    public function downloadResponse(Invoice $invoice)
    {
        $packet = $this->build($invoice);

        if (! $packet['is_zip']) {
            return response($packet['files'][0]['contents'], 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$packet['download_name'].'"',
            ]);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'invpkt');
        $zip = new ZipArchive();
        abort_unless($zip->open($tmp, ZipArchive::OVERWRITE) === true, 500, 'Unable to create package.');

        foreach ($packet['files'] as $file) {
            $zip->addFromString($file['name'], $file['contents']);
        }
        $zip->close();

        return response()->streamDownload(function () use ($tmp) {
            echo file_get_contents($tmp);
            @unlink($tmp);
        }, $packet['download_name'], [
            'Content-Type' => 'application/zip',
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
            'label' => $hasAttachments ? 'Download package' : 'Download PDF',
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
