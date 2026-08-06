<?php

namespace App\Http\Controllers;

use App\Models\BillingPeriod;
use App\Models\BillingPeriodDocument;
use App\Models\ChargeType;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Meter;
use App\Models\PeriodChargeInput;
use App\Models\PeriodMeterInput;
use App\Models\Unit;
use App\Services\BillingCalculator;
use App\Services\InvoicePacketBuilder;
use Illuminate\Http\Request;
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

        return Inertia::render('Billing/Show', [
            'period' => $billing,
            'meters' => Meter::where('property_id', $property->id)->where('is_active', true)->orderBy('kind')->orderBy('sort_order')->get(),
            'chargeTypes' => ChargeType::where('property_id', $property->id)->where('is_active', true)->orderBy('sort_order')->get(),
            'leases' => Lease::with(['tenant', 'unit'])->where('property_id', $property->id)->where('is_active', true)->get(),
            'units' => Unit::where('property_id', $property->id)->where('is_active', true)->orderBy('sort_order')->get(),
            'electricityUnits' => $electricityUnits,
            'leaseBalances' => $leaseBalances,
            'hasPriorPeriod' => (bool) $prior,
            'documents' => $billing->documents,
            'attachmentNeeds' => $attachmentNeeds,
            'invoiceAttachmentStatus' => $invoiceStatuses,
        ]);
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
            $folder = $packet['base'];

            if ($packet['is_zip']) {
                foreach ($packet['files'] as $file) {
                    $zip->addFromString($folder.'/'.$file['name'], $file['contents']);
                }
            } else {
                $zip->addFromString($folder.'.pdf', $packet['files'][0]['contents']);
            }
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
