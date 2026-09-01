<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CustomerStatus;
use App\Enums\DeviceStatus;
use App\Enums\NodeHealthStatus;
use App\Enums\NodeLifecycleStatus;
use App\Enums\PeerStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Device;
use App\Models\Location;
use App\Models\VpnNode;
use App\Models\VpnPeer;
use App\Services\Devices\DeviceCredentialService;
use Illuminate\Support\Facades\Http;
use Tests\ActivationTestCase;

class VpnProvisioningTest extends ActivationTestCase
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
            'device_name' => 'Pixel Test',
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

        // Mock Control Plane responses by default
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

    public function test_1_unauthenticated_device_cannot_provision(): void
    {
        $response = $this->postJson('/api/v1/vpn/provision', [
            'client_public_key' => 'jXpGt9enG8oV8lxX4vwNBi1czL89KqL8ImmWToKVHyv=',
        ]);

        $response->assertStatus(401);
        $this->assertEquals('DEVICE_CREDENTIAL_INVALID', $response->json('error.code'));
    }

    public function test_2_revoked_device_cannot_provision(): void
    {
        $this->device->update(['status' => DeviceStatus::Revoked, 'revoked_at' => now()]);

        $response = $this->withDeviceCredential($this->rawToken)->postJson('/api/v1/vpn/provision', [
            'client_public_key' => 'jXpGt9enG8oV8lxX4vwNBi1czL89KqL8ImmWToKVHyv=',
        ]);

        $response->assertStatus(403);
        $this->assertEquals('DEVICE_REVOKED', $response->json('error.code'));
    }

    public function test_3_suspended_customer_cannot_provision(): void
    {
        $this->device->customer->update(['status' => CustomerStatus::Suspended]);

        $response = $this->withDeviceCredential($this->rawToken)->postJson('/api/v1/vpn/provision', [
            'client_public_key' => 'jXpGt9enG8oV8lxX4vwNBi1czL89KqL8ImmWToKVHyv=',
        ]);

        $response->assertStatus(403);
        $this->assertEquals('CUSTOMER_SUSPENDED', $response->json('error.code'));
    }

    public function test_4_expired_subscription_cannot_provision(): void
    {
        $this->device->customer->subscriptions()->update([
            'status' => SubscriptionStatus::Active,
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->withDeviceCredential($this->rawToken)->postJson('/api/v1/vpn/provision', [
            'client_public_key' => 'jXpGt9enG8oV8lxX4vwNBi1czL89KqL8ImmWToKVHyv=',
        ]);

        $response->assertStatus(403);
        $this->assertEquals('SUBSCRIPTION_EXPIRED', $response->json('error.code'));
    }

    public function test_5_blocked_customer_cannot_provision(): void
    {
        $this->device->customer->update(['status' => CustomerStatus::Blocked]);

        $response = $this->withDeviceCredential($this->rawToken)->postJson('/api/v1/vpn/provision', [
            'client_public_key' => 'jXpGt9enG8oV8lxX4vwNBi1czL89KqL8ImmWToKVHyv=',
        ]);

        $response->assertStatus(403);
        $this->assertEquals('CUSTOMER_BLOCKED', $response->json('error.code'));
    }

    public function test_6_draining_node_receives_no_new_peer(): void
    {
        $this->node->update(['draining' => true]);

        $response = $this->withDeviceCredential($this->rawToken)->postJson('/api/v1/vpn/provision', [
            'location_id' => $this->location->id,
            'client_public_key' => 'jXpGt9enG8oV8lxX4vwNBi1czL89KqL8ImmWToKVHyv=',
        ]);

        $response->assertStatus(503);
        $this->assertEquals('NO_VPN_NODE_AVAILABLE', $response->json('error.code'));
    }

    public function test_7_down_node_receives_no_new_peer(): void
    {
        $this->node->update(['health_status' => NodeHealthStatus::Down]);

        $response = $this->withDeviceCredential($this->rawToken)->postJson('/api/v1/vpn/provision', [
            'location_id' => $this->location->id,
            'client_public_key' => 'jXpGt9enG8oV8lxX4vwNBi1czL89KqL8ImmWToKVHyv=',
        ]);

        $response->assertStatus(503);
        $this->assertEquals('NO_VPN_NODE_AVAILABLE', $response->json('error.code'));
    }

    public function test_8_maintenance_node_receives_no_new_peer(): void
    {
        $this->node->update(['maintenance_mode' => true]);

        $response = $this->withDeviceCredential($this->rawToken)->postJson('/api/v1/vpn/provision', [
            'location_id' => $this->location->id,
            'client_public_key' => 'jXpGt9enG8oV8lxX4vwNBi1czL89KqL8ImmWToKVHyv=',
        ]);

        $response->assertStatus(503);
        $this->assertEquals('NO_VPN_NODE_AVAILABLE', $response->json('error.code'));
    }

    public function test_9_capacity_full_node_receives_no_new_peer(): void
    {
        $this->node->update(['capacity_users' => 1]);

        // Create an existing peer on this node
        VpnPeer::query()->create([
            'peer_code' => 'PEER-'.fake()->unique()->numerify('######'),
            'device_id' => $this->device->id,
            'node_id' => $this->node->id,
            'public_key' => 'bXqhPrv20Pr1b7Mwx85tf1pjX3nJw/99GxamWOHGHHK=',
            'assigned_ip' => '10.200.20.2',
            'status' => PeerStatus::Active,
        ]);

        $otherCustomer = $this->createCustomer();
        $this->createSubscription($otherCustomer);
        $otherDevice = Device::query()->create([
            'customer_id' => $otherCustomer->id,
            'device_uuid' => fake()->uuid(),
            'platform' => 'ANDROID',
            'device_name' => 'Other Device',
            'status' => DeviceStatus::Active,
        ]);
        $issuedOther = app(DeviceCredentialService::class)->issue($otherDevice);

        $response = $this->withDeviceCredential($issuedOther['plaintext_token'])->postJson('/api/v1/vpn/provision', [
            'location_id' => $this->location->id,
            'client_public_key' => 'jXpGt9enG8oV8lxX4vwNBi1czL89KqL8ImmWToKVHyv=',
        ]);

        $response->assertStatus(503);
        $this->assertEquals('NO_VPN_NODE_AVAILABLE', $response->json('error.code'));
    }

    public function test_10_happy_path_provisioning_returns_customer_safe_config(): void
    {
        $response = $this->withDeviceCredential($this->rawToken)->postJson('/api/v1/vpn/provision', [
            'location_id' => $this->location->id,
            'client_public_key' => 'jXpGt9enG8oV8lxX4vwNBi1czL89KqL8ImmWToKVHyv=',
        ]);

        $response->assertCreated();
        $data = $response->json('data');

        $this->assertArrayHasKey('peer_id', $data);
        $this->assertEquals('10.200.20.2/32', $data['address']);
        $this->assertArrayHasKey('server', $data);
        $this->assertEquals('Singapore Test Node', $data['server']['name']);
        $this->assertArrayHasKey('public_key', $data['server']);
        $this->assertArrayHasKey('endpoint', $data['server']);
        $this->assertArrayHasKey('dns', $data);
        $this->assertArrayHasKey('allowed_ips', $data);
        $this->assertEquals(25, $data['persistent_keepalive']);

        // Check DB peer state
        $peer = VpnPeer::query()->where('device_id', $this->device->id)->first();
        $this->assertNotNull($peer);
        $this->assertEquals(PeerStatus::Active, $peer->status);
        $this->assertEquals('10.200.20.2', $peer->assigned_ip);
    }

    public function test_11_rejects_invalid_public_key(): void
    {
        $response = $this->withDeviceCredential($this->rawToken)->postJson('/api/v1/vpn/provision', [
            'client_public_key' => 'too-short',
        ]);

        $response->assertStatus(422);
    }

    public function test_12_locations_and_recommended_server_endpoints(): void
    {
        $locResponse = $this->withDeviceCredential($this->rawToken)->getJson('/api/v1/vpn/locations');
        $locResponse->assertStatus(200);
        $this->assertIsArray($locResponse->json('data'));

        $recResponse = $this->withDeviceCredential($this->rawToken)->getJson('/api/v1/vpn/recommended-server');
        $recResponse->assertStatus(200);
        $this->assertEquals($this->node->id, $recResponse->json('data.node_id'));
    }

    public function test_13_vless_provisioning_returns_structured_config(): void
    {
        $this->node->update([
            'supported_protocols' => ['wireguard', 'vless'],
            'vless_port' => 8443,
            'protocol_config' => [
                'vless' => [
                    'security' => 'tls',
                    'sni' => 'zentunnel.net',
                    'fingerprint' => 'chrome',
                ],
            ],
        ]);

        Http::fake([
            '*/internal/v1/peers*' => Http::response([
                'data' => [
                    'id' => 2,
                    'peer_id' => 'peer_vless_mock',
                    'status' => 'ACTIVE',
                ],
            ], 200),
        ]);

        $response = $this->withDeviceCredential($this->rawToken)->postJson('/api/v1/vpn/provision', [
            'location_id' => $this->location->id,
            'protocol' => 'vless',
        ]);

        $response->assertCreated();
        $data = $response->json('data');

        $this->assertSame('vless', $data['protocol']);
        $this->assertArrayHasKey('connection_id', $data);
        $this->assertArrayHasKey('vless', $data);
        $this->assertSame('tcp', $data['vless']['transport']);
        $this->assertSame('tls', $data['vless']['security']);
        $this->assertSame('zentunnel.net', $data['server']['host']);
        $this->assertSame(8443, $data['server']['port']);
        $this->assertArrayHasKey('share_url', $data);
        $this->assertStringStartsWith('vless://', $data['share_url']);
    }
}
