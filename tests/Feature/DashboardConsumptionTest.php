<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardConsumptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_includes_consumption_chart_series_from_seeded_bills(): void
    {
        $this->seed();
        $user = User::first();

        // Seeded August 2026 electricity meter bills + water charge input.
        $expectedCommon = 177 + 1214 + 901 + 17777 + 1312;
        $expectedUnit = 27623 + 708 + 2522 + 275 + 67629 + 23154 + 12339;
        $expectedWater = 4172.0;

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->has('consumptionByMonth', 1)
                ->where('consumptionByMonth.0.year', 2026)
                ->where('consumptionByMonth.0.month', 8)
                ->where('consumptionByMonth.0.electricity_common', fn ($v) => (float) $v === (float) $expectedCommon)
                ->where('consumptionByMonth.0.electricity_unit', fn ($v) => (float) $v === (float) $expectedUnit)
                ->where('consumptionByMonth.0.electricity_total', fn ($v) => (float) $v === (float) ($expectedCommon + $expectedUnit))
                ->where('consumptionByMonth.0.water', fn ($v) => (float) $v === $expectedWater)
                ->has('unitMeterTrendLastYear.labels', 12)
                ->has('unitMeterTrendLastYear.meters')
                ->where('unitMeterTrendLastYear.range', 'Sep 2025 – Aug 2026')
                ->where('unitMeterTrendLastYear.unit_total', fn ($v) => (float) $v === (float) $expectedUnit)
                ->has('unitBilledLastYear.labels', 12)
                ->has('unitBilledLastYear.units')
                ->where('unitBilledLastYear.range', 'Sep 2025 – Aug 2026')
                ->has('consumptionMom')
                ->missing('commonElectricityLastYear')
                ->missing('unitElectricityLastYear')
                ->missing('unitCollectionsLastYear')
            );

        $props = $this->actingAs($user)->get(route('dashboard'))->original->getData()['page']['props'];

        $trendMeter = collect($props['unitMeterTrendLastYear']['meters'])->first(
            fn ($row) => str_contains($row['label'], '237') || str_contains($row['label'], '3rd')
        );
        $this->assertNotNull($trendMeter);
        $this->assertCount(12, $trendMeter['amounts']);
        $this->assertEquals(67629.0, (float) end($trendMeter['amounts']));
        $this->assertEmpty(array_filter(
            $props['unitMeterTrendLastYear']['meters'],
            fn ($row) => str_contains(strtolower($row['label']), 'main')
        ), 'Common meters must not appear in the unit meter trend picker');

        $billedFirst = collect($props['unitBilledLastYear']['units'])->firstWhere('label', '1st');
        $this->assertNotNull($billedFirst);
        $this->assertCount(12, $billedFirst['amounts']);
        $this->assertGreaterThan(0, (float) end($billedFirst['amounts']));
        $this->assertEmpty(array_filter(
            $props['unitBilledLastYear']['units'],
            fn ($row) => strcasecmp($row['label'], '2nd') === 0
        ), 'Owner-occupied 2nd floor should not appear in unit billed picker');
    }
}
