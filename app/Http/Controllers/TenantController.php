<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TenantController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Tenants/Index', [
            'items' => Tenant::where('user_id', $request->user()->id)
                ->withCount('leases')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ]);

        Tenant::create([...$data, 'user_id' => $request->user()->id]);

        return back()->with('success', 'Tenant created.');
    }

    public function update(Request $request, Tenant $tenant)
    {
        abort_unless($tenant->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ]);

        $tenant->update($data);

        return back()->with('success', 'Tenant updated.');
    }

    public function destroy(Request $request, Tenant $tenant)
    {
        abort_unless($tenant->user_id === $request->user()->id, 403);
        $tenant->delete();

        return back()->with('success', 'Tenant deleted.');
    }

    public function enablePortal(Request $request, Tenant $tenant)
    {
        abort_unless($tenant->user_id === $request->user()->id, 403);
        $tenant->enablePortal();

        return back()->with('success', 'Portal link enabled for '.$tenant->name.'.');
    }

    public function rotatePortal(Request $request, Tenant $tenant)
    {
        abort_unless($tenant->user_id === $request->user()->id, 403);
        $tenant->rotatePortalToken();

        return back()->with('success', 'Portal link rotated for '.$tenant->name.'.');
    }

    public function disablePortal(Request $request, Tenant $tenant)
    {
        abort_unless($tenant->user_id === $request->user()->id, 403);
        $tenant->disablePortal();

        return back()->with('success', 'Portal link disabled for '.$tenant->name.'.');
    }
}
