<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Starter 30',
                'code' => 'STARTER_30',
                'description' => 'Starter plan — 30 days, 1 device',
                'price' => 9.99,
                'currency' => 'USD',
                'duration_days' => 30,
                'max_devices' => 1,
                'speed_limit_mbps' => 50,
                'traffic_limit_bytes' => null,
                'active' => true,
            ],
            [
                'name' => 'Standard 30',
                'code' => 'STANDARD_30',
                'description' => 'Standard plan — 30 days, 3 devices',
                'price' => 19.99,
                'currency' => 'USD',
                'duration_days' => 30,
                'max_devices' => 3,
                'speed_limit_mbps' => 100,
                'traffic_limit_bytes' => null,
                'active' => true,
            ],
            [
                'name' => 'Premium 30',
                'code' => 'PREMIUM_30',
                'description' => 'Premium plan — 30 days, 5 devices',
                'price' => 29.99,
                'currency' => 'USD',
                'duration_days' => 30,
                'max_devices' => 5,
                'speed_limit_mbps' => null,
                'traffic_limit_bytes' => null,
                'active' => true,
            ],
            [
                'name' => 'Premium 90',
                'code' => 'PREMIUM_90',
                'description' => 'Premium plan — 90 days, 5 devices',
                'price' => 79.99,
                'currency' => 'USD',
                'duration_days' => 90,
                'max_devices' => 5,
                'speed_limit_mbps' => null,
                'traffic_limit_bytes' => null,
                'active' => true,
            ],
            [
                'name' => 'Premium 365',
                'code' => 'PREMIUM_365',
                'description' => 'Premium plan — 365 days, 5 devices',
                'price' => 249.99,
                'currency' => 'USD',
                'duration_days' => 365,
                'max_devices' => 5,
                'speed_limit_mbps' => null,
                'traffic_limit_bytes' => null,
                'active' => true,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::query()->updateOrCreate(['code' => $plan['code']], $plan);
        }
    }
}
