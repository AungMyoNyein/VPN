<?php

namespace Tests\Feature\Vpn;

use App\Enums\NodeHealthStatus;
use App\Enums\NodeLifecycleStatus;
use App\Enums\PeerStatus;
use App\Models\Device;
use App\Models\Location;
use App\Models\VpnIpAllocation;
use App\Models\VpnIpPool;
use App\Models\VpnNode;
use App\Models\VpnPeer;
use App\Services\ControlPlane\ControlPlaneClient;
use App\Services\Vpn\ReconciliationService;
use App\Services\Vpn\VpnProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\ActivationTestCase;

class Phase4TelemetryAndRemoteNodeTest extends ActivationTestCase
{
    use RefreshDatabase;

    public function test_vpn_node_has_phase4_fields(): void
    {
        $location = Location::query()->create([
            'country_code' => 'SG',
            'country_name' => 'Singapore',
            'city' => 'Singapore',
            'display_name' => 'Singapore',
            'active' => true,
        ]);

        $node = VpnNode::query()->create([
            'location_id' => $location->id,
            'name' => 'Singapore Remote 01',
            'hostname' => 'sg-remote-01.vpn.local',
            'public_endpoint' => 'sg-remote-01.vpn.local',
            'vpn_port' => 51820,
            'public_key' => 'SG0111111111111111111111111111111111111111=',
            'capacity_users' => 500,
            'health_status' => NodeHealthStatus::Healthy,
            'lifecycle_status' => NodeLifecycleStatus::Active,
            'maintenance_mode' => false,
            'adapter_type' => 'remote',
            'agent_endpoint' => 'https://127.0.0.1:9443',
            'agent_version' => '1.0.0',
            'wireguard_interface' => 'wg0',
            'weight' => 100,
        ]);

        $this->assertTrue($node->isRemote());
        $this->assertFalse($node->isFake());
        $this->assertSame('https://127.0.0.1:9443', $node->agent_endpoint);
    }

    public function test_telemetry_sync_updates_peer_handshake_and_counters(): void
    {
        $customer = $this->createCustomer();
        $this->createSubscription($customer);
        $device = Device::query()->create([
            'customer_id' => $customer->id,
            'device_uuid' => 'test-device-uuid-1234',
            'device_name' => 'Pixel 8',
            'platform' => \App\Enums\DevicePlatform::Android,
            'os_version' => '14',
            'app_version' => '1.0.0',
            'status' => \App\Enums\DeviceStatus::Active,
        ]);

        $location = Location::query()->firstOrCreate(['display_name' => 'Singapore'], [
            'country_code' => 'SG',
            'country_name' => 'Singapore',
            'city' => 'Singapore',
            'active' => true,
        ]);

        $node = VpnNode::query()->create([
            'location_id' => $location->id,
            'name' => 'SG Node',
            'hostname' => 'sg-telemetry.vpn.local',
            'public_endpoint' => 'sg-telemetry.vpn.local',
            'vpn_port' => 51820,
            'public_key' => 'SG0111111111111111111111111111111111111111=',
            'capacity_users' => 100,
            'health_status' => NodeHealthStatus::Healthy,
            'lifecycle_status' => NodeLifecycleStatus::Active,
            'maintenance_mode' => false,
            'adapter_type' => 'remote',
            'agent_endpoint' => 'https://127.0.0.1:9443',
            'wireguard_interface' => 'wg0',
        ]);

        $pool = VpnIpPool::query()->create([
            'node_id' => $node->id,
            'network' => '10.200.20.0/24',
            'prefix_length' => 24,
            'gateway' => '10.200.20.1',
            'active' => true,
        ]);

        $peer = VpnPeer::query()->create([
            'peer_code' => 'WG-PEER-TEL-001',
            'device_id' => $device->id,
            'node_id' => $node->id,
            'public_key' => 'TEST_CLIENT_PUBLIC_KEY_BASE64_32_BYTES=',
            'assigned_ip' => '10.200.20.50',
            'status' => PeerStatus::Active,
            'provisioned_at' => now(),
        ]);

        $mockCp = Mockery::mock(ControlPlaneClient::class);
        $mockCp->shouldReceive('getNode')
            ->with((string) $node->id)
            ->andReturn([
                'node_id' => (string) $node->id,
                'health_status' => 'HEALTHY',
                'last_heartbeat_at' => now()->toIso8601String(),
            ]);

        $mockCp->shouldReceive('listPeers')
            ->with((string) $node->id)
            ->andReturn([
                [
                    'peer_id' => 'WG-PEER-TEL-001',
                    'public_key' => 'TEST_CLIENT_PUBLIC_KEY_BASE64_32_BYTES=',
                    'assigned_ip' => '10.200.20.50/32',
                    'latest_handshake_at' => now()->toIso8601String(),
                    'rx_bytes' => 1048576,
                    'tx_bytes' => 2097152,
                ],
            ]);

        $ipam = app(\App\Services\Ipam\IpamService::class);
        $provisioning = app(VpnProvisioningService::class);
        $recon = new ReconciliationService($mockCp, $ipam, $provisioning);

        $stats = $recon->syncTelemetry();

        $this->assertSame(1, $stats['nodes_synced']);
        $this->assertSame(1, $stats['peers_synced']);

        $peer->refresh();
        $this->assertSame(1048576, $peer->rx_bytes);
        $this->assertSame(2097152, $peer->tx_bytes);
        $this->assertNotNull($peer->latest_handshake_at);
        $this->assertTrue($peer->isRecentlyActive(5));
    }
}
