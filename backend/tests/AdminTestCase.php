<?php

namespace Tests;

use App\Models\AdminUser;
use App\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

abstract class AdminTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function createAdmin(string $roleCode = 'SUPER_ADMIN', array $overrides = []): AdminUser
    {
        $admin = AdminUser::query()->create(array_merge([
            'name' => 'Test Admin',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password123'),
            'status' => \App\Enums\AdminUserStatus::Active,
        ], $overrides));

        $role = Role::query()->where('code', $roleCode)->firstOrFail();
        $admin->roles()->attach($role);

        return $admin->fresh()->load('roles.permissions');
    }

    protected function adminToken(AdminUser $admin): string
    {
        return $admin->createToken('test')->plainTextToken;
    }

    protected function withAdmin(AdminUser $admin): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->adminToken($admin));
    }
}
