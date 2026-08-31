import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { activationKeysApi } from "../../api/endpoints";
import type { ActivationKey } from "../../api/types";
import { ApiClientError } from "../../api/client";
import { Badge } from "../../components/Badge";
import { ConfirmDialog } from "../../components/ConfirmDialog";
import { ListShell } from "../../components/ListHelpers";
import { PageHeader } from "../../components/PageHeader";
import { Pagination } from "../../components/Pagination";
import { useAuth } from "../../auth/AuthContext";
import { PERMISSIONS } from "../../lib/permissions";
import { formatDate } from "../../lib/format";

export function ActivationKeysPage() {
  const { hasPermission } = useAuth();
  const [keys, setKeys] = useState<ActivationKey[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [actionTarget, setActionTarget] = useState<{ key: ActivationKey; action: "revoke" | "suspend" } | null>(null);
  const [actionLoading, setActionLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await activationKeysApi.list({ page });
      setKeys(result.data);
      setLastPage(result.last_page);
      setTotal(result.total);
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Failed to load activation keys.");
    } finally {
      setLoading(false);
    }
  }, [page]);

  useEffect(() => {
    void load();
  }, [load]);

  async function handleAction() {
    if (!actionTarget) return;
    setActionLoading(true);
    try {
      if (actionTarget.action === "revoke") {
        await activationKeysApi.revoke(actionTarget.key.id);
      } else {
        await activationKeysApi.suspend(actionTarget.key.id);
      }
      setActionTarget(null);
      await load();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Action failed.");
    } finally {
      setActionLoading(false);
    }
  }

  return (
    <div>
      <PageHeader title="Activation Keys" subtitle="Manage customer activation keys (prefix only after creation)." />
      <ListShell
        loading={loading}
        error={error}
        empty={!loading && !error && keys.length === 0}
        emptyTitle="No activation keys"
        onRetry={() => void load()}
      >
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Prefix</th>
                <th>Customer</th>
                <th>Status</th>
                <th>Activations</th>
                <th>Expires</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {keys.map((k) => (
                <tr key={k.id}>
                  <td>{k.key_prefix}…</td>
                  <td>
                    {k.customer ? (
                      <Link to={`/customers/${k.customer_id}`}>{k.customer.name}</Link>
                    ) : (
                      k.customer_id
                    )}
                  </td>
                  <td>
                    <Badge status={k.status} />
                  </td>
                  <td>
                    {k.activation_count}/{k.max_activations}
                  </td>
                  <td>{formatDate(k.expires_at)}</td>
                  <td>
                    {hasPermission(PERMISSIONS.ACTIVATION_KEYS_MANAGE) && k.status === "ACTIVE" && (
                      <div className="action-row">
                        <button
                          type="button"
                          className="btn btn-secondary btn-sm"
                          onClick={() => setActionTarget({ key: k, action: "suspend" })}
                        >
                          Suspend
                        </button>
                        <button
                          type="button"
                          className="btn btn-danger btn-sm"
                          onClick={() => setActionTarget({ key: k, action: "revoke" })}
                        >
                          Revoke
                        </button>
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Pagination page={page} lastPage={lastPage} total={total} onPageChange={setPage} />
      </ListShell>

      <ConfirmDialog
        open={Boolean(actionTarget)}
        title={actionTarget?.action === "revoke" ? "Revoke activation key" : "Suspend activation key"}
        description={
          actionTarget?.action === "revoke"
            ? `Revoking key ${actionTarget.key.key_prefix}… permanently prevents new device activations using this key.`
            : `Suspending key ${actionTarget?.key.key_prefix}… temporarily blocks activations until re-enabled.`
        }
        confirmLabel={actionTarget?.action === "revoke" ? "Revoke key" : "Suspend key"}
        destructive={actionTarget?.action === "revoke"}
        loading={actionLoading}
        onConfirm={() => void handleAction()}
        onCancel={() => setActionTarget(null)}
      />
    </div>
  );
}
