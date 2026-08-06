<?php

namespace App\Http\Controllers;

use App\Models\BillingPeriod;
use App\Models\Invoice;
use App\Models\PeriodMeterInput;
use App\Services\PdfGenerator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AnalyticsController extends Controller
{
    public function __invoke(Request $request, PdfGenerator $pdfGenerator)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property, 404);

        $months = Invoice::query()
            ->select(
                'billing_periods.year',
                'billing_periods.month',
                DB::raw('SUM(invoices.total_amount) as billed'),
                DB::raw('SUM(invoices.paid_amount) as collected'),
                DB::raw('COUNT(invoices.id) as invoice_count')
            )
            ->join('billing_periods', 'billing_periods.id', '=', 'invoices.billing_period_id')
            ->where('invoices.property_id', $property->id)
            ->whereNotIn('invoices.status', ['void'])
            ->groupBy('billing_periods.year', 'billing_periods.month')
            ->orderBy('billing_periods.year')
            ->orderBy('billing_periods.month')
            ->get();

        $byTenant = Invoice::query()
            ->select(
                'tenants.name as tenant',
                DB::raw('SUM(invoices.total_amount) as billed'),
                DB::raw('SUM(invoices.paid_amount) as collected')
            )
            ->join('leases', 'leases.id', '=', 'invoices.lease_id')
            ->join('tenants', 'tenants.id', '=', 'leases.tenant_id')
            ->where('invoices.property_id', $property->id)
            ->whereNotIn('invoices.status', ['void', 'draft'])
            ->groupBy('tenants.name')
            ->orderByDesc('billed')
            ->get();

        $utilityCosts = PeriodMeterInput::query()
            ->select(
                'billing_periods.year',
                'billing_periods.month',
                DB::raw('SUM(period_meter_inputs.amount) as electricity_cost')
            )
            ->join('billing_periods', 'billing_periods.id', '=', 'period_meter_inputs.billing_period_id')
            ->where('billing_periods.property_id', $property->id)
            ->groupBy('billing_periods.year', 'billing_periods.month')
            ->get()
            ->keyBy(fn ($r) => sprintf('%04d-%02d', $r->year, $r->month));

        $profitRows = $months->map(function ($row) use ($utilityCosts) {
            $key = sprintf('%04d-%02d', $row->year, $row->month);
            $elec = (float) ($utilityCosts[$key]->electricity_cost ?? 0);

            return [
                'year' => $row->year,
                'month' => $row->month,
                'billed' => (float) $row->billed,
                'collected' => (float) $row->collected,
                'utility_cost' => $elec,
                'profit' => round((float) $row->billed - $elec, 2),
            ];
        });

        $openInvoices = Invoice::with(['lease.tenant', 'lease.unit', 'billingPeriod'])
            ->where('property_id', $property->id)
            ->whereIn('status', ['issued', 'partial'])
            ->get();

        $outstandingByTenant = $openInvoices
            ->groupBy(fn ($inv) => $inv->lease?->tenant?->name ?: 'Unknown')
            ->map(function ($group, $tenant) {
                $balance = round($group->sum(fn ($inv) => max(0, (float) $inv->total_amount - (float) $inv->paid_amount)), 2);
                $oldest = $group->map(function ($inv) {
                    return $inv->billingPeriod?->bill_date
                        ?? $inv->issued_at
                        ?? $inv->created_at;
                })->filter()->min();

                $ageDays = $oldest
                    ? Carbon::parse($oldest)->startOfDay()->diffInDays(now()->startOfDay())
                    : 0;

                return [
                    'tenant' => $tenant,
                    'balance' => $balance,
                    'invoice_count' => $group->count(),
                    'age_days' => $ageDays,
                ];
            })
            ->values()
            ->sortByDesc('balance')
            ->values();

        $year = (int) now()->year;
        $ytd = $profitRows->filter(fn ($r) => (int) $r['year'] === $year);
        $billedYtd = round($ytd->sum('billed'), 2);
        $collectedYtd = round($ytd->sum('collected'), 2);
        $outstanding = round($outstandingByTenant->sum('balance'), 2);

        return Inertia::render('Analytics/Index', [
            'months' => $months,
            'byTenant' => $byTenant,
            'profitRows' => $profitRows,
            'outstandingByTenant' => $outstandingByTenant,
            'summary' => [
                'billed_ytd' => $billedYtd,
                'collected_ytd' => $collectedYtd,
                'outstanding' => $outstanding,
                'collection_rate' => $billedYtd > 0 ? round(($collectedYtd / $billedYtd) * 100, 1) : 0,
            ],
        ]);
    }

    public function summaryPdf(Request $request, BillingPeriod $billing, PdfGenerator $pdfGenerator)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property && $billing->property_id === $property->id, 403);

        return $pdfGenerator->summary($billing)->stream('summary-'.$billing->period_key.'.pdf');
    }
}
