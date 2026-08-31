<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Devices\DeviceService;
use App\Services\Subscriptions\EntitlementService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function __construct(
        private readonly DeviceService $deviceService,
        private readonly EntitlementService $entitlementService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->attributes->get('customer');

        $subscription = $customer->subscriptions()
            ->where('status', 'ACTIVE')
            ->orderByDesc('expires_at')
            ->first();

        return ApiResponse::success([
            'account' => [
                'customer_code' => $customer->customer_code,
                'name' => $customer->name,
                'status' => $customer->status,
            ],
            'entitlement' => [
                'max_devices' => $this->entitlementService->effectiveMaxDevices($subscription),
                'active_devices' => $this->deviceService->activeDeviceCount($customer),
            ],
        ]);
    }
}
