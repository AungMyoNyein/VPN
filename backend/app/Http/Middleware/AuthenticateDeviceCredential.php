<?php

namespace App\Http\Middleware;

use App\Enums\CustomerStatus;
use App\Enums\DeviceStatus;
use App\Services\Devices\DeviceCredentialService;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves `Authorization: Bearer <token>` into device + customer for the
 * public /api/v1/* surface (alias: "device.auth"). No email/password
 * customer login exists — this is the only customer authentication path
 * after activation (ADR-0008).
 */
class AuthenticateDeviceCredential
{
    public function __construct(
        private readonly DeviceCredentialService $deviceCredentialService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return ApiResponse::error('DEVICE_CREDENTIAL_INVALID', 'Missing device credential.', 401);
        }

        $credential = $this->deviceCredentialService->findByPlaintext($token);

        if ($credential === null) {
            return ApiResponse::error('DEVICE_CREDENTIAL_INVALID', 'Device credential is invalid.', 401);
        }

        if ($credential->revoked_at !== null
            || ($credential->expires_at !== null && $credential->expires_at->isPast())) {
            return ApiResponse::error('DEVICE_CREDENTIAL_REVOKED', 'Device credential has been revoked or has expired.', 401);
        }

        $device = $credential->device;

        if ($device === null) {
            return ApiResponse::error('DEVICE_CREDENTIAL_INVALID', 'Device credential is invalid.', 401);
        }

        if ($device->status === DeviceStatus::Revoked) {
            return ApiResponse::error('DEVICE_REVOKED', 'Device has been revoked.', 403);
        }

        if ($device->status === DeviceStatus::Blocked) {
            return ApiResponse::error('DEVICE_BLOCKED', 'Device has been blocked.', 403);
        }

        $customer = $device->customer;

        if ($customer === null) {
            return ApiResponse::error('DEVICE_CREDENTIAL_INVALID', 'Device credential is invalid.', 401);
        }

        if ($customer->status === CustomerStatus::Suspended) {
            return ApiResponse::error('CUSTOMER_SUSPENDED', 'Customer account is suspended.', 403);
        }

        if ($customer->status !== CustomerStatus::Active) {
            return ApiResponse::error('CUSTOMER_BLOCKED', 'Customer account is not active.', 403);
        }

        $credential->forceFill(['last_used_at' => now()])->save();

        $request->attributes->set('device', $device);
        $request->attributes->set('customer', $customer);
        $request->attributes->set('device_credential', $credential);

        return $next($request);
    }
}
