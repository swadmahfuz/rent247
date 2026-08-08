<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\PeriodChargeInput;
use App\Models\PeriodMeterInput;
use App\Models\Property;
use App\Models\Unit;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $property = $request->attributes->get('currentProperty');

        $propertyIds = $property
            ? collect([$property->id])
            : Property::where('user_id', $user->id)->pluck('id');

        $draftCount = Invoice::whereIn('property_id', $propertyIds)->where('status', 'draft')->count();

        $recentPayments = Payment::query()
            ->with(['invoice.lease.tenant', 'invoice.property'])
            ->whereHas('invoice', fn ($q) => $q->whereIn('property_id', $propertyIds))
            ->latest('paid_on')
            ->limit(8)
            ->get();

        if (! $property) {
            return Inertia::render('Dashboard', [
                'stats' => [
                    'billed_ytd' => 0,
                    'collected_ytd' => 0,
                    'outstanding' => 0,
                    'collection_rate' => 0,
                    'draft_count' => $draftCount,
                ],
                'profitRows' => [],
                'byTenant' => [],
                'outstandingByTenant' => [],
                'recentPayments' => $recentPayments,
                'consumptionByMonth' => [],
                'unitMeterTrendLastYear' => [
                    'range' => null,
                    'labels' => [],
                    'meters' => [],
                    'unit_total' => 0,
                ],
                'unitBilledLastYear' => [
                    'range' => null,
                    'labels' => [],
                    'units' => [],
                    'unit_total' => 0,
                ],
                'consumptionMom' => [
                    'electricity_mom_pct' => null,
                    'water_mom_pct' => null,
                ],
            ]);
        }

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

        $consumptionByMonth = $this->consumptionByMonth($property->id);
        $unitMeterTrendLastYear = $this->unitMeterTrendLastYear($property->id);
        $unitBilledLastYear = $this->unitBilledLastYear($property->id);
        $latest = $consumptionByMonth->last();

        return Inertia::render('Dashboard', [
            'stats' => [
                'billed_ytd' => $billedYtd,
                'collected_ytd' => $collectedYtd,
                'outstanding' => $outstanding,
                'collection_rate' => $billedYtd > 0 ? round(($collectedYtd / $billedYtd) * 100, 1) : 0,
                'draft_count' => $draftCount,
            ],
            'profitRows' => $profitRows,
            'byTenant' => $byTenant,
            'outstandingByTenant' => $outstandingByTenant,
            'recentPayments' => $recentPayments,
            'consumptionByMonth' => $consumptionByMonth->values()->all(),
            'unitMeterTrendLastYear' => $unitMeterTrendLastYear,
            'unitBilledLastYear' => $unitBilledLastYear,
            'consumptionMom' => [
                'electricity_mom_pct' => $latest['electricity_mom_pct'] ?? null,
                'water_mom_pct' => $latest['water_mom_pct'] ?? null,
                'label' => $latest['label'] ?? null,
            ],
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function consumptionByMonth(int $propertyId): Collection
    {
        $electricity = PeriodMeterInput::query()
            ->select(
                'billing_periods.year',
                'billing_periods.month',
                'meters.kind',
                DB::raw('SUM(period_meter_inputs.amount) as total')
            )
            ->join('billing_periods', 'billing_periods.id', '=', 'period_meter_inputs.billing_period_id')
            ->join('meters', 'meters.id', '=', 'period_meter_inputs.meter_id')
            ->where('billing_periods.property_id', $propertyId)
            ->groupBy('billing_periods.year', 'billing_periods.month', 'meters.kind')
            ->get();

        $water = PeriodChargeInput::query()
            ->select(
                'billing_periods.year',
                'billing_periods.month',
                DB::raw('SUM(period_charge_inputs.amount) as total')
            )
            ->join('billing_periods', 'billing_periods.id', '=', 'period_charge_inputs.billing_period_id')
            ->join('charge_types', 'charge_types.id', '=', 'period_charge_inputs.charge_type_id')
            ->where('billing_periods.property_id', $propertyId)
            ->where('charge_types.code', 'water')
            ->whereNull('period_charge_inputs.lease_id')
            ->groupBy('billing_periods.year', 'billing_periods.month')
            ->get()
            ->keyBy(fn ($r) => sprintf('%04d-%02d', $r->year, $r->month));

        $keys = $electricity
            ->map(fn ($r) => sprintf('%04d-%02d', $r->year, $r->month))
            ->merge($water->keys())
            ->unique()
            ->sort()
            ->values();

        $elecByKey = [];
        foreach ($electricity as $row) {
            $key = sprintf('%04d-%02d', $row->year, $row->month);
            $elecByKey[$key] ??= ['common' => 0.0, 'unit' => 0.0];
            if ($row->kind === 'common') {
                $elecByKey[$key]['common'] = (float) $row->total;
            } elseif ($row->kind === 'unit') {
                $elecByKey[$key]['unit'] = (float) $row->total;
            }
        }

        $rows = $keys->map(function (string $key) use ($elecByKey, $water) {
            [$year, $month] = array_map('intval', explode('-', $key));
            $common = (float) ($elecByKey[$key]['common'] ?? 0);
            $unit = (float) ($elecByKey[$key]['unit'] ?? 0);
            $waterAmt = (float) ($water[$key]->total ?? 0);

            return [
                'year' => $year,
                'month' => $month,
                'label' => Carbon::create($year, $month, 1)->format('M Y'),
                'electricity_common' => round($common, 2),
                'electricity_unit' => round($unit, 2),
                'electricity_total' => round($common + $unit, 2),
                'water' => round($waterAmt, 2),
                'electricity_mom_pct' => null,
                'water_mom_pct' => null,
            ];
        });

        return $rows->values()->map(function (array $row, int $index) use ($rows) {
            if ($index === 0) {
                return $row;
            }

            $prev = $rows[$index - 1];
            $row['electricity_mom_pct'] = $this->momPct($prev['electricity_total'], $row['electricity_total']);
            $row['water_mom_pct'] = $this->momPct($prev['water'], $row['water']);

            return $row;
        });
    }

    /**
     * Trailing 12 months of bill amounts for each unit meter (for the meter picker chart).
     *
     * @return array{
     *   range: ?string,
     *   labels: list<string>,
     *   meters: list<array{id: int, label: string, amounts: list<float>}>,
     *   unit_total: float
     * }
     */
    private function unitMeterTrendLastYear(int $propertyId): array
    {
        $window = $this->meterBillingWindow($propertyId);
        if ($window === null) {
            return ['range' => null, 'labels' => [], 'meters' => [], 'unit_total' => 0.0];
        }

        [$start, $end, $startKey, $endKey] = $window;

        $labels = [];
        $monthKeys = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $monthKeys[] = sprintf('%04d-%02d', (int) $cursor->year, (int) $cursor->month);
            $labels[] = $cursor->format('M Y');
            $cursor->addMonth();
        }

        $meters = Meter::query()
            ->with('unit')
            ->where('property_id', $propertyId)
            ->where('kind', 'unit')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($meters->isEmpty()) {
            return [
                'range' => $start->format('M Y').' – '.$end->format('M Y'),
                'labels' => $labels,
                'meters' => [],
                'unit_total' => 0.0,
            ];
        }

        $rows = PeriodMeterInput::query()
            ->select(
                'period_meter_inputs.meter_id',
                'billing_periods.year',
                'billing_periods.month',
                DB::raw('SUM(period_meter_inputs.amount) as total')
            )
            ->join('billing_periods', 'billing_periods.id', '=', 'period_meter_inputs.billing_period_id')
            ->where('billing_periods.property_id', $propertyId)
            ->whereIn('period_meter_inputs.meter_id', $meters->pluck('id'))
            ->whereRaw('(billing_periods.year * 12 + billing_periods.month) >= ?', [$startKey])
            ->whereRaw('(billing_periods.year * 12 + billing_periods.month) <= ?', [$endKey])
            ->groupBy('period_meter_inputs.meter_id', 'billing_periods.year', 'billing_periods.month')
            ->get();

        $byMeterMonth = [];
        foreach ($rows as $row) {
            $key = sprintf('%04d-%02d', (int) $row->year, (int) $row->month);
            $byMeterMonth[(int) $row->meter_id][$key] = round((float) $row->total, 2);
        }

        $series = [];
        $unitTotal = 0.0;
        foreach ($meters as $meter) {
            $code = $meter->code ? trim((string) $meter->code) : '';
            $name = trim((string) $meter->name);
            $unitLabel = $meter->unit?->label ? trim((string) $meter->unit->label) : null;
            $parts = array_filter([
                $unitLabel,
                $name,
                $code !== '' ? "({$code})" : null,
            ]);
            $label = implode(' · ', $parts) ?: $name;

            $amounts = [];
            foreach ($monthKeys as $monthKey) {
                $amount = (float) ($byMeterMonth[$meter->id][$monthKey] ?? 0);
                $amounts[] = $amount;
                $unitTotal += $amount;
            }

            $series[] = [
                'id' => (int) $meter->id,
                'label' => $label,
                'amounts' => $amounts,
            ];
        }

        return [
            'range' => $start->format('M Y').' – '.$end->format('M Y'),
            'labels' => $labels,
            'meters' => $series,
            'unit_total' => round($unitTotal, 2),
        ];
    }

    /**
     * Trailing 12 months of invoice billed amounts per unit (by billing period).
     *
     * @return array{
     *   range: ?string,
     *   labels: list<string>,
     *   units: list<array{id: int, label: string, amounts: list<float>}>,
     *   unit_total: float
     * }
     */
    private function unitBilledLastYear(int $propertyId): array
    {
        $window = $this->invoiceBillingWindow($propertyId);
        if ($window === null) {
            return ['range' => null, 'labels' => [], 'units' => [], 'unit_total' => 0.0];
        }

        [$start, $end, $startKey, $endKey] = $window;

        $labels = [];
        $monthKeys = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $monthKeys[] = sprintf('%04d-%02d', (int) $cursor->year, (int) $cursor->month);
            $labels[] = $cursor->format('M Y');
            $cursor->addMonth();
        }

        $units = Unit::query()
            ->where('property_id', $propertyId)
            ->where('is_active', true)
            ->where('type', '!=', 'owner_occupied')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($units->isEmpty()) {
            return [
                'range' => $start->format('M Y').' – '.$end->format('M Y'),
                'labels' => $labels,
                'units' => [],
                'unit_total' => 0.0,
            ];
        }

        $rows = Invoice::query()
            ->select(
                'units.id as unit_id',
                'billing_periods.year',
                'billing_periods.month',
                DB::raw('SUM(invoices.total_amount) as total')
            )
            ->join('billing_periods', 'billing_periods.id', '=', 'invoices.billing_period_id')
            ->join('leases', 'leases.id', '=', 'invoices.lease_id')
            ->join('units', 'units.id', '=', 'leases.unit_id')
            ->where('invoices.property_id', $propertyId)
            ->whereNotIn('invoices.status', ['void'])
            ->whereIn('units.id', $units->pluck('id'))
            ->whereRaw('(billing_periods.year * 12 + billing_periods.month) >= ?', [$startKey])
            ->whereRaw('(billing_periods.year * 12 + billing_periods.month) <= ?', [$endKey])
            ->groupBy('units.id', 'billing_periods.year', 'billing_periods.month')
            ->get();

        $byUnitMonth = [];
        foreach ($rows as $row) {
            $key = sprintf('%04d-%02d', (int) $row->year, (int) $row->month);
            $byUnitMonth[(int) $row->unit_id][$key] = round((float) $row->total, 2);
        }

        $series = [];
        $unitTotal = 0.0;
        foreach ($units as $unit) {
            $amounts = [];
            foreach ($monthKeys as $monthKey) {
                $amount = (float) ($byUnitMonth[$unit->id][$monthKey] ?? 0);
                $amounts[] = $amount;
                $unitTotal += $amount;
            }

            $series[] = [
                'id' => (int) $unit->id,
                'label' => trim((string) $unit->label),
                'amounts' => $amounts,
            ];
        }

        return [
            'range' => $start->format('M Y').' – '.$end->format('M Y'),
            'labels' => $labels,
            'units' => $series,
            'unit_total' => round($unitTotal, 2),
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: int, 3: int}|null
     */
    private function invoiceBillingWindow(int $propertyId): ?array
    {
        $latest = Invoice::query()
            ->join('billing_periods', 'billing_periods.id', '=', 'invoices.billing_period_id')
            ->where('invoices.property_id', $propertyId)
            ->whereNotIn('invoices.status', ['void'])
            ->orderByDesc('billing_periods.year')
            ->orderByDesc('billing_periods.month')
            ->first(['billing_periods.year', 'billing_periods.month']);

        if (! $latest) {
            return null;
        }

        $end = Carbon::create((int) $latest->year, (int) $latest->month, 1)->startOfMonth();
        $start = $end->copy()->subMonths(11);
        $startKey = ((int) $start->year * 12) + (int) $start->month;
        $endKey = ((int) $end->year * 12) + (int) $end->month;

        return [$start, $end, $startKey, $endKey];
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: int, 3: int}|null
     */
    private function meterBillingWindow(int $propertyId): ?array
    {
        $latest = PeriodMeterInput::query()
            ->join('billing_periods', 'billing_periods.id', '=', 'period_meter_inputs.billing_period_id')
            ->join('meters', 'meters.id', '=', 'period_meter_inputs.meter_id')
            ->where('billing_periods.property_id', $propertyId)
            ->orderByDesc('billing_periods.year')
            ->orderByDesc('billing_periods.month')
            ->first(['billing_periods.year', 'billing_periods.month']);

        if (! $latest) {
            return null;
        }

        $end = Carbon::create((int) $latest->year, (int) $latest->month, 1)->startOfMonth();
        $start = $end->copy()->subMonths(11);
        $startKey = ((int) $start->year * 12) + (int) $start->month;
        $endKey = ((int) $end->year * 12) + (int) $end->month;

        return [$start, $end, $startKey, $endKey];
    }

    private function momPct(float $previous, float $current): ?float
    {
        if (abs($previous) < 0.0001) {
            return $current > 0.0001 ? 100.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
