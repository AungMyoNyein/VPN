<?php

namespace App\Services\Vpn;

use App\Enums\PeerStatus;
use App\Enums\ProvisioningOperationStatus;
use App\Enums\ProvisioningOperationType;
use App\Enums\VpnProtocol;
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
     * @param  array{location_id?: int|null, protocol?: string|null, client_public_key?: string|null, client_uuid?: string|null}  $payload
     * @return array{ok: bool, code?: string, message?: string, status?: int, data?: array<string, mixed>}
     */
    public function provision(Device $device, array $payload, ?string $idempotencyKey = null): array
    {
        $authResult = $this->authorizer->authorize($device);
        if (! $authResult['allowed']) {
            return [
                'ok' => false,
                'code' => $authResult['code'] ?? 'UNAUTHORIZED',
                'message' => 'Device is not entitled to provision VPN access.',
                'status' => 403,
            ];
        }

        $protocol = $this->resolveProtocol($payload['protocol'] ?? null);
        $locationId = isset($payload['location_id']) && $payload['location_id'] !== null
            ? (int) $payload['location_id']
            : null;

        $identity = $this->resolveClientIdentity($protocol, $payload);
        if (! $identity['ok']) {
            return $identity;
        }

        $clientIdentity = $identity['identity'];

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existingOp = ProvisioningOperation::query()
                ->where('idempotency_key', $idempotencyKey)
                ->where('device_id', $device->id)
                ->first();

            if ($existingOp !== null
                && $existingOp->status === ProvisioningOperationStatus::Succeeded
                && $existingOp->response_payload !== null
                && $this->idempotencyPayloadMatchesRequest($existingOp->response_payload, $locationId, $protocol)) {
                return [
                    'ok' => true,
                    'data' => $existingOp->response_payload,
                ];
            }
        }

        $existingActivePeer = VpnPeer::query()
            ->where('device_id', $device->id)
            ->whereIn('status', [PeerStatus::Pending, PeerStatus::Active, PeerStatus::Revoking])
            ->first();

        if ($existingActivePeer !== null) {
            $sameIdentity = $protocol === VpnProtocol::Wireguard
                ? $existingActivePeer->public_key === $clientIdentity
                : ($existingActivePeer->client_identity ?? $existingActivePeer->public_key) === $clientIdentity;

            if ($existingActivePeer->status === PeerStatus::Active
                && $sameIdentity
                && $existingActivePeer->protocol() === $protocol
                && ($locationId === null || $existingActivePeer->node?->location_id === $locationId)) {
                $node = $existingActivePeer->node;
                if ($node !== null) {
                    return [
                        'ok' => true,
                        'data' => $this->buildTunnelResponse($existingActivePeer, $node),
                    ];
                }
            }

            $this->revoke($device, $existingActivePeer);
        }

        $selectedNode = $this->nodeSelectionService->selectNode($locationId, $device->customer, $protocol);
        if ($selectedNode === null) {
            return [
                'ok' => false,
                'code' => 'NO_VPN_NODE_AVAILABLE',
                'message' => 'No eligible VPN server is available for the requested location and protocol.',
                'status' => 503,
            ];
        }

        $ipPool = null;
        if ($protocol->requiresIpAllocation()) {
            $ipPool = $selectedNode->ipPools()->where('active', true)->first();
            if ($ipPool === null) {
                return [
                    'ok' => false,
                    'code' => 'IP_POOL_EXHAUSTED',
                    'message' => 'No active IP pool available on the selected VPN server.',
                    'status' => 503,
                ];
            }
        }

        $idempKey = $idempotencyKey ?: (string) Str::uuid();
        $peerCode = $protocol->peerCodePrefix().strtoupper(Str::random(12));

        $peer = null;
        $operation = null;

        try {
            [$peer, $operation] = DB::transaction(function () use (
                $device,
                $selectedNode,
                $protocol,
                $clientIdentity,
                $ipPool,
                $idempKey,
                $peerCode
            ) {
                $peer = VpnPeer::create([
                    'peer_code' => $peerCode,
                    'device_id' => $device->id,
                    'node_id' => $selectedNode->id,
                    'protocol' => $protocol->value,
                    'public_key' => $clientIdentity,
                    'client_identity' => $protocol === VpnProtocol::Vless ? $clientIdentity : null,
                    'assigned_ip' => $protocol->requiresIpAllocation() ? '0.0.0.0' : '0.0.0.0',
                    'status' => PeerStatus::Pending,
                ]);

                if ($ipPool !== null) {
                    $allocation = $this->ipamService->allocate($ipPool, $device, $peer);
                    $peer->update(['assigned_ip' => $allocation->ip_address]);
                }

                $operation = ProvisioningOperation::create([
                    'idempotency_key' => $idempKey,
                    'peer_id' => $peer->id,
                    'device_id' => $device->id,
                    'operation_type' => ProvisioningOperationType::Provision,
                    'status' => ProvisioningOperationStatus::Running,
                    'attempt_count' => 1,
                ]);

                return [$peer, $operation];
            });
        } catch (Exception $e) {
            Log::error('VPN Provisioning DB transaction failed', [
                'device_id' => $device->id,
                'protocol' => $protocol->value,
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
                'message' => 'Failed to initialize VPN provisioning: '.$e->getMessage(),
                'status' => 500,
            ];
        }

        try {
            $this->controlPlaneClient->addPeer(
                (string) $selectedNode->id,
                $peer->peer_code,
                $peer->public_key,
                $peer->assigned_ip,
                config('vpn.allowed_ips', ['0.0.0.0/0']),
                null,
                $protocol->value,
                $protocol === VpnProtocol::Vless ? $clientIdentity : null,
                $protocol === VpnProtocol::Vless ? $selectedNode->vlessConfig() : []
            );

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
                    'protocol' => $protocol->value,
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
                'protocol' => $protocol->value,
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
                    'protocol' => $protocol->value,
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
     * @return array{ok: bool, code?: string, message?: string, status?: int, data?: array<string, mixed>}
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
            $this->controlPlaneClient->removePeer(
                $targetPeer->peer_code,
                (string) $targetPeer->node_id
            );

            $targetPeer->update([
                'status' => PeerStatus::Revoked,
                'revoked_at' => now(),
                'failure_reason' => $reason,
            ]);

            if ($targetPeer->protocol()->requiresIpAllocation()) {
                $this->ipamService->releaseForPeer($targetPeer);
            }

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
                    'protocol' => $targetPeer->protocol()->value,
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

            return [
                'ok' => false,
                'code' => 'VPN_REVOCATION_FAILED',
                'message' => 'Failed to remove peer from node: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function buildTunnelResponse(VpnPeer $peer, VpnNode $node): array
    {
        if ($peer->protocol() === VpnProtocol::Vless) {
            return $this->buildVlessResponse($peer, $node);
        }

        return [
            'protocol' => VpnProtocol::Wireguard->value,
            'peer_id' => $peer->peer_code,
            'address' => $peer->assigned_ip.'/32',
            'dns' => config('vpn.dns', ['1.1.1.1', '1.0.0.1']),
            'server' => [
                'id' => $node->id,
                'name' => $node->name,
                'location' => $node->location?->display_name ?? 'Default',
                'endpoint' => $node->public_endpoint.':'.$node->vpn_port,
                'public_key' => $node->public_key ?? '',
            ],
            'allowed_ips' => config('vpn.allowed_ips', ['0.0.0.0/0']),
            'persistent_keepalive' => (int) config('vpn.persistent_keepalive', 25),
            'mtu' => (int) config('vpn.mtu', 1420),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildVlessResponse(VpnPeer $peer, VpnNode $node): array
    {
        $vless = $node->vlessConfig();
        $uuid = $peer->client_identity ?? $peer->public_key;
        $host = $node->public_endpoint;
        $port = $node->vlessPort();
        $security = $vless['security'] ?? 'tls';
        $sni = $vless['sni'] ?? $host;
        $flow = $vless['flow'] ?? null;
        if ($security === 'tls' && is_string($flow) && str_starts_with($flow, 'xtls-')) {
            $flow = null;
        }
        $fingerprint = $vless['fingerprint'] ?? 'chrome';

        $params = [
            'encryption' => 'none',
            'security' => $security,
            'type' => 'tcp',
            'sni' => $sni,
        ];
        if ($flow) {
            $params['flow'] = $flow;
        }
        if ($fingerprint) {
            $params['fp'] = $fingerprint;
        }

        $query = http_build_query($params);
        $shareUrl = sprintf('vless://%s@%s:%d?%s#%s', $uuid, $host, $port, $query, rawurlencode($node->name));

        $alpn = $vless['alpn'] ?? 'h2,http/1.1';
        $alpnList = is_array($alpn)
            ? $alpn
            : array_values(array_filter(array_map('trim', explode(',', (string) $alpn))));

        return [
            'protocol' => VpnProtocol::Vless->value,
            'connection_id' => $peer->peer_code,
            'peer_id' => $peer->peer_code,
            'uuid' => $uuid,
            'dns' => config('vpn.dns', ['1.1.1.1', '1.0.0.1']),
            'mtu' => (int) config('vpn.vless.mtu', 1400),
            'server' => [
                'id' => $node->id,
                'name' => $node->name,
                'location' => $node->location?->display_name ?? 'Default',
                'host' => $host,
                'port' => $port,
                'endpoint' => $host.':'.$port,
                'security' => $security,
                'sni' => $sni,
                'flow' => $flow,
                'fingerprint' => $fingerprint,
                'alpn' => implode(',', $alpnList),
            ],
            'vless' => [
                'uuid' => $uuid,
                'encryption' => 'none',
                'transport' => 'tcp',
                'security' => $security,
                'sni' => $sni,
                'fingerprint' => $fingerprint,
                'flow' => $flow,
                'alpn' => $alpnList,
            ],
            'share_url' => $shareUrl,
        ];
    }

    private function resolveProtocol(?string $raw): VpnProtocol
    {
        $value = strtolower(trim((string) ($raw ?? VpnProtocol::Wireguard->value)));

        return VpnProtocol::tryFrom($value) ?? VpnProtocol::Wireguard;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function idempotencyPayloadMatchesRequest(array $payload, ?int $locationId, VpnProtocol $protocol): bool
    {
        $cachedProtocol = VpnProtocol::tryFrom(strtolower((string) ($payload['protocol'] ?? '')));
        if ($cachedProtocol !== $protocol) {
            return false;
        }

        if ($locationId === null) {
            return true;
        }

        $serverId = isset($payload['server']['id']) ? (int) $payload['server']['id'] : null;
        if ($serverId === null) {
            return false;
        }

        $node = VpnNode::query()->find($serverId);

        return $node !== null && $node->location_id === $locationId;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, identity?: string, code?: string, message?: string, status?: int}
     */
    private function resolveClientIdentity(VpnProtocol $protocol, array $payload): array
    {
        if ($protocol === VpnProtocol::Wireguard) {
            $clientPublicKey = trim((string) ($payload['client_public_key'] ?? ''));
            if (! $this->isValidPublicKey($clientPublicKey)) {
                return [
                    'ok' => false,
                    'code' => 'INVALID_PUBLIC_KEY',
                    'message' => 'The provided WireGuard public key is invalid.',
                    'status' => 422,
                ];
            }

            return ['ok' => true, 'identity' => $clientPublicKey];
        }

        $clientUuid = trim((string) ($payload['client_uuid'] ?? ''));
        if ($clientUuid === '') {
            $clientUuid = (string) Str::uuid();
        }

        if (! Str::isUuid($clientUuid)) {
            return [
                'ok' => false,
                'code' => 'VALIDATION_ERROR',
                'message' => 'The provided VLESS client UUID is invalid.',
                'status' => 422,
            ];
        }

        return ['ok' => true, 'identity' => $clientUuid];
    }
}
