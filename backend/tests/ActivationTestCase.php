<?php

namespace Tests;

use App\Enums\AdminUserStatus;
use App\Enums\CustomerStatus;
use App\Enums\SubscriptionStatus;
use App\Models\ActivationKey;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\ActivationKeys\ActivationKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

/**
 * Shared fixtures for Phase 2 activation / device-authorization tests.
 */
abstract class ActivationTestCase extends TestCase
{
    use RefreshDatabase;

    protected function createPlan(array $overrides = []): Plan
    {
        return Plan::query()->create(array_merge([
            'name' => 'Standard',
            'code' => 'STD_'.fake()->unique()->numerify('#####'),
            'description' => 'Test plan',
            'price' => 19.99,
            'currency' => 'USD',
            'duration_days' => 30,
            'max_devices' => 2,
            'speed_limit_mbps' => 100,
            'traffic_limit_bytes' => null,
            'active' => true,
        ], $overrides));
    }

    protected function createCustomer(array $overrides = []): Customer
    {
        return Customer::query()->create(array_merge([
            'customer_code' => 'CUST-'.fake()->unique()->numerify('######'),
            'name' => 'Test Customer',
            'status' => CustomerStatus::Active,
        ], $overrides));
    }

    protected function createSubscription(Customer $customer, ?Plan $plan = null, array $overrides = []): Subscription
    {
        $plan ??= $this->createPlan();

        return $customer->subscriptions()->create(array_merge([
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
            'source' => 'CRM',
            'auto_renew' => false,
        ], $overrides));
    }

    protected function systemAdmin(): AdminUser
    {
        return AdminUser::query()->firstOrCreate(
            ['email' => 'phase2-fixture-admin@example.test'],
            [
                'name' => 'Fixture Admin',
                'password' => Hash::make('password123'),
                'status' => AdminUserStatus::Active,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{key: ActivationKey, plaintext: string}
     */
    protected function createActivationKey(Customer $customer, array $options = []): array
    {
        $result = app(ActivationKeyService::class)->generate($customer, $this->systemAdmin(), $options, audit: false);

        return ['key' => $result['key'], 'plaintext' => $result['plaintext_key']];
    }

    /**
     * @return array<string, mixed>
     */
    protected function deviceInput(array $overrides = []): array
    {
        return array_merge([
            'uuid' => fake()->uuid(),
            'platform' => 'ANDROID',
            'name' => 'Pixel 8',
            'os_version' => '14',
            'app_version' => '1.0.0',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    protected function activatePayload(Customer $customer, string $activationKey, array $deviceOverrides = []): array
    {
        return [
            'customer_code' => $customer->customer_code,
            'activation_key' => $activationKey,
            'device' => $this->deviceInput($deviceOverrides),
        ];
    }

    protected function withDeviceCredential(string $token): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    protected function createLocation(array $overrides = []): \App\Models\Location
    {
        return \App\Models\Location::query()->create(array_merge([
            'country_code' => 'SG',
            'country_name' => 'Singapore',
            'city' => 'Singapore',
            'display_name' => 'Singapore',
            'active' => true,
            'sort_order' => 1,
        ], $overrides));
    }

    protected function createVpnNode(\App\Models\Location $location, array $overrides = []): \App\Models\VpnNode
    {
        return \App\Models\VpnNode::query()->create(array_merge([
            'location_id' => $location->id,
            'name' => 'Singapore Test Node',
            'hostname' => 'sg-test-'.fake()->unique()->numerify('###').'.vpn.local',
            'public_endpoint' => 'sg-test.vpn.local',
            'vpn_port' => 51820,
            'public_key' => 'SG0111111111111111111111111111111111111111=',
            'capacity_users' => 100,
            'health_status' => \App\Enums\NodeHealthStatus::Healthy,
            'lifecycle_status' => \App\Enums\NodeLifecycleStatus::Active,
            'maintenance_mode' => false,
            'draining' => false,
            'weight' => 100,
            'notes' => 'Test node',
        ], $overrides));
    }

    protected function createIpPool(\App\Models\VpnNode $node, array $overrides = []): \App\Models\VpnIpPool
    {
        return \App\Models\VpnIpPool::query()->create(array_merge([
            'node_id' => $node->id,
            'network' => '10.200.20.0/24',
            'prefix_length' => 24,
            'gateway' => '10.200.20.1',
            'active' => true,
        ], $overrides));
    }
}
