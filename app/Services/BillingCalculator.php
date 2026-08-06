<?php

namespace App\Services;

use App\Models\AllocationRule;
use App\Models\BillingPeriod;
use App\Models\ChargeType;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Lease;
use App\Models\PeriodChargeInput;
use App\Models\Unit;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BillingCalculator
{
    public function __construct(private NumberToWords $numberToWords)
    {
    }

    /**
     * Generate or regenerate draft invoices for a billing period.
     */
    public function generate(BillingPeriod $period): Collection
    {
        $period->load([
            'property.units',
            'property.chargeTypes.allocationRule',
            'property.meters',
            'meterInputs.meter',
            'chargeInputs',
        ]);

        if ($period->property->setting('auto_carry_arrears', true)) {
            $this->seedArrearsInputs($period);
            $period->load('chargeInputs');
        }

        $property = $period->property;
        $leases = Lease::with(['unit', 'tenant', 'chargeAssignments'])
            ->where('property_id', $property->id)
            ->where('is_active', true)
            ->get()
            ->filter(fn (Lease $lease) => $lease->unit && $lease->unit->type !== 'owner_occupied');

        $chargeMap = $property->chargeTypes->keyBy('code');
        $allocated = $this->allocateCharges($period, $leases, $chargeMap);

        return DB::transaction(function () use ($period, $property, $leases, $allocated, $chargeMap) {
            $invoices = collect();

            foreach ($leases as $lease) {
                $lines = $allocated[$lease->id] ?? [];
                $rentType = $chargeMap->get('rent') ?? $chargeMap->get('office_rent');

                if ($lease->rent_amount > 0) {
                    array_unshift($lines, [
                        'charge_type_id' => $rentType?->id,
                        'description' => $lease->rent_label ?: ($rentType?->label ?? 'Rent'),
                        'period_label' => $this->periodLabel($period, $rentType),
                        'amount' => round((float) $lease->rent_amount, 2),
                        'sort_order' => 1,
                    ]);
                }

                if ($lines === []) {
                    continue;
                }

                $invoices->push($this->saveDraftInvoice($period, $lease, $lines));
            }

            // Remove drafts for leases no longer active
            Invoice::where('billing_period_id', $period->id)
                ->where('status', 'draft')
                ->whereNotIn('lease_id', $leases->pluck('id'))
                ->delete();

            return $invoices;
        });
    }

    /**
     * Create arrears period inputs from unpaid prior invoices when missing.
     * Does not overwrite amounts already entered for this period.
     */
    public function seedArrearsInputs(BillingPeriod $period): void
    {
        $period->loadMissing(['property.chargeTypes', 'chargeInputs']);

        $arrearsType = $period->property->chargeTypes
            ->first(fn (ChargeType $type) => $type->code === 'arrears' || $type->category === 'arrears');

        if (! $arrearsType) {
            return;
        }

        $leases = Lease::with('unit')
            ->where('property_id', $period->property_id)
            ->where('is_active', true)
            ->get()
            ->filter(fn (Lease $lease) => $lease->unit && $lease->unit->type !== 'owner_occupied');

        foreach ($leases as $lease) {
            $existing = $period->chargeInputs
                ->where('charge_type_id', $arrearsType->id)
                ->where('lease_id', $lease->id)
                ->first();

            if ($existing) {
                continue;
            }

            $balance = (float) Invoice::query()
                ->where('property_id', $period->property_id)
                ->where('lease_id', $lease->id)
                ->whereIn('status', ['issued', 'partial'])
                ->whereHas('billingPeriod', function ($query) use ($period) {
                    $query->where(function ($q) use ($period) {
                        $q->where('year', '<', $period->year)
                            ->orWhere(function ($q2) use ($period) {
                                $q2->where('year', $period->year)
                                    ->where('month', '<', $period->month);
                            });
                    });
                })
                ->get()
                ->sum(fn (Invoice $invoice) => max(0, (float) $invoice->total_amount - (float) $invoice->paid_amount));

            if ($balance <= 0) {
                continue;
            }

            PeriodChargeInput::create([
                'billing_period_id' => $period->id,
                'charge_type_id' => $arrearsType->id,
                'lease_id' => $lease->id,
                'amount' => round($balance, 2),
            ]);
        }
    }

    private function saveDraftInvoice(
        BillingPeriod $period,
        Lease $lease,
        array $lines
    ): Invoice {
        $invoice = Invoice::updateOrCreate(
            [
                'billing_period_id' => $period->id,
                'lease_id' => $lease->id,
            ],
            [
                'property_id' => $period->property_id,
                'number' => sprintf(
                    '%s-%s-%d',
                    $period->property_id,
                    $period->period_key,
                    $lease->id
                ),
                'status' => 'draft',
                'total_amount' => round(collect($lines)->sum('amount'), 2),
                'paid_amount' => 0,
                'issued_at' => null,
            ]
        );

        $invoice->lines()->delete();

        foreach (array_values($lines) as $index => $line) {
            InvoiceLine::create([
                'invoice_id' => $invoice->id,
                'charge_type_id' => $line['charge_type_id'] ?? null,
                'description' => $line['description'],
                'period_label' => $line['period_label'] ?? null,
                'amount' => round((float) $line['amount'], 2),
                'sort_order' => $line['sort_order'] ?? $index + 1,
            ]);
        }

        $invoice->total_amount = round($invoice->lines()->sum('amount'), 2);
        $invoice->save();

        return $invoice->fresh(['lines', 'lease.tenant', 'lease.unit']);
    }

    /**
     * @param  Collection<int, Lease>  $leases
     * @param  Collection<string, ChargeType>  $chargeMap
     * @return array<int, array<int, array>>
     */
    private function allocateCharges(BillingPeriod $period, Collection $leases, Collection $chargeMap): array
    {
        $property = $period->property;
        $units = $property->units->where('is_active', true)->values();
        $result = [];

        foreach ($leases as $lease) {
            $result[$lease->id] = [];
        }

        // Common electricity from meters
        $commonMeters = $period->meterInputs->filter(fn ($i) => $i->meter?->kind === 'common');
        $commonTotal = round((float) $commonMeters->sum('amount'), 2);
        $commonCharge = $chargeMap->get('electricity_common');
        if ($commonCharge && $commonTotal > 0) {
            $rule = $commonCharge->allocationRule;
            $perUnit = $this->equalSplit($commonTotal, $units, $rule);
            $this->applyPerUnitAmount($result, $leases, $perUnit, $commonCharge, $period, 5);
        }

        // Unit electricity meters
        $electricityCharge = $chargeMap->get('electricity');
        if ($electricityCharge) {
            $unitMeters = $period->meterInputs->filter(fn ($i) => $i->meter?->kind === 'unit');
            foreach ($unitMeters as $input) {
                $unitId = $input->meter->unit_id;
                $lease = $leases->first(fn (Lease $l) => $l->unit_id === $unitId);
                if (! $lease || (float) $input->amount == 0.0) {
                    continue;
                }
                $result[$lease->id][] = [
                    'charge_type_id' => $electricityCharge->id,
                    'description' => $electricityCharge->label,
                    'period_label' => $this->periodLabel($period, $electricityCharge, $input->service_period),
                    'amount' => round((float) $input->amount, 2),
                    'sort_order' => 4,
                ];
            }
        }

        // Water and other charge inputs with allocation rules
        foreach ($property->chargeTypes->where('is_active', true) as $chargeType) {
            if (in_array($chargeType->code, ['rent', 'office_rent', 'electricity', 'electricity_common'], true)) {
                continue;
            }

            $rule = $chargeType->allocationRule;
            if (! $rule || ! $rule->is_active) {
                // Fall back to lease assignments / period per-lease inputs
                $this->applyLeaseAssignmentsAndInputs($result, $period, $leases, $chargeType);
                continue;
            }

            match ($rule->strategy) {
                'water_residential_commercial' => $this->applyWaterSplit($result, $period, $leases, $units, $chargeType, $rule),
                'equal_units' => $this->applyEqualFromInput($result, $period, $leases, $units, $chargeType, $rule),
                'fixed_amount', 'per_lease' => $this->applyLeaseAssignmentsAndInputs($result, $period, $leases, $chargeType),
                'fee_tier' => $this->applyFeeTier($result, $period, $leases, $chargeType, $rule),
                default => $this->applyLeaseAssignmentsAndInputs($result, $period, $leases, $chargeType),
            };
        }

        return $result;
    }

    private function applyWaterSplit(
        array &$result,
        BillingPeriod $period,
        Collection $leases,
        Collection $units,
        ChargeType $chargeType,
        AllocationRule $rule
    ): void {
        $input = $period->chargeInputs
            ->where('charge_type_id', $chargeType->id)
            ->whereNull('lease_id')
            ->first();

        if (! $input || (float) $input->amount <= 0) {
            return;
        }

        $totalBill = (float) $input->amount;
        $totalUnits = (float) ($input->units ?? 0);
        $floorCount = max(1, $units->whereIn('type', $rule->param('divisor_unit_types', ['residential', 'commercial', 'owner_occupied']))->count());
        $unitsPerFloor = $totalUnits > 0 ? $totalUnits / $floorCount : 0;

        $residentialTypes = $rule->param('residential_unit_types', ['residential', 'owner_occupied']);
        $commercialTypes = $rule->param('commercial_unit_types', ['commercial']);
        $rate = (float) $rule->param('residential_rate', 16.7);

        $residentialFloors = (int) ($rule->param('residential_count_override')
            ?? $units->whereIn('type', $residentialTypes)->count());
        $commercialFloors = (int) ($rule->param('commercial_count_override')
            ?? $units->whereIn('type', $commercialTypes)->count());

        $residentialPerFloor = round($unitsPerFloor * $rate, 2);
        $totalResidential = round($residentialPerFloor * $residentialFloors, 2);
        $totalCommercial = round($totalBill - $totalResidential, 2);
        $commercialPerFloor = $commercialFloors > 0 ? round($totalCommercial / $commercialFloors, 2) : 0;

        foreach ($leases as $lease) {
            $type = $lease->unit->type;
            $amount = 0;
            if (in_array($type, $commercialTypes, true)) {
                $amount = $commercialPerFloor;
            } elseif (in_array($type, $residentialTypes, true) && $type !== 'owner_occupied') {
                $amount = $residentialPerFloor;
            }
            if ($amount <= 0) {
                continue;
            }
            $result[$lease->id][] = [
                'charge_type_id' => $chargeType->id,
                'description' => $chargeType->label,
                'period_label' => $this->periodLabel($period, $chargeType),
                'amount' => $amount,
                'sort_order' => 3,
            ];
        }
    }

    private function applyEqualFromInput(
        array &$result,
        BillingPeriod $period,
        Collection $leases,
        Collection $units,
        ChargeType $chargeType,
        AllocationRule $rule
    ): void {
        $input = $period->chargeInputs
            ->where('charge_type_id', $chargeType->id)
            ->whereNull('lease_id')
            ->first();

        $amount = $input?->amount ?? $chargeType->default_amount;
        if ($amount === null || (float) $amount <= 0) {
            return;
        }

        $perUnit = $this->equalSplit((float) $amount, $units, $rule);
        $this->applyPerUnitAmount($result, $leases, $perUnit, $chargeType, $period, 10);
    }

    private function applyFeeTier(
        array &$result,
        BillingPeriod $period,
        Collection $leases,
        ChargeType $chargeType,
        AllocationRule $rule
    ): void {
        $tiers = $rule->param('tiers', []);
        $defaultAmount = (float) ($period->chargeInputs
            ->where('charge_type_id', $chargeType->id)
            ->whereNull('lease_id')
            ->first()?->amount ?? $chargeType->default_amount ?? 0);

        foreach ($leases as $lease) {
            $tier = $lease->fee_tier ?: 'full';
            $amount = (float) ($tiers[$tier] ?? $defaultAmount);
            if ($amount <= 0) {
                continue;
            }
            // Skip garages unless included
            if ($lease->unit->type === 'garage' && ! $rule->param('include_garage', false)) {
                continue;
            }
            $result[$lease->id][] = [
                'charge_type_id' => $chargeType->id,
                'description' => $chargeType->label,
                'period_label' => $this->periodLabel($period, $chargeType),
                'amount' => round($amount, 2),
                'sort_order' => 7,
            ];
        }
    }

    private function applyLeaseAssignmentsAndInputs(
        array &$result,
        BillingPeriod $period,
        Collection $leases,
        ChargeType $chargeType
    ): void {
        foreach ($leases as $lease) {
            // Period-specific per-lease override (arrears/other)
            $input = $period->chargeInputs
                ->where('charge_type_id', $chargeType->id)
                ->where('lease_id', $lease->id)
                ->first();

            if ($input !== null) {
                $amount = (float) ($input->amount ?? 0);
            } else {
                $assignment = $lease->chargeAssignments
                    ->where('charge_type_id', $chargeType->id)
                    ->where('is_active', true)
                    ->first();

                if (! $assignment && ! $chargeType->is_recurring) {
                    continue;
                }

                // For fixed recurring charges without assignment, use default if strategy implies all leases
                if ($assignment) {
                    $amount = (float) ($assignment->amount_override ?? $chargeType->default_amount ?? 0);
                } elseif ($chargeType->is_recurring && $chargeType->default_amount !== null && $lease->unit->type !== 'garage') {
                    $amount = (float) $chargeType->default_amount;
                } else {
                    continue;
                }
            }

            if ($amount == 0.0 && ! in_array($chargeType->category, ['arrears', 'other'], true)) {
                // Still show zero arrears/other lines only if period input exists
                if (! $input) {
                    continue;
                }
            }

            if ($lease->unit->type === 'garage' && $chargeType->category === 'fixed') {
                continue;
            }

            $result[$lease->id][] = [
                'charge_type_id' => $chargeType->id,
                'description' => $chargeType->label,
                'period_label' => $this->periodLabel($period, $chargeType),
                'amount' => round($amount, 2),
                'sort_order' => match ($chargeType->category) {
                    'fixed' => 6,
                    'arrears' => 10,
                    'other' => 9,
                    default => 8,
                },
            ];
        }
    }

    /**
     * @return array<int, float> unit_id => amount
     */
    private function equalSplit(float $total, Collection $units, ?AllocationRule $rule): array
    {
        $types = $rule?->param('unit_types', ['residential', 'commercial', 'owner_occupied']) ?? ['residential', 'commercial', 'owner_occupied'];
        $eligible = $units->whereIn('type', $types)->values();
        $count = max(1, $eligible->count());
        $per = round($total / $count, 2);
        $map = [];
        foreach ($eligible as $unit) {
            $map[$unit->id] = $per;
        }
        return $map;
    }

    private function applyPerUnitAmount(
        array &$result,
        Collection $leases,
        array $perUnit,
        ChargeType $chargeType,
        BillingPeriod $period,
        int $sortOrder
    ): void {
        foreach ($leases as $lease) {
            $amount = $perUnit[$lease->unit_id] ?? null;
            if ($amount === null || $amount <= 0) {
                continue;
            }
            $result[$lease->id][] = [
                'charge_type_id' => $chargeType->id,
                'description' => $chargeType->label,
                'period_label' => $this->periodLabel($period, $chargeType),
                'amount' => $amount,
                'sort_order' => $sortOrder,
            ];
        }
    }

    private function periodLabel(BillingPeriod $period, ?ChargeType $chargeType, $servicePeriod = null): string
    {
        if ($servicePeriod) {
            return Carbon::parse($servicePeriod)->format('M-y');
        }

        $offset = $chargeType?->period_offset_months ?? 0;
        $date = Carbon::createFromDate($period->year, $period->month, 1)->addMonths($offset);

        return $date->format('M-y');
    }

    public function amountInWords(float $amount, string $currency = 'BDT'): string
    {
        return $this->numberToWords->convert($amount, $currency);
    }
}
