<?php

namespace App\Services\Activation;

use App\Enums\ActivationKeyStatus;
use App\Enums\CustomerStatus;
use App\Enums\DeviceStatus;
use App\Models\ActivationKey;
use App\Models\Customer;
use App\Models\Device;
use App\Services\ActivationKeys\ActivationKeyService;
use App\Services\Audit\AuditLogger;
use App\Services\Devices\DeviceCredentialService;
use App\Services\Devices\DeviceService;
use App\Services\Subscriptions\EntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orchestrates Phase 2 customer activation: Customer ID + Activation Key
 * -> device binding -> device credential issuance.
 *
 * NO WireGuard / peer provisioning / IP allocation happens here — that is
 * Phase 3 (see VpnProvisioningAuthorizer).
 *
 * Anti-enumeration: an unknown customer_code and a wrong/foreign
 * activation key both return the generic ACTIVATION_INVALID code so a
 * caller cannot distinguish "customer does not exist" from "wrong key".
 * Only once the customer is found AND the key is verified to belong to
 * that customer do we reveal specific account/key state codes
 * (CUSTOMER_SUSPENDED, ACTIVATION_KEY_EXPIRED, ...).
 *
 * Device limit accounting: only ACTIVE devices consume a slot. BLOCKED and
 * REVOKED devices never count against activeDeviceCount() (see
 * DeviceService::activeDeviceCount) — this method relies on that contract.
 */
