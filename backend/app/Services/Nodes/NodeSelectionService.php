<?php

namespace App\Services\Nodes;

use App\Enums\NodeHealthStatus;
use App\Enums\NodeLifecycleStatus;
use App\Models\Customer;
use App\Models\Location;
use App\Models\VpnNode;
use Illuminate\Support\Collection;

class NodeSelectionService
{
    /**
     * Select the best eligible VPN node for provisioning.
     *
     * @param int|null $locationId Specific location requested by client
     * @param Customer|null $customer Authenticated customer (for future plan-location entitlements)
     * @return VpnNode|null
     */
    public function selectNode(?int $locationId = null, ?Customer $customer = null): ?VpnNode
    {
        $query = VpnNode::query()
            ->with(['location', 'ipPools' => fn ($q) => $q->where('active', true)])
            ->where('lifecycle_status', NodeLifecycleStatus::Active)
            ->where('health_status', NodeHealthStatus::Healthy)
            ->where('maintenance_mode', false)
            ->where('draining', false)
            ->whereHas('location', fn ($q) => $q->where('active', true))
            ->whereHas('ipPools', fn ($q) => $q->where('active', true))
            ->withCount(['peers as active_peers_count' => fn ($q) => $q->where('status', 'ACTIVE')]);

        if ($locationId !== null) {
            $query->where('location_id', $locationId);
        }

        $candidates = $query->get();

        $eligible = $candidates->filter(function (VpnNode $node) {
            return $node->active_peers_count < $node->capacity_users;
        });

        if ($eligible->isEmpty()) {
            return null;
        }

        // Score based on weight and lowest utilization
        return $eligible->sortByDesc(function (VpnNode $node) {
            $utilization = $node->capacity_users > 0 ? ($node->active_peers_count / $node->capacity_users) : 1.0;
            $weight = $node->weight > 0 ? $node->weight : 100;

            return $weight * (1.0 - $utilization);
        })->first();
    }

    /**
     * Get recommended server info for client display.
     *
     * @return array<string, mixed>|null
     */
    public function getRecommendedServer(?Customer $customer = null): ?array
    {
        $bestNode = $this->selectNode(null, $customer);
        if ($bestNode === null) {
            return null;
        }

        return [
            'node_id' => $bestNode->id,
            'name' => $bestNode->name,
            'location_id' => $bestNode->location_id,
            'location_name' => $bestNode->location?->display_name ?? 'Default',
            'country_code' => $bestNode->location?->country_code ?? '',
            'endpoint' => $bestNode->public_endpoint . ':' . $bestNode->vpn_port,
            'public_key' => $bestNode->public_key,
        ];
    }

    /**
     * Get active locations with available server capacity.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getAvailableLocations(?Customer $customer = null): Collection
    {
        return Location::query()
            ->where('active', true)
            ->with(['vpnNodes' => function ($q) {
                $q->where('lifecycle_status', NodeLifecycleStatus::Active)
                    ->where('health_status', NodeHealthStatus::Healthy)
                    ->where('maintenance_mode', false)
                    ->where('draining', false)
                    ->whereHas('ipPools', fn ($p) => $p->where('active', true))
                    ->withCount(['peers as active_peers_count' => fn ($p) => $p->where('status', 'ACTIVE')]);
            }])
            ->orderBy('sort_order')
            ->get()
            ->map(function (Location $loc) {
                $availableNodes = $loc->vpnNodes->filter(fn (VpnNode $n) => $n->active_peers_count < $n->capacity_users);

                return [
                    'id' => $loc->id,
                    'country_code' => $loc->country_code,
                    'country_name' => $loc->country_name,
                    'city' => $loc->city,
                    'display_name' => $loc->display_name,
                    'servers_count' => $loc->vpnNodes->count(),
                    'available' => $availableNodes->isNotEmpty(),
                ];
            });
    }
}
