<?php

namespace Tests\Unit\Services;

use App\Enums\ActivationKeyStatus;
use App\Enums\AdminUserStatus;
use App\Enums\CustomerStatus;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Services\ActivationKeys\ActivationKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ActivationKeyServiceScopingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): AdminUser
    {
        return AdminUser::query()->create([
            'name' => 'Admin',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password123'),
            'status' => AdminUserStatus::Active,
        ]);
    }

    private function customer(): Customer
    {
        return Customer::query()->create([
            'customer_code' => 'CUST-'.fake()->unique()->numerify('######'),
            'name' => 'Scoped Customer',
            'status' => CustomerStatus::Active,
        ]);
    }

    public function test_verify_without_customer_finds_key_regardless_of_owner(): void
    {
        $service = app(ActivationKeyService::class);
        $customer = $this->customer();
        $result = $service->generate($customer, $this->admin(), [], audit: false);

        $found = $service->verify($result['plaintext_key']);

        $this->assertNotNull($found);
        $this->assertSame($result['key']->id, $found->id);
    }

    public function test_verify_scoped_to_customer_rejects_foreign_key(): void
    {
        $service = app(ActivationKeyService::class);
        $owner = $this->customer();
        $stranger = $this->customer();
        $result = $service->generate($owner, $this->admin(), [], audit: false);

        $this->assertNull($service->verify($result['plaintext_key'], $stranger));
        $this->assertNotNull($service->verify($result['plaintext_key'], $owner));
    }

    public function test_is_key_usable_reflects_status_and_expiry(): void
    {
        $service = app(ActivationKeyService::class);
        $customer = $this->customer();

        $active = $service->generate($customer, $this->admin(), [], audit: false)['key'];
        $this->assertTrue($service->isKeyUsable($active));

        $revoked = $service->generate($customer, $this->admin(), [], audit: false)['key'];
        $revoked->update(['status' => ActivationKeyStatus::Revoked]);
        $this->assertFalse($service->isKeyUsable($revoked));

        $suspended = $service->generate($customer, $this->admin(), [], audit: false)['key'];
        $suspended->update(['status' => ActivationKeyStatus::Suspended]);
        $this->assertFalse($service->isKeyUsable($suspended));

        $expiredByStatus = $service->generate($customer, $this->admin(), [], audit: false)['key'];
        $expiredByStatus->update(['status' => ActivationKeyStatus::Expired]);
        $this->assertFalse($service->isKeyUsable($expiredByStatus));

        $expiredByDate = $service->generate($customer, $this->admin(), ['expires_at' => now()->subMinute()], audit: false)['key'];
        $this->assertFalse($service->isKeyUsable($expiredByDate));

        $used = $service->generate($customer, $this->admin(), [], audit: false)['key'];
        $used->update(['status' => ActivationKeyStatus::Used]);
        $this->assertTrue($service->isKeyUsable($used));
    }
}
