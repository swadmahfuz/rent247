<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = [
        'user_id', 'name', 'email', 'phone', 'notes',
        'portal_token', 'portal_enabled',
    ];

    protected $casts = [
        'portal_enabled' => 'boolean',
    ];

    protected $appends = ['portal_url'];

    /**
     * Parse the stored email field into a unique list of addresses.
     * Multiple addresses may be stored comma-separated.
     *
     * @return list<string>
     */
    public function emailAddresses(): array
    {
        return self::parseEmailList($this->email);
    }

    /**
     * @return list<string>
     */
    public static function parseEmailList(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return collect(explode(',', $value))
            ->map(fn (string $email) => trim($email))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public static function normalizeEmailList(?string $value): ?string
    {
        $emails = self::parseEmailList($value);

        return $emails === [] ? null : implode(', ', $emails);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    public function getPortalUrlAttribute(): ?string
    {
        if (! $this->portal_enabled || ! $this->portal_token) {
            return null;
        }

        return url('/portal/'.$this->portal_token);
    }

    /**
     * Ensure the tenant has an active portal link and return it.
     */
    public function ensurePortalUrl(): string
    {
        if (! $this->portal_enabled || ! $this->portal_token) {
            $this->enablePortal();
            $this->refresh();
        }

        return (string) $this->portal_url;
    }

    public function enablePortal(): void
    {
        $this->forceFill([
            'portal_enabled' => true,
            'portal_token' => $this->portal_token ?: bin2hex(random_bytes(32)),
        ])->save();
    }

    public function rotatePortalToken(): void
    {
        $this->forceFill([
            'portal_enabled' => true,
            'portal_token' => bin2hex(random_bytes(32)),
        ])->save();
    }

    public function disablePortal(): void
    {
        $this->forceFill([
            'portal_enabled' => false,
        ])->save();
    }
}
