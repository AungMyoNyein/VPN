<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V1\Subscriptions\StoreSubscriptionRequest;
use App\Http\Requests\Admin\V1\Subscriptions\UpdateSubscriptionRequest;
use App\Models\AdminUser;
use App\Models\Subscription;
use App\Services\Audit\AuditLogger;
use App\Services\Subscriptions\EntitlementService;
use App\Services\Subscriptions\SubscriptionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly EntitlementService $entitlementService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Subscription::query()->with(['customer', 'plan'])->orderByDesc('id');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->get('customer_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->get('status'));
        }

        return ApiResponse::success($query->paginate((int) $request->get('per_page', 15)));
    }

    public function store(StoreSubscriptionRequest $request): JsonResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user();

        return ApiResponse::success([
            'subscription' => $this->subscriptionService->create($request->validated(), $actor),
        ], status: 201);
    }

    public function show(Subscription $subscription): JsonResponse
    {
        $subscription->load(['customer', 'plan']);

        return ApiResponse::success([
            'subscription' => $subscription,
            'entitlement' => [
                'state' => $this->entitlementService->effectiveState($subscription)->value,
                'max_devices' => $this->entitlementService->effectiveMaxDevices($subscription),
                'is_usable' => $this->entitlementService->isUsable($subscription),
            ],
        ]);
    }

    public function update(UpdateSubscriptionRequest $request, Subscription $subscription): JsonResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user();
        $before = $subscription->toArray();

        $subscription->update($request->validated());

        $this->auditLogger->log(
            'subscription.updated',
            'subscription',
            $subscription->id,
            before: $before,
            after: $subscription->fresh()->toArray(),
            actor: $actor,
        );

        return ApiResponse::success(['subscription' => $subscription->fresh()->load('plan')]);
    }
}
