import { describe, expect, it } from "vitest";
import { hasPermission, canAccessRoute, PERMISSIONS } from "./permissions";
import { filterNavGroups, NAV_GROUPS } from "../nav";

describe("permissions helpers", () => {
  const perms = [
    PERMISSIONS.CUSTOMERS_VIEW,
    PERMISSIONS.DASHBOARD_VIEW,
    PERMISSIONS.PAYMENTS_VIEW,
  ];

  it("grants permission when code is present", () => {
    expect(hasPermission(perms, PERMISSIONS.CUSTOMERS_VIEW)).toBe(true);
  });

  it("denies permission when code is missing", () => {
    expect(hasPermission(perms, PERMISSIONS.PLANS_MANAGE)).toBe(false);
  });

  it("denies when permissions list is empty", () => {
    expect(hasPermission([], PERMISSIONS.CUSTOMERS_VIEW)).toBe(false);
    expect(hasPermission(undefined, PERMISSIONS.CUSTOMERS_VIEW)).toBe(false);
  });

  it("checks any-of permission lists", () => {
    expect(hasPermission(perms, [PERMISSIONS.PLANS_MANAGE, PERMISSIONS.PAYMENTS_VIEW])).toBe(true);
  });

  it("allows routes without permission requirement", () => {
    expect(canAccessRoute(perms, undefined)).toBe(true);
  });
});

describe("nav permission filtering", () => {
  it("hides billing nav for users without billing permissions", () => {
    const filtered = filterNavGroups(NAV_GROUPS, [PERMISSIONS.CUSTOMERS_VIEW], (p, req) =>
      req ? (p?.includes(req) ?? false) : true,
    );
    const labels = filtered.flatMap((g) => g.items.map((i) => i.label));
    expect(labels).toContain("Customers");
    expect(labels).not.toContain("Plans");
    expect(labels).not.toContain("Payments");
  });

  it("always shows settings", () => {
    const filtered = filterNavGroups(NAV_GROUPS, [], (p, req) =>
      req ? (p?.includes(req) ?? false) : true,
    );
    const labels = filtered.flatMap((g) => g.items.map((i) => i.label));
    expect(labels).toContain("Settings");
  });

  it("includes dashboard when permitted", () => {
    const filtered = filterNavGroups(NAV_GROUPS, [PERMISSIONS.DASHBOARD_VIEW], (p, req) =>
      req ? (p?.includes(req) ?? false) : true,
    );
    expect(filtered[0]?.items[0]?.label).toBe("Dashboard");
  });
});
