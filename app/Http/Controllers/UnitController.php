<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UnitController extends Controller
{
    public function index(Request $request)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property, 404, 'Select or create a property first.');

        return Inertia::render('Units/Index', [
            'items' => Unit::where('property_id', $property->id)->orderBy('sort_order')->get(),
            'types' => ['residential', 'commercial', 'owner_occupied', 'garage'],
        ]);
    }

    public function store(Request $request)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property, 404);

        $data = $request->validate([
            'label' => 'required|string|max:100',
            'type' => 'required|string|max:50',
            'sort_order' => 'nullable|integer',
            'is_active' => 'boolean',
        ]);

        Unit::create([
            ...$data,
            'property_id' => $property->id,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return back()->with('success', 'Unit created.');
    }

    public function update(Request $request, Unit $unit)
    {
        $this->authorizeUnit($request, $unit);

        $data = $request->validate([
            'label' => 'required|string|max:100',
            'type' => 'required|string|max:50',
            'sort_order' => 'nullable|integer',
            'is_active' => 'boolean',
        ]);

        $unit->update($data);

        return back()->with('success', 'Unit updated.');
    }

    public function destroy(Request $request, Unit $unit)
    {
        $this->authorizeUnit($request, $unit);
        $unit->delete();

        return back()->with('success', 'Unit deleted.');
    }

    private function authorizeUnit(Request $request, Unit $unit): void
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property && $unit->property_id === $property->id, 403);
    }
}
