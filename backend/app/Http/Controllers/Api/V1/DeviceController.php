<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Device;
use App\Services\Devices\DeviceCredentialService;
use App\Services\Devices\DeviceService;
use App\Services\Subscriptions\EntitlementService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service device endpoints, authenticated via device credential
 * bearer token ("device.auth" middleware). No admin actions live here —
 * admin revoke/block/reset-binding stay under /api/admin/v1.
 */
class DeviceController extends Controller
{
    public function __construct(
        private readonly DeviceService $deviceService,
        private readonly DeviceCredentialService $deviceCredentialService,
        private readonly EntitlementService $entitlementService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');
        /** @var Customer $customer */
        $customer = $request->attributes->get('customer');

        $subscription = $customer->subscriptions()
            ->where('status', 'ACTIVE')
            ->orderByDesc('expires_at')
            ->first();

        return ApiResponse::success([
            'device' => $device,
            'entitlement' => [
                'max_devices' => $this->entitlementService->effectiveMaxDevices($subscription),
                'active_devices' => $this->deviceService->activeDeviceCount($customer),
            ],
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        $issued = $this->deviceCredentialService->rotate($device);

        return ApiResponse::success([
            'device_credential' => $issued['plaintext_token'],
            'credential_expires_at' => $issued['credential']->expires_at,
        ]);
    }

    public function deactivate(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        $this->deviceService->revoke($device);

        return ApiResponse::success([
            'message' => 'Device deactivated.',
        ]);
    }
}
