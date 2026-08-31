import { useCallback, useEffect, useState } from "react";
import { rolesApi } from "../../api/endpoints";
import type { Role } from "../../api/types";
import { ApiClientError } from "../../api/client";
import { ListShell } from "../../components/ListHelpers";
import { PageHeader } from "../../components/PageHeader";

export function RolesPage() {
  const [roles, setRoles] = useState<Role[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await rolesApi.list();
      setRoles(result.roles);
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Failed to load roles.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <div>
      <PageHeader title="Roles & Permissions" subtitle="Read-only view of RBAC roles and permission codes." />
      <ListShell
        loading={loading}
        error={error}
        empty={!loading && !error && roles.length === 0}
        emptyTitle="No roles"
        onRetry={() => void load()}
      >
        <div className="roles-grid">
          {roles.map((role) => (
            <div key={role.id} className="panel role-card">
              <h3>{role.name}</h3>
              <p className="muted">{role.code}</p>
              {role.description && <p>{role.description}</p>}
              <ul className="permission-list">
                {(role.permissions ?? []).map((p) => (
                  <li key={p.id}>
                    <code>{p.code}</code> — {p.name}
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>
      </ListShell>
    </div>
  );
}
