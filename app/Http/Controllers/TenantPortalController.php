<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\InvoicePacketBuilder;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class TenantPortalController extends Controller
{
    public function show(string $token, InvoicePacketBuilder $packetBuilder)
    {
        $tenant = $this->resolveTenant($token);

        $leases = $tenant->leases()
            ->with(['unit', 'property'])
            ->where('is_active', true)
            ->get();

        $invoices = Invoice::with(['billingPeriod.documents', 'lease.unit', 'lease', 'property'])
            ->whereIn('lease_id', $leases->pluck('id'))
            ->whereIn('status', ['issued', 'partial', 'paid'])
            ->join('billing_periods', 'billing_periods.id', '=', 'invoices.billing_period_id')
            ->orderByDesc('billing_periods.year')
            ->orderByDesc('billing_periods.month')
            ->orderByDesc('invoices.id')
            ->select('invoices.*')
            ->get()
            ->map(function (Invoice $invoice) use ($packetBuilder) {
                $status = $packetBuilder->attachmentStatus($invoice);

                return [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'status' => $invoice->status,
                    'total_amount' => $invoice->total_amount,
                    'paid_amount' => $invoice->paid_amount,
                    'balance' => $invoice->balance,
                    'period' => $invoice->billingPeriod?->label,
                    'unit' => $invoice->lease?->unit?->label,
                    'property' => $invoice->property?->name,
                    'download_label' => $status['label'],
                ];
            });

        $outstanding = round($invoices->sum(fn ($inv) => max(0, (float) $inv['balance'])), 2);

        return Inertia::render('Portal/Show', [
            'tenant' => [
                'name' => $tenant->name,
            ],
            'leases' => $leases->map(fn ($lease) => [
                'id' => $lease->id,
                'unit' => $lease->unit?->label,
                'property' => $lease->property?->name,
                'rent_amount' => $lease->rent_amount,
            ]),
            'invoices' => $invoices,
            'outstanding' => $outstanding,
            'token' => $token,
        ]);
    }

    public function pdf(string $token, Invoice $invoice, InvoicePacketBuilder $packetBuilder): Response
    {
        $tenant = $this->resolveTenant($token);
        $invoice->load('lease');

        abort_unless($invoice->lease && (int) $invoice->lease->tenant_id === (int) $tenant->id, 403);
        abort_unless(in_array($invoice->status, ['issued', 'partial', 'paid'], true), 404);

        return $packetBuilder->downloadResponse($invoice);
    }

    private function resolveTenant(string $token): Tenant
    {
        $tenant = Tenant::where('portal_token', $token)
            ->where('portal_enabled', true)
            ->first();

        abort_unless($tenant, 404);

        return $tenant;
    }
}
