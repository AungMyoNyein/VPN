import type { PermissionCode } from "./lib/permissions";
import { PERMISSIONS } from "./lib/permissions";

export interface NavItem {
  path: string;
  label: string;
  permission?: PermissionCode;
}

export interface NavGroup {
  label: string;
  items: NavItem[];
}

export const NAV_GROUPS: NavGroup[] = [
  {
    label: "Overview",
    items: [{ path: "/", label: "Dashboard", permission: PERMISSIONS.DASHBOARD_VIEW }],
  },
  {
    label: "Customers",
    items: [{ path: "/customers", label: "Customers", permission: PERMISSIONS.CUSTOMERS_VIEW }],
  },
  {
    label: "Billing",
    items: [
      { path: "/plans", label: "Plans", permission: PERMISSIONS.PLANS_MANAGE },
      { path: "/subscriptions", label: "Subscriptions", permission: PERMISSIONS.SUBSCRIPTIONS_VIEW },
      { path: "/activation-keys", label: "Activation Keys", permission: PERMISSIONS.ACTIVATION_KEYS_VIEW },
      { path: "/payments", label: "Payments", permission: PERMISSIONS.PAYMENTS_VIEW },
    ],
  },
  {
    label: "Devices",
    items: [{ path: "/devices", label: "Devices", permission: PERMISSIONS.DEVICES_VIEW }],
  },
  {
    label: "VPN Infrastructure",
    items: [
      { path: "/locations", label: "Locations", permission: PERMISSIONS.LOCATIONS_MANAGE },
      { path: "/vpn-nodes", label: "VPN Servers", permission: PERMISSIONS.NODES_MANAGE },
      { path: "/ip-pools", label: "IP Pools", permission: PERMISSIONS.NODES_MANAGE },
    ],
  },
  {
    label: "Administration",
    items: [
      { path: "/admin-users", label: "Admin Users", permission: PERMISSIONS.ADMINS_MANAGE },
      { path: "/roles", label: "Roles & Permissions", permission: PERMISSIONS.ROLES_MANAGE },
      { path: "/audit-logs", label: "Audit Logs", permission: PERMISSIONS.AUDIT_VIEW },
    ],
  },
  {
    label: "System",
    items: [{ path: "/settings", label: "Settings" }],
  },
];

export function filterNavGroups(
  groups: NavGroup[],
  permissions: string[] | undefined,
  canAccess: (perms: string[] | undefined, permission?: PermissionCode) => boolean,
): NavGroup[] {
  return groups
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => canAccess(permissions, item.permission)),
    }))
    .filter((group) => group.items.length > 0);
}
