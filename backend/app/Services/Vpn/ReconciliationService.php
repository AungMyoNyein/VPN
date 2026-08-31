<?php

namespace App\Services\Vpn;

use App\Enums\PeerStatus;
use App\Enums\ProvisioningOperationStatus;
use App\Enums\ProvisioningOperationType;
use App\Models\ProvisioningOperation;
use App\Models\VpnPeer;
use App\Services\ControlPlane\ControlPlaneClient;
use App\Services\Ipam\IpamService;
use Exception;
use Illuminate\Support\Facades\Log;

class ReconciliationService
{
    public function __construct(
        private readonly ControlPlaneClient $controlPlaneClient,
        private readonly IpamService $ipamService,
        private readonly VpnProvisioningService $provisioningService,
    ) {}

    /**
     * Run reconciliation pass across unfinalized operations and peers.
     *
     * @return array{reconciled_provisions: int, reconciled_revocations: int, errors: int}
     */
    public function reconcile(): array
    {
        $maxAttempts = (int) config('vpn.reconciliation.max_attempts', 5);
        $batchSize = (int) config('vpn.reconciliation.batch_size', 50);

        $stats = [
            'reconciled_provisions' => 0,
            'reconciled_revocations' => 0,
            'errors' => 0,
        ];

        // 1. Reconcile provisioning operations
        $provisionOps = ProvisioningOperation::query()
            ->where('operation_type', ProvisioningOperationType::Provision)
            ->whereIn('status', [ProvisioningOperationStatus::Pending, ProvisioningOperationStatus::Running, ProvisioningOperationStatus::Failed])
            ->where('attempt_count', '<', $maxAttempts)
            ->with(['peer.node.location', 'device'])
            ->limit($batchSize)
            ->get();

        foreach ($provisionOps as $op) {
            $peer = $op->peer;
            if ($peer === null || $peer->node === null) {
                continue;
            }

            try {
                $cpPeer = $this->controlPlaneClient->getPeer($peer->peer_code);
                if ($cpPeer !== null) {
                    // Control plane already has this peer
                    $peer->update([
                        'status' => PeerStatus::Active,
                        'provisioned_at' => $peer->provisioned_at ?? now(),
                        'last_error' => null,
                    ]);

                    $op->update([
                        'status' => ProvisioningOperationStatus::Succeeded,
                        'response_payload' => $this->provisioningService->buildTunnelResponse($peer, $peer->node),
                        'last_error' => null,
                    ]);

                    $stats['reconciled_provisions']++;
                    continue;
                }

                // Peer missing on control plane — attempt retry
                $op->increment('attempt_count');

                $this->controlPlaneClient->addPeer(
                    (string) $peer->node_id,
                    $peer->peer_code,
                    $peer->public_key,
                    $peer->assigned_ip,
                    config('vpn.allowed_ips', ['0.0.0.0/0'])
                );

                $peer->update([
                    'status' => PeerStatus::Active,
                    'provisioned_at' => now(),
                    'last_error' => null,
                ]);

                $op->update([
                    'status' => ProvisioningOperationStatus::Succeeded,
                    'response_payload' => $this->provisioningService->buildTunnelResponse($peer, $peer->node),
                    'last_error' => null,
                ]);

                $stats['reconciled_provisions']++;
            } catch (Exception $e) {
                $stats['errors']++;
                Log::warning('Reconciliation failed for provision op', ['op_id' => $op->id, 'error' => $e->getMessage()]);

                $op->update([
                    'last_error' => $e->getMessage(),
                    'status' => $op->attempt_count >= $maxAttempts ? ProvisioningOperationStatus::Failed : $op->status,
                ]);

                if ($op->attempt_count >= $maxAttempts) {
                    $peer->update([
                        'status' => PeerStatus::Error,
                        'last_error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // 2. Reconcile revocation operations & revoking peers
        $revokeOps = ProvisioningOperation::query()
            ->where('operation_type', ProvisioningOperationType::Revoke)
            ->whereIn('status', [ProvisioningOperationStatus::Pending, ProvisioningOperationStatus::Running, ProvisioningOperationStatus::Failed])
            ->where('attempt_count', '<', $maxAttempts)
            ->with(['peer', 'device'])
            ->limit($batchSize)
            ->get();

        foreach ($revokeOps as $op) {
            $peer = $op->peer;
            if ($peer === null) {
                $op->update(['status' => ProvisioningOperationStatus::Succeeded]);
                continue;
            }

            try {
                $cpPeer = $this->controlPlaneClient->getPeer($peer->peer_code);
                if ($cpPeer === null) {
                    // Node confirms peer is gone
                    $peer->update([
                        'status' => PeerStatus::Revoked,
                        'revoked_at' => $peer->revoked_at ?? now(),
                    ]);
                    $this->ipamService->releaseForPeer($peer);
                    $op->update(['status' => ProvisioningOperationStatus::Succeeded]);
                    $stats['reconciled_revocations']++;
                    continue;
                }

                // Node still has peer — retry remove
                $op->increment('attempt_count');
                $this->controlPlaneClient->removePeer($peer->peer_code);

                $peer->update([
                    'status' => PeerStatus::Revoked,
                    'revoked_at' => now(),
                ]);
                $this->ipamService->releaseForPeer($peer);
                $op->update(['status' => ProvisioningOperationStatus::Succeeded]);
                $stats['reconciled_revocations']++;
            } catch (Exception $e) {
                $stats['errors']++;
                Log::warning('Reconciliation failed for revoke op', ['op_id' => $op->id, 'error' => $e->getMessage()]);
                $op->update([
                    'last_error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * Synchronize runtime WireGuard telemetry (handshake, rx/tx counters) from control plane into database.
     *
     * @return array{nodes_synced: int, peers_synced: int}
     */
    public function syncTelemetry(): array
    {
        $nodes = \App\Models\VpnNode::query()->where('lifecycle_status', \App\Enums\NodeLifecycleStatus::Active)->get();
        $syncedNodes = 0;
        $syncedPeers = 0;

        foreach ($nodes as $node) {
            $cpNode = $this->controlPlaneClient->getNode((string) $node->id);
            if ($cpNode !== null) {
                $node->update([
                    'health_status' => $cpNode['health_status'] ?? $node->health_status,
                    'last_heartbeat_at' => isset($cpNode['last_heartbeat_at']) ? \Carbon\Carbon::parse($cpNode['last_heartbeat_at']) : now(),
                    'last_synced_at' => now(),
                ]);
                $syncedNodes++;
            }

            $cpPeers = $this->controlPlaneClient->listPeers((string) $node->id);
            if (!empty($cpPeers)) {
                $peerMap = [];
                foreach ($cpPeers as $p) {
                    if (isset($p['peer_id'])) {
                        $peerMap[$p['peer_id']] = $p;
                    }
                }

                $dbPeers = VpnPeer::query()
                    ->where('node_id', $node->id)
                    ->whereIn('peer_code', array_keys($peerMap))
                    ->get();

                foreach ($dbPeers as $dbPeer) {
                    $pData = $peerMap[$dbPeer->peer_code] ?? null;
                    if ($pData) {
                        $handshake = isset($pData['latest_handshake_at']) && !str_starts_with($pData['latest_handshake_at'], '0001')
                            ? \Carbon\Carbon::parse($pData['latest_handshake_at'])
                            : $dbPeer->latest_handshake_at;

                        $dbPeer->update([
                            'latest_handshake_at' => $handshake,
                            'rx_bytes' => $pData['rx_bytes'] ?? $dbPeer->rx_bytes,
                            'tx_bytes' => $pData['tx_bytes'] ?? $dbPeer->tx_bytes,
                            'last_synced_at' => now(),
                        ]);
                        $syncedPeers++;
                    }
                }
            }
        }

        return [
            'nodes_synced' => $syncedNodes,
            'peers_synced' => $syncedPeers,
        ];
    }
}
