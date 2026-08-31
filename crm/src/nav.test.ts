import { describe, expect, it } from "vitest";
import { NAV_GROUPS } from "./nav";
import { PERMISSIONS } from "./lib/permissions";

describe("CRM navigation", () => {
  it("includes grouped Phase 1 navigation sections", () => {
    const groupLabels = NAV_GROUPS.map((g) => g.label);
    expect(groupLabels).toContain("Billing");
    expect(groupLabels).toContain("VPN Infrastructure");
    expect(groupLabels).toContain("Administration");
  });

  it("includes required page routes", () => {
    const paths = NAV_GROUPS.flatMap((g) => g.items.map((i) => i.path));
    expect(paths).toContain("/");
    expect(paths).toContain("/customers");
    expect(paths).toContain("/plans");
    expect(paths).toContain("/activation-keys");
    expect(paths).toContain("/vpn-nodes");
    expect(paths).toContain("/audit-logs");
    expect(paths).toContain("/settings");
  });

  it("assigns permissions to protected routes", () => {
    const customers = NAV_GROUPS.flatMap((g) => g.items).find((i) => i.path === "/customers");
    expect(customers?.permission).toBe(PERMISSIONS.CUSTOMERS_VIEW);
  });
});
