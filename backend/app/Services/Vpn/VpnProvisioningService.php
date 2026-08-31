<?php

namespace App\Services\Vpn;

use App\Enums\PeerStatus;
use App\Enums\ProvisioningOperationStatus;
use App\Enums\ProvisioningOperationType;
use App\Models\AdminUser;
use App\Models\Device;
use App\Models\ProvisioningOperation;
use App\Models\VpnNode;
use App\Models\VpnPeer;
use App\Services\Audit\AuditLogger;
use App\Services\ControlPlane\ControlPlaneClient;
use App\Services\Ipam\IpamService;
use App\Services\Nodes\NodeSelectionService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VpnProvisioningService
{
    public function __construct(
        private readonly VpnProvisioningAuthorizer $authorizer,
        private readonly NodeSelectionService $nodeSelectionService,
        private readonly IpamService $ipamService,
        private readonly ControlPlaneClient $controlPlaneClient,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Public key validator for WireGuard (32-byte base64 encoded string).
     */
    public function isValidPublicKey(string $key): bool
    {
        $trimmed = trim($key);
        if (strlen($trimmed) !== 44) {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9+\/]{42}[AEIMQUYcgkosw048]=$/', $trimmed)
            || (bool) preg_match('/^[A-Za-z0-9+\/]{43}=$/', $trimmed);
    }

    /**
     * Provision a VPN peer for an authenticated device.
     *
     * @param array{location_id?: int|null, client_public_key: string} $payload
     * @return array{ok: bool, code?: string, message?: string, status?: int, data?: array<string, mixed>}
     */
    public function provision(Device $device, array $payload, ?string $idempotencyKey = null): array
    {
        // 1. Authorization & Entitlement check
        $authResult = $this->authorizer->authorize($device);
        if (! $authResult['allowed']) {
            return [
                'ok' => false,
                'code' => $authResult['code'] ?? 'UNAUTHORIZED',
                'message' => 'Device is not entitled to provision VPN access.',
                'status' => 403,
            ];
        }

        // 2. Validate client public key format
        $clientPublicKey = trim($payload['client_public_key'] ?? '');
        if (! $this->isValidPublicKey($clientPublicKey)) {
            return [
                'ok' => false,
                'code' => 'INVALID_PUBLIC_KEY',
                'message' => 'The provided WireGuard public key is invalid.',
                'status' => 422,
            ];
        }

        $locationId = isset($payload['location_id']) && $payload['location_id'] !== null
            ? (int) $payload['location_id']
            : null;

        // 3. Check Idempotency Key
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existingOp = ProvisioningOperation::query()
                ->where('idempotency_key', $idempotencyKey)
                ->where('device_id', $device->id)
                ->first();

            if ($existingOp !== null && $existingOp->status === ProvisioningOperationStatus::Succeeded && $existingOp->response_payload !== null) {
                return [
                    'ok' => true,
                    'data' => $existingOp->response_payload,
                ];
            }
        }

        // 4. One active peer per device invariant: if active peer exists, revoke it first
        $existingActivePeer = VpnPeer::query()
            ->where('device_id', $device->id)
            ->whereIn('status', [PeerStatus::Pending, PeerStatus::Active, PeerStatus::Revoking])
            ->first();

        if ($existingActivePeer !== null) {
            // If it's already active with the identical public key, same node, and no location change requested, return idempotent config
            if ($existingActivePeer->status === PeerStatus::Active
                && $existingActivePeer->public_key === $clientPublicKey
                && ($locationId === null || $existingActivePeer->node?->location_id === $locationId)) {
                $node = $existingActivePeer->node;
                if ($node !== null) {
                    $response = $this->buildTunnelResponse($existingActivePeer, $node);
                    return ['ok' => true, 'data' => $response];
                }
            }

            // Otherwise, revoke the prior peer cleanly before provisioning new one
            $this->revoke($device, $existingActivePeer);
        }

        // 5. Select eligible VPN Node
        $selectedNode = $this->nodeSelectionService->selectNode($locationId, $device->customer);
        if ($selectedNode === null) {
            return [
                'ok' => false,
                'code' => 'NO_VPN_NODE_AVAILABLE',
                'message' => 'No eligible VPN server is available for the requested location.',
                'status' => 503,
            ];
        }

        // 6. Select IP Pool on the node
        $ipPool = $selectedNode->ipPools()->where('active', true)->first();
        if ($ipPool === null) {
            return [
                'ok' => false,
                'code' => 'IP_POOL_EXHAUSTED',
                'message' => 'No active IP pool available on the selected VPN server.',
                'status' => 503,
            ];
        }

        // 7. Transactional DB Setup (Allocate IP, create PENDING peer, create operation)
        $idempKey = $idempotencyKey ?: (string) Str::uuid();
        $peerCode = 'WG-PEER-' . strtoupper(Str::random(12));

        $peer = null;
        $operation = null;
        $allocation = null;

        try {
            [$peer, $allocation, $operation] = DB::transaction(function () use (
                $device,
                $selectedNode,
                $clientPublicKey,
                $ipPool,
                $idempKey,
                $peerCode
            ) {
                $peer = VpnPeer::create([
                    'peer_code' => $peerCode,
                    'device_id' => $device->id,
                    'node_id' => $selectedNode->id,
                    'public_key' => $clientPublicKey,
                    'assigned_ip' => '0.0.0.0', // Updated on allocation
                    'status' => PeerStatus::Pending,
                ]);

                $allocation = $this->ipamService->allocate($ipPool, $device, $peer);

                $peer->update([
                    'assigned_ip' => $allocation->ip_address,
                ]);

                $operation = ProvisioningOperation::create([
                    'idempotency_key' => $idempKey,
                    'peer_id' => $peer->id,
                    'device_id' => $device->id,
                    'operation_type' => ProvisioningOperationType::Provision,
                    'status' => ProvisioningOperationStatus::Running,
                    'attempt_count' => 1,
                ]);

                return [$peer, $allocation, $operation];
            });
        } catch (Exception $e) {
            Log::error('VPN Provisioning DB transaction failed', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);

            if ($e->getMessage() === 'IP_POOL_EXHAUSTED') {
                return [
                    'ok' => false,
                    'code' => 'IP_POOL_EXHAUSTED',
                    'message' => 'VPN IP address capacity exhausted on the server.',
                    'status' => 503,
                ];
            }

            return [
                'ok' => false,
                'code' => 'VPN_PROVISIONING_FAILED',
                'message' => 'Failed to initialize VPN provisioning: ' . $e->getMessage(),
                'status' => 500,
            ];
        }

        // 8. Call Control Plane to Add Peer to the node
        try {
            $this->controlPlaneClient->addPeer(
                (string) $selectedNode->id,
                $peer->peer_code,
                $clientPublicKey,
                $peer->assigned_ip,
                config('vpn.allowed_ips', ['0.0.0.0/0'])
            );

            // Mutation succeeded -> peer becomes ACTIVE
            $peer->update([
                'status' => PeerStatus::Active,
                'provisioned_at' => now(),
            ]);

            $tunnelResponse = $this->buildTunnelResponse($peer, $selectedNode);

            $operation->update([
                'status' => ProvisioningOperationStatus::Succeeded,
                'response_payload' => $tunnelResponse,
            ]);

            $this->auditLogger->log(
                'vpn.provisioned',
                'vpn_peer',
                $peer->id,
                before: null,
                after: [
                    'peer_code' => $peer->peer_code,
                    'node' => $selectedNode->name,
                    'assigned_ip' => $peer->assigned_ip,
                ]
            );

            return [
                'ok' => true,
                'data' => $tunnelResponse,
            ];
        } catch (Exception $e) {
            Log::error('Control plane AddPeer failed', [
                'peer_code' => $peer->peer_code,
                'node_id' => $selectedNode->id,
                'error' => $e->getMessage(),
            ]);

            $peer->update([
                'status' => PeerStatus::Error,
                'last_error' => $e->getMessage(),
            ]);

            $operation->update([
                'status' => ProvisioningOperationStatus::Failed,
                'last_error' => $e->getMessage(),
            ]);

            $this->auditLogger->log(
                'vpn.provisioning_failed',
                'vpn_peer',
                $peer->id,
                before: null,
                after: [
                    'peer_code' => $peer->peer_code,
                    'error' => $e->getMessage(),
                ]
            );

            return [
                'ok' => false,
                'code' => 'VPN_PROVISIONING_FAILED',
                'message' => 'Failed to activate VPN tunnel configuration on node.',
                'status' => 500,
            ];
        }
    }

    /**
     * Revoke a VPN peer for a device.
     *
     * @return array{ok: bool, code?: string, message?: string, data?: array<string, mixed>}
     */
    public function revoke(Device $device, ?VpnPeer $peer = null, ?AdminUser $actor = null, ?string $reason = null): array
    {
        $targetPeer = $peer ?? VpnPeer::query()
            ->where('device_id', $device->id)
            ->whereIn('status', [PeerStatus::Pending, PeerStatus::Active, PeerStatus::Revoking, PeerStatus::Error])
            ->latest()
            ->first();

        if ($targetPeer === null) {
            return [
                'ok' => true,
                'data' => ['revoked' => false, 'message' => 'No active VPN peer found'],
            ];
        }

        $targetPeer->update([
            'status' => PeerStatus::Revoking,
        ]);

        $operation = ProvisioningOperation::create([
            'idempotency_key' => (string) Str::uuid(),
            'peer_id' => $targetPeer->id,
            'device_id' => $device->id,
            'operation_type' => ProvisioningOperationType::Revoke,
            'status' => ProvisioningOperationStatus::Running,
            'attempt_count' => 1,
        ]);

        try {
            $this->controlPlaneClient->removePeer($targetPeer->peer_code);

            $targetPeer->update([
                'status' => PeerStatus::Revoked,
                'revoked_at' => now(),
                'failure_reason' => $reason,
            ]);

            $this->ipamService->releaseForPeer($targetPeer);

            $operation->update([
                'status' => ProvisioningOperationStatus::Succeeded,
            ]);

            $this->auditLogger->log(
                'vpn.revoked',
                'vpn_peer',
                $targetPeer->id,
                before: ['status' => PeerStatus::Revoking->value],
                after: ['status' => PeerStatus::Revoked->value],
                actor: $actor
            );

            return [
                'ok' => true,
                'data' => [
                    'revoked' => true,
                    'peer_id' => $targetPeer->peer_code,
                ],
            ];
        } catch (Exception $e) {
            Log::error('Control plane RemovePeer failed', [
                'peer_code' => $targetPeer->peer_code,
                'error' => $e->getMessage(),
            ]);

            $targetPeer->update([
                'last_error' => $e->getMessage(),
            ]);

            $operation->update([
                'status' => ProvisioningOperationStatus::Failed,
                'last_error' => $e->getMessage(),
            ]);

            // IP remains allocated until reconciliation cleanly confirms removal
            return [
                'ok' => false,
                'code' => 'VPN_REVOCATION_FAILED',
                'message' => 'Failed to remove peer from node: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Build customer-safe tunnel configuration response.
     *
     * @return array<string, mixed>
     */
    public function buildTunnelResponse(VpnPeer $peer, VpnNode $node): array
    {
        return [
            'peer_id' => $peer->peer_code,
            'address' => $peer->assigned_ip . '/32',
            'dns' => config('vpn.dns', ['1.1.1.1', '1.0.0.1']),
            'server' => [
                'id' => $node->id,
                'name' => $node->name,
                'location' => $node->location?->display_name ?? 'Default',
                'endpoint' => $node->public_endpoint . ':' . $node->vpn_port,
                'public_key' => $node->public_key ?? '',
            ],
            'allowed_ips' => config('vpn.allowed_ips', ['0.0.0.0/0']),
            'persistent_keepalive' => (int) config('vpn.persistent_keepalive', 25),
            'mtu' => (int) config('vpn.mtu', 1420),
        ];
    }
}
