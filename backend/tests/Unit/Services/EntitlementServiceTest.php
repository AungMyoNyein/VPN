<?php

namespace Tests\Unit\Services;

use App\Enums\EntitlementState;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Subscriptions\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntitlementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_not_started_when_future_start(): void
    {
        $plan = Plan::query()->create([
            'name' => 'Test',
            'code' => 'TEST',
            'price' => 10,
            'currency' => 'USD',
            'duration_days' => 30,
            'max_devices' => 1,
            'active' => true,
        ]);

        $subscription = new Subscription([
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->addWeek(),
            'expires_at' => now()->addMonth(),
        ]);
        $subscription->setRelation('plan', $plan);

        $this->assertSame(
            EntitlementState::NotStarted,
            app(EntitlementService::class)->effectiveState($subscription),
        );
    }
}
