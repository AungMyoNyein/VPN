<?php

namespace App\Models;

use App\Enums\ProvisioningOperationStatus;
use App\Enums\ProvisioningOperationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProvisioningOperation extends Model
{
    protected $fillable = [
        'idempotency_key',
        'peer_id',
        'device_id',
        'operation_type',
        'status',
        'attempt_count',
        'last_error',
        'response_payload',
    ];

    protected function casts(): array
    {
        return [
            'operation_type' => ProvisioningOperationType::class,
            'status' => ProvisioningOperationStatus::class,
            'attempt_count' => 'integer',
            'response_payload' => 'array',
        ];
    }

    public function peer(): BelongsTo
    {
        return $this->belongsTo(VpnPeer::class, 'peer_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
