<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Device;
use App\Services\Devices\DeviceService;
use App\Services\Subscriptions\EntitlementService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function __construct(
        private readonly DeviceService $deviceService,
        private readonly EntitlementService $entitlementService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Device::query()->with(['customer', 'activeCredential'])->orderByDesc('id');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->get('customer_id'));
        }

        $paginator = $query->paginate((int) $request->get('per_page', 15));
        $paginator->getCollection()->transform(function (Device $device) {
            $payload = $device->toArray();
            unset($payload['device_token_hash'], $payload['active_credential'], $payload['credentials']);
            $payload['has_active_credential'] = $device->activeCredential !== null;

            return $payload;
        });

        return ApiResponse::success($paginator);
    }

    public function show(Device $device): JsonResponse
    {
        $device->load(['customer', 'activeCredential']);

        $subscription = $device->customer->subscriptions()
            ->where('status', 'ACTIVE')
            ->orderByDesc('expires_at')
            ->first();

        $payload = $device->toArray();
        unset($payload['device_token_hash'], $payload['active_credential'], $payload['credentials']);
        $payload['has_active_credential'] = $device->activeCredential !== null;
        $payload['credential_issued_at'] = $device->activeCredential?->issued_at;
        $payload['credential_last_used_at'] = $device->activeCredential?->last_used_at;

        return ApiResponse::success([
            'device' => $payload,
            'entitlement' => [
                'max_devices' => $this->entitlementService->effectiveMaxDevices($subscription),
                'active_devices' => $this->deviceService->activeDeviceCount($device->customer),
            ],
        ]);
    }

    public function revoke(Device $device, Request $request): JsonResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user();

        return ApiResponse::success([
            'device' => $this->deviceService->revoke($device, $actor),
        ]);
    }

    public function block(Device $device, Request $request): JsonResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user();

        return ApiResponse::success([
            'device' => $this->deviceService->block($device, $actor),
        ]);
    }

    public function resetBinding(Device $device, Request $request): JsonResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user();

        return ApiResponse::success([
            'device' => $this->deviceService->resetBinding($device, $actor),
        ]);
    }
}
