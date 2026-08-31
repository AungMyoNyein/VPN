<?php

namespace App\Models;

use App\Enums\PeerStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VpnPeer extends Model
{
    protected $fillable = [
        'peer_code',
        'device_id',
        'node_id',
        'public_key',
        'assigned_ip',
        'status',
        'failure_reason',
        'last_error',
        'provisioned_at',
        'revoked_at',
        'latest_handshake_at',
        'rx_bytes',
        'tx_bytes',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PeerStatus::class,
            'provisioned_at' => 'datetime',
            'revoked_at' => 'datetime',
            'latest_handshake_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'rx_bytes' => 'integer',
            'tx_bytes' => 'integer',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(VpnNode::class, 'node_id');
    }

    public function allocation(): HasOne
    {
        return $this->hasOne(VpnIpAllocation::class, 'vpn_peer_id');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(ProvisioningOperation::class, 'peer_id');
    }

    public function isActive(): bool
    {
        return $this->status === PeerStatus::Active;
    }

    public function isRecentlyActive(int $minutes = 3): bool
    {
        if (!$this->latest_handshake_at) {
            return false;
        }
        return $this->latest_handshake_at->isAfter(Carbon::now()->subMinutes($minutes));
    }
}
