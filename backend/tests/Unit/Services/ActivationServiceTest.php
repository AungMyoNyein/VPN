<?php

namespace Tests\Unit\Services;

use App\Enums\ActivationKeyStatus;
use App\Services\Activation\ActivationService;
use Illuminate\Support\Str;
use Tests\ActivationTestCase;

/**
 * Service-level tests for the activation orchestration, including a
 * deterministic same-process proof of device-limit enforcement. A real
 * OS-process concurrency test lives in
 * tests/Feature/Concurrency/DeviceLimitConcurrencyTest.php (pcntl_fork,
 * best-effort, skips gracefully when unavailable).
 */
class ActivationServiceTest extends ActivationTestCase
{
    public function test_device_limit_enforced_across_sequential_activations(): void
    {
        $plan = $this->createPlan(['max_devices' => 1]);
        $customer = $this->createCustomer();
        $this->createSubscription($customer, $plan);
        $key = $this->createActivationKey($customer, ['max_activations' => 5]);

        $service = app(ActivationService::class);

        $first = $service->activate($this->activatePayload($customer, $key['plaintext'], [
            'uuid' => (string) Str::uuid(),
        ]));
        $this->assertTrue($first['ok']);

        // Simulates the "loser" of a race for the same customer's single
        // device slot: by the time this call acquires the customer row
        // lock, the winner has already committed its device.
        $second = $service->activate($this->activatePayload($customer, $key['plaintext'], [
            'uuid' => (string) Str::uuid(),
        ]));

        $this->assertFalse($second['ok']);
        $this->assertSame('DEVICE_LIMIT_REACHED', $second['code']);

        $this->assertDatabaseCount('devices', 1);
    }

    public function test_failed_activation_does_not_increment_key_activation_count(): void
    {
        $plan = $this->createPlan(['max_devices' => 1]);
        $customer = $this->createCustomer();
        $this->createSubscription($customer, $plan);
        $key = $this->createActivationKey($customer, ['max_activations' => 5]);

        $service = app(ActivationService::class);
        $service->activate($this->activatePayload($customer, $key['plaintext'], ['uuid' => (string) Str::uuid()]))['ok'];

        $before = $key['key']->fresh()->activation_count;

        $result = $service->activate($this->activatePayload($customer, $key['plaintext'], ['uuid' => (string) Str::uuid()]));

        $this->assertFalse($result['ok']);
        $this->assertSame($before, $key['key']->fresh()->activation_count);
    }

    public function test_activation_key_status_transitions_to_used_at_limit(): void
    {
        $plan = $this->createPlan(['max_devices' => 5]);
        $customer = $this->createCustomer();
        $this->createSubscription($customer, $plan);
        $key = $this->createActivationKey($customer, ['max_activations' => 2]);

        $service = app(ActivationService::class);

        $service->activate($this->activatePayload($customer, $key['plaintext'], ['uuid' => (string) Str::uuid()]));
        $this->assertSame(ActivationKeyStatus::Active, $key['key']->fresh()->status);

        $service->activate($this->activatePayload($customer, $key['plaintext'], ['uuid' => (string) Str::uuid()]));
        $this->assertSame(ActivationKeyStatus::Used, $key['key']->fresh()->status);
    }
}
