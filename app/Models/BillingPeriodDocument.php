<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class BillingPeriodDocument extends Model
{
    protected $fillable = [
        'billing_period_id', 'kind', 'unit_id', 'meter_id',
        'original_name', 'path', 'mime', 'size',
    ];

    protected $appends = ['url', 'label'];

    public function billingPeriod(): BelongsTo
    {
        return $this->belongsTo(BillingPeriod::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function meter(): BelongsTo
    {
        return $this->belongsTo(Meter::class);
    }

    public function meterNumber(): ?string
    {
        return $this->meter?->code ?: $this->meter?->name;
    }

    public function getUrlAttribute(): ?string
    {
        if (! $this->path || ! Storage::disk('public')->exists($this->path)) {
            return null;
        }

        return Storage::disk('public')->url($this->path);
    }

    public function getLabelAttribute(): string
    {
        if ($this->kind === 'water') {
            return 'Water bill (building)';
        }

        if ($this->unit_id) {
            $meter = $this->meterNumber();

            return 'Electricity bill · '.($this->unit?->label ?: 'unit').($meter ? ' · meter '.$meter : '');
        }

        return 'Electricity bill (building)';
    }

    public function absolutePath(): ?string
    {
        if (! $this->path || ! Storage::disk('public')->exists($this->path)) {
            return null;
        }

        return Storage::disk('public')->path($this->path);
    }

    public function deleteFile(): void
    {
        if ($this->path && Storage::disk('public')->exists($this->path)) {
            Storage::disk('public')->delete($this->path);
        }
    }
}
