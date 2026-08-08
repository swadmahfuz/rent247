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
        $data = $this->validatedTenant($request);
        $data['email'] = Tenant::normalizeEmailList($data['email'] ?? null);

        Tenant::create([...$data, 'user_id' => $request->user()->id]);

        return back()->with('success', 'Tenant created.');
    }

    public function update(Request $request, Tenant $tenant)
    {
        abort_unless($tenant->user_id === $request->user()->id, 403);

        $data = $this->validatedTenant($request);
        $data['email'] = Tenant::normalizeEmailList($data['email'] ?? null);

        $tenant->update($data);

        return back()->with('success', 'Tenant updated.');
    }

    /**
     * @return array{name: string, email?: string|null, phone?: string|null, notes?: string|null}
     */
    private function validatedTenant(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'nullable',
                'string',
                'max:500',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    foreach (Tenant::parseEmailList(is_string($value) ? $value : null) as $email) {
                        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $fail('Enter one or more valid emails separated by commas.');

                            return;
                        }
                    }
                },
            ],
            'phone' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ]);
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
