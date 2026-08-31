import { describe, expect, it } from "vitest";
import {
  countExpiringSoon,
  formatMetricValue,
  getDashboardCardState,
  paymentsThisMonthAmount,
} from "./dashboard";

describe("dashboard helpers", () => {
  it("returns loading state while fetching", () => {
    expect(getDashboardCardState(true, false, null)).toBe("loading");
  });

  it("returns error state on failure", () => {
    expect(getDashboardCardState(false, true, null)).toBe("error");
  });

  it("returns empty when metrics are null", () => {
    expect(getDashboardCardState(false, false, null)).toBe("empty");
  });

  it("returns ready when metrics exist", () => {
    expect(
      getDashboardCardState(false, false, {
        customers_total: 1,
        customers_active: 1,
        subscriptions_active: 1,
        devices_active: 0,
        nodes_healthy: 1,
        nodes_total: 1,
        payments_total_amount: 0,
      }),
    ).toBe("ready");
  });

  it("formats metric values by state", () => {
    expect(formatMetricValue("loading", 5)).toBe("…");
    expect(formatMetricValue("error", 5)).toBe("—");
    expect(formatMetricValue("ready", 5)).toBe("5");
  });

  it("counts subscriptions expiring within 7 days", () => {
    const soon = new Date(Date.now() + 3 * 24 * 60 * 60 * 1000).toISOString();
    const later = new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString();
    expect(
      countExpiringSoon([
        { status: "ACTIVE", expires_at: soon },
        { status: "ACTIVE", expires_at: later },
        { status: "EXPIRED", expires_at: soon },
      ]),
    ).toBe(1);
  });

  it("sums payments paid this month", () => {
    const now = new Date();
    expect(
      paymentsThisMonthAmount([
        { paid_at: now.toISOString(), amount: "10.00" },
        { paid_at: now.toISOString(), amount: "5.50" },
        { paid_at: null, amount: "99.00" },
      ]),
    ).toBe(15.5);
  });
});