class ActivationService
{
    public function __construct(
        private readonly ActivationKeyService $activationKeyService,
        private readonly DeviceService $deviceService,
        private readonly DeviceCredentialService $deviceCredentialService,
        private readonly EntitlementService $entitlementService,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{customer_code: string, activation_key: string, device: array<string, mixed>}  $payload
     * @return array{ok: bool, code?: string, message?: string, status?: int, data?: array<string, mixed>}
     */
    public function activate(array $payload): array
    {
        $customerCode = Str::upper(trim($payload['customer_code']));
        $plaintextKey = $payload['activation_key'];
        $deviceInput = $payload['device'];

        return DB::transaction(function () use ($customerCode, $plaintextKey, $deviceInput) {
            // Row lock serializes concurrent activations for the same
            // customer, which is what makes device-limit enforcement race-safe.
            $customer = Customer::query()
                ->where('customer_code', $customerCode)
                ->lockForUpdate()
                ->first();

            if ($customer === null) {
                return $this->fail('ACTIVATION_INVALID', 'Customer ID or activation key is invalid.', 401);
            }

            $key = $this->activationKeyService->verify($plaintextKey, $customer);

            if ($key === null) {
                return $this->fail('ACTIVATION_INVALID', 'Customer ID or activation key is invalid.', 401);
            }

            // Customer + key ownership is now proven — safe to reveal specific state.
            if ($customer->status === CustomerStatus::Suspended) {
                return $this->fail('CUSTOMER_SUSPENDED', 'Customer account is suspended.', 403);
            }

            if ($customer->status === CustomerStatus::Blocked) {
                return $this->fail('CUSTOMER_BLOCKED', 'Customer account is blocked.', 403);
            }

            if ($customer->status === CustomerStatus::Closed) {
                return $this->fail('CUSTOMER_CLOSED', 'Customer account is closed.', 403);
            }

            if (in_array($key->status, [ActivationKeyStatus::Revoked, ActivationKeyStatus::Suspended], true)) {
                return $this->fail('ACTIVATION_KEY_REVOKED', 'Activation key has been revoked or suspended.', 403);
            }

            if ($key->status === ActivationKeyStatus::Expired
                || ($key->expires_at !== null && $key->expires_at->lte(now()))) {
                return $this->fail('ACTIVATION_KEY_EXPIRED', 'Activation key has expired.', 403);
            }

            $deviceUuid = (string) $deviceInput['uuid'];

            $existingDevice = Device::query()
                ->where('customer_id', $customer->id)
                ->where('device_uuid', $deviceUuid)
                ->first();

            $isIdempotentSameActiveDevice = $existingDevice !== null && $existingDevice->status === DeviceStatus::Active;

            // A USED key at its activation limit may still be used for an
            // idempotent re-activation of the same already-active device.
            if ($key->status === ActivationKeyStatus::Used
                && $key->activation_count >= $key->max_activations
                && ! $isIdempotentSameActiveDevice) {
                return $this->fail('ACTIVATION_KEY_EXHAUSTED', 'Activation key has reached its activation limit.', 403);
            }

            $subscription = $customer->subscriptions()
                ->where('status', 'ACTIVE')
                ->orderByDesc('expires_at')
                ->first();

            if ($subscription === null) {
                return $this->fail('SUBSCRIPTION_REQUIRED', 'No subscription found for this customer.', 402);
            }

            if (! $this->entitlementService->isUsable($subscription)) {
                return $this->fail('SUBSCRIPTION_EXPIRED', 'Subscription has expired.', 402);
            }

            $maxDevices = $this->entitlementService->effectiveMaxDevices($subscription);

            $deviceAttributes = [
                'platform' => $deviceInput['platform'],
                'device_name' => $deviceInput['name'],
                'os_version' => $deviceInput['os_version'] ?? null,
                'app_version' => $deviceInput['app_version'] ?? null,
            ];

            if ($isIdempotentSameActiveDevice) {
                // Idempotent: refresh metadata, rotate credential, do NOT
                // touch activation_count.
                $existingDevice->update(array_merge($deviceAttributes, [
                    'last_seen_at' => now(),
                ]));
                $device = $existingDevice->fresh();
            } elseif ($existingDevice !== null && $existingDevice->status === DeviceStatus::Blocked) {
                return $this->fail('DEVICE_BLOCKED', 'This device has been blocked by an administrator.', 403);
            } elseif ($existingDevice !== null) {
                // REVOKED — allow re-activation as a new ACTIVE binding if
                // still under the device limit. Counts as a new binding, so
                // the key activation_count is incremented.
                if ($this->deviceService->activeDeviceCount($customer) >= $maxDevices) {
                    return $this->fail('DEVICE_LIMIT_REACHED', 'Maximum number of devices reached for this plan.', 403);
                }

                $existingDevice->update(array_merge($deviceAttributes, [
                    'status' => DeviceStatus::Active,
                    'activated_at' => now(),
                    'last_seen_at' => now(),
                    'revoked_at' => null,
                ]));
                $device = $existingDevice->fresh();
                $this->consumeActivation($key);
            } else {
                if ($this->deviceService->activeDeviceCount($customer) >= $maxDevices) {
                    return $this->fail('DEVICE_LIMIT_REACHED', 'Maximum number of devices reached for this plan.', 403);
                }

                $device = Device::query()->create(array_merge($deviceAttributes, [
                    'customer_id' => $customer->id,
                    'device_uuid' => $deviceUuid,
                    'status' => DeviceStatus::Active,
                    'activated_at' => now(),
                    'last_seen_at' => now(),
                ]));
                $this->consumeActivation($key);
            }

            $issued = $this->deviceCredentialService->issue($device);

            $this->auditLogger->log(
                'activation.succeeded',
                'device',
                $device->id,
                after: [
                    'customer_id' => $customer->id,
                    'customer_code' => $customer->customer_code,
                    'device_uuid' => $device->device_uuid,
                    'activation_key_id' => $key->id,
                    'activation_key_prefix' => $key->key_prefix,
                ],
            );

            return [
                'ok' => true,
                'data' => [
                    'device' => $device,
                    'device_credential' => $issued['plaintext_token'],
                    'credential_expires_at' => $issued['credential']->expires_at,
                    'entitlement' => [
                        'max_devices' => $maxDevices,
                        'active_devices' => $this->deviceService->activeDeviceCount($customer),
                        'subscription_expires_at' => $subscription->expires_at,
                    ],
                ],
            ];
        });
    }

    private function consumeActivation(ActivationKey $key): void
    {
        $newCount = $key->activation_count + 1;

        $key->update([
            'activation_count' => $newCount,
            'activated_at' => $key->activated_at ?? now(),
            'last_used_at' => now(),
            'status' => $newCount >= $key->max_activations ? ActivationKeyStatus::Used : $key->status,
        ]);
    }

    /**
     * @return array{ok: false, code: string, message: string, status: int}
     */
    private function fail(string $code, string $message, int $status): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message, 'status' => $status];
    }
}
