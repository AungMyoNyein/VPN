<?php

namespace Tests\Feature\Admin\V1;

use App\Enums\AdminUserStatus;
use App\Models\AdminUser;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\AdminTestCase;

class AuthTest extends AdminTestCase
{
    public function test_login_with_valid_credentials(): void
    {
        $admin = $this->createAdmin();

        $response = $this->postJson('/api/admin/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['token', 'admin'], 'meta' => ['request_id']])
            ->assertJsonPath('data.admin.email', $admin->email);
    }

    public function test_login_with_invalid_credentials(): void
    {
        $admin = $this->createAdmin();

        $response = $this->postJson('/api/admin/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'wrong-password',
        ]);

        $response->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_disabled_admin_cannot_login(): void
    {
        $admin = $this->createAdmin(overrides: ['status' => AdminUserStatus::Disabled]);

        $response = $this->postJson('/api/admin/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'password123',
        ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_me_returns_admin_and_permissions(): void
    {
        $admin = $this->createAdmin();

        $response = $this->withAdmin($admin)->getJson('/api/admin/v1/auth/me');

        $response->assertOk()
            ->assertJsonStructure(['data' => ['admin', 'permissions']]);
    }

    public function test_logout_revokes_token(): void
    {
        $admin = $this->createAdmin();
        $token = $this->adminToken($admin);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/admin/v1/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_seeder_admin_can_login(): void
    {
        $this->seed(AdminUserSeeder::class);

        $response = $this->postJson('/api/admin/v1/auth/login', [
            'email' => env('ADMIN_EMAIL', 'admin@vpn.local'),
            'password' => env('ADMIN_PASSWORD', 'ChangeMe_LocalOnly_1!'),
        ]);

        $response->assertOk();
    }
}
