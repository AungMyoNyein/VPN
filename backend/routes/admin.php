<?php

use App\Http\Controllers\Admin\V1\ActivationKeyController;
use App\Http\Controllers\Admin\V1\AdminUserController;
use App\Http\Controllers\Admin\V1\AuditLogController;
use App\Http\Controllers\Admin\V1\AuthController;
use App\Http\Controllers\Admin\V1\CustomerController;
use App\Http\Controllers\Admin\V1\DashboardController;
use App\Http\Controllers\Admin\V1\DeviceController;
use App\Http\Controllers\Admin\V1\LocationController;
use App\Http\Controllers\Admin\V1\PaymentController;
use App\Http\Controllers\Admin\V1\PlanController;
use App\Http\Controllers\Admin\V1\RoleController;
use App\Http\Controllers\Admin\V1\SubscriptionController;
use App\Http\Controllers\Admin\V1\VpnNodeController;
use App\Support\Permissions;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1');

Route::middleware(['auth:sanctum', 'admin.user'])->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::get('/dashboard', [DashboardController::class, 'show'])
        ->middleware('admin.permission:'.Permissions::DASHBOARD_VIEW);

    Route::get('/customers', [CustomerController::class, 'index'])
        ->middleware('admin.permission:'.Permissions::CUSTOMERS_VIEW);
    Route::post('/customers', [CustomerController::class, 'store'])
        ->middleware('admin.permission:'.Permissions::CUSTOMERS_MANAGE);
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])
        ->middleware('admin.permission:'.Permissions::CUSTOMERS_VIEW);
    Route::put('/customers/{customer}', [CustomerController::class, 'update'])
        ->middleware('admin.permission:'.Permissions::CUSTOMERS_MANAGE);
    Route::patch('/customers/{customer}/status', [CustomerController::class, 'changeStatus'])
        ->middleware('admin.permission:'.Permissions::CUSTOMERS_MANAGE);
    Route::post('/customers/{customer}/renew', [CustomerController::class, 'renew'])
        ->middleware('admin.permission:'.Permissions::SUBSCRIPTIONS_RENEW);
    Route::post('/customers/{customer}/activation-keys', [CustomerController::class, 'generateKey'])
        ->middleware('admin.permission:'.Permissions::ACTIVATION_KEYS_MANAGE);
    Route::post('/customers/{customer}/payments', [CustomerController::class, 'addPayment'])
        ->middleware('admin.permission:'.Permissions::PAYMENTS_MANAGE);

    Route::get('/plans', [PlanController::class, 'index'])
        ->middleware('admin.permission:'.Permissions::PLANS_MANAGE);
    Route::post('/plans', [PlanController::class, 'store'])
        ->middleware('admin.permission:'.Permissions::PLANS_MANAGE);
    Route::get('/plans/{plan}', [PlanController::class, 'show'])
        ->middleware('admin.permission:'.Permissions::PLANS_MANAGE);
    Route::put('/plans/{plan}', [PlanController::class, 'update'])
        ->middleware('admin.permission:'.Permissions::PLANS_MANAGE);
    Route::delete('/plans/{plan}', [PlanController::class, 'destroy'])
        ->middleware('admin.permission:'.Permissions::PLANS_MANAGE);

    Route::get('/subscriptions', [SubscriptionController::class, 'index'])
        ->middleware('admin.permission:'.Permissions::SUBSCRIPTIONS_VIEW);
    Route::post('/subscriptions', [SubscriptionController::class, 'store'])
        ->middleware('admin.permission:'.Permissions::SUBSCRIPTIONS_MANAGE);
    Route::get('/subscriptions/{subscription}', [SubscriptionController::class, 'show'])
        ->middleware('admin.permission:'.Permissions::SUBSCRIPTIONS_VIEW);
    Route::put('/subscriptions/{subscription}', [SubscriptionController::class, 'update'])
        ->middleware('admin.permission:'.Permissions::SUBSCRIPTIONS_MANAGE);

    Route::get('/activation-keys', [ActivationKeyController::class, 'index'])
        ->middleware('admin.permission:'.Permissions::ACTIVATION_KEYS_VIEW);
    Route::get('/activation-keys/{activation_key}', [ActivationKeyController::class, 'show'])
        ->middleware('admin.permission:'.Permissions::ACTIVATION_KEYS_VIEW);
    Route::post('/activation-keys/{activation_key}/revoke', [ActivationKeyController::class, 'revoke'])
        ->middleware('admin.permission:'.Permissions::ACTIVATION_KEYS_MANAGE);
    Route::post('/activation-keys/{activation_key}/suspend', [ActivationKeyController::class, 'suspend'])
        ->middleware('admin.permission:'.Permissions::ACTIVATION_KEYS_MANAGE);

    Route::get('/devices', [DeviceController::class, 'index'])
        ->middleware('admin.permission:'.Permissions::DEVICES_VIEW);
    Route::get('/devices/{device}', [DeviceController::class, 'show'])
        ->middleware('admin.permission:'.Permissions::DEVICES_VIEW);
    Route::post('/devices/{device}/revoke', [DeviceController::class, 'revoke'])
        ->middleware('admin.permission:'.Permissions::DEVICES_MANAGE);
    Route::post('/devices/{device}/block', [DeviceController::class, 'block'])
        ->middleware('admin.permission:'.Permissions::DEVICES_MANAGE);
    Route::post('/devices/{device}/reset-binding', [DeviceController::class, 'resetBinding'])
        ->middleware('admin.permission:'.Permissions::DEVICES_MANAGE);

    Route::get('/locations', [LocationController::class, 'index'])
        ->middleware('admin.permission:'.Permissions::LOCATIONS_MANAGE);
    Route::post('/locations', [LocationController::class, 'store'])
        ->middleware('admin.permission:'.Permissions::LOCATIONS_MANAGE);
    Route::get('/locations/{location}', [LocationController::class, 'show'])
        ->middleware('admin.permission:'.Permissions::LOCATIONS_MANAGE);
    Route::put('/locations/{location}', [LocationController::class, 'update'])
        ->middleware('admin.permission:'.Permissions::LOCATIONS_MANAGE);
    Route::delete('/locations/{location}', [LocationController::class, 'destroy'])
        ->middleware('admin.permission:'.Permissions::LOCATIONS_MANAGE);

    Route::get('/vpn-nodes', [VpnNodeController::class, 'index'])
        ->middleware('admin.permission:'.Permissions::NODES_MANAGE);
    Route::post('/vpn-nodes', [VpnNodeController::class, 'store'])
        ->middleware('admin.permission:'.Permissions::NODES_MANAGE);
    Route::get('/vpn-nodes/{vpn_node}', [VpnNodeController::class, 'show'])
        ->middleware('admin.permission:'.Permissions::NODES_MANAGE);
    Route::put('/vpn-nodes/{vpn_node}', [VpnNodeController::class, 'update'])
        ->middleware('admin.permission:'.Permissions::NODES_MANAGE);
    Route::patch('/vpn-nodes/{vpn_node}/lifecycle', [VpnNodeController::class, 'updateLifecycle'])
        ->middleware('admin.permission:'.Permissions::NODES_LIFECYCLE);
    Route::post('/vpn-nodes/{vpn_node}/drain', [VpnNodeController::class, 'toggleDrain'])
        ->middleware('admin.permission:'.Permissions::NODES_LIFECYCLE);
    Route::post('/vpn-nodes/{vpn_node}/maintenance', [VpnNodeController::class, 'toggleMaintenance'])
        ->middleware('admin.permission:'.Permissions::NODES_LIFECYCLE);
    Route::delete('/vpn-nodes/{vpn_node}', [VpnNodeController::class, 'destroy'])
        ->middleware('admin.permission:'.Permissions::NODES_MANAGE);

    Route::get('/ip-pools', [\App\Http\Controllers\Admin\V1\IpPoolController::class, 'index'])
        ->middleware('admin.permission:'.Permissions::NODES_MANAGE);
    Route::post('/ip-pools', [\App\Http\Controllers\Admin\V1\IpPoolController::class, 'store'])
        ->middleware('admin.permission:'.Permissions::NODES_MANAGE);
    Route::post('/ip-pools/{ip_pool}/toggle', [\App\Http\Controllers\Admin\V1\IpPoolController::class, 'toggleActive'])
        ->middleware('admin.permission:'.Permissions::NODES_MANAGE);

    Route::get('/payments', [PaymentController::class, 'index'])
        ->middleware('admin.permission:'.Permissions::PAYMENTS_VIEW);
    Route::post('/payments', [PaymentController::class, 'store'])
        ->middleware('admin.permission:'.Permissions::PAYMENTS_MANAGE);
    Route::get('/payments/{payment}', [PaymentController::class, 'show'])
        ->middleware('admin.permission:'.Permissions::PAYMENTS_VIEW);

    Route::get('/admin-users', [AdminUserController::class, 'index'])
        ->middleware('admin.permission:'.Permissions::ADMINS_MANAGE);
    Route::post('/admin-users', [AdminUserController::class, 'store'])
        ->middleware('admin.permission:'.Permissions::ADMINS_MANAGE);
    Route::get('/admin-users/{admin_user}', [AdminUserController::class, 'show'])
        ->middleware('admin.permission:'.Permissions::ADMINS_MANAGE);
    Route::put('/admin-users/{admin_user}', [AdminUserController::class, 'update'])
        ->middleware('admin.permission:'.Permissions::ADMINS_MANAGE);
    Route::delete('/admin-users/{admin_user}', [AdminUserController::class, 'destroy'])
        ->middleware('admin.permission:'.Permissions::ADMINS_MANAGE);

    Route::get('/roles', [RoleController::class, 'index'])
        ->middleware('admin.permission:'.Permissions::ROLES_MANAGE);
    Route::get('/roles/{role}', [RoleController::class, 'show'])
        ->middleware('admin.permission:'.Permissions::ROLES_MANAGE);

    Route::get('/audit-logs', [AuditLogController::class, 'index'])
        ->middleware('admin.permission:'.Permissions::AUDIT_VIEW);
    Route::get('/audit-logs/{audit_log}', [AuditLogController::class, 'show'])
        ->middleware('admin.permission:'.Permissions::AUDIT_VIEW);
});
