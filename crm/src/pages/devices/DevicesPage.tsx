import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { devicesApi } from "../../api/endpoints";
import type { Device } from "../../api/types";
import { ApiClientError } from "../../api/client";
import { Badge } from "../../components/Badge";
import { ConfirmDialog } from "../../components/ConfirmDialog";
import { ListShell } from "../../components/ListHelpers";
import { PageHeader } from "../../components/PageHeader";
import { Pagination } from "../../components/Pagination";
import { useAuth } from "../../auth/AuthContext";
import { PERMISSIONS } from "../../lib/permissions";
import { formatDate } from "../../lib/format";

type DeviceAction = "revoke" | "block" | "reset";

export function DevicesPage() {
  const { hasPermission } = useAuth();
  const [devices, setDevices] = useState<Device[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [actionTarget, setActionTarget] = useState<{ device: Device; action: DeviceAction } | null>(null);
  const [actionLoading, setActionLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await devicesApi.list({ page });
      setDevices(result.data);
      setLastPage(result.last_page);
      setTotal(result.total);
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Failed to load devices.");
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
      const { device, action } = actionTarget;
      if (action === "revoke") await devicesApi.revoke(device.id);
      else if (action === "block") await devicesApi.block(device.id);
      else await devicesApi.resetBinding(device.id);
      setActionTarget(null);
      await load();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Action failed.");
    } finally {
      setActionLoading(false);
    }
  }

  function getConfirmCopy(action: DeviceAction, device: Device) {
    const name = device.device_name ?? device.device_uuid.slice(0, 8);
    switch (action) {
      case "revoke":
        return `Revoking "${name}" removes VPN access on this device and frees a device slot for the customer.`;
      case "block":
        return `Blocking "${name}" immediately denies VPN connections from this device until unblocked.`;
      case "reset":
        return `Resetting binding for "${name}" clears the device credential so the customer must re-activate.`;
    }
  }

  return (
    <div>
      <PageHeader title="Devices" subtitle="Registered customer devices." />
      <ListShell
        loading={loading}
        error={error}
        empty={!loading && !error && devices.length === 0}
        emptyTitle="No devices"
        onRetry={() => void load()}
      >
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Device</th>
                <th>Customer</th>
                <th>Platform</th>
                <th>Status</th>
                <th>Last seen</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {devices.map((d) => (
                <tr key={d.id}>
                  <td>{d.device_name ?? d.device_uuid.slice(0, 12)}</td>
                  <td>
                    {d.customer ? (
                      <Link to={`/customers/${d.customer_id}`}>{d.customer.name}</Link>
                    ) : (
                      d.customer_id
                    )}
                  </td>
                  <td>{d.platform}</td>
                  <td>
                    <Badge status={d.status} />
                  </td>
                  <td>{formatDate(d.last_seen_at)}</td>
                  <td>
                    {hasPermission(PERMISSIONS.DEVICES_MANAGE) && d.status === "ACTIVE" && (
                      <div className="action-row">
                        <button
                          type="button"
                          className="btn btn-secondary btn-sm"
                          onClick={() => setActionTarget({ device: d, action: "reset" })}
                        >
                          Reset
                        </button>
                        <button
                          type="button"
                          className="btn btn-secondary btn-sm"
                          onClick={() => setActionTarget({ device: d, action: "block" })}
                        >
                          Block
                        </button>
                        <button
                          type="button"
                          className="btn btn-danger btn-sm"
                          onClick={() => setActionTarget({ device: d, action: "revoke" })}
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
        title={
          actionTarget?.action === "revoke"
            ? "Revoke device"
            : actionTarget?.action === "block"
              ? "Block device"
              : "Reset device binding"
        }
        description={actionTarget ? getConfirmCopy(actionTarget.action, actionTarget.device) : ""}
        confirmLabel={
          actionTarget?.action === "revoke"
            ? "Revoke device"
            : actionTarget?.action === "block"
              ? "Block device"
              : "Reset binding"
        }
        destructive={actionTarget?.action === "revoke"}
        loading={actionLoading}
        onConfirm={() => void handleAction()}
        onCancel={() => setActionTarget(null)}
      />
    </div>
  );
}
