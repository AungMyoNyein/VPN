<?php

namespace App\Support;

final class Permissions
{
    public const CUSTOMERS_VIEW = 'customers.view';

    public const CUSTOMERS_MANAGE = 'customers.manage';

    public const PLANS_MANAGE = 'plans.manage';

    public const SUBSCRIPTIONS_VIEW = 'subscriptions.view';

    public const SUBSCRIPTIONS_MANAGE = 'subscriptions.manage';

    public const SUBSCRIPTIONS_RENEW = 'subscriptions.renew';

    public const SUBSCRIPTIONS_CUSTOM_EXPIRY = 'subscriptions.custom_expiry';

    public const ACTIVATION_KEYS_VIEW = 'activation_keys.view';

    public const ACTIVATION_KEYS_MANAGE = 'activation_keys.manage';

    public const DEVICES_VIEW = 'devices.view';

    public const DEVICES_MANAGE = 'devices.manage';

    public const PAYMENTS_VIEW = 'payments.view';

    public const PAYMENTS_MANAGE = 'payments.manage';

    public const LOCATIONS_MANAGE = 'locations.manage';

    public const NODES_MANAGE = 'nodes.manage';

    public const NODES_LIFECYCLE = 'nodes.lifecycle';

    public const ADMINS_MANAGE = 'admins.manage';

    public const ROLES_MANAGE = 'roles.manage';

    public const AUDIT_VIEW = 'audit.view';

    public const DASHBOARD_VIEW = 'dashboard.view';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::CUSTOMERS_VIEW,
            self::CUSTOMERS_MANAGE,
            self::PLANS_MANAGE,
            self::SUBSCRIPTIONS_VIEW,
            self::SUBSCRIPTIONS_MANAGE,
            self::SUBSCRIPTIONS_RENEW,
            self::SUBSCRIPTIONS_CUSTOM_EXPIRY,
            self::ACTIVATION_KEYS_VIEW,
            self::ACTIVATION_KEYS_MANAGE,
            self::DEVICES_VIEW,
            self::DEVICES_MANAGE,
            self::PAYMENTS_VIEW,
            self::PAYMENTS_MANAGE,
            self::LOCATIONS_MANAGE,
            self::NODES_MANAGE,
            self::NODES_LIFECYCLE,
            self::ADMINS_MANAGE,
            self::ROLES_MANAGE,
            self::AUDIT_VIEW,
            self::DASHBOARD_VIEW,
        ];
    }
}
