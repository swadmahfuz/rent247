<?php

namespace Tests\Feature;

use App\Models\BillingPeriod;
use App\Models\PeriodChargeInput;
use App\Models\PeriodMeterInput;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingChecklistTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_period_checklist_marks_meters_charges_and_invoices_complete(): void
    {
        $this->seed();
        $user = User::first();
        $period = BillingPeriod::where('year', 2026)->where('month', 8)->firstOrFail();

        $this->actingAs($user)
            ->get(route('billing.show', $period))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Billing/Show')
                ->has('checklist.items', 5)
                ->where('checklist.items.0.key', 'meters')
                ->where('checklist.items.0.ok', true)
                ->where('checklist.items.1.key', 'charges')
                ->where('checklist.items.1.ok', true)
                ->where('checklist.items.3.key', 'invoices')
                ->where('checklist.items.3.ok', true)
                ->where('checklist.invoices_filter.billing_period_id', $period->id)
                ->where('checklist.invoices_filter.status', 'outstanding')
            );
    }

    public function test_empty_period_checklist_is_incomplete_with_generate_blockers(): void
    {
        $this->seed();
        $user = User::first();
        $seeded = BillingPeriod::where('year', 2026)->where('month', 8)->firstOrFail();

        $period = BillingPeriod::create([
            'property_id' => $seeded->property_id,
            'year' => 2026,
            'month' => 10,
            'bill_date' => now()->toDateString(),
            'status' => 'draft',
        ]);

        $this->actingAs($user)
            ->get(route('billing.show', $period))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Billing/Show')
                ->where('checklist.items.0.key', 'meters')
                ->where('checklist.items.0.ok', false)
                ->where('checklist.items.1.key', 'charges')
                ->where('checklist.items.1.ok', false)
                ->where('checklist.items.3.key', 'invoices')
                ->where('checklist.items.3.ok', false)
                ->has('checklist.blockers_generate', 2)
                ->where('checklist.blockers_generate.0', 'Meter readings incomplete')
                ->where('checklist.blockers_generate.1', 'Charge inputs incomplete')
                ->where('checklist.unpaid_count', 0)
            );
    }

    public function test_checklist_unpaid_count_for_issued_balances(): void
    {
        $this->seed();
        $user = User::first();
        $period = BillingPeriod::where('year', 2026)->where('month', 8)->firstOrFail();

        $period->invoices()->update([
            'status' => 'issued',
            'issued_at' => now(),
            'paid_amount' => 0,
        ]);

        $this->actingAs($user)
            ->get(route('billing.show', $period))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('checklist.items.4.key', 'unpaid')
                ->where('checklist.items.4.ok', false)
                ->where('checklist.unpaid_count', fn ($count) => $count > 0)
            );
    }

    public function test_partial_meter_fill_keeps_meters_incomplete(): void
    {
        $this->seed();
        $user = User::first();
        $seeded = BillingPeriod::where('year', 2026)->where('month', 8)->firstOrFail();

        $period = BillingPeriod::create([
            'property_id' => $seeded->property_id,
            'year' => 2026,
            'month' => 11,
            'bill_date' => now()->toDateString(),
            'status' => 'draft',
        ]);

        $firstMeterId = $seeded->meterInputs()->value('meter_id');
        PeriodMeterInput::create([
            'billing_period_id' => $period->id,
            'meter_id' => $firstMeterId,
            'amount' => 100,
            'service_period' => now()->toDateString(),
        ]);

        $waterTypeId = $seeded->chargeInputs()->whereNull('lease_id')->value('charge_type_id');
        PeriodChargeInput::create([
            'billing_period_id' => $period->id,
            'charge_type_id' => $waterTypeId,
            'lease_id' => null,
            'amount' => 50,
        ]);

        $this->actingAs($user)
            ->get(route('billing.show', $period))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('checklist.items.0.ok', false)
                ->where('checklist.blockers_generate', fn ($blockers) => collect($blockers)->contains('Meter readings incomplete'))
            );
    }
}
