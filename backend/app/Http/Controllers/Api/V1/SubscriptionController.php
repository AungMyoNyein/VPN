<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Subscriptions\EntitlementService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly EntitlementService $entitlementService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->attributes->get('customer');

        // Not filtered to ACTIVE — subscription must remain readable even
        // when expired (only VPN provisioning is gated on usability).
        $subscription = $customer->subscriptions()
            ->with('plan')
            ->orderByDesc('expires_at')
            ->first();

        return ApiResponse::success([
            'subscription' => $subscription,
            'entitlement_state' => $this->entitlementService->effectiveState($subscription)->value,
            'max_devices' => $this->entitlementService->effectiveMaxDevices($subscription),
        ]);
    }
}
