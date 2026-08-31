import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { customersApi } from "../../api/endpoints";
import type { Customer } from "../../api/types";
import { ApiClientError } from "../../api/client";
import { Badge } from "../../components/Badge";
import { ListShell, SearchBar } from "../../components/ListHelpers";
import { PageHeader } from "../../components/PageHeader";
import { Pagination } from "../../components/Pagination";
import { useAuth } from "../../auth/AuthContext";
import { PERMISSIONS } from "../../lib/permissions";

export function CustomersPage() {
  const { hasPermission } = useAuth();
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await customersApi.list({
        page,
        search: search || undefined,
        status: status || undefined,
      });
      setCustomers(result.data);
      setLastPage(result.last_page);
      setTotal(result.total);
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Failed to load customers.");
    } finally {
      setLoading(false);
    }
  }, [page, search, status]);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <div>
      <PageHeader
        title="Customers"
        subtitle="Manage customer accounts and subscriptions."
        actions={
          hasPermission(PERMISSIONS.CUSTOMERS_MANAGE) ? (
            <Link to="/customers/new" className="btn btn-primary">
              New customer
            </Link>
          ) : undefined
        }
      />
      <SearchBar
        value={search}
        onChange={(v) => {
          setSearch(v);
          setPage(1);
        }}
        placeholder="Search by name, code, phone…"
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
            <option value="SUSPENDED">Suspended</option>
          </select>
        }
      />
      <ListShell
        loading={loading}
        error={error}
        empty={!loading && !error && customers.length === 0}
        emptyTitle="No customers found"
        emptyDescription="Create a customer to get started."
        onRetry={() => void load()}
      >
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Phone</th>
                <th>Status</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {customers.map((c) => (
                <tr key={c.id}>
                  <td>
                    <Link to={`/customers/${c.id}`}>{c.customer_code}</Link>
                  </td>
                  <td>{c.name}</td>
                  <td>{c.phone ?? "—"}</td>
                  <td>
                    <Badge status={c.status} />
                  </td>
                  <td>
                    <Link to={`/customers/${c.id}`} className="btn btn-secondary btn-sm">
                      View
                    </Link>
                  </td>
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
