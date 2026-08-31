import { useCallback, useEffect, useState } from "react";
import { auditLogsApi } from "../../api/endpoints";
import type { AuditLog } from "../../api/types";
import { ApiClientError } from "../../api/client";
import { ListShell } from "../../components/ListHelpers";
import { PageHeader } from "../../components/PageHeader";
import { Pagination } from "../../components/Pagination";
import { formatDate } from "../../lib/format";

export function AuditLogsPage() {
  const [logs, setLogs] = useState<AuditLog[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await auditLogsApi.list({ page });
      setLogs(result.data);
      setLastPage(result.last_page);
      setTotal(result.total);
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Failed to load audit logs.");
    } finally {
      setLoading(false);
    }
  }, [page]);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <div>
      <PageHeader title="Audit Logs" subtitle="Administrative action history." />
      <ListShell
        loading={loading}
        error={error}
        empty={!loading && !error && logs.length === 0}
        emptyTitle="No audit logs"
        onRetry={() => void load()}
      >
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Time</th>
                <th>Action</th>
                <th>Target</th>
                <th>Actor</th>
                <th>Request ID</th>
              </tr>
            </thead>
            <tbody>
              {logs.map((log) => (
                <tr key={log.id}>
                  <td>{formatDate(log.created_at)}</td>
                  <td>{log.action}</td>
                  <td>
                    {log.target_type} #{log.target_id ?? "—"}
                  </td>
                  <td>
                    {log.actor_type} #{log.actor_id ?? "—"}
                  </td>
                  <td>
                    <code>{log.request_id?.slice(0, 8) ?? "—"}</code>
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
