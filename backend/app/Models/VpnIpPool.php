<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VpnIpPool extends Model
{
    protected $fillable = [
        'node_id',
        'network',
        'prefix_length',
        'gateway',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'prefix_length' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(VpnNode::class, 'node_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(VpnIpAllocation::class, 'pool_id');
    }
}
