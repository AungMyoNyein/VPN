import { useCallback, useEffect, useState } from "react";
import { adminUsersApi } from "../../api/endpoints";
import type { AdminUser } from "../../api/types";
import { ApiClientError } from "../../api/client";
import { Badge } from "../../components/Badge";
import { ListShell } from "../../components/ListHelpers";
import { PageHeader } from "../../components/PageHeader";

export function AdminUsersPage() {
  const [users, setUsers] = useState<AdminUser[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await adminUsersApi.list();
      setUsers(result.admin_users);
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Failed to load admin users.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <div>
      <PageHeader title="Admin Users" subtitle="Administrator accounts (super admin access required)." />
      <ListShell
        loading={loading}
        error={error}
        empty={!loading && !error && users.length === 0}
        emptyTitle="No admin users"
        onRetry={() => void load()}
      >
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Roles</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {users.map((u) => (
                <tr key={u.id}>
                  <td>{u.name}</td>
                  <td>{u.email}</td>
                  <td>{u.roles.join(", ")}</td>
                  <td>
                    <Badge status={u.status} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </ListShell>
    </div>
  );
}
