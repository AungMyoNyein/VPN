<?php

namespace Tests\Feature\Api\V1;

use App\Enums\DeviceStatus;
use App\Enums\NodeHealthStatus;
use App\Enums\NodeLifecycleStatus;
use App\Enums\PeerStatus;
use App\Models\Device;
use App\Models\Location;
use App\Models\ProvisioningOperation;
use App\Models\VpnIpAllocation;
use App\Models\VpnNode;
use App\Models\VpnPeer;
use App\Services\Devices\DeviceCredentialService;
use Illuminate\Support\Facades\Http;
use Tests\ActivationTestCase;

class VpnIdempotencyTest extends ActivationTestCase
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
            'device_name' => 'Pixel Idempotency',
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

    public function test_repeated_provisioning_with_same_idempotency_key_returns_same_result(): void
    {
        $idempotencyKey = 'idem-req-' . fake()->uuid();
        $pubKey = 'jXpGt9enG8oV8lxX4vwNBi1czL89KqL8ImmWToKVHyv=';

        $payload = [
            'location_id' => $this->location->id,
            'client_public_key' => $pubKey,
        ];

        // First call
        $res1 = $this->withDeviceCredential($this->rawToken)
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/vpn/provision', $payload);

        $res1->assertCreated();
        $data1 = $res1->json('data');

        // Second call with same idempotency key
        $res2 = $this->withDeviceCredential($this->rawToken)
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/vpn/provision', $payload);

        $res2->assertCreated();
        $data2 = $res2->json('data');

        // Assert identical payloads
        $this->assertEquals($data1['peer_id'], $data2['peer_id']);
        $this->assertEquals($data1['address'], $data2['address']);

        // Assert exactly 1 peer, 1 allocation, 1 provisioning operation
        $this->assertEquals(1, VpnPeer::query()->where('device_id', $this->device->id)->count());
        $this->assertEquals(1, VpnIpAllocation::query()->where('device_id', $this->device->id)->count());
        $this->assertEquals(1, ProvisioningOperation::query()->where('idempotency_key', $idempotencyKey)->count());
    }

    public function test_reprovisioning_with_new_key_revokes_previous_peer(): void
    {
        $pubKey1 = 'jXpGt9enG8oV8lxX4vwNBi1czL89KqL8ImmWToKVHyv=';
        $pubKey2 = 'bXqhPrv20Pr1b7Mwx85tf1pjX3nJw/99GxamWOHGHHK=';

        // Provision peer 1
        $res1 = $this->withDeviceCredential($this->rawToken)
            ->postJson('/api/v1/vpn/provision', [
                'location_id' => $this->location->id,
                'client_public_key' => $pubKey1,
            ])
            ->assertCreated();

        $peer1 = VpnPeer::query()->where('public_key', $pubKey1)->first();
        $this->assertEquals(PeerStatus::Active, $peer1->status);

        // Provision peer 2 with different key
        $res2 = $this->withDeviceCredential($this->rawToken)
            ->postJson('/api/v1/vpn/provision', [
                'location_id' => $this->location->id,
                'client_public_key' => $pubKey2,
            ])
            ->assertCreated();

        $peer1->refresh();
        $peer2 = VpnPeer::query()->where('public_key', $pubKey2)->first();

        // Old peer is now REVOKED
        $this->assertEquals(PeerStatus::Revoked, $peer1->status);
        $this->assertNotNull($peer1->revoked_at);

        // New peer is ACTIVE
        $this->assertEquals(PeerStatus::Active, $peer2->status);

        // Invariant: only 1 active peer exists for this device
        $activePeers = VpnPeer::query()
            ->where('device_id', $this->device->id)
            ->whereIn('status', [PeerStatus::Pending, PeerStatus::Active, PeerStatus::Revoking])
            ->count();
        $this->assertEquals(1, $activePeers);
    }
}
