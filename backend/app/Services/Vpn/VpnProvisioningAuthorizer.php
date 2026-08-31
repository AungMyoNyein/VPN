<?php

namespace App\Services\Vpn;

use App\Enums\CustomerStatus;
use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Services\Subscriptions\EntitlementService;

/**
 * Read-only authorization check consumed by the Phase 3 VPN provisioning
 * flow. Decides whether a device is currently entitled to request a
 * session — performs NO WireGuard peer provisioning and NO IP allocation.
 */
class VpnProvisioningAuthorizer
{
    public function __construct(
        private readonly EntitlementService $entitlementService,
    ) {}

    /**
     * @return array{allowed: bool, code: string|null, entitlement: array<string, mixed>|null}
     */
    public function authorize(Device $device): array
    {
        if ($device->status === DeviceStatus::Revoked) {
            return $this->deny('DEVICE_REVOKED');
        }

        if ($device->status === DeviceStatus::Blocked) {
            return $this->deny('DEVICE_BLOCKED');
        }

        $customer = $device->customer;

        if ($customer === null || $customer->status === CustomerStatus::Suspended) {
            return $this->deny('CUSTOMER_SUSPENDED');
        }

        if ($customer->status !== CustomerStatus::Active) {
            return $this->deny('CUSTOMER_BLOCKED');
        }

        $subscription = $customer->subscriptions()
            ->where('status', 'ACTIVE')
            ->orderByDesc('expires_at')
            ->first();

        if ($subscription === null) {
            return $this->deny('SUBSCRIPTION_REQUIRED');
        }

        if (! $this->entitlementService->isUsable($subscription)) {
            return $this->deny('SUBSCRIPTION_EXPIRED');
        }

        return [
            'allowed' => true,
            'code' => null,
            'entitlement' => [
                'max_devices' => $this->entitlementService->effectiveMaxDevices($subscription),
                'subscription_expires_at' => $subscription->expires_at,
            ],
        ];
    }

    /**
     * @return array{allowed: false, code: string, entitlement: null}
     */
    private function deny(string $code): array
    {
        return ['allowed' => false, 'code' => $code, 'entitlement' => null];
    }
}
