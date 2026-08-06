<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'property_id', 'billing_period_id', 'lease_id', 'number',
        'status', 'total_amount', 'paid_amount', 'issued_at', 'pdf_path',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'issued_at' => 'datetime',
    ];

    protected $appends = ['balance', 'is_fully_paid'];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function billingPeriod(): BelongsTo
    {
        return $this->belongsTo(BillingPeriod::class);
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('sort_order');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function getBalanceAttribute(): float
    {
        return round((float) $this->total_amount - (float) $this->paid_amount, 2);
    }

    public function getIsFullyPaidAttribute(): bool
    {
        return $this->balance <= 0.009;
    }

    public function refreshPaymentStatus(): void
    {
        $paid = (float) $this->payments()->sum('amount');
        $this->paid_amount = $paid;

        if ($this->status === 'void' || $this->status === 'draft') {
            $this->save();
            return;
        }

        if ($paid <= 0) {
            $this->status = 'issued';
        } elseif ($paid + 0.009 >= (float) $this->total_amount) {
            $this->status = 'paid';
        } else {
            $this->status = 'partial';
        }

        $this->save();
    }
}
