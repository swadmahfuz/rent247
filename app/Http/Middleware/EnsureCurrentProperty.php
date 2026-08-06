<?php

namespace App\Http\Middleware;

use App\Models\Property;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentProperty
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $property = null;
        if ($user->current_property_id) {
            $property = Property::where('user_id', $user->id)
                ->where('id', $user->current_property_id)
                ->first();
        }

        if (! $property) {
            $property = Property::where('user_id', $user->id)->where('is_active', true)->first();
            if ($property && $user->current_property_id !== $property->id) {
                $user->update(['current_property_id' => $property->id]);
            }
        }

        app()->instance('currentProperty', $property);
        $request->attributes->set('currentProperty', $property);

        return $next($request);
    }
}
