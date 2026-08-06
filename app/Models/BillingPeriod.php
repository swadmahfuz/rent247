<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class BillingPeriod extends Model
{
    protected $fillable = [
        'property_id', 'year', 'month', 'bill_date', 'status', 'notes',
    ];

    protected $casts = [
        'bill_date' => 'date',
    ];

    protected $appends = ['label', 'period_key'];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function meterInputs(): HasMany
    {
        return $this->hasMany(PeriodMeterInput::class);
    }

    public function chargeInputs(): HasMany
    {
        return $this->hasMany(PeriodChargeInput::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(BillingPeriodDocument::class);
    }

    public function getLabelAttribute(): string
    {
        return Carbon::createFromDate($this->year, $this->month, 1)->format('F Y');
    }

    public function getPeriodKeyAttribute(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }
}
