<?php

namespace App\Services\Ipam;

use App\Enums\IpAllocationStatus;
use App\Models\Device;
use App\Models\VpnIpAllocation;
use App\Models\VpnIpPool;
use App\Models\VpnPeer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class IpamService
{
    /**
     * Allocate a unique IP address from the given pool for a device and peer.
     * Must be called within or wraps a transactional lock to ensure concurrency safety.
     *
     * @throws RuntimeException when pool is exhausted
     */
    public function allocate(VpnIpPool $pool, Device $device, VpnPeer $peer): VpnIpAllocation
    {
        return DB::transaction(function () use ($pool, $device, $peer) {
            // Lock the pool to serialize allocations on this pool
            $lockedPool = VpnIpPool::query()
                ->where('id', $pool->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedPool->active) {
                throw new RuntimeException('IP_POOL_INACTIVE');
            }

            $allocatedIps = VpnIpAllocation::query()
                ->where('pool_id', $lockedPool->id)
                ->whereNull('released_at')
                ->where('status', IpAllocationStatus::Allocated)
                ->pluck('ip_address')
                ->all();

            $allocatedSet = array_flip($allocatedIps);

            $availableIp = $this->findNextAvailableIp(
                $lockedPool->network,
                $lockedPool->prefix_length,
                $lockedPool->gateway,
                $allocatedSet
            );

            if ($availableIp === null) {
                throw new RuntimeException('IP_POOL_EXHAUSTED');
            }

            return VpnIpAllocation::create([
                'pool_id' => $lockedPool->id,
                'device_id' => $device->id,
                'vpn_peer_id' => $peer->id,
                'ip_address' => $availableIp,
                'status' => IpAllocationStatus::Allocated,
                'allocated_at' => now(),
            ]);
        });
    }

    /**
     * Release an IP allocation.
     */
    public function release(VpnIpAllocation $allocation): VpnIpAllocation
    {
        $allocation->update([
            'status' => IpAllocationStatus::Released,
            'released_at' => now(),
        ]);

        return $allocation->fresh();
    }

    /**
     * Release any active allocation associated with a peer.
     */
    public function releaseForPeer(VpnPeer $peer): void
    {
        VpnIpAllocation::query()
            ->where('vpn_peer_id', $peer->id)
            ->whereNull('released_at')
            ->update([
                'status' => IpAllocationStatus::Released,
                'released_at' => now(),
            ]);
    }

    /**
     * Calculate next free IP in the subnet excluding network, gateway, and broadcast.
     *
     * @param array<string, int> $allocatedSet
     */
    public function findNextAvailableIp(string $networkCidr, int $prefixLength, string $gateway, array $allocatedSet): ?string
    {
        [$baseIp] = explode('/', $networkCidr);
        $baseLong = ip2long($baseIp);
        $gatewayLong = ip2long($gateway);

        if ($baseLong === false || $gatewayLong === false) {
            throw new InvalidArgumentException("Invalid CIDR or gateway: {$networkCidr}, {$gateway}");
        }

        $mask = -1 << (32 - $prefixLength);
        $networkLong = $baseLong & $mask;
        $broadcastLong = $networkLong | (~$mask & 0xFFFFFFFF);

        // Reserved: network address (networkLong), broadcast (broadcastLong), gateway (gatewayLong)
        $reserved = [
            $networkLong => true,
            $broadcastLong => true,
            $gatewayLong => true,
        ];

        for ($ipLong = $networkLong + 1; $ipLong < $broadcastLong; $ipLong++) {
            if (isset($reserved[$ipLong])) {
                continue;
            }

            $ipStr = long2ip($ipLong);
            if (! isset($allocatedSet[$ipStr])) {
                return $ipStr;
            }
        }

        return null;
    }

    /**
     * Calculate total usable capacity for a pool.
     */
    public function getPoolCapacity(int $prefixLength): int
    {
        if ($prefixLength < 1 || $prefixLength > 30) {
            return 0;
        }

        $totalHosts = 1 << (32 - $prefixLength);
        // Exclude network, broadcast, and gateway (3 IPs)
        return max(0, $totalHosts - 3);
    }

    /**
     * Validate CIDR and gateway compatibility.
     */
    public function validatePool(string $networkCidr, int $prefixLength, string $gateway): bool
    {
        if ($prefixLength < 16 || $prefixLength > 30) {
            return false;
        }

        [$baseIp, $cidrPrefix] = array_pad(explode('/', $networkCidr), 2, null);
        if ($cidrPrefix !== null && (int) $cidrPrefix !== $prefixLength) {
            return false;
        }

        $baseLong = ip2long($baseIp);
        $gatewayLong = ip2long($gateway);

        if ($baseLong === false || $gatewayLong === false) {
            return false;
        }

        $mask = -1 << (32 - $prefixLength);
        $networkLong = $baseLong & $mask;
        $broadcastLong = $networkLong | (~$mask & 0xFFFFFFFF);

        // Gateway must be inside subnet and not be network/broadcast
        return $gatewayLong > $networkLong && $gatewayLong < $broadcastLong;
    }
}
