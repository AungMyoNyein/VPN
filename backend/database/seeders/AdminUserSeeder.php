<?php

namespace Database\Seeders;

use App\Enums\AdminUserStatus;
use App\Models\AdminUser;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@vpn.local');
        $password = env('ADMIN_PASSWORD', 'ChangeMe_LocalOnly_1!');

        $admin = AdminUser::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Super Admin',
                'password' => Hash::make($password),
                'status' => AdminUserStatus::Active,
            ],
        );

        $superRole = Role::query()->where('code', 'SUPER_ADMIN')->first();

        if ($superRole !== null) {
            $admin->roles()->syncWithoutDetaching([$superRole->id]);
        }
    }
}
