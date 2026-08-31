import { useCallback, useEffect, useState } from "react";
import { dashboardApi, paymentsApi, subscriptionsApi } from "../api/endpoints";
import type { DashboardMetrics } from "../api/types";
import { ApiClientError } from "../api/client";
import { ErrorState, LoadingState } from "../components/StateBox";
import { PageHeader } from "../components/PageHeader";
import {
  countExpiringSoon,
  formatMetricValue,
  getDashboardCardState,
  paymentsThisMonthAmount,
} from "../lib/dashboard";

const CARDS = [
  { key: "customers_active", label: "Active Customers" },
  { key: "subscriptions_active", label: "Active Subscriptions" },
  { key: "expiring_soon", label: "Expiring Soon" },
  { key: "devices_active", label: "Active Devices" },
  { key: "nodes_healthy", label: "VPN Nodes Healthy" },
  { key: "payments_month", label: "Payments This Month" },
] as const;

export function DashboardPage() {
  const [metrics, setMetrics] = useState<DashboardMetrics | null>(null);
  const [expiringSoon, setExpiringSoon] = useState(0);
  const [paymentsMonth, setPaymentsMonth] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const [dash, subs, pays] = await Promise.all([
        dashboardApi.metrics(),
        subscriptionsApi.list({ status: "ACTIVE", per_page: 100 }),
        paymentsApi.list({ per_page: 100 }),
      ]);
      setMetrics(dash.metrics);
      setExpiringSoon(countExpiringSoon(subs.data));
      setPaymentsMonth(paymentsThisMonthAmount(pays.data));
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Failed to load dashboard.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const cardState = getDashboardCardState(loading, Boolean(error), metrics);

  function getValue(key: (typeof CARDS)[number]["key"]): string {
    if (cardState !== "ready" || !metrics) {
      return formatMetricValue(cardState, 0);
    }
    switch (key) {
      case "expiring_soon":
        return String(expiringSoon);
      case "payments_month":
        return paymentsMonth.toFixed(2);
      case "nodes_healthy":
        return `${metrics.nodes_healthy} / ${metrics.nodes_total}`;
      default:
        return formatMetricValue(cardState, metrics[key as keyof DashboardMetrics] ?? 0);
    }
  }

  return (
    <div>
      <PageHeader title="Dashboard" subtitle="Operational overview for your VPN platform." />
      {loading && <LoadingState label="Loading metrics…" />}
      {error && !loading && <ErrorState message={error} onRetry={() => void load()} />}
      {!loading && !error && (
        <div className="cards">
          {CARDS.map((card) => (
            <div key={card.key} className="card">
              <div className="card-label">{card.label}</div>
              <div className="card-value">{getValue(card.key)}</div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
