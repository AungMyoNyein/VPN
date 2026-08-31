<?php

namespace App\Services\Subscriptions;

use App\Enums\PeerStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\VpnPeer;
use App\Services\Audit\AuditLogger;
use App\Services\Vpn\VpnProvisioningService;
use Illuminate\Support\Facades\Log;

class SubscriptionExpiryService
{
    public function __construct(
        private readonly EntitlementService $entitlementService,
        private readonly VpnProvisioningService $vpnProvisioningService,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Process expired subscriptions and revoke active VPN peers for unentitled devices.
     *
     * @return array{expired_subscriptions: int, revoked_peers: int}
     */
    public function processExpired(): array
    {
        $stats = [
            'expired_subscriptions' => 0,
            'revoked_peers' => 0,
        ];

        // 1. Mark expired subscriptions
        $expiredSubs = Subscription::query()
            ->where('status', 'ACTIVE')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($expiredSubs as $sub) {
            $sub->update(['status' => SubscriptionStatus::Expired]);
            $stats['expired_subscriptions']++;

            $this->auditLogger->log(
                'subscription.expired',
                'subscription',
                $sub->id,
                before: ['status' => 'ACTIVE'],
                after: ['status' => SubscriptionStatus::Expired->value]
            );
        }

        // 2. Find active VPN peers where the device's customer has no usable active subscription
        $activePeers = VpnPeer::query()
            ->whereIn('status', [PeerStatus::Active, PeerStatus::Pending])
            ->with(['device.customer.subscriptions'])
            ->get();

        foreach ($activePeers as $peer) {
            $device = $peer->device;
            if ($device === null || $device->customer === null) {
                continue;
            }

            $customer = $device->customer;
            $activeSub = $customer->subscriptions
                ->where('status', 'ACTIVE')
                ->where('expires_at', '>', now())
                ->first();

            if ($activeSub === null) {
                Log::info('Revoking VPN peer due to subscription expiry', [
                    'peer_id' => $peer->peer_code,
                    'device_id' => $device->id,
                    'customer_id' => $customer->id,
                ]);

                $this->vpnProvisioningService->revoke($device, $peer, null, 'SUBSCRIPTION_EXPIRED');
                $stats['revoked_peers']++;
            }
        }

        return $stats;
    }
}
