<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Services\SignatureImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class PropertyController extends Controller
{
    public function index(Request $request)
    {
        $properties = Property::where('user_id', $request->user()->id)
            ->withCount(['units', 'leases', 'billingPeriods'])
            ->orderBy('name')
            ->get();

        return Inertia::render('Properties/Index', [
            'items' => $properties,
            'currentProperty' => $request->attributes->get('currentProperty'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:500',
            'owner_display_name' => 'nullable|string|max:255',
            'currency' => 'nullable|string|max:10',
        ]);

        $property = Property::create([
            ...$data,
            'user_id' => $request->user()->id,
            'currency' => $data['currency'] ?? 'BDT',
            'timezone' => 'Asia/Dhaka',
            'settings' => [
                'invoice_title' => 'House Rent and Utility Bill',
                'auto_carry_arrears' => true,
            ],
            'is_active' => true,
        ]);

        if (! $request->user()->current_property_id) {
            $request->user()->update(['current_property_id' => $property->id]);
        }

        return redirect()->route('properties.index')->with('success', 'Property created.');
    }

    public function update(Request $request, Property $property)
    {
        $this->authorizeProperty($request, $property);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:500',
            'owner_display_name' => 'nullable|string|max:255',
            'currency' => 'nullable|string|max:10',
            'is_active' => 'boolean',
            'auto_carry_arrears' => 'nullable|boolean',
        ]);

        $settings = $property->settings ?? [];
        if (array_key_exists('auto_carry_arrears', $data)) {
            $settings['auto_carry_arrears'] = (bool) $data['auto_carry_arrears'];
            unset($data['auto_carry_arrears']);
        }

        $property->update([
            ...$data,
            'settings' => $settings,
        ]);

        return back()->with('success', 'Property updated.');
    }

    public function uploadSignature(Request $request, Property $property, SignatureImage $signatureImage)
    {
        $this->authorizeProperty($request, $property);

        $request->validate([
            'signature' => 'required|image|mimes:png,jpg,jpeg,webp|max:2048',
        ]);

        $old = $property->setting('signature_path');
        if ($old && Storage::disk('public')->exists($old)) {
            Storage::disk('public')->delete($old);
        }

        $path = $request->file('signature')->store('signatures/'.$property->id, 'public');
        $signatureImage->normalize(Storage::disk('public')->path($path));

        $settings = $property->settings ?? [];
        $settings['signature_path'] = $path;
        $property->update(['settings' => $settings]);

        return back()->with('success', 'Owner signature saved.');
    }

    public function clearSignature(Request $request, Property $property)
    {
        $this->authorizeProperty($request, $property);

        $old = $property->setting('signature_path');
        if ($old && Storage::disk('public')->exists($old)) {
            Storage::disk('public')->delete($old);
        }

        $settings = $property->settings ?? [];
        unset($settings['signature_path']);
        $property->update(['settings' => $settings]);

        return back()->with('success', 'Owner signature removed.');
    }

    public function switch(Request $request, Property $property)
    {
        $this->authorizeProperty($request, $property);
        $request->user()->update(['current_property_id' => $property->id]);

        return back()->with('success', 'Switched to '.$property->name);
    }

    private function authorizeProperty(Request $request, Property $property): void
    {
        abort_unless($property->user_id === $request->user()->id, 403);
    }
}
