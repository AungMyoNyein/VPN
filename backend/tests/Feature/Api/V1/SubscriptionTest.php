<?php

namespace Tests\Feature\Api\V1;

use App\Enums\EntitlementState;
use Tests\ActivationTestCase;

class SubscriptionTest extends ActivationTestCase
{
    public function test_subscription_is_readable_when_active(): void
    {
        $plan = $this->createPlan(['max_devices' => 4]);
        $customer = $this->createCustomer();
        $this->createSubscription($customer, $plan);
        $key = $this->createActivationKey($customer);

        $token = $this->postJson('/api/v1/activate', $this->activatePayload($customer, $key['plaintext']))
            ->assertOk()
            ->json('data.device_credential');

        $this->withDeviceCredential($token)
            ->getJson('/api/v1/subscription')
            ->assertOk()
            ->assertJsonPath('data.entitlement_state', EntitlementState::Active->value)
            ->assertJsonPath('data.max_devices', 4);
    }

    public function test_subscription_still_readable_after_expiry_but_marked_expired(): void
    {
        $plan = $this->createPlan();
        $customer = $this->createCustomer();
        $subscription = $this->createSubscription($customer, $plan);
        $key = $this->createActivationKey($customer);

        $token = $this->postJson('/api/v1/activate', $this->activatePayload($customer, $key['plaintext']))
            ->assertOk()
            ->json('data.device_credential');

        $subscription->update(['expires_at' => now()->subDay()]);

        $this->withDeviceCredential($token)
            ->getJson('/api/v1/subscription')
            ->assertOk()
            ->assertJsonPath('data.entitlement_state', EntitlementState::Expired->value)
            ->assertJsonPath('data.subscription.id', $subscription->id);
    }

    public function test_renewal_is_reflected_in_subscription_endpoint(): void
    {
        $plan = $this->createPlan();
        $customer = $this->createCustomer();
        $subscription = $this->createSubscription($customer, $plan, ['expires_at' => now()->subDay()]);

        // Activation is blocked while expired, so bind the device first
        // while active, then simulate expiry -> renewal.
        $subscription->update(['expires_at' => now()->addDay()]);
        $key = $this->createActivationKey($customer);
        $token = $this->postJson('/api/v1/activate', $this->activatePayload($customer, $key['plaintext']))
            ->assertOk()
            ->json('data.device_credential');

        $subscription->update(['expires_at' => now()->subDay()]);

        $this->withDeviceCredential($token)
            ->getJson('/api/v1/subscription')
            ->assertJsonPath('data.entitlement_state', EntitlementState::Expired->value);

        // Renewal (admin/CRM extends expiry) restores entitlement.
        $subscription->update(['expires_at' => now()->addMonth()]);

        $this->withDeviceCredential($token)
            ->getJson('/api/v1/subscription')
            ->assertJsonPath('data.entitlement_state', EntitlementState::Active->value);
    }
}
