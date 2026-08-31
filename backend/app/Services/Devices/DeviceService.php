<?php

namespace App\Services\Devices;

use App\Enums\DeviceStatus;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Device;
use App\Services\Audit\AuditLogger;
use App\Services\Subscriptions\EntitlementService;
use App\Services\Vpn\VpnProvisioningService;

class DeviceService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly EntitlementService $entitlementService,
        private readonly DeviceCredentialService $deviceCredentialService,
        private readonly VpnProvisioningService $vpnProvisioningService,
    ) {}

    /**
     * Revokes a device. $actor is null for customer self-service actions
     * (audited as SYSTEM); an AdminUser for admin-initiated revocation.
     */
    public function revoke(Device $device, ?AdminUser $actor = null): Device
    {
        $before = $device->toArray();

        $this->deviceCredentialService->revokeAllForDevice($device);
        $this->vpnProvisioningService->revoke($device, null, $actor, 'DEVICE_REVOKED');

        $device->update([
            'device_token_hash' => null,
            'status' => DeviceStatus::Revoked,
            'revoked_at' => now(),
        ]);

        $this->auditLogger->log(
            'device.revoked',
            'device',
            $device->id,
            before: $before,
            after: $device->fresh()->toArray(),
            actor: $actor,
        );

        return $device->fresh();
    }

    public function block(Device $device, AdminUser $actor): Device
    {
        $before = $device->toArray();

        $this->deviceCredentialService->revokeAllForDevice($device);
        $this->vpnProvisioningService->revoke($device, null, $actor, 'DEVICE_BLOCKED');

        $device->update([
            'device_token_hash' => null,
            'status' => DeviceStatus::Blocked,
        ]);

        $this->auditLogger->log(
            'device.blocked',
            'device',
            $device->id,
            before: $before,
            after: $device->fresh()->toArray(),
            actor: $actor,
        );

        return $device->fresh();
    }

    public function resetBinding(Device $device, AdminUser $actor): Device
    {
        $before = $device->toArray();

        $this->deviceCredentialService->revokeAllForDevice($device);
        $this->vpnProvisioningService->revoke($device, null, $actor, 'DEVICE_BINDING_RESET');

        $device->update([
            'device_token_hash' => null,
            'status' => DeviceStatus::Revoked,
            'revoked_at' => now(),
        ]);

        $this->auditLogger->log(
            'device.binding_reset',
            'device',
            $device->id,
            before: $before,
            after: $device->fresh()->toArray(),
            actor: $actor,
        );

        return $device->fresh();
    }

    /**
     * Only ACTIVE devices consume a device-limit slot. BLOCKED and REVOKED
     * devices never count here — callers (ActivationService,
     * VpnProvisioningAuthorizer) rely on this contract.
     */
    public function activeDeviceCount(Customer $customer): int
    {
        return $customer->devices()
            ->where('status', DeviceStatus::Active)
            ->count();
    }

    /**
     * True if the customer can add another active device under their plan.
     */
    public function canAddDevice(Customer $customer): bool
    {
        $subscription = $customer->subscriptions()
            ->where('status', 'ACTIVE')
            ->orderByDesc('expires_at')
            ->first();

        if ($subscription === null) {
            return false;
        }

        $limit = $this->entitlementService->effectiveMaxDevices($subscription);

        return $this->activeDeviceCount($customer) < $limit;
    }

    public function canRegisterDevice(Customer $customer): bool
    {
        return $this->canAddDevice($customer);
    }
}
