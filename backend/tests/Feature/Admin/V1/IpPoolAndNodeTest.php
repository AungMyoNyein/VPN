<?php

namespace Tests\Feature\Admin\V1;

use App\Enums\NodeHealthStatus;
use App\Enums\NodeLifecycleStatus;
use App\Models\Location;
use App\Models\VpnIpPool;
use App\Models\VpnNode;
use Tests\AdminTestCase;

class IpPoolAndNodeTest extends AdminTestCase
{
    private Location $location;
    private VpnNode $node;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::query()->create([
            'country_code' => 'SG',
            'country_name' => 'Singapore',
            'city' => 'Singapore',
            'display_name' => 'Singapore',
            'active' => true,
            'sort_order' => 1,
        ]);

        $this->node = VpnNode::query()->create([
            'location_id' => $this->location->id,
            'name' => 'Singapore Node 01',
            'hostname' => 'sg01.vpn.local',
            'public_endpoint' => 'sg01.vpn.local',
            'vpn_port' => 51820,
            'public_key' => 'SG0111111111111111111111111111111111111111=',
            'capacity_users' => 100,
            'health_status' => NodeHealthStatus::Healthy,
            'lifecycle_status' => NodeLifecycleStatus::Active,
            'maintenance_mode' => false,
            'draining' => false,
            'weight' => 100,
        ]);
    }

    public function test_admin_can_list_and_create_ip_pools(): void
    {
        $admin = $this->createAdmin('SUPER_ADMIN');

        // 1. Create IP pool
        $res = $this->withAdmin($admin)->postJson('/api/admin/v1/ip-pools', [
            'node_id' => $this->node->id,
            'network' => '10.200.50.0/24',
            'prefix_length' => 24,
            'gateway' => '10.200.50.1',
        ]);

        $res->assertCreated();
        $this->assertEquals('10.200.50.0/24', $res->json('data.ip_pool.network'));

        // 2. List IP pools
        $listRes = $this->withAdmin($admin)->getJson('/api/admin/v1/ip-pools');
        $listRes->assertOk();
        $this->assertCount(1, $listRes->json('data.ip_pools'));
        $this->assertEquals(253, $listRes->json('data.ip_pools.0.capacity'));
    }

    public function test_rejects_invalid_ip_pool_creation(): void
    {
        $admin = $this->createAdmin('SUPER_ADMIN');

        // Invalid CIDR prefix / gateway
        $res = $this->withAdmin($admin)->postJson('/api/admin/v1/ip-pools', [
            'node_id' => $this->node->id,
            'network' => '10.200.50.0/24',
            'prefix_length' => 24,
            'gateway' => '192.168.1.1', // Gateway outside CIDR
        ]);

        $res->assertStatus(422);
    }

    public function test_admin_can_toggle_ip_pool_active(): void
    {
        $admin = $this->createAdmin('SUPER_ADMIN');
        $pool = VpnIpPool::query()->create([
            'node_id' => $this->node->id,
            'network' => '10.200.60.0/24',
            'prefix_length' => 24,
            'gateway' => '10.200.60.1',
            'active' => true,
        ]);

        $res = $this->withAdmin($admin)->postJson("/api/admin/v1/ip-pools/{$pool->id}/toggle");
        $res->assertOk();
        $this->assertFalse($res->json('data.ip_pool.active'));
        $this->assertFalse($pool->fresh()->active);
    }

    public function test_admin_can_toggle_node_draining_and_maintenance(): void
    {
        $admin = $this->createAdmin('SUPER_ADMIN');

        // Toggle draining
        $resDrain = $this->withAdmin($admin)->postJson("/api/admin/v1/vpn-nodes/{$this->node->id}/drain");
        $resDrain->assertOk();
        $this->assertTrue($resDrain->json('data.vpn_node.draining'));
        $this->assertTrue($this->node->fresh()->draining);

        // Toggle maintenance
        $resMaint = $this->withAdmin($admin)->postJson("/api/admin/v1/vpn-nodes/{$this->node->id}/maintenance");
        $resMaint->assertOk();
        $this->assertTrue($resMaint->json('data.vpn_node.maintenance_mode'));
        $this->assertTrue($this->node->fresh()->maintenance_mode);
    }
}
