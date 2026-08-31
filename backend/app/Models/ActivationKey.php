<?php

namespace App\Models;

use App\Enums\ActivationKeyStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivationKey extends Model
{
    protected $fillable = [
        'customer_id',
        'key_prefix',
        'key_hash',
        'status',
        'max_activations',
        'activation_count',
        'activated_at',
        'expires_at',
        'last_used_at',
        'created_by',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ActivationKeyStatus::class,
            'activated_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }
}
