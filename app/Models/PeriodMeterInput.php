<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PeriodMeterInput extends Model
{
    protected $fillable = [
        'billing_period_id', 'meter_id', 'amount', 'service_period',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'service_period' => 'date',
    ];

    public function billingPeriod(): BelongsTo
    {
        return $this->belongsTo(BillingPeriod::class);
    }

    public function meter(): BelongsTo
    {
        return $this->belongsTo(Meter::class);
    }
}
