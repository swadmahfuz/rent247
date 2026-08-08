<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PeriodChargeInput;
use App\Models\PeriodMeterInput;
use App\Models\Property;
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
                'commonElectricityLastYear' => [],
                'unitElectricityLastYear' => [],
                'electricityMetersLastYearRange' => null,
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
        $electricityMetersLastYear = $this->electricityMetersLastYear($property->id);
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
            'commonElectricityLastYear' => $electricityMetersLastYear['common'],
            'unitElectricityLastYear' => $electricityMetersLastYear['unit'],
            'electricityMetersLastYearRange' => $electricityMetersLastYear['range'],
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
     * Trailing 12 billing months of electricity bill amounts, one row per meter.
     * Window ends at the newest period that has meter bills.
     *
     * @return array{
     *   common: list<array{meter_id: int, label: string, name: string, code: ?string, amount: float}>,
     *   unit: list<array{meter_id: int, label: string, name: string, code: ?string, unit: ?string, amount: float}>,
     *   range: ?string
     * }
     */
    private function electricityMetersLastYear(int $propertyId): array
    {
        $latest = PeriodMeterInput::query()
            ->join('billing_periods', 'billing_periods.id', '=', 'period_meter_inputs.billing_period_id')
            ->join('meters', 'meters.id', '=', 'period_meter_inputs.meter_id')
            ->where('billing_periods.property_id', $propertyId)
            ->orderByDesc('billing_periods.year')
            ->orderByDesc('billing_periods.month')
            ->first(['billing_periods.year', 'billing_periods.month']);

        if (! $latest) {
            return ['common' => [], 'unit' => [], 'range' => null];
        }

        $end = Carbon::create((int) $latest->year, (int) $latest->month, 1)->startOfMonth();
        $start = $end->copy()->subMonths(11);
        $startKey = ((int) $start->year * 12) + (int) $start->month;
        $endKey = ((int) $end->year * 12) + (int) $end->month;

        $rows = PeriodMeterInput::query()
            ->select(
                'meters.id as meter_id',
                'meters.kind',
                'meters.name',
                'meters.code',
                'units.label as unit_label',
                DB::raw('SUM(period_meter_inputs.amount) as total')
            )
            ->join('billing_periods', 'billing_periods.id', '=', 'period_meter_inputs.billing_period_id')
            ->join('meters', 'meters.id', '=', 'period_meter_inputs.meter_id')
            ->leftJoin('units', 'units.id', '=', 'meters.unit_id')
            ->where('billing_periods.property_id', $propertyId)
            ->whereRaw('(billing_periods.year * 12 + billing_periods.month) >= ?', [$startKey])
            ->whereRaw('(billing_periods.year * 12 + billing_periods.month) <= ?', [$endKey])
            ->groupBy('meters.id', 'meters.kind', 'meters.name', 'meters.code', 'units.label')
            ->orderByDesc('total')
            ->get();

        $common = [];
        $unit = [];
        foreach ($rows as $row) {
            $code = $row->code ? trim((string) $row->code) : '';
            $name = trim((string) $row->name);
            $amount = round((float) $row->total, 2);

            if ($row->kind === 'common') {
                $label = $code !== '' ? "{$name} ({$code})" : $name;
                $common[] = [
                    'meter_id' => (int) $row->meter_id,
                    'label' => $label,
                    'name' => $name,
                    'code' => $code !== '' ? $code : null,
                    'amount' => $amount,
                ];
            } elseif ($row->kind === 'unit') {
                $unitLabel = $row->unit_label ? trim((string) $row->unit_label) : null;
                $parts = array_filter([
                    $unitLabel,
                    $name,
                    $code !== '' ? "({$code})" : null,
                ]);
                $label = implode(' · ', $parts) ?: $name;
                $unit[] = [
                    'meter_id' => (int) $row->meter_id,
                    'label' => $label,
                    'name' => $name,
                    'code' => $code !== '' ? $code : null,
                    'unit' => $unitLabel,
                    'amount' => $amount,
                ];
            }
        }

        return [
            'common' => $common,
            'unit' => $unit,
            'range' => $start->format('M Y').' – '.$end->format('M Y'),
        ];
    }

    private function momPct(float $previous, float $current): ?float
    {
        if (abs($previous) < 0.0001) {
            return $current > 0.0001 ? 100.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
