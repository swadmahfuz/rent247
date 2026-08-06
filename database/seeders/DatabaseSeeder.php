<?php

namespace Database\Seeders;

use App\Models\AllocationRule;
use App\Models\BillingPeriod;
use App\Models\ChargeType;
use App\Models\Lease;
use App\Models\LeaseChargeAssignment;
use App\Models\Meter;
use App\Models\PeriodChargeInput;
use App\Models\PeriodMeterInput;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\BillingCalculator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'admin@rent247.test'],
            [
                'name' => 'Brig General M Mofizur Rahman',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $property = Property::updateOrCreate(
            ['user_id' => $user->id, 'name' => 'House-247'],
            [
                'address' => 'House -247, Road-3, Baridhara DOHS, Dhaka Cantt., Dhaka-1206',
                'owner_display_name' => 'Brig General M Mofizur Rahman (Retd)',
                'currency' => 'BDT',
                'timezone' => 'Asia/Dhaka',
                'settings' => [
                    'invoice_title' => 'House Rent and Utility Bill',
                    'auto_carry_arrears' => true,
                ],
                'is_active' => true,
            ]
        );

        $user->update(['current_property_id' => $property->id]);

        $units = [
            ['label' => '1st', 'type' => 'commercial', 'sort_order' => 1],
            ['label' => '2nd', 'type' => 'owner_occupied', 'sort_order' => 2],
            ['label' => '3rd', 'type' => 'commercial', 'sort_order' => 3],
            ['label' => '4th', 'type' => 'commercial', 'sort_order' => 4],
            ['label' => '5th', 'type' => 'commercial', 'sort_order' => 5],
            ['label' => 'Garage 1', 'type' => 'garage', 'sort_order' => 11],
            ['label' => 'Garage 2', 'type' => 'garage', 'sort_order' => 12],
            ['label' => 'Garage 3', 'type' => 'garage', 'sort_order' => 13],
            ['label' => 'Garage 4', 'type' => 'garage', 'sort_order' => 14],
        ];

        $unitModels = [];
        foreach ($units as $u) {
            $unitModels[$u['label']] = Unit::updateOrCreate(
                ['property_id' => $property->id, 'label' => $u['label']],
                $u + ['is_active' => true]
            );
        }

        $tenants = [
            'Biswa Shera Travel Agency' => Tenant::updateOrCreate(
                ['user_id' => $user->id, 'name' => 'Biswa Shera Travel Agency'],
                ['email' => null]
            ),
            'Pentex' => Tenant::updateOrCreate(
                ['user_id' => $user->id, 'name' => 'Pentex'],
                ['email' => null]
            ),
            'Nishat Fabrics' => Tenant::updateOrCreate(
                ['user_id' => $user->id, 'name' => 'Nishat Fabrics'],
                ['email' => null]
            ),
            'Maj Ismail Bhuiyan' => Tenant::updateOrCreate(
                ['user_id' => $user->id, 'name' => 'Maj Ismail Bhuiyan'],
                []
            ),
            'Shati' => Tenant::updateOrCreate(
                ['user_id' => $user->id, 'name' => 'Shati'],
                []
            ),
            'Lt. Col Sakhawat' => Tenant::updateOrCreate(
                ['user_id' => $user->id, 'name' => 'Lt. Col Sakhawat'],
                []
            ),
            'Sareen Kabir' => Tenant::updateOrCreate(
                ['user_id' => $user->id, 'name' => 'Sareen Kabir'],
                []
            ),
        ];

        $leaseDefs = [
            ['unit' => '1st', 'tenant' => 'Biswa Shera Travel Agency', 'rent' => 100000, 'tier' => 'full'],
            ['unit' => '3rd', 'tenant' => 'Pentex', 'rent' => 80000, 'tier' => 'full'],
            ['unit' => '4th', 'tenant' => 'Pentex', 'rent' => 80000, 'tier' => 'full'],
            ['unit' => '5th', 'tenant' => 'Nishat Fabrics', 'rent' => 105556, 'tier' => 'full'],
            ['unit' => 'Garage 1', 'tenant' => 'Maj Ismail Bhuiyan', 'rent' => 0, 'tier' => 'none', 'label' => 'Garage Rent'],
            ['unit' => 'Garage 2', 'tenant' => 'Shati', 'rent' => 4000, 'tier' => 'none', 'label' => 'Garage Rent'],
            ['unit' => 'Garage 3', 'tenant' => 'Lt. Col Sakhawat', 'rent' => 0, 'tier' => 'none', 'label' => 'Garage Rent'],
            ['unit' => 'Garage 4', 'tenant' => 'Sareen Kabir', 'rent' => 4000, 'tier' => 'none', 'label' => 'Garage Rent'],
        ];

        $leases = [];
        foreach ($leaseDefs as $def) {
            $leases[$def['unit']] = Lease::updateOrCreate(
                [
                    'property_id' => $property->id,
                    'unit_id' => $unitModels[$def['unit']]->id,
                    'tenant_id' => $tenants[$def['tenant']]->id,
                ],
                [
                    'rent_amount' => $def['rent'],
                    'rent_label' => $def['label'] ?? 'Office Rent',
                    'fee_tier' => $def['tier'],
                    'is_active' => true,
                ]
            );
        }

        // Charge types
        $charges = [
            ['code' => 'office_rent', 'label' => 'Office Rent', 'category' => 'rent', 'default_amount' => null, 'offset' => 0, 'sort' => 1],
            ['code' => 'gas', 'label' => 'Gas Bill', 'category' => 'fixed', 'default_amount' => 1080, 'offset' => 0, 'sort' => 2],
            ['code' => 'water', 'label' => 'Water Bill', 'category' => 'utility', 'default_amount' => null, 'offset' => -1, 'sort' => 3],
            ['code' => 'electricity', 'label' => 'Electricity Bill', 'category' => 'utility', 'default_amount' => null, 'offset' => -1, 'sort' => 4],
            ['code' => 'electricity_common', 'label' => 'Electricity Bill (common)', 'category' => 'utility', 'default_amount' => null, 'offset' => -1, 'sort' => 5],
            ['code' => 'caretaker', 'label' => 'Care Taker / Security Guard', 'category' => 'fixed', 'default_amount' => 6000, 'offset' => 0, 'sort' => 6],
            ['code' => 'dohs', 'label' => 'DOHS Porishod', 'category' => 'fixed', 'default_amount' => 1500, 'offset' => 0, 'sort' => 7],
            ['code' => 'lift', 'label' => 'Lift Maintenance', 'category' => 'fixed', 'default_amount' => 1000, 'offset' => 0, 'sort' => 8],
            ['code' => 'other', 'label' => 'Other Charges', 'category' => 'other', 'default_amount' => 0, 'offset' => 0, 'sort' => 9, 'recurring' => false],
            ['code' => 'arrears', 'label' => 'Arrears', 'category' => 'arrears', 'default_amount' => 0, 'offset' => 0, 'sort' => 10, 'recurring' => false],
        ];

        $chargeModels = [];
        foreach ($charges as $c) {
            $chargeModels[$c['code']] = ChargeType::updateOrCreate(
                ['property_id' => $property->id, 'code' => $c['code']],
                [
                    'label' => $c['label'],
                    'category' => $c['category'],
                    'default_amount' => $c['default_amount'],
                    'is_recurring' => $c['recurring'] ?? true,
                    'on_invoice' => true,
                    'period_offset_months' => $c['offset'],
                    'is_active' => true,
                    'sort_order' => $c['sort'],
                ]
            );
        }

        AllocationRule::updateOrCreate(
            ['property_id' => $property->id, 'charge_type_id' => $chargeModels['electricity_common']->id],
            [
                'strategy' => 'equal_units',
                'params' => ['unit_types' => ['residential', 'commercial', 'owner_occupied']],
                'is_active' => true,
            ]
        );

        AllocationRule::updateOrCreate(
            ['property_id' => $property->id, 'charge_type_id' => $chargeModels['water']->id],
            [
                'strategy' => 'water_residential_commercial',
                'params' => [
                    'residential_rate' => 16.7,
                    'residential_unit_types' => ['residential', 'owner_occupied'],
                    'commercial_unit_types' => ['commercial'],
                    'residential_count_override' => 2,
                    'divisor_unit_types' => ['residential', 'commercial', 'owner_occupied'],
                ],
                'is_active' => true,
            ]
        );

        AllocationRule::updateOrCreate(
            ['property_id' => $property->id, 'charge_type_id' => $chargeModels['dohs']->id],
            [
                'strategy' => 'fee_tier',
                'params' => [
                    'tiers' => ['full' => 1500, 'half' => 1000, 'none' => 0],
                    'include_garage' => false,
                ],
                'is_active' => true,
            ]
        );

        foreach (['gas', 'caretaker', 'lift'] as $code) {
            AllocationRule::updateOrCreate(
                ['property_id' => $property->id, 'charge_type_id' => $chargeModels[$code]->id],
                [
                    'strategy' => 'per_lease',
                    'params' => [],
                    'is_active' => true,
                ]
            );
        }

        // Assign fixed charges to commercial floor leases
        foreach (['1st', '3rd', '4th', '5th'] as $label) {
            foreach (['gas', 'caretaker', 'lift'] as $code) {
                LeaseChargeAssignment::updateOrCreate(
                    [
                        'lease_id' => $leases[$label]->id,
                        'charge_type_id' => $chargeModels[$code]->id,
                    ],
                    ['amount_override' => null, 'is_active' => true]
                );
            }
        }

        // Meters
        $commonMeters = [
            ['code' => '581', 'name' => 'Stair', 'sort' => 1],
            ['code' => '317', 'name' => 'Water', 'sort' => 2],
            ['code' => '318', 'name' => 'Ground', 'sort' => 3],
            ['code' => '676', 'name' => 'Main', 'sort' => 4],
            ['code' => '059', 'name' => 'New', 'sort' => 5],
        ];
        $commonMeterModels = [];
        foreach ($commonMeters as $m) {
            $commonMeterModels[$m['name']] = Meter::updateOrCreate(
                ['property_id' => $property->id, 'name' => $m['name'], 'kind' => 'common'],
                ['code' => $m['code'], 'is_active' => true, 'sort_order' => $m['sort']]
            );
        }

        $unitMeterDefs = [
            ['unit' => '1st', 'code' => '563', 'name' => '1st Floor'],
            ['unit' => '2nd', 'code' => '223', 'name' => '2nd Floor A'],
            ['unit' => '2nd', 'code' => '327', 'name' => '2nd Floor B'],
            ['unit' => '2nd', 'code' => '670', 'name' => '2nd Floor C'],
            ['unit' => '3rd', 'code' => '237', 'name' => '3rd Floor'],
            ['unit' => '4th', 'code' => '545', 'name' => '4th Floor'],
            ['unit' => '5th', 'code' => '565', 'name' => '5th Floor'],
        ];
        $unitMeterModels = [];
        foreach ($unitMeterDefs as $i => $m) {
            $unitMeterModels[$m['code']] = Meter::updateOrCreate(
                ['property_id' => $property->id, 'code' => $m['code'], 'kind' => 'unit'],
                [
                    'name' => $m['name'],
                    'unit_id' => $unitModels[$m['unit']]->id,
                    'is_active' => true,
                    'sort_order' => $i + 1,
                ]
            );
        }

        // August 2026 billing period with Excel sample inputs
        $period = BillingPeriod::updateOrCreate(
            ['property_id' => $property->id, 'year' => 2026, 'month' => 8],
            [
                'bill_date' => '2026-08-04',
                'status' => 'draft',
                'notes' => 'Seeded from Excel sample August 2026',
            ]
        );

        $commonAmounts = [
            'Stair' => 177,
            'Water' => 1214,
            'Ground' => 901,
            'Main' => 17777,
            'New' => 1312,
        ];
        foreach ($commonAmounts as $name => $amount) {
            PeriodMeterInput::updateOrCreate(
                ['billing_period_id' => $period->id, 'meter_id' => $commonMeterModels[$name]->id],
                ['amount' => $amount, 'service_period' => '2026-07-01']
            );
        }

        $unitAmounts = [
            '563' => 27623,
            '223' => 708,
            '327' => 2522,
            '670' => 275,
            '237' => 67629,
            '545' => 23154,
            '565' => 12339,
        ];
        foreach ($unitAmounts as $code => $amount) {
            PeriodMeterInput::updateOrCreate(
                ['billing_period_id' => $period->id, 'meter_id' => $unitMeterModels[$code]->id],
                ['amount' => $amount, 'service_period' => '2026-07-01']
            );
        }

        PeriodChargeInput::updateOrCreate(
            [
                'billing_period_id' => $period->id,
                'charge_type_id' => $chargeModels['water']->id,
                'lease_id' => null,
            ],
            ['amount' => 4172, 'units' => 142]
        );

        foreach (['gas' => 1080, 'caretaker' => 6000, 'lift' => 1000, 'dohs' => 1500] as $code => $amount) {
            PeriodChargeInput::updateOrCreate(
                [
                    'billing_period_id' => $period->id,
                    'charge_type_id' => $chargeModels[$code]->id,
                    'lease_id' => null,
                ],
                ['amount' => $amount]
            );
        }

        // Zero arrears/other per floor lease
        foreach (['1st', '3rd', '4th', '5th'] as $label) {
            foreach (['arrears', 'other'] as $code) {
                PeriodChargeInput::updateOrCreate(
                    [
                        'billing_period_id' => $period->id,
                        'charge_type_id' => $chargeModels[$code]->id,
                        'lease_id' => $leases[$label]->id,
                    ],
                    ['amount' => 0]
                );
            }
        }

        app(BillingCalculator::class)->generate($period);
    }
}
