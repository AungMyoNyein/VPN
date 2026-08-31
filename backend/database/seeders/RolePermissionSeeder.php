<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Permissions::all() as $code) {
            Permission::query()->firstOrCreate(
                ['code' => $code],
                ['name' => str_replace('.', ' ', ucwords(str_replace('_', ' ', $code)))],
            );
        }

        $allPermissionIds = Permission::query()->pluck('id', 'code');

        $roles = [
            'SUPER_ADMIN' => Permissions::all(),
            'NOC' => [
                Permissions::LOCATIONS_MANAGE,
                Permissions::NODES_MANAGE,
                Permissions::NODES_LIFECYCLE,
                Permissions::DEVICES_VIEW,
                Permissions::CUSTOMERS_VIEW,
                Permissions::DASHBOARD_VIEW,
                Permissions::AUDIT_VIEW,
            ],
            'SUPPORT' => [
                Permissions::CUSTOMERS_VIEW,
                Permissions::CUSTOMERS_MANAGE,
                Permissions::DEVICES_MANAGE,
                Permissions::SUBSCRIPTIONS_VIEW,
                Permissions::ACTIVATION_KEYS_VIEW,
                Permissions::ACTIVATION_KEYS_MANAGE,
                Permissions::DASHBOARD_VIEW,
            ],
            'FINANCE' => [
                Permissions::CUSTOMERS_VIEW,
                Permissions::PLANS_MANAGE,
                Permissions::SUBSCRIPTIONS_VIEW,
                Permissions::SUBSCRIPTIONS_MANAGE,
                Permissions::SUBSCRIPTIONS_RENEW,
                Permissions::SUBSCRIPTIONS_CUSTOM_EXPIRY,
                Permissions::PAYMENTS_VIEW,
                Permissions::PAYMENTS_MANAGE,
                Permissions::DASHBOARD_VIEW,
            ],
        ];

        foreach ($roles as $code => $permissionCodes) {
            $role = Role::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => str_replace('_', ' ', ucwords(strtolower($code), '_')),
                    'description' => "System role: {$code}",
                ],
            );

            $role->permissions()->sync(
                collect($permissionCodes)->map(fn (string $perm) => $allPermissionIds[$perm])->all(),
            );
        }
    }
}
