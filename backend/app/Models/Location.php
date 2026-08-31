<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    protected $fillable = [
        'country_code',
        'country_name',
        'city',
        'display_name',
        'active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function vpnNodes(): HasMany
    {
        return $this->hasMany(VpnNode::class);
    }
}
