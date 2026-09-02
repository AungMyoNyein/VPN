<?php

namespace App\Services\Nodes;

use App\Enums\NodeHealthStatus;
use App\Enums\NodeLifecycleStatus;
use App\Enums\VpnProtocol;
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
    public function selectNode(?int $locationId = null, ?Customer $customer = null, ?VpnProtocol $protocol = null): ?VpnNode
    {
        $protocolValue = ($protocol ?? VpnProtocol::Wireguard)->value;

        $query = VpnNode::query()
            ->with(['location', 'ipPools' => fn ($q) => $q->where('active', true)])
            ->where('adapter_type', 'remote')
            ->where('lifecycle_status', NodeLifecycleStatus::Active)
            ->where('health_status', NodeHealthStatus::Healthy)
            ->where('maintenance_mode', false)
            ->where('draining', false)
            ->whereHas('location', fn ($q) => $q->where('active', true))
            ->withCount(['peers as active_peers_count' => fn ($q) => $q->where('status', 'ACTIVE')]);

        if ($protocolValue === VpnProtocol::Wireguard->value) {
            $query->whereHas('ipPools', fn ($q) => $q->where('active', true));
        }

        if ($locationId !== null) {
            $query->where('location_id', $locationId);
        }

        $candidates = $query->get()->filter(function (VpnNode $node) use ($protocolValue) {
            return $node->supportsProtocol($protocolValue);
        });

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
    public function getRecommendedServer(?Customer $customer = null, ?VpnProtocol $protocol = null): ?array
    {
        $bestNode = $this->selectNode(null, $customer, $protocol);
        if ($bestNode === null) {
            return null;
        }

        $protocolValue = ($protocol ?? VpnProtocol::Wireguard)->value;

        $response = [
            'node_id' => $bestNode->id,
            'name' => $bestNode->name,
            'location_id' => $bestNode->location_id,
            'location_name' => $bestNode->location?->display_name ?? 'Default',
            'country_code' => $bestNode->location?->country_code ?? '',
            'protocol' => $protocolValue,
            'supported_protocols' => $bestNode->supportedProtocols(),
        ];

        if ($protocolValue === VpnProtocol::Vless->value) {
            $vless = $bestNode->vlessConfig();
            $response['endpoint'] = $bestNode->public_endpoint . ':' . $bestNode->vlessPort();
            $response['security'] = $vless['security'] ?? 'tls';
            $response['sni'] = $vless['sni'] ?? $bestNode->public_endpoint;
            $response['flow'] = $vless['flow'] ?? null;
        } else {
            $response['endpoint'] = $bestNode->public_endpoint . ':' . $bestNode->vpn_port;
            $response['public_key'] = $bestNode->public_key;
        }

        return $response;
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
                $q->where('adapter_type', 'remote')
                    ->where('lifecycle_status', NodeLifecycleStatus::Active)
                    ->where('health_status', NodeHealthStatus::Healthy)
                    ->where('maintenance_mode', false)
                    ->where('draining', false)
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
                    'protocols' => $this->protocolsForLocation($loc),
                ];
            });
    }

    /**
     * @return list<string>
     */
    private function protocolsForLocation(Location $location): array
    {
        $protocols = [];
        foreach ($location->vpnNodes as $node) {
            foreach ($node->supportedProtocols() as $protocol) {
                $protocols[$protocol] = true;
            }
        }

        return array_keys($protocols);
    }
}
