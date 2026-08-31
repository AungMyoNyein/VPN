<?php

namespace Tests\Feature\Admin\V1;

use App\Enums\CustomerStatus;
use App\Enums\DevicePlatform;
use App\Enums\DeviceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Plan;
use App\Services\Devices\DeviceCredentialService;
use App\Services\Devices\DeviceService;
use Database\Seeders\PlanSeeder;
use Tests\AdminTestCase;

class DeviceTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function customerWithSubscription(int $maxDevices = 2): Customer
    {
        $plan = Plan::query()->firstOrFail();
        $plan->update(['max_devices' => $maxDevices]);

        $customer = Customer::query()->create([
            'customer_code' => 'CUST-'.fake()->unique()->numerify('######'),
            'name' => 'Device Customer',
            'status' => CustomerStatus::Active,
        ]);

        $customer->subscriptions()->create([
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
            'source' => 'CRM',
            'auto_renew' => false,
        ]);

        return $customer;
    }

    public function test_revoke_device(): void
    {
        $admin = $this->createAdmin();
        $customer = $this->customerWithSubscription();
        $device = $customer->devices()->create([
            'device_uuid' => fake()->uuid(),
            'platform' => DevicePlatform::Android,
            'device_name' => 'Pixel',
            'status' => DeviceStatus::Active,
            'activated_at' => now(),
        ]);

        $this->withAdmin($admin)->postJson("/api/admin/v1/devices/{$device->id}/revoke")
            ->assertOk()
            ->assertJsonPath('data.device.status', DeviceStatus::Revoked->value);
    }

    public function test_effective_device_limit(): void
    {
        $customer = $this->customerWithSubscription(maxDevices: 2);

        $customer->devices()->create([
            'device_uuid' => fake()->uuid(),
            'platform' => DevicePlatform::Android,
            'device_name' => 'D1',
            'status' => DeviceStatus::Active,
        ]);
        $customer->devices()->create([
            'device_uuid' => fake()->uuid(),
            'platform' => DevicePlatform::Ios,
            'device_name' => 'D2',
            'status' => DeviceStatus::Active,
        ]);

        $service = app(DeviceService::class);
        $this->assertFalse($service->canRegisterDevice($customer));
        $this->assertSame(2, $service->activeDeviceCount($customer));
    }

    public function test_revoked_device_frees_slot(): void
    {
        $admin = $this->createAdmin();
        $customer = $this->customerWithSubscription(maxDevices: 1);

        $device = $customer->devices()->create([
            'device_uuid' => fake()->uuid(),
            'platform' => DevicePlatform::Android,
            'device_name' => 'Only Device',
            'status' => DeviceStatus::Active,
        ]);

        $service = app(DeviceService::class);
        $this->assertFalse($service->canRegisterDevice($customer));

        $service->revoke($device, $admin);

        $this->assertTrue($service->canRegisterDevice($customer));
    }

    public function test_admin_revoke_invalidates_device_credential(): void
    {
        $admin = $this->createAdmin();
        $customer = $this->customerWithSubscription();

        $device = $customer->devices()->create([
            'device_uuid' => fake()->uuid(),
            'platform' => DevicePlatform::Android,
            'device_name' => 'Credentialed Device',
            'status' => DeviceStatus::Active,
            'activated_at' => now(),
        ]);

        $credentialService = app(DeviceCredentialService::class);
        $issued = $credentialService->issue($device);

        $this->assertNotNull($credentialService->findValidByPlaintext($issued['plaintext_token']));

        $this->withAdmin($admin)->postJson("/api/admin/v1/devices/{$device->id}/revoke")
            ->assertOk()
            ->assertJsonPath('data.device.status', DeviceStatus::Revoked->value);

        $this->assertNull($credentialService->findValidByPlaintext($issued['plaintext_token']));
        $this->assertNotNull($issued['credential']->fresh()->revoked_at);
    }

    public function test_admin_block_invalidates_device_credential(): void
    {
        $admin = $this->createAdmin();
        $customer = $this->customerWithSubscription();

        $device = $customer->devices()->create([
            'device_uuid' => fake()->uuid(),
            'platform' => DevicePlatform::Android,
            'device_name' => 'Credentialed Device',
            'status' => DeviceStatus::Active,
            'activated_at' => now(),
        ]);

        $credentialService = app(DeviceCredentialService::class);
        $issued = $credentialService->issue($device);

        $this->withAdmin($admin)->postJson("/api/admin/v1/devices/{$device->id}/block")
            ->assertOk()
            ->assertJsonPath('data.device.status', DeviceStatus::Blocked->value);

        $this->assertNull($credentialService->findValidByPlaintext($issued['plaintext_token']));
    }
}
