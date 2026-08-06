<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AllocationRule extends Model
{
    protected $fillable = [
        'property_id', 'charge_type_id', 'strategy', 'params', 'is_active',
    ];

    protected $casts = [
        'params' => 'array',
        'is_active' => 'boolean',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function chargeType(): BelongsTo
    {
        return $this->belongsTo(ChargeType::class);
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return data_get($this->params, $key, $default);
    }
}
