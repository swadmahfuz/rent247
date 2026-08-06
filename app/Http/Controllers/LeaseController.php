<?php

namespace App\Http\Controllers;

use App\Models\ChargeType;
use App\Models\Lease;
use App\Models\LeaseChargeAssignment;
use App\Models\Tenant;
use App\Models\Unit;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LeaseController extends Controller
{
    public function index(Request $request)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property, 404);

        return Inertia::render('Leases/Index', [
            'items' => Lease::with(['unit', 'tenant', 'chargeAssignments.chargeType'])
                ->where('property_id', $property->id)
                ->orderByDesc('is_active')
                ->get(),
            'units' => Unit::where('property_id', $property->id)->orderBy('sort_order')->get(),
            'tenants' => Tenant::where('user_id', $request->user()->id)->orderBy('name')->get(),
            'chargeTypes' => ChargeType::where('property_id', $property->id)
                ->where('category', 'fixed')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property, 404);

        $data = $request->validate([
            'unit_id' => 'required|exists:units,id',
            'tenant_id' => 'required|exists:tenants,id',
            'rent_amount' => 'required|numeric|min:0',
            'rent_label' => 'nullable|string|max:100',
            'invoice_mode' => 'required|in:combined,split',
            'attach_water_bill' => 'boolean',
            'attach_electricity_bill' => 'boolean',
            'fee_tier' => 'nullable|string|max:50',
            'starts_on' => 'nullable|date',
            'ends_on' => 'nullable|date',
            'is_active' => 'boolean',
            'charge_type_ids' => 'nullable|array',
            'charge_type_ids.*' => 'integer|exists:charge_types,id',
        ]);

        abort_unless(Unit::where('id', $data['unit_id'])->where('property_id', $property->id)->exists(), 403);
        abort_unless(Tenant::where('id', $data['tenant_id'])->where('user_id', $request->user()->id)->exists(), 403);

        $lease = Lease::create([
            'property_id' => $property->id,
            'unit_id' => $data['unit_id'],
            'tenant_id' => $data['tenant_id'],
            'rent_amount' => $data['rent_amount'],
            'rent_label' => $data['rent_label'] ?? 'Office Rent',
            'invoice_mode' => $data['invoice_mode'],
            'attach_water_bill' => (bool) ($data['attach_water_bill'] ?? false),
            'attach_electricity_bill' => (bool) ($data['attach_electricity_bill'] ?? false),
            'fee_tier' => $data['fee_tier'] ?? null,
            'starts_on' => $data['starts_on'] ?? null,
            'ends_on' => $data['ends_on'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->syncAssignments($lease, $data['charge_type_ids'] ?? []);

        return back()->with('success', 'Lease created.');
    }

    public function update(Request $request, Lease $lease)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property && $lease->property_id === $property->id, 403);

        $data = $request->validate([
            'unit_id' => 'required|exists:units,id',
            'tenant_id' => 'required|exists:tenants,id',
            'rent_amount' => 'required|numeric|min:0',
            'rent_label' => 'nullable|string|max:100',
            'invoice_mode' => 'required|in:combined,split',
            'attach_water_bill' => 'boolean',
            'attach_electricity_bill' => 'boolean',
            'fee_tier' => 'nullable|string|max:50',
            'starts_on' => 'nullable|date',
            'ends_on' => 'nullable|date',
            'is_active' => 'boolean',
            'charge_type_ids' => 'nullable|array',
            'charge_type_ids.*' => 'integer|exists:charge_types,id',
        ]);

        $lease->update([
            'unit_id' => $data['unit_id'],
            'tenant_id' => $data['tenant_id'],
            'rent_amount' => $data['rent_amount'],
            'rent_label' => $data['rent_label'] ?? $lease->rent_label,
            'invoice_mode' => $data['invoice_mode'],
            'attach_water_bill' => (bool) ($data['attach_water_bill'] ?? false),
            'attach_electricity_bill' => (bool) ($data['attach_electricity_bill'] ?? false),
            'fee_tier' => $data['fee_tier'] ?? null,
            'starts_on' => $data['starts_on'] ?? null,
            'ends_on' => $data['ends_on'] ?? null,
            'is_active' => $data['is_active'] ?? $lease->is_active,
        ]);

        $this->syncAssignments($lease, $data['charge_type_ids'] ?? []);

        return back()->with('success', 'Lease updated.');
    }

    public function destroy(Request $request, Lease $lease)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property && $lease->property_id === $property->id, 403);
        $lease->delete();

        return back()->with('success', 'Lease deleted.');
    }

    private function syncAssignments(Lease $lease, array $chargeTypeIds): void
    {
        $lease->chargeAssignments()->delete();
        foreach ($chargeTypeIds as $id) {
            LeaseChargeAssignment::create([
                'lease_id' => $lease->id,
                'charge_type_id' => $id,
                'is_active' => true,
            ]);
        }
    }
}
