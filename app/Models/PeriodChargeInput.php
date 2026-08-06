<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PeriodChargeInput extends Model
{
    protected $fillable = [
        'billing_period_id', 'charge_type_id', 'lease_id', 'amount', 'units', 'meta',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'units' => 'decimal:4',
        'meta' => 'array',
    ];

    public function billingPeriod(): BelongsTo
    {
        return $this->belongsTo(BillingPeriod::class);
    }

    public function chargeType(): BelongsTo
    {
        return $this->belongsTo(ChargeType::class);
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }
}
