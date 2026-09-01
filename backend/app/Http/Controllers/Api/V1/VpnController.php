<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\VpnProtocol;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\VpnProvisionRequest;
use App\Models\Device;
use App\Services\Nodes\NodeSelectionService;
use App\Services\Vpn\VpnProvisioningService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VpnController extends Controller
{
    public function __construct(
        private readonly VpnProvisioningService $provisioningService,
        private readonly NodeSelectionService $nodeSelectionService,
    ) {}

    /**
     * Provision VPN peer configuration for authenticated device.
     */
    public function provision(VpnProvisionRequest $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');
        $idempotencyKey = $request->header('Idempotency-Key');

        $result = $this->provisioningService->provision(
            $device,
            $request->validated(),
            $idempotencyKey
        );

        if (! $result['ok']) {
            return ApiResponse::error(
                $result['code'] ?? 'VPN_PROVISIONING_FAILED',
                $result['message'] ?? 'VPN provisioning failed.',
                $result['status'] ?? 400
            );
        }

        return ApiResponse::success($result['data'], status: 201);
    }

    /**
     * Revoke active VPN peer for authenticated device.
     */
    public function revoke(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        $result = $this->provisioningService->revoke($device);

        if (! $result['ok']) {
            return ApiResponse::error(
                $result['code'] ?? 'VPN_REVOCATION_FAILED',
                $result['message'] ?? 'VPN revocation failed.',
                $result['status'] ?? 400
            );
        }

        return ApiResponse::success($result['data']);
    }

    /**
     * Get available VPN locations.
     */
    public function locations(Request $request): JsonResponse
    {
        $customer = $request->attributes->get('customer');
        $locations = $this->nodeSelectionService->getAvailableLocations($customer);

        return ApiResponse::success($locations->values()->all());
    }

    /**
     * Get supported VPN protocols.
     */
    public function protocols(): JsonResponse
    {
        return ApiResponse::success([
            'protocols' => VpnProtocol::values(),
            'default' => VpnProtocol::Wireguard->value,
        ]);
    }

    /**
     * Get recommended VPN server.
     */
    public function recommendedServer(Request $request): JsonResponse
    {
        $customer = $request->attributes->get('customer');
        $protocol = VpnProtocol::tryFrom(strtolower((string) $request->query('protocol', VpnProtocol::Wireguard->value)));
        $recommended = $this->nodeSelectionService->getRecommendedServer($customer, $protocol);

        if ($recommended === null) {
            return ApiResponse::error(
                'NO_VPN_NODE_AVAILABLE',
                'No recommended VPN server is currently available.',
                503
            );
        }

        return ApiResponse::success($recommended);
    }

    /**
     * Get current device active VPN peer status.
     */
    public function status(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');
        $activePeer = $device->activePeer;

        if ($activePeer === null) {
            return ApiResponse::success([
                'active' => false,
                'peer' => null,
            ]);
        }

        $node = $activePeer->node;

        return ApiResponse::success([
            'active' => $activePeer->isActive(),
            'protocol' => $activePeer->protocol()->value,
            'peer' => [
                'peer_code' => $activePeer->peer_code,
                'status' => $activePeer->status->value,
                'assigned_ip' => $activePeer->assigned_ip,
                'location' => $node?->location?->display_name ?? 'Default',
                'node_name' => $node?->name ?? '',
                'provisioned_at' => $activePeer->provisioned_at,
            ],
        ]);
    }
}
