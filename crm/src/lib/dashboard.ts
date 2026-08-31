import type { DashboardMetrics } from "../api/types";

export type DashboardCardState = "loading" | "empty" | "ready" | "error";

export function getDashboardCardState(
  loading: boolean,
  error: boolean,
  metrics: DashboardMetrics | null,
): DashboardCardState {
  if (loading) return "loading";
  if (error) return "error";
  if (!metrics) return "empty";
  return "ready";
}

export function countExpiringSoon(subscriptions: { status: string; expires_at: string }[]): number {
  const now = Date.now();
  const horizon = now + 7 * 24 * 60 * 60 * 1000;
  return subscriptions.filter((s) => {
    if (s.status !== "ACTIVE") return false;
    const exp = new Date(s.expires_at).getTime();
    return exp >= now && exp <= horizon;
  }).length;
}

export function formatMetricValue(state: DashboardCardState, value: number | string): string {
  if (state === "loading") return "…";
  if (state === "error") return "—";
  if (state === "empty") return "0";
  return String(value);
}

export function paymentsThisMonthAmount(payments: { paid_at: string | null; amount: string }[]): number {
  const now = new Date();
  const start = new Date(now.getFullYear(), now.getMonth(), 1);
  return payments.reduce((sum, p) => {
    if (!p.paid_at) return sum;
    const paid = new Date(p.paid_at);
    if (paid >= start && paid <= now) {
      return sum + parseFloat(p.amount);
    }
    return sum;
  }, 0);
}
