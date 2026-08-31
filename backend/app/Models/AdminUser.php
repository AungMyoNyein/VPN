<?php

namespace App\Models;

use App\Enums\AdminUserStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'status'])]
#[Hidden(['password'])]
class AdminUser extends Authenticatable
{
    use HasApiTokens;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => AdminUserStatus::class,
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'admin_user_role');
    }

    public function isActive(): bool
    {
        return $this->status === AdminUserStatus::Active;
    }

    public function hasPermission(string $code): bool
    {
        $this->loadMissing('roles.permissions');

        foreach ($this->roles as $role) {
            if ($role->permissions->contains('code', $code)) {
                return true;
            }
        }

        return false;
    }

    public function hasAnyPermission(array $codes): bool
    {
        foreach ($codes as $code) {
            if ($this->hasPermission($code)) {
                return true;
            }
        }

        return false;
    }
}
