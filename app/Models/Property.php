<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Property extends Model
{
    protected $fillable = [
        'user_id', 'name', 'address', 'owner_display_name',
        'currency', 'timezone', 'settings', 'is_active',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
    ];

    protected $appends = ['signature_url'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class)->orderBy('sort_order');
    }

    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    public function meters(): HasMany
    {
        return $this->hasMany(Meter::class)->orderBy('sort_order');
    }

    public function chargeTypes(): HasMany
    {
        return $this->hasMany(ChargeType::class)->orderBy('sort_order');
    }

    public function allocationRules(): HasMany
    {
        return $this->hasMany(AllocationRule::class);
    }

    public function billingPeriods(): HasMany
    {
        return $this->hasMany(BillingPeriod::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    public function getSignatureUrlAttribute(): ?string
    {
        $path = $this->setting('signature_path');
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
