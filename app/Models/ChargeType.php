<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChargeType extends Model
{
    protected $fillable = [
        'property_id', 'code', 'label', 'category', 'default_amount',
        'is_recurring', 'on_invoice', 'period_offset_months', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'default_amount' => 'decimal:2',
        'is_recurring' => 'boolean',
        'on_invoice' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function allocationRule(): HasOne
    {
        return $this->hasOne(AllocationRule::class);
    }
}
