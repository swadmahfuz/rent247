<?php

namespace App\Http\Controllers;

use App\Mail\InvoiceMail;
use App\Models\BillingPeriod;
use App\Models\BillingPeriodDocument;
use App\Models\ChargeType;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\MailLog;
use App\Models\Meter;
use App\Models\PeriodChargeInput;
use App\Models\PeriodMeterInput;
use App\Models\Unit;
use App\Services\BillingCalculator;
use App\Services\InvoicePacketBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class BillingPeriodController extends Controller
{
    public function index(Request $request)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property, 404);

        return Inertia::render('Billing/Index', [
            'items' => BillingPeriod::withCount('invoices')
                ->where('property_id', $property->id)
                ->orderByDesc('year')
                ->orderByDesc('month')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property, 404);

        $data = $request->validate([
            'year' => 'required|integer|min:2000|max:2100',
            'month' => 'required|integer|min:1|max:12',
            'bill_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $period = BillingPeriod::create([
            'year' => $data['year'],
            'month' => $data['month'],
            'bill_date' => $data['bill_date'] ?? now()->toDateString(),
            'notes' => $data['notes'] ?? null,
            'property_id' => $property->id,
            'status' => 'draft',
        ]);

        if ($property->setting('auto_carry_arrears', true)) {
            app(BillingCalculator::class)->seedArrearsInputs($period);
        }

        return redirect()->route('billing.show', $period)->with('success', 'Billing period created.');
    }

    public function show(Request $request, BillingPeriod $billing)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property && $billing->property_id === $property->id, 403);

        $billing->load([
            'meterInputs',
            'chargeInputs',
            'documents.unit',
            'documents.meter',
            'invoices.lease.tenant',
            'invoices.lease.unit',
            'invoices.lines',
        ]);

        $leaseBalances = Invoice::query()
            ->selectRaw('lease_id, SUM(total_amount - paid_amount) as balance')
            ->where('property_id', $property->id)
            ->whereIn('status', ['issued', 'partial'])
            ->whereHas('billingPeriod', function ($query) use ($billing) {
                $query->where(function ($q) use ($billing) {
                    $q->where('year', '<', $billing->year)
                        ->orWhere(function ($q2) use ($billing) {
                            $q2->where('year', $billing->year)
                                ->where('month', '<', $billing->month);
                        });
                });
            })
            ->groupBy('lease_id')
            ->pluck('balance', 'lease_id');

        $prior = $this->priorPeriod($billing);
        $packet = app(InvoicePacketBuilder::class);
        $docs = $billing->documents;

        $attachmentNeeds = Lease::with(['unit', 'tenant'])
            ->where('property_id', $property->id)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('attach_water_bill', true)->orWhere('attach_electricity_bill', true);
            })
            ->get()
            ->map(function (Lease $lease) use ($packet, $docs) {
                $missing = [];
                if ($lease->attach_water_bill && ! $packet->resolveWater($docs)) {
                    $missing[] = 'water';
                }
                if ($lease->attach_electricity_bill && $packet->resolveElectricity($docs, $lease->unit_id)->isEmpty()) {
                    $missing[] = 'unit electricity';
                }

                return [
                    'lease_id' => $lease->id,
                    'unit' => $lease->unit?->label,
                    'tenant' => $lease->tenant?->name,
                    'wants_water' => (bool) $lease->attach_water_bill,
                    'wants_electricity' => (bool) $lease->attach_electricity_bill,
                    'missing' => $missing,
                ];
            });

        $invoiceStatuses = $billing->invoices->mapWithKeys(function (Invoice $invoice) use ($packet) {
            return [$invoice->id => $packet->attachmentStatus($invoice)];
        });

        // One upload slot per unit electricity meter, so a floor with several meters
        // can attach a bill for each of them.
        $electricityUnits = Unit::where('property_id', $property->id)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereHas('meters', fn ($m) => $m->where('kind', 'unit')->where('is_active', true))
                    ->orWhereHas('leases', fn ($l) => $l->where('is_active', true)->where('attach_electricity_bill', true));
            })
            ->with(['meters' => fn ($m) => $m->where('kind', 'unit')->where('is_active', true)->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Unit $unit) => [
                'id' => $unit->id,
                'label' => $unit->label,
                'meters' => $unit->meters->map(fn (Meter $meter) => [
                    'id' => $meter->id,
                    'number' => $meter->code ?: $meter->name,
                    'name' => $meter->name,
                ])->values()->all(),
            ]);

        $meters = Meter::where('property_id', $property->id)->where('is_active', true)->orderBy('kind')->orderBy('sort_order')->get();
        $chargeTypes = ChargeType::where('property_id', $property->id)->where('is_active', true)->orderBy('sort_order')->get();
        $leases = Lease::with(['tenant', 'unit'])->where('property_id', $property->id)->where('is_active', true)->get();

        return Inertia::render('Billing/Show', [
            'period' => $billing,
            'meters' => $meters,
            'chargeTypes' => $chargeTypes,
            'leases' => $leases,
            'units' => Unit::where('property_id', $property->id)->where('is_active', true)->orderBy('sort_order')->get(),
            'electricityUnits' => $electricityUnits,
            'leaseBalances' => $leaseBalances,
            'hasPriorPeriod' => (bool) $prior,
            'documents' => $billing->documents,
            'attachmentNeeds' => $attachmentNeeds,
            'invoiceAttachmentStatus' => $invoiceStatuses,
            'checklist' => $this->buildChecklist($billing, $meters, $chargeTypes, $leases, $attachmentNeeds),
        ]);
    }

    /**
     * Compact monthly close status for the billing period page.
     *
     * @param  \Illuminate\Support\Collection<int, Meter>  $meters
     * @param  \Illuminate\Support\Collection<int, ChargeType>  $chargeTypes
     * @param  \Illuminate\Support\Collection<int, Lease>  $leases
     * @param  \Illuminate\Support\Collection<int, array>  $attachmentNeeds
     * @return array{
     *   items: list<array<string, mixed>>,
     *   blockers_generate: list<string>,
     *   blockers_finalize: list<string>,
     *   unpaid_count: int,
     *   invoices_filter: array{status: string, billing_period_id: int}
     * }
     */
    private function buildChecklist(
        BillingPeriod $billing,
        $meters,
        $chargeTypes,
        $leases,
        $attachmentNeeds,
    ): array {
        $meterInputs = $billing->meterInputs->keyBy('meter_id');
        $metersFilled = $meters->filter(function (Meter $meter) use ($meterInputs) {
            $input = $meterInputs->get($meter->id);

            return $input && $input->amount !== null && $input->amount !== '';
        })->count();
        $metersTotal = $meters->count();
        $metersOk = $metersTotal === 0 || $metersFilled === $metersTotal;

        $requiredChargeTypes = $chargeTypes->filter(function (ChargeType $type) {
            if (! in_array($type->category, ['utility', 'fixed'], true)) {
                return false;
            }

            return ! in_array($type->code, ['electricity', 'electricity_common', 'office_rent', 'rent'], true);
        })->values();

        $buildingChargeInputs = $billing->chargeInputs->whereNull('lease_id')->keyBy('charge_type_id');
        $chargesFilled = $requiredChargeTypes->filter(function (ChargeType $type) use ($buildingChargeInputs) {
            $input = $buildingChargeInputs->get($type->id);

            return $input && $input->amount !== null && $input->amount !== '';
        })->count();
        $chargesTotal = $requiredChargeTypes->count();
        $chargesOk = $chargesTotal === 0 || $chargesFilled === $chargesTotal;

        $leasesMissingBills = collect($attachmentNeeds)->filter(fn (array $row) => count($row['missing'] ?? []) > 0)->values();
        $billsOk = $leasesMissingBills->isEmpty();

        // Match BillingCalculator: skip owner-occupied; skip garages with no rent (no invoice lines).
        $billableLeases = $leases->filter(function (Lease $lease) {
            if (! $lease->unit || $lease->unit->type === 'owner_occupied') {
                return false;
            }

            if ($lease->unit->type === 'garage' && (float) $lease->rent_amount <= 0.009) {
                return false;
            }

            return true;
        });

        $invoiceLeaseIds = $billing->invoices->pluck('lease_id')->map(fn ($id) => (int) $id)->unique();
        $invoicesDone = $billableLeases->filter(fn (Lease $lease) => $invoiceLeaseIds->contains((int) $lease->id))->count();
        $invoicesTotal = $billableLeases->count();
        $invoicesOk = $invoicesTotal === 0 || $invoicesDone === $invoicesTotal;

        $unpaidInvoices = $billing->invoices->filter(function (Invoice $invoice) {
            if (! in_array($invoice->status, ['issued', 'partial'], true)) {
                return false;
            }

            return (float) $invoice->balance > 0.009;
        });
        $unpaidCount = $unpaidInvoices->count();

        $items = [
            [
                'key' => 'meters',
                'label' => 'Meter readings',
                'ok' => $metersOk,
                'detail' => $metersTotal === 0
                    ? 'No active meters'
                    : "{$metersFilled} / {$metersTotal} filled",
            ],
            [
                'key' => 'charges',
                'label' => 'Charge inputs',
                'ok' => $chargesOk,
                'detail' => $chargesTotal === 0
                    ? 'No building charges required'
                    : "{$chargesFilled} / {$chargesTotal} building charges filled",
            ],
            [
                'key' => 'bills',
                'label' => 'Utility bills',
                'ok' => $billsOk,
                'detail' => $billsOk
                    ? 'All required bill copies uploaded'
                    : $leasesMissingBills->count().' lease(s) missing bill copies',
            ],
            [
                'key' => 'invoices',
                'label' => 'Invoices generated',
                'ok' => $invoicesOk,
                'detail' => $invoicesTotal === 0
                    ? 'No active leases'
                    : "{$invoicesDone} / {$invoicesTotal} leases",
            ],
            [
                'key' => 'unpaid',
                'label' => 'Outstanding this period',
                'ok' => $unpaidCount === 0,
                'detail' => $unpaidCount === 0
                    ? 'None unpaid'
                    : "{$unpaidCount} unpaid invoice".($unpaidCount === 1 ? '' : 's'),
                'count' => $unpaidCount,
            ],
        ];

        $blockersGenerate = [];
        $blockersFinalize = [];
        foreach ($items as $item) {
            if ($item['ok']) {
                continue;
            }
            if (in_array($item['key'], ['meters', 'charges'], true)) {
                $blockersGenerate[] = $item['label'].' incomplete';
                $blockersFinalize[] = $item['label'].' incomplete';
            }
            if (in_array($item['key'], ['bills', 'invoices'], true)) {
                $blockersFinalize[] = $item['label'].' incomplete';
            }
        }

        return [
            'items' => $items,
            'blockers_generate' => $blockersGenerate,
            'blockers_finalize' => $blockersFinalize,
            'unpaid_count' => $unpaidCount,
            'invoices_filter' => [
                'status' => 'outstanding',
                'billing_period_id' => $billing->id,
            ],
        ];
    }

    public function updateInputs(Request $request, BillingPeriod $billing)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property && $billing->property_id === $property->id, 403);
        abort_if($billing->status === 'finalized', 422, 'Period is finalized.');

        $data = $request->validate([
            'bill_date' => 'nullable|date',
            'meter_inputs' => 'nullable|array',
            'meter_inputs.*.meter_id' => 'required|exists:meters,id',
            'meter_inputs.*.amount' => 'nullable|numeric',
            'meter_inputs.*.service_period' => 'nullable|date',
            'charge_inputs' => 'nullable|array',
            'charge_inputs.*.charge_type_id' => 'required|exists:charge_types,id',
            'charge_inputs.*.lease_id' => 'nullable|exists:leases,id',
            'charge_inputs.*.amount' => 'nullable|numeric',
            'charge_inputs.*.units' => 'nullable|numeric',
        ]);

        if (array_key_exists('bill_date', $data) && $data['bill_date']) {
            $billing->update(['bill_date' => $data['bill_date']]);
        } elseif (array_key_exists('bill_date', $data) && empty($data['bill_date'])) {
            $billing->update(['bill_date' => now()->toDateString()]);
        }

        foreach ($data['meter_inputs'] ?? [] as $row) {
            PeriodMeterInput::updateOrCreate(
                [
                    'billing_period_id' => $billing->id,
                    'meter_id' => $row['meter_id'],
                ],
                [
                    'amount' => $row['amount'] ?? 0,
                    'service_period' => $row['service_period'] ?? null,
                ]
            );
        }

        // Replace charge inputs wholesale for simplicity
        if (array_key_exists('charge_inputs', $data)) {
            $billing->chargeInputs()->delete();
            foreach ($data['charge_inputs'] as $row) {
                PeriodChargeInput::create([
                    'billing_period_id' => $billing->id,
                    'charge_type_id' => $row['charge_type_id'],
                    'lease_id' => $row['lease_id'] ?? null,
                    'amount' => $row['amount'] ?? null,
                    'units' => $row['units'] ?? null,
                ]);
            }
        }

        return back()->with('success', 'Inputs saved.');
    }

    public function generate(Request $request, BillingPeriod $billing, BillingCalculator $calculator)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property && $billing->property_id === $property->id, 403);

        $calculator->generate($billing);

        return back()->with('success', 'Invoices generated.');
    }

    public function finalize(Request $request, BillingPeriod $billing)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property && $billing->property_id === $property->id, 403);

        $billing->update(['status' => 'finalized']);
        $billing->invoices()->where('status', 'draft')->update([
            'status' => 'issued',
            'issued_at' => now(),
        ]);

        return back()->with('success', 'Period finalized and invoices issued.');
    }

    public function copyPrior(Request $request, BillingPeriod $billing)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property && $billing->property_id === $property->id, 403);
        abort_if($billing->status === 'finalized', 422, 'Period is finalized.');

        $prior = $this->priorPeriod($billing);
        abort_unless($prior, 404, 'No prior billing period found.');

        $prior->load(['meterInputs', 'chargeInputs.chargeType']);

        foreach ($prior->meterInputs as $input) {
            PeriodMeterInput::updateOrCreate(
                [
                    'billing_period_id' => $billing->id,
                    'meter_id' => $input->meter_id,
                ],
                [
                    'amount' => $input->amount,
                    'service_period' => $input->service_period,
                ]
            );
        }

        $arrearsIds = ChargeType::where('property_id', $property->id)
            ->where(function ($q) {
                $q->where('code', 'arrears')->orWhere('category', 'arrears');
            })
            ->pluck('id');

        // Replace non-arrears charge inputs; keep existing arrears rows.
        $billing->chargeInputs()
            ->whereNotIn('charge_type_id', $arrearsIds)
            ->delete();

        foreach ($prior->chargeInputs as $input) {
            if ($arrearsIds->contains($input->charge_type_id)) {
                continue;
            }

            PeriodChargeInput::create([
                'billing_period_id' => $billing->id,
                'charge_type_id' => $input->charge_type_id,
                'lease_id' => $input->lease_id,
                'amount' => $input->amount,
                'units' => $input->units,
            ]);
        }

        return back()->with('success', 'Copied meter and charge inputs from '.$prior->label.'.');
    }

    public function invoicesZip(Request $request, BillingPeriod $billing, InvoicePacketBuilder $packetBuilder): StreamedResponse
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property && $billing->property_id === $property->id, 403);

        $billing->load(['invoices.lease.tenant', 'invoices.lease.unit', 'documents']);
        abort_if($billing->invoices->isEmpty(), 422, 'No invoices to download.');

        $tmp = tempnam(sys_get_temp_dir(), 'invzip');
        $zip = new ZipArchive();
        abort_unless($zip->open($tmp, ZipArchive::OVERWRITE) === true, 500, 'Unable to create ZIP.');

        foreach ($billing->invoices as $invoice) {
            $packet = $packetBuilder->build($invoice);
            $zip->addFromString($packet['base'].'.pdf', $packet['contents']);
        }

        $zip->close();

        $downloadName = 'invoices-'.$billing->period_key.'.zip';

        return response()->streamDownload(function () use ($tmp) {
            echo file_get_contents($tmp);
            @unlink($tmp);
        }, $downloadName, [
            'Content-Type' => 'application/zip',
        ]);
    }

    public function emailInvoices(Request $request, BillingPeriod $billing, InvoicePacketBuilder $packetBuilder)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property && $billing->property_id === $property->id, 403);
        abort_unless($billing->status === 'finalized', 422, 'Finalize the period before emailing invoices.');

        $billing->load(['invoices.lease.tenant', 'invoices.lease.unit', 'property']);
        abort_if($billing->invoices->isEmpty(), 422, 'No invoices to email.');

        $groups = $billing->invoices
            ->filter(fn (Invoice $invoice) => $invoice->lease?->tenant)
            ->groupBy(fn (Invoice $invoice) => $invoice->lease->tenant_id);

        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($groups as $tenantInvoices) {
            /** @var \Illuminate\Support\Collection<int, Invoice> $tenantInvoices */
            $tenant = $tenantInvoices->first()->lease->tenant;
            $emails = $tenant->emailAddresses();

            if ($emails === []) {
                $skipped++;
                continue;
            }

            try {
                $pdfFiles = [];
                foreach ($tenantInvoices->sortBy(fn (Invoice $invoice) => $invoice->lease?->unit?->label) as $invoice) {
                    $packet = $packetBuilder->build($invoice);
                    $path = sprintf('invoices/%d/%s.pdf', $invoice->property_id, $invoice->number ?: $invoice->id);
                    Storage::disk('local')->put($path, $packet['contents']);
                    $invoice->update(['pdf_path' => $path]);

                    $unit = $invoice->lease?->unit?->label ?: 'unit';
                    $pdfFiles[] = [
                        'path' => $path,
                        'as' => ($invoice->number ?: $unit).'.pdf',
                    ];
                }

                Mail::to($emails)->send(new InvoiceMail(
                    invoices: $tenantInvoices->values(),
                    pdfFiles: $pdfFiles,
                    property: $billing->property ?? $property,
                    tenant: $tenant,
                    periodLabel: $billing->label,
                ));

                $toList = implode(', ', $emails);
                foreach ($tenantInvoices as $invoice) {
                    MailLog::create([
                        'invoice_id' => $invoice->id,
                        'to_email' => $toList,
                        'status' => 'sent',
                    ]);
                }

                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                $toList = implode(', ', $emails);
                foreach ($tenantInvoices as $invoice) {
                    MailLog::create([
                        'invoice_id' => $invoice->id,
                        'to_email' => $toList,
                        'status' => 'failed',
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $parts = [];
        if ($sent > 0) {
            $parts[] = "emailed {$sent} tenant".($sent === 1 ? '' : 's');
        }
        if ($skipped > 0) {
            $parts[] = "skipped {$skipped} without email";
        }
        if ($failed > 0) {
            $parts[] = "failed {$failed}";
        }

        $message = $parts === []
            ? 'No tenant emails were sent.'
            : 'Period invoices: '.implode('; ', $parts).'.';

        if ($failed > 0) {
            return back()->with('error', $message);
        }

        return back()->with('success', $message);
    }

    public function storeDocument(Request $request, BillingPeriod $billing)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property && $billing->property_id === $property->id, 403);

        $data = $request->validate([
            'kind' => 'required|in:water,electricity',
            'unit_id' => 'nullable|exists:units,id',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|mimetypes:application/pdf,image/jpeg,image/png|max:10240',
        ]);

        $unitId = $data['unit_id'] ?? null;
        if ($data['kind'] === 'water') {
            $unitId = null;
        }
        if ($unitId) {
            abort_unless(Unit::where('id', $unitId)->where('property_id', $property->id)->exists(), 403);
            abort_unless($data['kind'] === 'electricity', 422, 'Only electricity bills can be per-unit.');
        }

        $this->replaceDocument($billing, $data['kind'], $unitId, null, $request->file('file'));

        return back()->with('success', 'Utility bill copy uploaded.');
    }

    public function storeUnitElectricityDocuments(Request $request, BillingPeriod $billing)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property && $billing->property_id === $property->id, 403);

        $data = $request->validate([
            'files' => 'required|array|min:1',
            'files.*' => 'required|file|mimes:pdf,jpg,jpeg,png|mimetypes:application/pdf,image/jpeg,image/png|max:10240',
        ]);

        $meterIds = array_values(array_unique(array_map('intval', array_keys($data['files']))));
        $meters = Meter::where('property_id', $property->id)
            ->where('kind', 'unit')
            ->whereIn('id', $meterIds)
            ->get()
            ->keyBy('id');
        abort_unless($meters->count() === count($meterIds), 403);

        foreach ($data['files'] as $meterId => $file) {
            $meter = $meters[(int) $meterId];
            $this->replaceDocument($billing, 'electricity', $meter->unit_id, $meter->id, $file);
        }

        return back()->with('success', count($data['files']).' unit electricity bill(s) uploaded.');
    }

    private function replaceDocument(BillingPeriod $billing, string $kind, ?int $unitId, ?int $meterId, $file): void
    {
        $existing = BillingPeriodDocument::query()
            ->where('billing_period_id', $billing->id)
            ->where('kind', $kind)
            ->when(
                $unitId,
                fn ($q) => $q->where('unit_id', $unitId),
                fn ($q) => $q->whereNull('unit_id')
            )
            ->when(
                $meterId,
                fn ($q) => $q->where('meter_id', $meterId),
                fn ($q) => $q->whereNull('meter_id')
            )
            ->get();

        foreach ($existing as $doc) {
            $doc->deleteFile();
            $doc->delete();
        }

        $path = $file->store('period-docs/'.$billing->id, 'public');

        BillingPeriodDocument::create([
            'billing_period_id' => $billing->id,
            'kind' => $kind,
            'unit_id' => $unitId,
            'meter_id' => $meterId,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize() ?: 0,
        ]);
    }

    public function destroyDocument(Request $request, BillingPeriod $billing, BillingPeriodDocument $document)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property && $billing->property_id === $property->id, 403);
        abort_unless($document->billing_period_id === $billing->id, 404);

        $document->deleteFile();
        $document->delete();

        return back()->with('success', 'Utility bill copy removed.');
    }

    private function priorPeriod(BillingPeriod $billing): ?BillingPeriod
    {
        $year = (int) $billing->year;
        $month = (int) $billing->month - 1;
        if ($month < 1) {
            $month = 12;
            $year--;
        }

        return BillingPeriod::where('property_id', $billing->property_id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();
    }
}
