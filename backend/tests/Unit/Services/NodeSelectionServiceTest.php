<?php

namespace Tests\Unit\Services;

use App\Enums\NodeHealthStatus;
use App\Enums\NodeLifecycleStatus;
use App\Enums\PeerStatus;
use App\Models\Device;
use App\Models\Location;
use App\Models\VpnNode;
use App\Models\VpnPeer;
use App\Services\Nodes\NodeSelectionService;
use Tests\ActivationTestCase;

class NodeSelectionServiceTest extends ActivationTestCase
{
    private NodeSelectionService $service;
    private Location $locationSg;
    private Location $locationBkk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(NodeSelectionService::class);

        $this->locationSg = $this->createLocation([
            'country_code' => 'SG',
            'country_name' => 'Singapore',
            'city' => 'Singapore',
            'display_name' => 'Singapore',
        ]);

        $this->locationBkk = $this->createLocation([
            'country_code' => 'TH',
            'country_name' => 'Thailand',
            'city' => 'Bangkok',
            'display_name' => 'Bangkok',
        ]);
    }

    public function test_selects_healthy_active_node(): void
    {
        $node = $this->createVpnNode($this->locationSg, [
            'health_status' => NodeHealthStatus::Healthy,
            'lifecycle_status' => NodeLifecycleStatus::Active,
            'capacity_users' => 100,
        ]);
        $this->createIpPool($node);

        $selected = $this->service->selectNode($this->locationSg->id);
        $this->assertNotNull($selected);
        $this->assertEquals($node->id, $selected->id);
    }

    public function test_excludes_draining_node(): void
    {
        $node = $this->createVpnNode($this->locationSg, [
            'health_status' => NodeHealthStatus::Healthy,
            'lifecycle_status' => NodeLifecycleStatus::Active,
            'draining' => true,
        ]);
        $this->createIpPool($node);

        $selected = $this->service->selectNode($this->locationSg->id);
        $this->assertNull($selected);
    }

    public function test_excludes_maintenance_mode_node(): void
    {
        $node = $this->createVpnNode($this->locationSg, [
            'health_status' => NodeHealthStatus::Healthy,
            'lifecycle_status' => NodeLifecycleStatus::Active,
            'maintenance_mode' => true,
        ]);
        $this->createIpPool($node);

        $selected = $this->service->selectNode($this->locationSg->id);
        $this->assertNull($selected);
    }

    public function test_excludes_unhealthy_node(): void
    {
        $nodeDown = $this->createVpnNode($this->locationSg, [
            'health_status' => NodeHealthStatus::Down,
            'lifecycle_status' => NodeLifecycleStatus::Active,
        ]);
        $this->createIpPool($nodeDown);

        $selected = $this->service->selectNode($this->locationSg->id);
        $this->assertNull($selected);
    }

    public function test_excludes_inactive_lifecycle_node(): void
    {
        $nodeRetired = $this->createVpnNode($this->locationSg, [
            'health_status' => NodeHealthStatus::Healthy,
            'lifecycle_status' => NodeLifecycleStatus::Retired,
        ]);
        $this->createIpPool($nodeRetired);

        $selected = $this->service->selectNode($this->locationSg->id);
        $this->assertNull($selected);
    }

    public function test_excludes_node_without_active_ip_pool(): void
    {
        $node = $this->createVpnNode($this->locationSg, [
            'health_status' => NodeHealthStatus::Healthy,
            'lifecycle_status' => NodeLifecycleStatus::Active,
        ]);
        // Note: No IP pool created

        $selected = $this->service->selectNode($this->locationSg->id);
        $this->assertNull($selected);
    }

    public function test_prefers_lower_utilization_node(): void
    {
        $nodeBusy = $this->createVpnNode($this->locationSg, [
            'name' => 'Busy Node',
            'capacity_users' => 10,
            'weight' => 100,
        ]);
        $this->createIpPool($nodeBusy, ['network' => '10.200.20.0/24', 'gateway' => '10.200.20.1']);

        $nodeFree = $this->createVpnNode($this->locationSg, [
            'name' => 'Free Node',
            'capacity_users' => 100,
            'weight' => 100,
        ]);
        $this->createIpPool($nodeFree, ['network' => '10.200.21.0/24', 'gateway' => '10.200.21.1']);

        $customer = $this->createCustomer();
        // Create 5 active peers on nodeBusy (50% utilization)
        for ($i = 0; $i < 5; $i++) {
            $device = Device::query()->create([
                'customer_id' => $customer->id,
                'device_uuid' => fake()->uuid(),
                'platform' => 'ANDROID',
                'device_name' => "Device $i",
                'status' => 'ACTIVE',
            ]);
            VpnPeer::query()->create([
                'peer_code' => 'PEER-'.fake()->unique()->numerify('######'),
                'device_id' => $device->id,
                'node_id' => $nodeBusy->id,
                'public_key' => fake()->unique()->regexify('[A-Za-z0-9+/]{43}='),
                'assigned_ip' => "10.200.20.".($i + 2),
                'status' => PeerStatus::Active,
            ]);
        }

        $selected = $this->service->selectNode($this->locationSg->id);
        $this->assertNotNull($selected);
        $this->assertEquals($nodeFree->id, $selected->id);
    }

    public function test_get_recommended_server(): void
    {
        $nodeSg = $this->createVpnNode($this->locationSg, ['weight' => 50]);
        $this->createIpPool($nodeSg, ['network' => '10.200.20.0/24', 'gateway' => '10.200.20.1']);

        $nodeBkk = $this->createVpnNode($this->locationBkk, ['weight' => 100]);
        $this->createIpPool($nodeBkk, ['network' => '10.200.10.0/24', 'gateway' => '10.200.10.1']);

        $recommended = $this->service->getRecommendedServer();
        $this->assertNotNull($recommended);
        $this->assertEquals($nodeBkk->id, $recommended['node_id']);
    }

    public function test_get_available_locations(): void
    {
        $nodeSg = $this->createVpnNode($this->locationSg);
        $this->createIpPool($nodeSg, ['network' => '10.200.20.0/24', 'gateway' => '10.200.20.1']);

        $locations = $this->service->getAvailableLocations();
        $this->assertGreaterThanOrEqual(1, $locations->count());
        $sgItem = $locations->firstWhere('country_code', 'SG');
        $this->assertNotNull($sgItem);
        $this->assertEquals('Singapore', $sgItem['country_name']);
        $this->assertEquals(1, $sgItem['servers_count']);
    }
}
