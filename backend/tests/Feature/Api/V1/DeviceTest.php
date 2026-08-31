<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CustomerStatus;
use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Services\Devices\DeviceService;
use Tests\ActivationTestCase;

class DeviceTest extends ActivationTestCase
{
    private function activatedDevice(): array
    {
        $plan = $this->createPlan(['max_devices' => 2]);
        $customer = $this->createCustomer();
        $this->createSubscription($customer, $plan);
        $key = $this->createActivationKey($customer, ['max_activations' => 5]);

        $response = $this->postJson('/api/v1/activate', $this->activatePayload($customer, $key['plaintext']))
            ->assertOk();

        return [
            'customer' => $customer,
            'device' => Device::query()->findOrFail($response->json('data.device.id')),
            'token' => $response->json('data.device_credential'),
        ];
    }

    public function test_show_returns_current_device_and_entitlement(): void
    {
        $ctx = $this->activatedDevice();

        $this->withDeviceCredential($ctx['token'])
            ->getJson('/api/v1/device')
            ->assertOk()
            ->assertJsonPath('data.device.id', $ctx['device']->id)
            ->assertJsonPath('data.entitlement.max_devices', 2)
            ->assertJsonPath('data.entitlement.active_devices', 1);
    }

    public function test_missing_bearer_token_is_rejected(): void
    {
        $this->getJson('/api/v1/device')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'DEVICE_CREDENTIAL_INVALID');
    }

    public function test_garbage_bearer_token_is_rejected(): void
    {
        $this->withDeviceCredential('not-a-real-token')
            ->getJson('/api/v1/device')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'DEVICE_CREDENTIAL_INVALID');
    }

    public function test_refresh_rotates_credential_and_invalidates_old_token(): void
    {
        $ctx = $this->activatedDevice();

        $refreshResponse = $this->withDeviceCredential($ctx['token'])
            ->postJson('/api/v1/device/refresh')
            ->assertOk();

        $newToken = $refreshResponse->json('data.device_credential');
        $this->assertNotEmpty($newToken);
        $this->assertNotSame($ctx['token'], $newToken);

        $this->withDeviceCredential($ctx['token'])
            ->getJson('/api/v1/device')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'DEVICE_CREDENTIAL_REVOKED');

        $this->withDeviceCredential($newToken)
            ->getJson('/api/v1/device')
            ->assertOk();
    }

    public function test_deactivate_revokes_device_and_credential(): void
    {
        $ctx = $this->activatedDevice();

        $this->withDeviceCredential($ctx['token'])
            ->postJson('/api/v1/device/deactivate')
            ->assertOk();

        $this->assertSame(DeviceStatus::Revoked, $ctx['device']->fresh()->status);

        $this->withDeviceCredential($ctx['token'])
            ->getJson('/api/v1/device')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'DEVICE_CREDENTIAL_REVOKED');
    }

    public function test_deactivate_frees_device_limit_slot(): void
    {
        $ctx = $this->activatedDevice();

        $this->withDeviceCredential($ctx['token'])
            ->postJson('/api/v1/device/deactivate')
            ->assertOk();

        $this->assertSame(0, app(DeviceService::class)->activeDeviceCount($ctx['customer']->fresh()));
    }

    public function test_device_blocked_out_of_band_is_rejected_by_middleware(): void
    {
        $ctx = $this->activatedDevice();
        $ctx['device']->update(['status' => DeviceStatus::Blocked]);

        $this->withDeviceCredential($ctx['token'])
            ->getJson('/api/v1/device')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'DEVICE_BLOCKED');
    }

    public function test_device_revoked_out_of_band_is_rejected_by_middleware(): void
    {
        $ctx = $this->activatedDevice();
        $ctx['device']->update(['status' => DeviceStatus::Revoked]);

        $this->withDeviceCredential($ctx['token'])
            ->getJson('/api/v1/device')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'DEVICE_REVOKED');
    }

    public function test_customer_suspended_denies_device_endpoints(): void
    {
        $ctx = $this->activatedDevice();
        $ctx['customer']->update(['status' => CustomerStatus::Suspended]);

        $this->withDeviceCredential($ctx['token'])
            ->getJson('/api/v1/device')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CUSTOMER_SUSPENDED');
    }

    public function test_customer_blocked_denies_device_endpoints(): void
    {
        $ctx = $this->activatedDevice();
        $ctx['customer']->update(['status' => CustomerStatus::Blocked]);

        $this->withDeviceCredential($ctx['token'])
            ->getJson('/api/v1/device')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CUSTOMER_BLOCKED');
    }
}
