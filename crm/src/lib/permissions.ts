export const PERMISSIONS = {
  DASHBOARD_VIEW: "dashboard.view",
  CUSTOMERS_VIEW: "customers.view",
  CUSTOMERS_MANAGE: "customers.manage",
  PLANS_MANAGE: "plans.manage",
  SUBSCRIPTIONS_VIEW: "subscriptions.view",
  SUBSCRIPTIONS_MANAGE: "subscriptions.manage",
  SUBSCRIPTIONS_RENEW: "subscriptions.renew",
  SUBSCRIPTIONS_CUSTOM_EXPIRY: "subscriptions.custom_expiry",
  ACTIVATION_KEYS_VIEW: "activation_keys.view",
  ACTIVATION_KEYS_MANAGE: "activation_keys.manage",
  DEVICES_VIEW: "devices.view",
  DEVICES_MANAGE: "devices.manage",
  PAYMENTS_VIEW: "payments.view",
  PAYMENTS_MANAGE: "payments.manage",
  LOCATIONS_MANAGE: "locations.manage",
  NODES_MANAGE: "nodes.manage",
  NODES_LIFECYCLE: "nodes.lifecycle",
  ADMINS_MANAGE: "admins.manage",
  ROLES_MANAGE: "roles.manage",
  AUDIT_VIEW: "audit.view",
} as const;

export type PermissionCode = (typeof PERMISSIONS)[keyof typeof PERMISSIONS];

export function hasPermission(
  permissions: string[] | undefined,
  required: PermissionCode | PermissionCode[],
): boolean {
  if (!permissions?.length) return false;
  const needed = Array.isArray(required) ? required : [required];
  return needed.some((p) => permissions.includes(p));
}

export function hasAnyPermission(
  permissions: string[] | undefined,
  codes: PermissionCode[],
): boolean {
  return hasPermission(permissions, codes);
}

export function canAccessRoute(
  permissions: string[] | undefined,
  routePermission?: PermissionCode,
): boolean {
  if (!routePermission) return true;
  return hasPermission(permissions, routePermission);
}
