<?php

namespace Tests\Feature\Admin\V1;

use App\Models\Location;
use App\Models\Plan;
use App\Support\Permissions;
use Tests\AdminTestCase;

class RbacTest extends AdminTestCase
{
    public function test_support_cannot_manage_roles(): void
    {
        $admin = $this->createAdmin('SUPPORT');

        $this->withAdmin($admin)
            ->getJson('/api/admin/v1/roles')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_finance_cannot_manage_nodes(): void
    {
        $admin = $this->createAdmin('FINANCE');

        $this->withAdmin($admin)
            ->getJson('/api/admin/v1/vpn-nodes')
            ->assertForbidden();
    }

    public function test_finance_cannot_update_node_lifecycle(): void
    {
        $admin = $this->createAdmin('FINANCE');

        $location = Location::query()->create([
            'country_code' => 'TH',
            'country_name' => 'Thailand',
            'city' => 'Bangkok',
            'display_name' => 'Bangkok Test',
            'active' => true,
            'sort_order' => 1,
        ]);

        $node = \App\Models\VpnNode::query()->create([
            'location_id' => $location->id,
            'name' => 'Test Node',
            'hostname' => 'test-node.vpn.local',
            'public_endpoint' => 'test-node.vpn.local',
            'vpn_port' => 51820,
            'capacity_users' => 50,
        ]);

        $this->withAdmin($admin)
            ->patchJson("/api/admin/v1/vpn-nodes/{$node->id}/lifecycle", [
                'lifecycle_status' => 'DRAINING',
            ])
            ->assertForbidden();
    }

    public function test_noc_cannot_modify_payments(): void
    {
        $admin = $this->createAdmin('NOC');

        $this->withAdmin($admin)
            ->postJson('/api/admin/v1/payments', [
                'customer_id' => 1,
                'payment_method' => 'CASH',
                'amount' => 10,
                'currency' => 'USD',
            ])
            ->assertForbidden();
    }

    public function test_noc_can_view_customers(): void
    {
        $admin = $this->createAdmin('NOC');

        $this->withAdmin($admin)
            ->getJson('/api/admin/v1/customers')
            ->assertOk();
    }

    public function test_super_admin_can_access_all_areas(): void
    {
        $admin = $this->createAdmin('SUPER_ADMIN');

        $this->withAdmin($admin)->getJson('/api/admin/v1/roles')->assertOk();
        $this->withAdmin($admin)->getJson('/api/admin/v1/vpn-nodes')->assertOk();
        $this->withAdmin($admin)->getJson('/api/admin/v1/payments')->assertOk();
        $this->withAdmin($admin)->getJson('/api/admin/v1/admin-users')->assertOk();
    }

    public function test_super_admin_has_all_permissions(): void
    {
        $admin = $this->createAdmin('SUPER_ADMIN');

        $response = $this->withAdmin($admin)->getJson('/api/admin/v1/auth/me');

        $permissions = $response->json('data.permissions');

        foreach (Permissions::all() as $code) {
            $this->assertContains($code, $permissions, "Missing permission: {$code}");
        }
    }
}
