<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lease extends Model
{
    protected $fillable = [
        'property_id', 'unit_id', 'tenant_id', 'rent_amount', 'rent_label',
        'invoice_mode', 'attach_water_bill', 'attach_electricity_bill',
        'fee_tier', 'starts_on', 'ends_on', 'is_active',
    ];

    protected $casts = [
        'rent_amount' => 'decimal:2',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'is_active' => 'boolean',
        'attach_water_bill' => 'boolean',
        'attach_electricity_bill' => 'boolean',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function chargeAssignments(): HasMany
    {
        return $this->hasMany(LeaseChargeAssignment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
