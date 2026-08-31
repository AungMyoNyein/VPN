<?php

namespace Tests\Unit\Services;

use App\Enums\CustomerStatus;
use App\Enums\DevicePlatform;
use App\Enums\DeviceStatus;
use App\Models\Customer;
use App\Services\Devices\DeviceCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DeviceCredentialServiceTest extends TestCase
{
    use RefreshDatabase;

    private function device()
    {
        $customer = Customer::query()->create([
            'customer_code' => 'CUST-'.fake()->unique()->numerify('######'),
            'name' => 'Credential Customer',
            'status' => CustomerStatus::Active,
        ]);

        return $customer->devices()->create([
            'device_uuid' => fake()->uuid(),
            'platform' => DevicePlatform::Android,
            'device_name' => 'Pixel',
            'status' => DeviceStatus::Active,
            'activated_at' => now(),
        ]);
    }

    public function test_issue_stores_hash_not_plaintext(): void
    {
        $service = app(DeviceCredentialService::class);
        $device = $this->device();

        $result = $service->issue($device);

        $this->assertNotEmpty($result['plaintext_token']);
        $this->assertNotSame($result['plaintext_token'], $result['credential']->token_hash);
        $this->assertTrue(Hash::check($result['plaintext_token'], $result['credential']->token_hash));
        $this->assertSame(substr($result['plaintext_token'], 0, 8), $result['credential']->token_prefix);
    }

    public function test_issue_revokes_previous_active_credential(): void
    {
        $service = app(DeviceCredentialService::class);
        $device = $this->device();

        $first = $service->issue($device);
        $second = $service->issue($device);

        $this->assertNotNull($first['credential']->fresh()->revoked_at);
        $this->assertNull($second['credential']->fresh()->revoked_at);
        $this->assertNull($service->findValidByPlaintext($first['plaintext_token']));
        $this->assertNotNull($service->findValidByPlaintext($second['plaintext_token']));
    }

    public function test_rotate_is_equivalent_to_issue(): void
    {
        $service = app(DeviceCredentialService::class);
        $device = $this->device();

        $first = $service->issue($device);
        $rotated = $service->rotate($device);

        $this->assertNotSame($first['plaintext_token'], $rotated['plaintext_token']);
        $this->assertNull($service->findValidByPlaintext($first['plaintext_token']));
    }

    public function test_revoke_all_for_device_invalidates_credential(): void
    {
        $service = app(DeviceCredentialService::class);
        $device = $this->device();

        $issued = $service->issue($device);
        $service->revokeAllForDevice($device);

        $this->assertNull($service->findValidByPlaintext($issued['plaintext_token']));
        $this->assertNotNull($service->findByPlaintext($issued['plaintext_token']));
        $this->assertNotNull($service->findByPlaintext($issued['plaintext_token'])->revoked_at);
    }

    public function test_find_by_plaintext_returns_null_for_unknown_token(): void
    {
        $service = app(DeviceCredentialService::class);

        $this->assertNull($service->findByPlaintext('totally-unknown-token'));
        $this->assertNull($service->findByPlaintext(''));
    }

    public function test_expired_credential_is_not_valid(): void
    {
        $service = app(DeviceCredentialService::class);
        $device = $this->device();

        $issued = $service->issue($device);
        $issued['credential']->update(['expires_at' => now()->subMinute()]);

        $this->assertNull($service->findValidByPlaintext($issued['plaintext_token']));
        $this->assertNotNull($service->findByPlaintext($issued['plaintext_token']));
    }
}
