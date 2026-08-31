<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V1\VpnNodes\StoreVpnNodeRequest;
use App\Http\Requests\Admin\V1\VpnNodes\UpdateVpnNodeLifecycleRequest;
use App\Http\Requests\Admin\V1\VpnNodes\UpdateVpnNodeRequest;
use App\Models\AdminUser;
use App\Models\VpnNode;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VpnNodeController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = VpnNode::query()
            ->with(['location', 'ipPools'])
            ->withCount(['peers as active_peers_count' => fn ($q) => $q->where('status', 'ACTIVE')])
            ->orderBy('name');

        if ($request->filled('location_id')) {
            $query->where('location_id', $request->get('location_id'));
        }

        $nodes = $query->get()->map(function (VpnNode $node) {
            $payload = $node->toArray();
            $cap = $node->capacity_users > 0 ? $node->capacity_users : 1;
            $payload['active_peers_count'] = $node->active_peers_count ?? 0;
            $payload['utilization_percent'] = round(($payload['active_peers_count'] / $cap) * 100, 1);
            return $payload;
        });

        return ApiResponse::success(['vpn_nodes' => $nodes]);
    }

    public function store(StoreVpnNodeRequest $request): JsonResponse
    {
        $node = VpnNode::query()->create($request->validated());

        return ApiResponse::success(['vpn_node' => $node], status: 201);
    }

    public function show(VpnNode $vpnNode): JsonResponse
    {
        $vpnNode->load(['location', 'ipPools', 'peers' => fn ($q) => $q->latest()->limit(50)]);
        $vpnNode->loadCount(['peers as active_peers_count' => fn ($q) => $q->where('status', 'ACTIVE')]);

        return ApiResponse::success(['vpn_node' => $vpnNode]);
    }

    public function update(UpdateVpnNodeRequest $request, VpnNode $vpnNode): JsonResponse
    {
        $vpnNode->update($request->validated());

        return ApiResponse::success(['vpn_node' => $vpnNode->fresh()]);
    }

    public function updateLifecycle(UpdateVpnNodeLifecycleRequest $request, VpnNode $vpnNode): JsonResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user();
        $before = $vpnNode->toArray();

        $vpnNode->update($request->validated());

        $this->auditLogger->log(
            'vpn_node.lifecycle_updated',
            'vpn_node',
            $vpnNode->id,
            before: $before,
            after: $vpnNode->fresh()->toArray(),
            actor: $actor,
        );

        return ApiResponse::success(['vpn_node' => $vpnNode->fresh()]);
    }

    public function toggleDrain(Request $request, VpnNode $vpnNode): JsonResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user();
        $before = $vpnNode->toArray();

        $drain = $request->boolean('draining', ! $vpnNode->draining);
        $vpnNode->update(['draining' => $drain]);

        $this->auditLogger->log(
            'vpn_node.drain_toggled',
            'vpn_node',
            $vpnNode->id,
            before: $before,
            after: $vpnNode->fresh()->toArray(),
            actor: $actor,
        );

        return ApiResponse::success(['vpn_node' => $vpnNode->fresh()]);
    }

    public function toggleMaintenance(Request $request, VpnNode $vpnNode): JsonResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user();
        $before = $vpnNode->toArray();

        $maint = $request->boolean('maintenance_mode', ! $vpnNode->maintenance_mode);
        $vpnNode->update(['maintenance_mode' => $maint]);

        $this->auditLogger->log(
            'vpn_node.maintenance_toggled',
            'vpn_node',
            $vpnNode->id,
            before: $before,
            after: $vpnNode->fresh()->toArray(),
            actor: $actor,
        );

        return ApiResponse::success(['vpn_node' => $vpnNode->fresh()]);
    }

    public function destroy(VpnNode $vpnNode): JsonResponse
    {
        $vpnNode->delete();

        return ApiResponse::success(['message' => 'VPN node deleted.']);
    }
}
