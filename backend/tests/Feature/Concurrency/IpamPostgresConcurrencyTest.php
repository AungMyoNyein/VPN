<?php

namespace Tests\Feature\Concurrency;

use App\Enums\DeviceStatus;
use App\Enums\NodeHealthStatus;
use App\Enums\NodeLifecycleStatus;
use App\Enums\PeerStatus;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Location;
use App\Models\VpnIpAllocation;
use App\Models\VpnIpPool;
use App\Models\VpnNode;
use App\Models\VpnPeer;
use App\Services\Ipam\IpamService;
use Exception;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

class IpamPostgresConcurrencyTest extends TestCase
{
    private bool $postgresAvailable = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available in this environment.');
        }

        try {
            $pdo = new PDO('pgsql:host=127.0.0.1;port=15432;dbname=vpn', 'vpn', 'vpn_dev_password');
            $this->postgresAvailable = true;
        } catch (Exception) {
            $this->markTestSkipped('PostgreSQL test container not reachable at 127.0.0.1:15432.');
        }

        Config::set('database.default', 'pgsql');
        Config::set('database.connections.pgsql.host', '127.0.0.1');
        Config::set('database.connections.pgsql.port', 15432);
        Config::set('database.connections.pgsql.database', 'vpn');
        Config::set('database.connections.pgsql.username', 'vpn');
        Config::set('database.connections.pgsql.password', 'vpn_dev_password');

        DB::purge('pgsql');
        DB::reconnect('pgsql');

        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        if ($this->postgresAvailable) {
            DB::disconnect('pgsql');
        }
        parent::tearDown();
    }

    public function test_concurrent_ipam_allocations_on_postgres_are_race_safe_and_unique(): void
    {
        // 1. Setup fixtures in Postgres
        $location = Location::query()->create([
            'country_code' => 'SG',
            'country_name' => 'Singapore',
            'city' => 'Singapore',
            'display_name' => 'Singapore',
            'active' => true,
        ]);

        $node = VpnNode::query()->create([
            'location_id' => $location->id,
            'name' => 'Postgres Race Node',
            'hostname' => 'pg-race.vpn.local',
            'public_endpoint' => 'pg-race.vpn.local',
            'vpn_port' => 51820,
            'public_key' => 'PGRACE111111111111111111111111111111111111=',
            'capacity_users' => 100,
            'health_status' => NodeHealthStatus::Healthy,
            'lifecycle_status' => NodeLifecycleStatus::Active,
            'weight' => 100,
        ]);

        $pool = VpnIpPool::query()->create([
            'node_id' => $node->id,
            'network' => '10.200.88.0/24',
            'prefix_length' => 24,
            'gateway' => '10.200.88.1',
            'active' => true,
        ]);

        $customer = Customer::query()->create([
            'customer_code' => 'CUST-PGRACE01',
            'name' => 'Postgres Race Customer',
            'status' => 'ACTIVE',
        ]);

        $concurrency = 8;
        $devices = [];
        $peers = [];

        for ($i = 0; $i < $concurrency; $i++) {
            $device = Device::query()->create([
                'customer_id' => $customer->id,
                'device_uuid' => fake()->uuid(),
                'platform' => 'ANDROID',
                'device_name' => "Race Device $i",
                'status' => DeviceStatus::Active,
            ]);

            $peer = VpnPeer::query()->create([
                'peer_code' => 'PEER-PGRACE-' . $i,
                'device_id' => $device->id,
                'node_id' => $node->id,
                'public_key' => fake()->unique()->regexify('[A-Za-z0-9+/]{43}='),
                'assigned_ip' => '0.0.0.0',
                'status' => PeerStatus::Pending,
            ]);

            $devices[] = $device;
            $peers[] = $peer;
        }

        // Close parent connection before fork
        DB::disconnect('pgsql');

        $resultFile = sys_get_temp_dir() . '/ipam_race_results_' . uniqid() . '.json';
        file_put_contents($resultFile, json_encode([]));

        $pids = [];
        for ($i = 0; $i < $concurrency; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Failed to fork process for concurrency test');
            } elseif ($pid === 0) {
                // Child process
                DB::reconnect('pgsql');
                $ipam = app(IpamService::class);

                try {
                    $alloc = $ipam->allocate($pool, $devices[$i], $peers[$i]);
                    $ip = $alloc->ip_address;
                } catch (Exception $e) {
                    $ip = 'ERROR: ' . $e->getMessage();
                }

                // Write result safely with file lock
                $fp = fopen($resultFile, 'c+');
                if (flock($fp, LOCK_EX)) {
                    $content = stream_get_contents($fp);
                    $arr = $content ? json_decode($content, true) : [];
                    $arr[] = [
                        'worker' => $i,
                        'ip' => $ip,
                    ];
                    ftruncate($fp, 0);
                    rewind($fp);
                    fwrite($fp, json_encode($arr));
                    flock($fp, LOCK_UN);
                }
                fclose($fp);

                DB::disconnect('pgsql');
                exit(0);
            } else {
                $pids[] = $pid;
            }
        }

        // Wait for all children
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        DB::reconnect('pgsql');

        $results = json_decode(file_get_contents($resultFile), true);
        @unlink($resultFile);

        $this->assertCount($concurrency, $results);

        $allocatedIps = array_column($results, 'ip');
        // Ensure none resulted in an error
        foreach ($allocatedIps as $ip) {
            $this->assertStringNotContainsString('ERROR', $ip);
            $this->assertStringStartsWith('10.200.88.', $ip);
        }

        // Ensure all allocated IPs are unique!
        $uniqueIps = array_unique($allocatedIps);
        $this->assertCount($concurrency, $uniqueIps, 'Concurrent IP allocations produced duplicate IPs: ' . implode(', ', $allocatedIps));

        // Check PostgreSQL database state
        $dbAllocations = VpnIpAllocation::query()->where('pool_id', $pool->id)->whereNull('released_at')->count();
        $this->assertEquals($concurrency, $dbAllocations);
    }
}
