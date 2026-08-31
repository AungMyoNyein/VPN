<?php

namespace Tests\Feature\Vpn;

use App\Enums\DeviceStatus;
use App\Enums\IpAllocationStatus;
use App\Enums\NodeHealthStatus;
use App\Enums\NodeLifecycleStatus;
use App\Enums\PeerStatus;
use App\Enums\ProvisioningOperationStatus;
use App\Enums\ProvisioningOperationType;
use App\Models\Device;
use App\Models\Location;
use App\Models\ProvisioningOperation;
use App\Models\VpnIpAllocation;
use App\Models\VpnNode;
use App\Models\VpnPeer;
use App\Services\Vpn\ReconciliationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\ActivationTestCase;

class ReconciliationTest extends ActivationTestCase
{
    private ReconciliationService $reconciliation;
    private Device $device;
    private Location $location;
    private VpnNode $node;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reconciliation = app(ReconciliationService::class);

        $customer = $this->createCustomer();
        $this->createSubscription($customer);

        $this->device = Device::query()->create([
            'customer_id' => $customer->id,
            'device_uuid' => fake()->uuid(),
            'platform' => 'ANDROID',
            'device_name' => 'Pixel Recon',
            'status' => DeviceStatus::Active,
        ]);

        $this->location = $this->createLocation();
        $this->node = $this->createVpnNode($this->location, [
            'health_status' => NodeHealthStatus::Healthy,
            'lifecycle_status' => NodeLifecycleStatus::Active,
            'capacity_users' => 10,
        ]);
        $this->createIpPool($this->node, [
            'network' => '10.200.20.0/24',
            'gateway' => '10.200.20.1',
        ]);
    }

    public function test_reconciles_pending_peer_when_control_plane_already_has_peer(): void
    {
        $peer = VpnPeer::query()->create([
            'peer_code' => 'PEER-RECON-001',
            'device_id' => $this->device->id,
            'node_id' => $this->node->id,
            'public_key' => 'jXpGt9enG8oV8lxX4vwNBi1czL89KqL8ImmWToKVHyv=',
            'assigned_ip' => '10.200.20.2',
            'status' => PeerStatus::Pending,
        ]);

        $op = ProvisioningOperation::query()->create([
            'idempotency_key' => (string) Str::uuid(),
            'peer_id' => $peer->id,
            'device_id' => $this->device->id,
            'operation_type' => ProvisioningOperationType::Provision,
            'status' => ProvisioningOperationStatus::Pending,
            'attempt_count' => 1,
        ]);

        // Control plane returns that the peer is active on the node
        Http::fake([
            '*/internal/v1/peers/PEER-RECON-001' => Http::response([
                'data' => [
                    'id' => 1,
                    'peer_id' => 'PEER-RECON-001',
                    'status' => 'ACTIVE',
                ],
            ], 200),
        ]);

        $stats = $this->reconciliation->reconcile();

        $peer->refresh();
        $op->refresh();

        $this->assertEquals(PeerStatus::Active, $peer->status);
        $this->assertEquals(ProvisioningOperationStatus::Succeeded, $op->status);
        $this->assertGreaterThanOrEqual(1, $stats['reconciled_provisions']);
    }

    public function test_reconciles_pending_peer_by_retrying_when_cp_missing_peer(): void
    {
        $peer = VpnPeer::query()->create([
            'peer_code' => 'PEER-RECON-002',
            'device_id' => $this->device->id,
            'node_id' => $this->node->id,
            'public_key' => 'jXpGt9enG8oV8lxX4vwNBi1czL89KqL8ImmWToKVHyv=',
            'assigned_ip' => '10.200.20.3',
            'status' => PeerStatus::Pending,
        ]);

        $op = ProvisioningOperation::query()->create([
            'idempotency_key' => (string) Str::uuid(),
            'peer_id' => $peer->id,
            'device_id' => $this->device->id,
            'operation_type' => ProvisioningOperationType::Provision,
            'status' => ProvisioningOperationStatus::Pending,
            'attempt_count' => 1,
        ]);

        // CP returns 404 on GET, then succeeds on POST (AddPeer)
        Http::fake([
            '*/internal/v1/peers/PEER-RECON-002' => Http::response([], 404),
            '*/internal/v1/peers' => Http::response([
                'data' => ['id' => 2, 'peer_id' => 'PEER-RECON-002', 'status' => 'ACTIVE'],
            ], 200),
        ]);

        $this->reconciliation->reconcile();

        $peer->refresh();
        $op->refresh();

        $this->assertEquals(PeerStatus::Active, $peer->status);
        $this->assertEquals(ProvisioningOperationStatus::Succeeded, $op->status);
    }

    public function test_reconciles_revoking_peer_when_cp_already_removed_peer(): void
    {
        $peer = VpnPeer::query()->create([
            'peer_code' => 'PEER-RECON-003',
            'device_id' => $this->device->id,
            'node_id' => $this->node->id,
            'public_key' => 'jXpGt9enG8oV8lxX4vwNBi1czL89KqL8ImmWToKVHyv=',
            'assigned_ip' => '10.200.20.4',
            'status' => PeerStatus::Revoking,
        ]);

        $pool = $this->node->ipPools()->first();
        $alloc = VpnIpAllocation::query()->create([
            'pool_id' => $pool->id,
            'device_id' => $this->device->id,
            'vpn_peer_id' => $peer->id,
            'ip_address' => '10.200.20.4',
            'status' => IpAllocationStatus::Allocated,
            'allocated_at' => now(),
        ]);

        $op = ProvisioningOperation::query()->create([
            'idempotency_key' => (string) Str::uuid(),
            'peer_id' => $peer->id,
            'device_id' => $this->device->id,
            'operation_type' => ProvisioningOperationType::Revoke,
            'status' => ProvisioningOperationStatus::Pending,
            'attempt_count' => 1,
        ]);

        // CP returns 404 (already removed)
        Http::fake([
            '*/internal/v1/peers/PEER-RECON-003' => Http::response([], 404),
        ]);

        $this->reconciliation->reconcile();

        $peer->refresh();
        $alloc->refresh();
        $op->refresh();

        $this->assertEquals(PeerStatus::Revoked, $peer->status);
        $this->assertEquals(IpAllocationStatus::Released, $alloc->status);
        $this->assertEquals(ProvisioningOperationStatus::Succeeded, $op->status);
    }
}
