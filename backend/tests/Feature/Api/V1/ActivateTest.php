<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ActivationKeyStatus;
use App\Enums\CustomerStatus;
use App\Enums\DeviceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceCredential;
use App\Services\Devices\DeviceService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\ActivationTestCase;

class ActivateTest extends ActivationTestCase
{
    public function test_new_device_activation_succeeds_and_issues_credential(): void
    {
        $plan = $this->createPlan(['max_devices' => 2]);
        $customer = $this->createCustomer();
        $this->createSubscription($customer, $plan);
        $key = $this->createActivationKey($customer, ['max_activations' => 1]);

        $response = $this->postJson('/api/v1/activate', $this->activatePayload($customer, $key['plaintext']));

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'device' => ['id', 'device_uuid', 'status'],
                    'device_credential',
                    'entitlement' => ['max_devices', 'active_devices'],
                ],
                'meta' => ['request_id'],
            ])
            ->assertJsonPath('data.device.status', DeviceStatus::Active->value)
            ->assertJsonPath('data.entitlement.max_devices', 2)
            ->assertJsonPath('data.entitlement.active_devices', 1);

        $this->assertNotEmpty($response->json('data.device_credential'));

        $this->assertDatabaseHas('devices', [
            'customer_id' => $customer->id,
            'status' => DeviceStatus::Active->value,
        ]);

        $this->assertDatabaseCount('device_credentials', 1);

        $key['key']->refresh();
        $this->assertSame(1, $key['key']->activation_count);
        $this->assertSame(ActivationKeyStatus::Used, $key['key']->status);
    }

    public function test_activation_key_never_appears_in_response_or_audit(): void
    {
        $plan = $this->createPlan();
        $customer = $this->createCustomer();
        $this->createSubscription($customer, $plan);
        $key = $this->createActivationKey($customer);

        $response = $this->postJson('/api/v1/activate', $this->activatePayload($customer, $key['plaintext']));
        $response->assertOk();

        $encoded = $response->content();
        $this->assertStringNotContainsString($key['plaintext'], $encoded);

        $log = AuditLog::query()->where('action', 'activation.succeeded')->latest('id')->first();
        $this->assertNotNull($log);
        $logEncoded = json_encode($log->toArray());
        $this->assertStringNotContainsString($key['plaintext'], $logEncoded);
        $this->assertStringNotContainsString($response->json('data.device_credential'), $logEncoded);
    }

    public function test_idempotent_reactivation_of_same_device_does_not_increment_count(): void
    {
        $plan = $this->createPlan(['max_devices' => 2]);
        $customer = $this->createCustomer();
        $this->createSubscription($customer, $plan);
        $key = $this->createActivationKey($customer, ['max_activations' => 5]);
        $payload = $this->activatePayload($customer, $key['plaintext']);

        $first = $this->postJson('/api/v1/activate', $payload)->assertOk();
        $firstToken = $first->json('data.device_credential');
        $firstDeviceId = $first->json('data.device.id');

        $second = $this->postJson('/api/v1/activate', $payload)->assertOk();
        $secondToken = $second->json('data.device_credential');

        $this->assertSame($firstDeviceId, $second->json('data.device.id'));
        $this->assertNotSame($firstToken, $secondToken, 'Re-activation should rotate the credential.');

        $key['key']->refresh();
        $this->assertSame(1, $key['key']->activation_count);

        $this->assertDatabaseCount('devices', 1);
        // Rotation creates a new row and revokes the previous one — never two active at once.
        $this->assertDatabaseCount('device_credentials', 2);
        $this->assertSame(1, DeviceCredential::query()->whereNull('revoked_at')->count());
    }

    public function test_reactivating_revoked_device_creates_new_active_binding_and_increments_count(): void
    {
        $plan = $this->createPlan(['max_devices' => 2]);
        $customer = $this->createCustomer();
        $this->createSubscription($customer, $plan);
        $key = $this->createActivationKey($customer, ['max_activations' => 5]);
        $payload = $this->activatePayload($customer, $key['plaintext']);

        $first = $this->postJson('/api/v1/activate', $payload)->assertOk();
        $deviceId = $first->json('data.device.id');

        app(DeviceService::class)->revoke(Device::query()->findOrFail($deviceId));

        $key['key']->refresh();
        $this->assertSame(1, $key['key']->activation_count);

        $second = $this->postJson('/api/v1/activate', $payload)->assertOk();
        $this->assertSame($deviceId, $second->json('data.device.id'));
        $second->assertJsonPath('data.device.status', DeviceStatus::Active->value);

        $key['key']->refresh();
        $this->assertSame(2, $key['key']->activation_count);
    }

    public function test_reactivating_blocked_device_is_rejected(): void
    {
        $plan = $this->createPlan();
        $customer = $this->createCustomer();
        $this->createSubscription($customer, $plan);
        $key = $this->createActivationKey($customer, ['max_activations' => 5]);
        $payload = $this->activatePayload($customer, $key['plaintext']);

        $first = $this->postJson('/api/v1/activate', $payload)->assertOk();
        $deviceId = $first->json('data.device.id');

        $device = Device::query()->findOrFail($deviceId);
        $device->update(['status' => DeviceStatus::Blocked]);

        $response = $this->postJson('/api/v1/activate', $payload);

        $response->assertStatus(403)->assertJsonPath('error.code', 'DEVICE_BLOCKED');
    }

    public function test_device_limit_reached_for_second_distinct_device(): void
    {
        $plan = $this->createPlan(['max_devices' => 1]);
        $customer = $this->createCustomer();
        $this->createSubscription($customer, $plan);
        $key = $this->createActivationKey($customer, ['max_activations' => 5]);

        $this->postJson('/api/v1/activate', $this->activatePayload($customer, $key['plaintext']))
            ->assertOk();

        $response = $this->postJson('/api/v1/activate', $this->activatePayload($customer, $key['plaintext']));

        $response->assertStatus(403)->assertJsonPath('error.code', 'DEVICE_LIMIT_REACHED');

        $key['key']->refresh();
        $this->assertSame(1, $key['key']->activation_count, 'Failed activation must not consume the key.');
        $this->assertDatabaseCount('devices', 1);
    }

    public function test_unknown_customer_code_returns_generic_activation_invalid(): void
    {
        $plan = $this->createPlan();
        $customer = $this->createCustomer();
        $this->createSubscription($customer, $plan);
        $key = $this->createActivationKey($customer);

        $response = $this->postJson('/api/v1/activate', [
            'customer_code' => 'CUST-DOES-NOT-EXIST',
            'activation_key' => $key['plaintext'],
            'device' => $this->deviceInput(),
        ]);

        $response->assertStatus(401)->assertJsonPath('error.code', 'ACTIVATION_INVALID');
    }

    public function test_wrong_activation_key_returns_generic_activation_invalid(): void
    {
        $plan = $this->createPlan();
        $customer = $this->createCustomer();
        $this->createSubscription($customer, $plan);
        $this->createActivationKey($customer);

        $response = $this->postJson('/api/v1/activate', $this->activatePayload($customer, 'VPN-WRNG-WRNG-WRNG'));

        $response->assertStatus(401)->assertJsonPath('error.code', 'ACTIVATION_INVALID');
    }

    public function test_key_belonging_to_another_customer_returns_generic_activation_invalid(): void
    {
        $plan = $this->createPlan();
        $customerA = $this->createCustomer();
        $customerB = $this->createCustomer();
        $this->createSubscription($customerA, $plan);
        $this->createSubscription($customerB, $plan);
        $keyForB = $this->createActivationKey($customerB);

        $response = $this->postJson('/api/v1/activate', $this->activatePayload($customerA, $keyForB['plaintext']));

        $response->assertStatus(401)->assertJsonPath('error.code', 'ACTIVATION_INVALID');
    }

    public function test_suspended_customer_is_rejected_with_specific_code(): void
    {
        $plan = $this->createPlan();
        $customer = $this->createCustomer(['status' => CustomerStatus::Suspended]);
        $this->createSubscription($customer, $plan);
        $key = $this->createActivationKey($customer);

        $response = $this->postJson('/api/v1/activate', $this->activatePayload($customer, $key['plaintext']));

        $response->assertStatus(403)->assertJsonPath('error.code', 'CUSTOMER_SUSPENDED');
    }

    public function test_blocked_customer_is_rejected_with_specific_code(): void
    {
        $plan = $this->createPlan();
        $customer = $this->createCustomer(['status' => CustomerStatus::Blocked]);
        $this->createSubscription($customer, $plan);
        $key = $this->createActivationKey($customer);

        $response = $this->postJson('/api/v1/activate', $this->activatePayload($customer, $key['plaintext']));

        $response->assertStatus(403)->assertJsonPath('error.code', 'CUSTOMER_BLOCKED');
    }

    public function test_closed_customer_is_rejected_with_specific_code(): void
    {
        $plan = $this->createPlan();
        $customer = $this->createCustomer(['status' => CustomerStatus::Closed]);
        $this->createSubscription($customer, $plan);
        $key = $this->createActivationKey($customer);

        $response = $this->postJson('/api/v1/activate', $this->activatePayload($customer, $key['plaintext']));

        $response->assertStatus(403)->assertJsonPath('error.code', 'CUSTOMER_CLOSED');
    }

    public function test_revoked_key_is_rejected(): void
    {
        $plan = $this->createPlan();
        $customer = $this->createCustomer();
        $this->createSubscription($customer, $plan);
        $key = $this->createActivationKey($customer);
        $key['key']->update(['status' => ActivationKeyStatus::Revoked, 'revoked_at' => now()]);

        $response = $this->postJson('/api/v1/activate', $this->activatePayload($customer, $key['plaintext']));

        $response->assertStatus(403)->assertJsonPath('error.code', 'ACTIVATION_KEY_REVOKED');
    }

    public function test_suspended_key_is_rejected(): void
    {
        $plan = $this->createPlan();
        $customer = $this->createCustomer();
        $this->createSubscription($customer, $plan);
        $key = $this->createActivationKey($customer);
        $key['key']->update(['status' => ActivationKeyStatus::Suspended]);

        $response = $this->postJson('/api/v1/activate', $this->activatePayload($customer, $key['plaintext']));

        $response->assertStatus(403)->assertJsonPath('error.code', 'ACTIVATION_KEY_REVOKED');
    }

    public function test_expired_key_is_rejected_even_if_status_active(): void
    {
        $plan = $this->createPlan();
        $customer = $this->createCustomer();
        $this->createSubscription($customer, $plan);
        $key = $this->createActivationKey($customer, ['expires_at' => now()->subMinute()]);

        $response = $this->postJson('/api/v1/activate', $this->activatePayload($customer, $key['plaintext']));

        $response->assertStatus(403)->assertJsonPath('error.code', 'ACTIVATION_KEY_EXPIRED');
    }

    public function test_exhausted_key_is_rejected_for_a_new_device(): void
    {
        $plan = $this->createPlan(['max_devices' => 5]);
        $customer = $this->createCustomer();
        $this->createSubscription($customer, $plan);
        $key = $this->createActivationKey($customer, ['max_activations' => 1]);

        $this->postJson('/api/v1/activate', $this->activatePayload($customer, $key['plaintext']))
            ->assertOk();

        $response = $this->postJson('/api/v1/activate', $this->activatePayload($customer, $key['plaintext'], [
            'uuid' => (string) Str::uuid(),
        ]));

        $response->assertStatus(403)->assertJsonPath('error.code', 'ACTIVATION_KEY_EXHAUSTED');
    }

    public function test_subscription_required_when_none_exists(): void
    {
        $customer = $this->createCustomer();
        $key = $this->createActivationKey($customer);

        $response = $this->postJson('/api/v1/activate', $this->activatePayload($customer, $key['plaintext']));

        $response->assertStatus(402)->assertJsonPath('error.code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_subscription_expired_is_rejected(): void
    {
        $plan = $this->createPlan();
        $customer = $this->createCustomer();
        $this->createSubscription($customer, $plan, [
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->subDay(),
        ]);
        $key = $this->createActivationKey($customer);

        $response = $this->postJson('/api/v1/activate', $this->activatePayload($customer, $key['plaintext']));

        $response->assertStatus(402)->assertJsonPath('error.code', 'SUBSCRIPTION_EXPIRED');
    }

    public function test_renewal_restores_entitlement_for_activation(): void
    {
        $plan = $this->createPlan();
        $customer = $this->createCustomer();
        $subscription = $this->createSubscription($customer, $plan, [
            'expires_at' => now()->subDay(),
        ]);
        $key = $this->createActivationKey($customer);

        $this->postJson('/api/v1/activate', $this->activatePayload($customer, $key['plaintext']))
            ->assertStatus(402)
            ->assertJsonPath('error.code', 'SUBSCRIPTION_EXPIRED');

        $subscription->update(['expires_at' => now()->addMonth()]);

        $this->postJson('/api/v1/activate', $this->activatePayload($customer, $key['plaintext']))
            ->assertOk()
            ->assertJsonPath('data.device.status', DeviceStatus::Active->value);
    }

    public function test_activation_rate_limit_returns_rate_limited_code(): void
    {
        Config::set('activation.activate_per_minute', 2);

        $payload = [
            'customer_code' => 'CUST-RATE-LIMIT',
            'activation_key' => 'VPN-0000-0000-0000',
            'device' => $this->deviceInput(),
        ];

        $this->postJson('/api/v1/activate', $payload)->assertStatus(401);
        $this->postJson('/api/v1/activate', $payload)->assertStatus(401);

        $response = $this->postJson('/api/v1/activate', $payload);
        $response->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    public function test_validation_error_for_malformed_payload(): void
    {
        $response = $this->postJson('/api/v1/activate', [
            'customer_code' => '',
            'activation_key' => '',
            'device' => ['uuid' => 'not-a-uuid'],
        ]);

        $response->assertStatus(422);
    }
}
