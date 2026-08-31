<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\VpnIpPool;
use App\Services\Audit\AuditLogger;
use App\Services\Ipam\IpamService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IpPoolController extends Controller
{
    public function __construct(
        private readonly IpamService $ipamService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = VpnIpPool::query()
            ->with(['node.location'])
            ->withCount(['allocations as active_allocations_count' => fn ($q) => $q->whereNull('released_at')]);

        if ($request->filled('node_id')) {
            $query->where('node_id', $request->get('node_id'));
        }

        $pools = $query->get()->map(function (VpnIpPool $pool) {
            $payload = $pool->toArray();
            $capacity = $this->ipamService->getPoolCapacity($pool->prefix_length);
            $allocated = $pool->active_allocations_count ?? 0;
            $payload['capacity'] = $capacity;
            $payload['allocated_count'] = $allocated;
            $payload['available_count'] = max(0, $capacity - $allocated);

            return $payload;
        });

        return ApiResponse::success(['ip_pools' => $pools]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'node_id' => ['required', 'exists:vpn_nodes,id'],
            'network' => ['required', 'string'],
            'prefix_length' => ['required', 'integer', 'min:16', 'max:30'],
            'gateway' => ['required', 'ip'],
        ]);

        if (! $this->ipamService->validatePool($validated['network'], (int) $validated['prefix_length'], $validated['gateway'])) {
            return ApiResponse::error(
                'INVALID_IP_POOL',
                'Invalid CIDR network or gateway configuration for the pool.',
                422
            );
        }

        $pool = VpnIpPool::create([
            'node_id' => $validated['node_id'],
            'network' => $validated['network'],
            'prefix_length' => $validated['prefix_length'],
            'gateway' => $validated['gateway'],
            'active' => true,
        ]);

        /** @var AdminUser $actor */
        $actor = $request->user();
        $this->auditLogger->log(
            'ip_pool.created',
            'vpn_ip_pool',
            $pool->id,
            before: null,
            after: $pool->toArray(),
            actor: $actor
        );

        return ApiResponse::success(['ip_pool' => $pool], status: 201);
    }

    public function toggleActive(Request $request, VpnIpPool $ipPool): JsonResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user();
        $before = $ipPool->toArray();

        $ipPool->update(['active' => ! $ipPool->active]);

        $this->auditLogger->log(
            'ip_pool.toggled',
            'vpn_ip_pool',
            $ipPool->id,
            before: $before,
            after: $ipPool->fresh()->toArray(),
            actor: $actor
        );

        return ApiResponse::success(['ip_pool' => $ipPool->fresh()]);
    }
}
