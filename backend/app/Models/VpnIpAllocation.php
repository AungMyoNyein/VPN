<?php

namespace App\Models;

use App\Enums\IpAllocationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VpnIpAllocation extends Model
{
    protected $fillable = [
        'pool_id',
        'device_id',
        'vpn_peer_id',
        'ip_address',
        'status',
        'allocated_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => IpAllocationStatus::class,
            'allocated_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(VpnIpPool::class, 'pool_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function peer(): BelongsTo
    {
        return $this->belongsTo(VpnPeer::class, 'vpn_peer_id');
    }
}
