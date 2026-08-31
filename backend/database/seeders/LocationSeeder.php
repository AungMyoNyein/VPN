<?php

namespace Database\Seeders;

use App\Enums\NodeHealthStatus;
use App\Enums\NodeLifecycleStatus;
use App\Models\Location;
use App\Models\VpnIpPool;
use App\Models\VpnNode;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $locations = [
            [
                'country_code' => 'TH',
                'country_name' => 'Thailand',
                'city' => 'Bangkok',
                'display_name' => 'Bangkok, Thailand',
                'active' => true,
                'sort_order' => 10,
                'nodes' => [
                    [
                        'name' => 'Bangkok Dev 01',
                        'hostname' => 'bkk-dev-01.vpn.local',
                        'public_endpoint' => 'bkk-dev-01.vpn.local',
                        'vpn_port' => 51820,
                        'public_key' => 'BKK1111111111111111111111111111111111111111=',
                        'capacity_users' => 100,
                        'pool' => [
                            'network' => '10.200.10.0/24',
                            'prefix_length' => 24,
                            'gateway' => '10.200.10.1',
                        ],
                    ],
                ],
            ],
            [
                'country_code' => 'SG',
                'country_name' => 'Singapore',
                'city' => 'Singapore',
                'display_name' => 'Singapore',
                'active' => true,
                'sort_order' => 20,
                'nodes' => [
                    [
                        'name' => 'Singapore Dev 01',
                        'hostname' => 'sg-dev-01.vpn.local',
                        'public_endpoint' => 'sg-dev-01.vpn.local',
                        'vpn_port' => 51820,
                        'public_key' => 'SG0111111111111111111111111111111111111111=',
                        'capacity_users' => 100,
                        'pool' => [
                            'network' => '10.200.20.0/24',
                            'prefix_length' => 24,
                            'gateway' => '10.200.20.1',
                        ],
                    ],
                ],
            ],
            [
                'country_code' => 'JP',
                'country_name' => 'Japan',
                'city' => 'Tokyo',
                'display_name' => 'Tokyo, Japan',
                'active' => true,
                'sort_order' => 30,
                'nodes' => [
                    [
                        'name' => 'Tokyo Dev 01',
                        'hostname' => 'tyo-dev-01.vpn.local',
                        'public_endpoint' => 'tyo-dev-01.vpn.local',
                        'vpn_port' => 51820,
                        'public_key' => 'TYO1111111111111111111111111111111111111111=',
                        'capacity_users' => 100,
                        'pool' => [
                            'network' => '10.200.30.0/24',
                            'prefix_length' => 24,
                            'gateway' => '10.200.30.1',
                        ],
                    ],
                ],
            ],
        ];

        foreach ($locations as $locationData) {
            $nodes = $locationData['nodes'];
            unset($locationData['nodes']);

            $location = Location::query()->updateOrCreate(
                ['display_name' => $locationData['display_name']],
                $locationData,
            );

            foreach ($nodes as $nodeData) {
                $poolData = $nodeData['pool'] ?? null;
                unset($nodeData['pool']);

                $node = VpnNode::query()->updateOrCreate(
                    ['hostname' => $nodeData['hostname']],
                    array_merge($nodeData, [
                        'location_id' => $location->id,
                        'health_status' => NodeHealthStatus::Healthy,
                        'lifecycle_status' => NodeLifecycleStatus::Active,
                        'maintenance_mode' => false,
                        'adapter_type' => 'fake',
                        'wireguard_interface' => 'wg0',
                        'weight' => 100,
                        'notes' => 'DEV inventory only',
                    ]),
                );

                if ($poolData !== null) {
                    VpnIpPool::query()->updateOrCreate(
                        ['node_id' => $node->id, 'network' => $poolData['network']],
                        [
                            'prefix_length' => $poolData['prefix_length'],
                            'gateway' => $poolData['gateway'],
                            'active' => true,
                        ]
                    );
                }
            }
        }
    }
}
