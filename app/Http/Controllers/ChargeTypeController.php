<?php

namespace App\Http\Controllers;

use App\Models\AllocationRule;
use App\Models\ChargeType;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ChargeTypeController extends Controller
{
    public function index(Request $request)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property, 404);

        return Inertia::render('Charges/Index', [
            'items' => ChargeType::with('allocationRule')
                ->where('property_id', $property->id)
                ->orderBy('sort_order')
                ->get(),
            'strategies' => [
                'equal_units',
                'per_lease',
                'meter_to_unit',
                'water_residential_commercial',
                'fixed_amount',
                'fee_tier',
                'none',
            ],
            'categories' => ['rent', 'utility', 'fixed', 'arrears', 'other'],
        ]);
    }

    public function store(Request $request)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property, 404);

        $data = $request->validate([
            'code' => 'required|string|max:50',
            'label' => 'required|string|max:100',
            'category' => 'required|string|max:50',
            'default_amount' => 'nullable|numeric',
            'is_recurring' => 'boolean',
            'on_invoice' => 'boolean',
            'period_offset_months' => 'nullable|integer',
            'sort_order' => 'nullable|integer',
            'is_active' => 'boolean',
            'strategy' => 'nullable|string|max:50',
            'params' => 'nullable|array',
        ]);

        $charge = ChargeType::create([
            'property_id' => $property->id,
            'code' => $data['code'],
            'label' => $data['label'],
            'category' => $data['category'],
            'default_amount' => $data['default_amount'] ?? null,
            'is_recurring' => $data['is_recurring'] ?? true,
            'on_invoice' => $data['on_invoice'] ?? true,
            'period_offset_months' => $data['period_offset_months'] ?? 0,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);

        if (! empty($data['strategy'])) {
            AllocationRule::create([
                'property_id' => $property->id,
                'charge_type_id' => $charge->id,
                'strategy' => $data['strategy'],
                'params' => $data['params'] ?? [],
                'is_active' => true,
            ]);
        }

        return back()->with('success', 'Charge type created.');
    }

    public function update(Request $request, ChargeType $charge)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property && $charge->property_id === $property->id, 403);

        $data = $request->validate([
            'code' => 'required|string|max:50',
            'label' => 'required|string|max:100',
            'category' => 'required|string|max:50',
            'default_amount' => 'nullable|numeric',
            'is_recurring' => 'boolean',
            'on_invoice' => 'boolean',
            'period_offset_months' => 'nullable|integer',
            'sort_order' => 'nullable|integer',
            'is_active' => 'boolean',
            'strategy' => 'nullable|string|max:50',
            'params' => 'nullable|array',
        ]);

        $charge->update([
            'code' => $data['code'],
            'label' => $data['label'],
            'category' => $data['category'],
            'default_amount' => $data['default_amount'] ?? null,
            'is_recurring' => $data['is_recurring'] ?? $charge->is_recurring,
            'on_invoice' => $data['on_invoice'] ?? $charge->on_invoice,
            'period_offset_months' => $data['period_offset_months'] ?? $charge->period_offset_months,
            'sort_order' => $data['sort_order'] ?? $charge->sort_order,
            'is_active' => $data['is_active'] ?? $charge->is_active,
        ]);

        if (! empty($data['strategy'])) {
            AllocationRule::updateOrCreate(
                ['charge_type_id' => $charge->id],
                [
                    'property_id' => $property->id,
                    'strategy' => $data['strategy'],
                    'params' => $data['params'] ?? [],
                    'is_active' => true,
                ]
            );
        }

        return back()->with('success', 'Charge type updated.');
    }

    public function destroy(Request $request, ChargeType $charge)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property && $charge->property_id === $property->id, 403);
        $charge->allocationRule()->delete();
        $charge->delete();

        return back()->with('success', 'Charge type deleted.');
    }
}
