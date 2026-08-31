<?php

namespace Tests\Feature\Admin\V1;

use App\Enums\EntitlementState;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Plan;
use App\Services\Subscriptions\EntitlementService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\PlanSeeder;
use Tests\AdminTestCase;

class SubscriptionTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_entitlement_active_and_usable(): void
    {
        $plan = Plan::query()->where('code', 'STARTER_30')->firstOrFail();
        $customer = Customer::query()->create([
            'customer_code' => 'CUST-000001',
            'name' => 'Sub Customer',
            'status' => \App\Enums\CustomerStatus::Active,
        ]);

        $subscription = $customer->subscriptions()->create([
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDays(20),
            'source' => 'CRM',
            'auto_renew' => false,
        ]);

        $service = app(EntitlementService::class);

        $this->assertSame(EntitlementState::Active, $service->effectiveState($subscription));
        $this->assertTrue($service->isUsable($subscription));
        $this->assertSame($plan->max_devices, $service->effectiveMaxDevices($subscription));
    }

    public function test_entitlement_expired(): void
    {
        $plan = Plan::query()->firstOrFail();
        $subscription = $plan->subscriptions()->create([
            'customer_id' => Customer::query()->create([
                'customer_code' => 'CUST-000002',
                'name' => 'Expired',
                'status' => \App\Enums\CustomerStatus::Active,
            ])->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDays(60),
            'expires_at' => now()->subDay(),
            'source' => 'CRM',
            'auto_renew' => false,
        ]);

        $service = app(EntitlementService::class);

        $this->assertSame(EntitlementState::Expired, $service->effectiveState($subscription));
        $this->assertFalse($service->isUsable($subscription));
    }

    public function test_custom_max_devices_override(): void
    {
        $plan = Plan::query()->firstOrFail();
        $subscription = $plan->subscriptions()->make([
            'customer_id' => 1,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
            'source' => 'CRM',
            'auto_renew' => false,
            'custom_max_devices' => 10,
        ]);

        $this->assertSame(10, app(EntitlementService::class)->effectiveMaxDevices($subscription));
    }

    public function test_renew_extend_mode(): void
    {
        $admin = $this->createAdmin();
        $plan = Plan::query()->where('code', 'STARTER_30')->firstOrFail();
        $customer = Customer::query()->create([
            'customer_code' => 'CUST-000003',
            'name' => 'Renew Customer',
            'status' => \App\Enums\CustomerStatus::Active,
        ]);

        $expiresAt = now()->addDays(5);
        $subscription = $customer->subscriptions()->create([
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDays(25),
            'expires_at' => $expiresAt,
            'source' => 'CRM',
            'auto_renew' => false,
        ]);

        $renewed = app(SubscriptionService::class)->renew($subscription, ['mode' => 'extend'], $admin);

        $this->assertTrue($renewed->expires_at->greaterThan($expiresAt));
    }

    public function test_renew_from_now_mode(): void
    {
        $admin = $this->createAdmin();
        $plan = Plan::query()->firstOrFail();
        $customer = Customer::query()->create([
            'customer_code' => 'CUST-000004',
            'name' => 'From Now',
            'status' => \App\Enums\CustomerStatus::Active,
        ]);

        $subscription = $customer->subscriptions()->create([
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Expired,
            'starts_at' => now()->subDays(60),
            'expires_at' => now()->subDays(30),
            'source' => 'CRM',
            'auto_renew' => false,
        ]);

        $renewed = app(SubscriptionService::class)->renew($subscription, ['mode' => 'from_now'], $admin);

        $this->assertTrue($renewed->starts_at->greaterThan(now()->subMinute()));
        $this->assertTrue($renewed->expires_at->greaterThan(now()->addDays($plan->duration_days - 1)));
    }

    public function test_customer_renew_endpoint(): void
    {
        $admin = $this->createAdmin();
        $plan = Plan::query()->firstOrFail();

        $customerId = $this->withAdmin($admin)->postJson('/api/admin/v1/customers', [
            'name' => 'API Renew',
            'plan_id' => $plan->id,
        ])->json('data.customer.id');

        $this->withAdmin($admin)->postJson("/api/admin/v1/customers/{$customerId}/renew", [
            'mode' => 'extend',
        ])->assertOk();
    }
}
