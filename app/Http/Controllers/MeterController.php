<?php

namespace App\Http\Controllers;

use App\Models\Meter;
use App\Models\Unit;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MeterController extends Controller
{
    public function index(Request $request)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property, 404);

        return Inertia::render('Meters/Index', [
            'items' => Meter::with('unit')
                ->where('property_id', $property->id)
                ->orderBy('kind')
                ->orderBy('sort_order')
                ->get(),
            'units' => Unit::where('property_id', $property->id)->orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property, 404);

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'nullable|string|max:50',
            'kind' => 'required|in:common,unit',
            'unit_id' => 'nullable|exists:units,id',
            'sort_order' => 'nullable|integer',
            'is_active' => 'boolean',
        ]);

        Meter::create([
            ...$data,
            'property_id' => $property->id,
            'unit_id' => $data['kind'] === 'unit' ? ($data['unit_id'] ?? null) : null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return back()->with('success', 'Electricity meter created.');
    }

    public function update(Request $request, Meter $meter)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property && $meter->property_id === $property->id, 403);

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'nullable|string|max:50',
            'kind' => 'required|in:common,unit',
            'unit_id' => 'nullable|exists:units,id',
            'sort_order' => 'nullable|integer',
            'is_active' => 'boolean',
        ]);

        $meter->update([
            ...$data,
            'unit_id' => $data['kind'] === 'unit' ? ($data['unit_id'] ?? null) : null,
        ]);

        return back()->with('success', 'Electricity meter updated.');
    }

    public function destroy(Request $request, Meter $meter)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property && $meter->property_id === $property->id, 403);
        $meter->delete();

        return back()->with('success', 'Electricity meter deleted.');
    }
}
