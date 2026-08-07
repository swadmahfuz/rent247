<?php

namespace App\Http\Middleware;

use App\Models\Property;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): string|null
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();
        $properties = [];
        $currentProperty = null;

        if ($user) {
            $properties = Property::where('user_id', $user->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']);

            $currentProperty = $request->attributes->get('currentProperty');
            if (! $currentProperty && $user->current_property_id) {
                $currentProperty = Property::where('user_id', $user->id)
                    ->where('id', $user->current_property_id)
                    ->first();
            }
            if (! $currentProperty) {
                $currentProperty = Property::where('user_id', $user->id)
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->first();
            }
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'importResult' => fn () => $request->session()->get('import_result'),
            'properties' => $properties,
            'currentProperty' => $currentProperty,
            'assetBase' => rtrim((string) (config('app.asset_url') ?: url('/')), '/'),
        ];
    }
}
