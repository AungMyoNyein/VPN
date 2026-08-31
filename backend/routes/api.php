<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\ActivateController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\VpnController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API v1 (Phase 3 — VPN Provisioning, IPAM & Device Authorization)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function (): void {
    Route::get('/health', [HealthController::class, 'show']);

    Route::post('/activate', [ActivateController::class, 'store'])
        ->middleware('throttle:activate');

    Route::middleware('device.auth')->group(function (): void {
        Route::post('/device/refresh', [DeviceController::class, 'refresh']);
        Route::post('/device/deactivate', [DeviceController::class, 'deactivate']);
        Route::get('/device', [DeviceController::class, 'show']);
        Route::get('/account', [AccountController::class, 'show']);
        Route::get('/subscription', [SubscriptionController::class, 'show']);

        // Phase 3 VPN endpoints
        Route::get('/vpn/locations', [VpnController::class, 'locations']);
        Route::get('/vpn/recommended-server', [VpnController::class, 'recommendedServer']);
        Route::get('/vpn/status', [VpnController::class, 'status']);
        Route::post('/vpn/provision', [VpnController::class, 'provision']);
        Route::post('/vpn/revoke', [VpnController::class, 'revoke']);
    });
});
