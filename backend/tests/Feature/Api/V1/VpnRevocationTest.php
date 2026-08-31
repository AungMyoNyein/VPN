<?php

namespace Tests\Feature\Api\V1;

use App\Enums\DeviceStatus;
use App\Enums\IpAllocationStatus;
use App\Enums\NodeHealthStatus;
use App\Enums\NodeLifecycleStatus;
use App\Enums\PeerStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Device;
use App\Models\Location;
use App\Models\VpnIpAllocation;
use App\Models\VpnNode;
use App\Models\VpnPeer;
use App\Services\Devices\DeviceCredentialService;
use App\Services\Devices\DeviceService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\ActivationTestCase;

class VpnRevocationTest extends ActivationTestCase
{
    private string $rawToken;
    private Device $device;
    private Location $location;
    private VpnNode $node;

    protected function setUp(): void
    {
        parent::setUp();

        $customer = $this->createCustomer();
        $this->createSubscription($customer);

        $this->device = Device::query()->create([
            'customer_id' => $customer->id,
            'device_uuid' => fake()->uuid(),
            'platform' => 'ANDROID',
            'device_name' => 'Pixel Revocation',
            'status' => DeviceStatus::Active,
        ]);

        $issued = app(DeviceCredentialService::class)->issue($this->device);
        $this->rawToken = $issued['plaintext_token'];

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

        Http::fake([
            '*/internal/v1/peers*' => Http::response([
                'data' => [
                    'id' => 1,
                    'peer_id' => 'peer_mock',
                    'status' => 'ACTIVE',
                ],
            ], 200),
        ]);
    }

    public function test_device_can_revoke_its_own_peer(): void
    {
        // 1. Provision peer
        $this->withDeviceCredential($this->rawToken)
            ->postJson('/api/v1/vpn/provision', [
                'location_id' => $this->location->id,
                'client_public_key' => 'jXpGt9enG8oV8lxX4vwNBi1czL89KqL8ImmWToKVHyv=',
            ])
            ->assertCreated();

        $peer = VpnPeer::query()->where('device_id', $this->device->id)->first();
        $this->assertEquals(PeerStatus::Active, $peer->status);

        $alloc = VpnIpAllocation::query()->where('vpn_peer_id', $peer->id)->first();
        $this->assertEquals(IpAllocationStatus::Allocated, $alloc->status);

        // 2. Revoke peer
        $res = $this->withDeviceCredential($this->rawToken)
            ->postJson('/api/v1/vpn/revoke');

        $res->assertOk();
        $this->assertTrue($res->json('data.revoked'));

        $peer->refresh();
        $alloc->refresh();

        $this->assertEquals(PeerStatus::Revoked, $peer->status);
        $this->assertNotNull($peer->revoked_at);
        $this->assertEquals(IpAllocationStatus::Released, $alloc->status);
        $this->assertNotNull($alloc->released_at);
    }

    public function test_device_revocation_triggers_peer_revocation(): void
    {
        // 1. Provision peer
        $this->withDeviceCredential($this->rawToken)
            ->postJson('/api/v1/vpn/provision', [
                'location_id' => $this->location->id,
                'client_public_key' => 'jXpGt9enG8oV8lxX4vwNBi1czL89KqL8ImmWToKVHyv=',
            ])
            ->assertCreated();

        $peer = VpnPeer::query()->where('device_id', $this->device->id)->first();
        $this->assertEquals(PeerStatus::Active, $peer->status);

        // 2. Admin revokes device through DeviceService
        $admin = $this->systemAdmin();
        app(DeviceService::class)->revoke($this->device, $admin, 'Admin test revoke');

        $peer->refresh();
        $this->assertEquals(PeerStatus::Revoked, $peer->status);
        $this->assertEquals('DEVICE_REVOKED', $peer->failure_reason);
    }

    public function test_subscription_expiry_command_revokes_active_peers(): void
    {
        // 1. Provision peer
        $this->withDeviceCredential($this->rawToken)
            ->postJson('/api/v1/vpn/provision', [
                'location_id' => $this->location->id,
                'client_public_key' => 'jXpGt9enG8oV8lxX4vwNBi1czL89KqL8ImmWToKVHyv=',
            ])
            ->assertCreated();

        $peer = VpnPeer::query()->where('device_id', $this->device->id)->first();
        $this->assertEquals(PeerStatus::Active, $peer->status);

        // 2. Expire subscription
        $this->device->customer->subscriptions()->update([
            'status' => SubscriptionStatus::Active,
            'expires_at' => now()->subHours(2),
        ]);

        // 3. Run artisan command
        $exitCode = Artisan::call('vpn:process-expired-subscriptions');
        $this->assertEquals(0, $exitCode);

        $peer->refresh();
        $this->assertEquals(PeerStatus::Revoked, $peer->status);
        $this->assertEquals('SUBSCRIPTION_EXPIRED', $peer->failure_reason);
    }
}
