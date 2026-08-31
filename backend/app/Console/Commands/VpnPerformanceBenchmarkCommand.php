<?php

namespace App\Console\Commands;

use App\Enums\DeviceStatus;
use App\Enums\NodeHealthStatus;
use App\Enums\NodeLifecycleStatus;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Location;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\VpnIpPool;
use App\Models\VpnNode;
use App\Models\VpnPeer;
use App\Services\Ipam\IpamService;
use App\Services\Nodes\NodeSelectionService;
use App\Services\Vpn\VpnProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VpnPerformanceBenchmarkCommand extends Command
{
    protected $signature = 'vpn:benchmark {--count=1000 : Number of provisioning cycles}';
    protected $description = 'Run a performance baseline test for VPN node selection, IPAM, and provisioning against the fake adapter.';

    public function handle(
        IpamService $ipamService,
        NodeSelectionService $nodeSelectionService,
        VpnProvisioningService $provisioningService
    ): int {
        $count = (int) $this->option('count');
        $this->info("Starting VPN provisioning performance baseline benchmark with {$count} records...");

        // 1. Setup multiple locations and nodes with large IP pools (/16 or /20)
        $this->info('Setting up benchmark fixtures...');
        $plan = Plan::query()->firstOrCreate(
            ['code' => 'BENCH_PLAN'],
            ['name' => 'Benchmark Plan', 'price' => 9.99, 'currency' => 'USD', 'duration_days' => 30, 'max_devices' => 5, 'active' => true]
        );

        $locSingapore = Location::query()->firstOrCreate(
            ['country_code' => 'SG'],
            ['country_name' => 'Singapore', 'city' => 'Singapore', 'display_name' => 'Singapore', 'active' => true, 'sort_order' => 1]
        );
        $locTokyo = Location::query()->firstOrCreate(
            ['country_code' => 'JP'],
            ['country_name' => 'Japan', 'city' => 'Tokyo', 'display_name' => 'Tokyo', 'active' => true, 'sort_order' => 2]
        );

        $nodeSg = VpnNode::query()->firstOrCreate(
            ['hostname' => 'sg-bench.vpn.local'],
            [
                'location_id' => $locSingapore->id,
                'name' => 'SG Benchmark Node',
                'public_endpoint' => 'sg-bench.vpn.local',
                'vpn_port' => 51820,
                'public_key' => 'SGBENCH111111111111111111111111111111111111=',
                'capacity_users' => 5000,
                'health_status' => NodeHealthStatus::Healthy,
                'lifecycle_status' => NodeLifecycleStatus::Active,
                'weight' => 100,
            ]
        );

        $nodeJp = VpnNode::query()->firstOrCreate(
            ['hostname' => 'jp-bench.vpn.local'],
            [
                'location_id' => $locTokyo->id,
                'name' => 'JP Benchmark Node',
                'public_endpoint' => 'jp-bench.vpn.local',
                'vpn_port' => 51820,
                'public_key' => 'JPBENCH111111111111111111111111111111111111=',
                'capacity_users' => 5000,
                'health_status' => NodeHealthStatus::Healthy,
                'lifecycle_status' => NodeLifecycleStatus::Active,
                'weight' => 100,
            ]
        );

        $poolSg = VpnIpPool::query()->firstOrCreate(
            ['node_id' => $nodeSg->id],
            ['network' => '10.200.0.0/16', 'prefix_length' => 16, 'gateway' => '10.200.0.1', 'active' => true]
        );
        $poolJp = VpnIpPool::query()->firstOrCreate(
            ['node_id' => $nodeJp->id],
            ['network' => '10.201.0.0/16', 'prefix_length' => 16, 'gateway' => '10.201.0.1', 'active' => true]
        );

        // Pre-create customers and devices in bulk
        $this->info("Creating {$count} customers, subscriptions, and devices...");
        $customers = [];
        $devices = [];

        DB::beginTransaction();
        for ($i = 1; $i <= $count; $i++) {
            $customer = Customer::create([
                'customer_code' => 'CUST-BENCH-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'name' => "Benchmark Customer $i",
                'status' => 'ACTIVE',
            ]);
            $customer->subscriptions()->create([
                'plan_id' => $plan->id,
                'status' => 'ACTIVE',
                'starts_at' => now()->subDay(),
                'expires_at' => now()->addMonth(),
            ]);
            $device = Device::create([
                'customer_id' => $customer->id,
                'device_uuid' => (string) Str::uuid(),
                'platform' => $i % 2 === 0 ? 'IOS' : 'ANDROID',
                'device_name' => "Benchmark Device $i",
                'status' => DeviceStatus::Active,
            ]);
            $customers[] = $customer;
            $devices[] = $device;
        }
        DB::commit();

        // 2. Measure Node Selection Latency (1,000 iterations)
        $this->info('Benchmarking Node Selection...');
        $t0 = microtime(true);
        for ($i = 0; $i < $count; $i++) {
            $nodeSelectionService->selectNode($i % 2 === 0 ? $locSingapore->id : $locTokyo->id);
        }
        $nodeSelectionTime = (microtime(true) - $t0) * 1000;
        $avgNodeSelection = $nodeSelectionTime / $count;

        // 3. Measure IPAM Allocation Latency (1,000 iterations)
        $this->info('Benchmarking IPAM Allocation...');
        $t1 = microtime(true);
        for ($i = 0; $i < $count; $i++) {
            $pool = $i % 2 === 0 ? $poolSg : $poolJp;
            $peer = VpnPeer::create([
                'peer_code' => 'PEER-BENCH-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'device_id' => $devices[$i]->id,
                'node_id' => $pool->node_id,
                'public_key' => fake()->unique()->regexify('[A-Za-z0-9+/]{43}='),
                'assigned_ip' => '0.0.0.0',
                'status' => 'PENDING',
            ]);
            $alloc = $ipamService->allocate($pool, $devices[$i], $peer);
            $peer->update(['assigned_ip' => $alloc->ip_address, 'status' => 'ACTIVE']);
        }
        $ipamTime = (microtime(true) - $t1) * 1000;
        $avgIpam = $ipamTime / $count;

        // 4. Measure Peer List / Lookup Queries (1,000 iterations)
        $this->info('Benchmarking Peer Lookup & Query...');
        $t2 = microtime(true);
        for ($i = 0; $i < $count; $i++) {
            $devices[$i]->activePeer;
        }
        $peerQueryTime = (microtime(true) - $t2) * 1000;
        $avgPeerQuery = $peerQueryTime / $count;

        // Output results table
        $this->newLine();
        $this->info('=== VPN PERFORMANCE BASELINE RESULTS ===');
        $this->table(
            ['Metric', 'Total Time (ms)', 'Ops / Sec', 'Avg Latency (ms/op)'],
            [
                ['Node Selection (' . $count . ' ops)', round($nodeSelectionTime, 2), round(($count / ($nodeSelectionTime / 1000)), 1), round($avgNodeSelection, 3)],
                ['IPAM Allocation (' . $count . ' ops)', round($ipamTime, 2), round(($count / ($ipamTime / 1000)), 1), round($avgIpam, 3)],
                ['Peer Query / Active (' . $count . ' ops)', round($peerQueryTime, 2), round(($count / ($peerQueryTime / 1000)), 1), round($avgPeerQuery, 3)],
            ]
        );

        return 0;
    }
}
