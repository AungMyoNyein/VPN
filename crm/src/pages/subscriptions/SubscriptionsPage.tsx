import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { subscriptionsApi } from "../../api/endpoints";
import type { Subscription } from "../../api/types";
import { ApiClientError } from "../../api/client";
import { Badge } from "../../components/Badge";
import { ListShell, SearchBar } from "../../components/ListHelpers";
import { PageHeader } from "../../components/PageHeader";
import { Pagination } from "../../components/Pagination";
import { formatDate } from "../../lib/format";

export function SubscriptionsPage() {
  const [items, setItems] = useState<Subscription[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [status, setStatus] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await subscriptionsApi.list({ page, status: status || undefined });
      setItems(result.data);
      setLastPage(result.last_page);
      setTotal(result.total);
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Failed to load subscriptions.");
    } finally {
      setLoading(false);
    }
  }, [page, status]);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <div>
      <PageHeader title="Subscriptions" subtitle="Active and historical customer subscriptions." />
      <SearchBar
        value=""
        onChange={() => undefined}
        placeholder="Filter subscriptions"
        filters={
          <select
            className="input input-sm"
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
            }}
            aria-label="Filter by status"
          >
            <option value="">All statuses</option>
            <option value="ACTIVE">Active</option>
            <option value="EXPIRED">Expired</option>
            <option value="SUSPENDED">Suspended</option>
          </select>
        }
      />
      <ListShell
        loading={loading}
        error={error}
        empty={!loading && !error && items.length === 0}
        emptyTitle="No subscriptions"
        onRetry={() => void load()}
      >
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Customer</th>
                <th>Plan</th>
                <th>Status</th>
                <th>Expires</th>
              </tr>
            </thead>
            <tbody>
              {items.map((s) => (
                <tr key={s.id}>
                  <td>
                    {s.customer ? (
                      <Link to={`/customers/${s.customer_id}`}>{s.customer.name}</Link>
                    ) : (
                      s.customer_id
                    )}
                  </td>
                  <td>{s.plan?.name ?? s.plan_id}</td>
                  <td>
                    <Badge status={s.status} />
                  </td>
                  <td>{formatDate(s.expires_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Pagination page={page} lastPage={lastPage} total={total} onPageChange={setPage} />
      </ListShell>
    </div>
  );
}
