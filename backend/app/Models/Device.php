<?php

namespace App\Models;

use App\Enums\DevicePlatform;
use App\Enums\DeviceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Device extends Model
{
    protected $fillable = [
        'customer_id',
        'device_uuid',
        'platform',
        'device_name',
        'os_version',
        'app_version',
        'device_token_hash',
        'status',
        'activated_at',
        'last_seen_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'platform' => DevicePlatform::class,
            'status' => DeviceStatus::class,
            'activated_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(DeviceCredential::class);
    }

    /**
     * Most recently issued, not-yet-revoked credential (at most one per
     * device — see device_credentials_one_active_per_device index).
     */
    public function activeCredential(): HasOne
    {
        return $this->hasOne(DeviceCredential::class)->whereNull('revoked_at')->latestOfMany();
    }

    public function vpnPeers(): HasMany
    {
        return $this->hasMany(VpnPeer::class);
    }

    public function activePeer(): HasOne
    {
        return $this->hasOne(VpnPeer::class)->whereIn('status', ['PENDING', 'ACTIVE', 'REVOKING'])->latestOfMany();
    }

    public function isActive(): bool
    {
        return $this->status === DeviceStatus::Active;
    }
}
