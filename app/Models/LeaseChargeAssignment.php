<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaseChargeAssignment extends Model
{
    protected $fillable = [
        'lease_id', 'charge_type_id', 'amount_override', 'is_active',
    ];

    protected $casts = [
        'amount_override' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function chargeType(): BelongsTo
    {
        return $this->belongsTo(ChargeType::class);
    }
}
