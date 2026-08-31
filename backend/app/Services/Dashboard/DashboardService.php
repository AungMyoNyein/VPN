<?php

namespace App\Services\Dashboard;

use App\Enums\CustomerStatus;
use App\Enums\DeviceStatus;
use App\Enums\NodeHealthStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\VpnNode;

class DashboardService
{
    /**
     * @return array<string, int|float>
     */
    public function metrics(): array
    {
        return [
            'customers_total' => Customer::query()->count(),
            'customers_active' => Customer::query()->where('status', CustomerStatus::Active)->count(),
            'subscriptions_active' => Subscription::query()->where('status', SubscriptionStatus::Active)->count(),
            'devices_active' => Device::query()->where('status', DeviceStatus::Active)->count(),
            'nodes_healthy' => VpnNode::query()->where('health_status', NodeHealthStatus::Healthy)->count(),
            'nodes_total' => VpnNode::query()->count(),
            'payments_total_amount' => (float) Payment::query()->sum('amount'),
        ];
    }
}
