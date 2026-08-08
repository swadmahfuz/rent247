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
                ->has('commonElectricityLastYear', 5)
                ->has('unitElectricityLastYear', 7)
                ->where('electricityMetersLastYearRange', 'Sep 2025 – Aug 2026')
                ->has('consumptionMom')
            );

        $props = $this->actingAs($user)->get(route('dashboard'))->original->getData()['page']['props'];

        $main = collect($props['commonElectricityLastYear'])->firstWhere('name', 'Main');
        $this->assertNotNull($main);
        $this->assertSame('Main (676)', $main['label']);
        $this->assertEquals(17777.0, (float) $main['amount']);

        $third = collect($props['unitElectricityLastYear'])->first(
            fn ($row) => ($row['unit'] ?? null) === '3rd' || str_contains($row['label'], '3rd')
        );
        $this->assertNotNull($third);
        $this->assertEquals(67629.0, (float) $third['amount']);
        $this->assertStringContainsString('237', $third['label']);
    }
}
