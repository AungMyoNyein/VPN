<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'customer_code',
        'name',
        'phone',
        'email',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => CustomerStatus::class,
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function activationKeys(): HasMany
    {
        return $this->hasMany(ActivationKey::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
