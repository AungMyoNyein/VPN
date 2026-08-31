<?php

namespace Tests\Unit\Services;

use App\Enums\IpAllocationStatus;
use App\Enums\PeerStatus;
use App\Models\Device;
use App\Models\VpnIpAllocation;
use App\Models\VpnIpPool;
use App\Models\VpnNode;
use App\Models\VpnPeer;
use App\Services\Ipam\IpamService;
use RuntimeException;
use Tests\ActivationTestCase;

class IpamServiceTest extends ActivationTestCase
{
    private IpamService $ipam;
    private VpnNode $node;
    private VpnIpPool $pool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ipam = app(IpamService::class);

        $location = $this->createLocation();
        $this->node = $this->createVpnNode($location);
        $this->pool = $this->createIpPool($this->node, [
            'network' => '10.200.20.0/29', // 8 addresses: .0 network, .1 gateway, .2-.6 usable (5 hosts), .7 broadcast
            'prefix_length' => 29,
            'gateway' => '10.200.20.1',
        ]);
    }

    private function createTestPeer(Device $device): VpnPeer
    {
        return VpnPeer::query()->create([
            'peer_code' => 'PEER-'.fake()->unique()->numerify('######'),
            'device_id' => $device->id,
            'node_id' => $this->node->id,
            'public_key' => fake()->unique()->regexify('[A-Za-z0-9+/]{43}='),
            'assigned_ip' => '0.0.0.0',
            'status' => PeerStatus::Pending,
        ]);
    }

    public function test_allocates_sequential_unique_ips_and_skips_reserved(): void
    {
        $customer = $this->createCustomer();
        $sub = $this->createSubscription($customer);

        $allocations = [];
        for ($i = 0; $i < 5; $i++) {
            $device = Device::query()->create([
                'customer_id' => $customer->id,
                'device_uuid' => fake()->uuid(),
                'platform' => 'ANDROID',
                'device_name' => "Device $i",
                'status' => 'ACTIVE',
            ]);

            $peer = $this->createTestPeer($device);
            $alloc = $this->ipam->allocate($this->pool, $device, $peer);
            $this->assertNotNull($alloc);
            $this->assertEquals(IpAllocationStatus::Allocated, $alloc->status);
            $this->assertNull($alloc->released_at);
            $allocations[] = $alloc->ip_address;
        }

        // Must allocate .2, .3, .4, .5, .6 (skipping .0 network and .1 gateway and .7 broadcast)
        $this->assertEquals(['10.200.20.2', '10.200.20.3', '10.200.20.4', '10.200.20.5', '10.200.20.6'], $allocations);
    }

    public function test_throws_when_pool_is_exhausted(): void
    {
        $customer = $this->createCustomer();

        // Allocate all 5 usable IPs
        for ($i = 0; $i < 5; $i++) {
            $device = Device::query()->create([
                'customer_id' => $customer->id,
                'device_uuid' => fake()->uuid(),
                'platform' => 'ANDROID',
                'device_name' => "Device $i",
                'status' => 'ACTIVE',
            ]);
            $peer = $this->createTestPeer($device);
            $this->ipam->allocate($this->pool, $device, $peer);
        }

        // 6th allocation should throw IP_POOL_EXHAUSTED
        $device6 = Device::query()->create([
            'customer_id' => $customer->id,
            'device_uuid' => fake()->uuid(),
            'platform' => 'ANDROID',
            'device_name' => 'Device 6',
            'status' => 'ACTIVE',
        ]);
        $peer6 = $this->createTestPeer($device6);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('IP_POOL_EXHAUSTED');
        $this->ipam->allocate($this->pool, $device6, $peer6);
    }

    public function test_released_allocation_becomes_reusable(): void
    {
        $customer = $this->createCustomer();
        $device1 = Device::query()->create([
            'customer_id' => $customer->id,
            'device_uuid' => fake()->uuid(),
            'platform' => 'ANDROID',
            'device_name' => 'Device 1',
            'status' => 'ACTIVE',
        ]);
        $peer1 = $this->createTestPeer($device1);

        $alloc1 = $this->ipam->allocate($this->pool, $device1, $peer1);
        $this->assertEquals('10.200.20.2', $alloc1->ip_address);

        // Release alloc1
        $this->ipam->release($alloc1);
        $this->assertEquals(IpAllocationStatus::Released, $alloc1->fresh()->status);
        $this->assertNotNull($alloc1->fresh()->released_at);

        // Allocate again for device2 - should reuse 10.200.20.2 since it's the first available
        $device2 = Device::query()->create([
            'customer_id' => $customer->id,
            'device_uuid' => fake()->uuid(),
            'platform' => 'IOS',
            'device_name' => 'Device 2',
            'status' => 'ACTIVE',
        ]);
        $peer2 = $this->createTestPeer($device2);

        $alloc2 = $this->ipam->allocate($this->pool, $device2, $peer2);
        $this->assertEquals('10.200.20.2', $alloc2->ip_address);
        $this->assertNotEquals($alloc1->id, $alloc2->id);
    }

    public function test_release_for_peer(): void
    {
        $customer = $this->createCustomer();
        $device = Device::query()->create([
            'customer_id' => $customer->id,
            'device_uuid' => fake()->uuid(),
            'platform' => 'ANDROID',
            'device_name' => 'Device Test',
            'status' => 'ACTIVE',
        ]);
        $peer = $this->createTestPeer($device);
        $alloc = $this->ipam->allocate($this->pool, $device, $peer);

        $this->ipam->releaseForPeer($peer);
        $this->assertEquals(IpAllocationStatus::Released, $alloc->fresh()->status);
    }

    public function test_validates_malformed_cidrs(): void
    {
        $this->assertFalse($this->ipam->validatePool('not-a-cidr', 24, '10.0.0.1'));
        $this->assertFalse($this->ipam->validatePool('10.0.0.0/31', 31, '10.0.0.1'));
        $this->assertFalse($this->ipam->validatePool('10.0.0.0/24', 24, '192.168.1.1'));
        $this->assertTrue($this->ipam->validatePool('10.200.99.0/24', 24, '10.200.99.1'));
    }

    public function test_get_pool_capacity_counts(): void
    {
        $capacity29 = $this->ipam->getPoolCapacity(29);
        $this->assertEquals(5, $capacity29);

        $capacity24 = $this->ipam->getPoolCapacity(24);
        $this->assertEquals(253, $capacity24);
    }
}
