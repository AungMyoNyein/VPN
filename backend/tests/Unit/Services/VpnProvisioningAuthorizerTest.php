<?php

namespace Tests\Unit\Services;

use App\Enums\CustomerStatus;
use App\Enums\DevicePlatform;
use App\Enums\DeviceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Plan;
use App\Services\Vpn\VpnProvisioningAuthorizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VpnProvisioningAuthorizerTest extends TestCase
{
    use RefreshDatabase;

    private function plan(): Plan
    {
        return Plan::query()->create([
            'name' => 'Test',
            'code' => 'TEST_'.fake()->unique()->numerify('#####'),
            'price' => 9.99,
            'currency' => 'USD',
            'duration_days' => 30,
            'max_devices' => 2,
            'active' => true,
        ]);
    }

    private function customer(array $overrides = []): Customer
    {
        return Customer::query()->create(array_merge([
            'customer_code' => 'CUST-'.fake()->unique()->numerify('######'),
            'name' => 'Authorizer Customer',
            'status' => CustomerStatus::Active,
        ], $overrides));
    }

    private function device(Customer $customer, array $overrides = [])
    {
        return $customer->devices()->create(array_merge([
            'device_uuid' => fake()->uuid(),
            'platform' => DevicePlatform::Android,
            'device_name' => 'Pixel',
            'status' => DeviceStatus::Active,
            'activated_at' => now(),
        ], $overrides));
    }

    public function test_allows_when_customer_and_subscription_usable(): void
    {
        $customer = $this->customer();
        $customer->subscriptions()->create([
            'plan_id' => $this->plan()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
            'source' => 'CRM',
        ]);
        $device = $this->device($customer);

        $result = app(VpnProvisioningAuthorizer::class)->authorize($device);

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['code']);
        $this->assertNotNull($result['entitlement']);
    }

    public function test_denies_revoked_device(): void
    {
        $customer = $this->customer();
        $device = $this->device($customer, ['status' => DeviceStatus::Revoked]);

        $result = app(VpnProvisioningAuthorizer::class)->authorize($device);

        $this->assertFalse($result['allowed']);
        $this->assertSame('DEVICE_REVOKED', $result['code']);
    }

    public function test_denies_blocked_device(): void
    {
        $customer = $this->customer();
        $device = $this->device($customer, ['status' => DeviceStatus::Blocked]);

        $result = app(VpnProvisioningAuthorizer::class)->authorize($device);

        $this->assertFalse($result['allowed']);
        $this->assertSame('DEVICE_BLOCKED', $result['code']);
    }

    public function test_denies_suspended_customer(): void
    {
        $customer = $this->customer(['status' => CustomerStatus::Suspended]);
        $device = $this->device($customer);

        $result = app(VpnProvisioningAuthorizer::class)->authorize($device);

        $this->assertFalse($result['allowed']);
        $this->assertSame('CUSTOMER_SUSPENDED', $result['code']);
    }

    public function test_denies_when_no_subscription(): void
    {
        $customer = $this->customer();
        $device = $this->device($customer);

        $result = app(VpnProvisioningAuthorizer::class)->authorize($device);

        $this->assertFalse($result['allowed']);
        $this->assertSame('SUBSCRIPTION_REQUIRED', $result['code']);
    }

    public function test_denies_when_subscription_expired(): void
    {
        $customer = $this->customer();
        $customer->subscriptions()->create([
            'plan_id' => $this->plan()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->subDay(),
            'source' => 'CRM',
        ]);
        $device = $this->device($customer);

        $result = app(VpnProvisioningAuthorizer::class)->authorize($device);

        $this->assertFalse($result['allowed']);
        $this->assertSame('SUBSCRIPTION_EXPIRED', $result['code']);
    }
}
