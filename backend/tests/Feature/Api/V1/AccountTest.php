<?php

namespace Tests\Feature\Api\V1;

use Tests\ActivationTestCase;

class AccountTest extends ActivationTestCase
{
    public function test_account_is_readable_with_device_credential(): void
    {
        $plan = $this->createPlan(['max_devices' => 3]);
        $customer = $this->createCustomer(['name' => 'Jane Doe']);
        $this->createSubscription($customer, $plan);
        $key = $this->createActivationKey($customer);

        $token = $this->postJson('/api/v1/activate', $this->activatePayload($customer, $key['plaintext']))
            ->assertOk()
            ->json('data.device_credential');

        $this->withDeviceCredential($token)
            ->getJson('/api/v1/account')
            ->assertOk()
            ->assertJsonPath('data.account.customer_code', $customer->customer_code)
            ->assertJsonPath('data.account.name', 'Jane Doe')
            ->assertJsonPath('data.entitlement.max_devices', 3)
            ->assertJsonPath('data.entitlement.active_devices', 1);
    }

    public function test_account_still_readable_when_subscription_expired(): void
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
            ->getJson('/api/v1/account')
            ->assertOk();
    }

    public function test_account_requires_device_credential(): void
    {
        $this->getJson('/api/v1/account')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'DEVICE_CREDENTIAL_INVALID');
    }
}
