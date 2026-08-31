<?php

namespace App\Models;

use App\Enums\NodeHealthStatus;
use App\Enums\NodeLifecycleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VpnNode extends Model
{
    protected $fillable = [
        'location_id',
        'name',
        'hostname',
        'public_endpoint',
        'vpn_port',
        'public_key',
        'capacity_users',
        'health_status',
        'lifecycle_status',
        'maintenance_mode',
        'draining',
        'adapter_type',
        'agent_endpoint',
        'agent_version',
        'wireguard_interface',
        'weight',
        'last_heartbeat_at',
        'last_synced_at',
        'latest_rx_bytes',
        'latest_tx_bytes',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'health_status' => NodeHealthStatus::class,
            'lifecycle_status' => NodeLifecycleStatus::class,
            'maintenance_mode' => 'boolean',
            'draining' => 'boolean',
            'last_heartbeat_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'latest_rx_bytes' => 'integer',
            'latest_tx_bytes' => 'integer',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function ipPools(): HasMany
    {
        return $this->hasMany(VpnIpPool::class, 'node_id');
    }

    public function peers(): HasMany
    {
        return $this->hasMany(VpnPeer::class, 'node_id');
    }

    public function activePeers(): HasMany
    {
        return $this->hasMany(VpnPeer::class, 'node_id')->where('status', 'ACTIVE');
    }

    public function isRemote(): bool
    {
        return strtolower($this->adapter_type ?? 'fake') === 'remote';
    }

    public function isFake(): bool
    {
        return !$this->isRemote();
    }
}
