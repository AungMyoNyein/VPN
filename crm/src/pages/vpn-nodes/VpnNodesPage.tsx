import { FormEvent, useCallback, useEffect, useState } from "react";
import { locationsApi, vpnNodesApi } from "../../api/endpoints";
import type { Location, VpnNode } from "../../api/types";
import { ApiClientError } from "../../api/client";
import { Badge } from "../../components/Badge";
import { ConfirmDialog } from "../../components/ConfirmDialog";
import { ListShell } from "../../components/ListHelpers";
import { Modal } from "../../components/Modal";
import { PageHeader } from "../../components/PageHeader";
import { useAuth } from "../../auth/AuthContext";
import { PERMISSIONS } from "../../lib/permissions";
import { formatDate } from "../../lib/format";

export function VpnNodesPage() {
  const { hasPermission } = useAuth();
  const [nodes, setNodes] = useState<VpnNode[]>([]);
  const [locations, setLocations] = useState<Location[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [modalOpen, setModalOpen] = useState(false);
  const [lifecycleTarget, setLifecycleTarget] = useState<{ node: VpnNode; status: string } | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const [name, setName] = useState("");
  const [locationId, setLocationId] = useState("");
  const [hostname, setHostname] = useState("");
  const [publicEndpoint, setPublicEndpoint] = useState("");
  const [vpnPort, setVpnPort] = useState("51820");
  const [capacityUsers, setCapacityUsers] = useState("50");

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const [nodesResult, locResult] = await Promise.all([vpnNodesApi.list(), locationsApi.list()]);
      setNodes(nodesResult.vpn_nodes);
      setLocations(locResult.locations);
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Failed to load VPN servers.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  async function handleCreate(e: FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    try {
      await vpnNodesApi.create({
        name,
        location_id: Number(locationId),
        hostname,
        public_endpoint: publicEndpoint,
        vpn_port: parseInt(vpnPort, 10),
        capacity_users: parseInt(capacityUsers, 10),
      });
      setModalOpen(false);
      await load();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Failed to create node.");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleLifecycleChange() {
    if (!lifecycleTarget) return;
    setSubmitting(true);
    try {
      await vpnNodesApi.updateLifecycle(lifecycleTarget.node.id, {
        lifecycle_status: lifecycleTarget.status,
      });
      setLifecycleTarget(null);
      await load();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Failed to update lifecycle.");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleToggleDrain(node: VpnNode) {
    setSubmitting(true);
    try {
      await vpnNodesApi.toggleDrain(node.id, !node.draining);
      await load();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Failed to toggle drain state.");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleToggleMaintenance(node: VpnNode) {
    setSubmitting(true);
    try {
      await vpnNodesApi.toggleMaintenance(node.id, !node.maintenance_mode);
      await load();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Failed to toggle maintenance mode.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div>
      <PageHeader
        title="VPN Servers"
        subtitle="VPN node inventory, capacity, and lifecycle management."
        actions={
          hasPermission(PERMISSIONS.NODES_MANAGE) ? (
            <button type="button" className="btn btn-primary" onClick={() => setModalOpen(true)}>
              Add node
            </button>
          ) : undefined
        }
      />
      <ListShell
        loading={loading}
        error={error}
        empty={!loading && !error && nodes.length === 0}
        emptyTitle="No VPN servers"
        onRetry={() => void load()}
      >
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Location</th>
                <th>Adapter / Mode</th>
                <th>Health</th>
                <th>Lifecycle</th>
                <th>Active Peers</th>
                <th>Capacity</th>
                <th>Utilization</th>
                <th>Last Heartbeat</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {nodes.map((n) => (
                <tr key={n.id}>
                  <td>
                    <strong>{n.name}</strong>
                    <div style={{ fontSize: "0.8em", color: "var(--muted)" }}>{n.hostname} ({n.wireguard_interface ?? "wg0"})</div>
                  </td>
                  <td>{n.location?.display_name ?? n.location_id}</td>
                  <td>
                    <div style={{ display: "flex", gap: "4px", alignItems: "center" }}>
                      <span className={`badge ${n.adapter_type === "remote" ? "badge-success" : "badge-secondary"}`}>
                        {n.adapter_type?.toUpperCase() ?? "FAKE"}
                      </span>
                      {n.agent_version && <small style={{ color: "var(--muted)" }}>v{n.agent_version}</small>}
                    </div>
                  </td>
                  <td>
                    <Badge status={n.health_status} />
                  </td>
                  <td>
                    <div className="badge-row" style={{ display: "flex", gap: "4px", flexWrap: "wrap" }}>
                      <Badge status={n.lifecycle_status} />
                      {n.draining && <Badge status="DRAINING" />}
                      {n.maintenance_mode && <Badge status="MAINTENANCE" />}
                    </div>
                  </td>
                  <td>{n.active_peers_count ?? 0}</td>
                  <td>{n.capacity_users}</td>
                  <td>
                    <code>{n.utilization_percent ?? 0}%</code>
                  </td>
                  <td>{formatDate(n.last_heartbeat_at)}</td>
                  <td>
                    <div className="action-row" style={{ display: "flex", gap: "6px" }}>
                      {hasPermission(PERMISSIONS.NODES_LIFECYCLE) && (
                        <>
                          <button
                            type="button"
                            className="btn btn-secondary btn-sm"
                            disabled={submitting}
                            onClick={() => void handleToggleDrain(n)}
                          >
                            {n.draining ? "Undrain" : "Drain"}
                          </button>
                          <button
                            type="button"
                            className="btn btn-secondary btn-sm"
                            disabled={submitting}
                            onClick={() => void handleToggleMaintenance(n)}
                          >
                            {n.maintenance_mode ? "Exit Maint" : "Maint"}
                          </button>
                          <select
                            className="input input-sm"
                            value={n.lifecycle_status}
                            onChange={(e) => setLifecycleTarget({ node: n, status: e.target.value })}
                            aria-label={`Change lifecycle for ${n.name}`}
                          >
                            <option value="ACTIVE">Active</option>
                            <option value="DRAINING">Draining</option>
                            <option value="MAINTENANCE">Maintenance</option>
                            <option value="RETIRED">Retired</option>
                          </select>
                        </>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </ListShell>

      <Modal open={modalOpen} title="Add VPN node" onClose={() => setModalOpen(false)}>
        <form className="form-stack" onSubmit={(e) => void handleCreate(e)}>
          <label className="field">
            <span>Name</span>
            <input className="input" value={name} onChange={(e) => setName(e.target.value)} required />
          </label>
          <label className="field">
            <span>Location</span>
            <select className="input" value={locationId} onChange={(e) => setLocationId(e.target.value)} required>
              <option value="">Select location</option>
              {locations.map((l) => (
                <option key={l.id} value={l.id}>
                  {l.display_name}
                </option>
              ))}
            </select>
          </label>
          <label className="field">
            <span>Hostname</span>
            <input className="input" value={hostname} onChange={(e) => setHostname(e.target.value)} required />
          </label>
          <label className="field">
            <span>Public endpoint</span>
            <input className="input" value={publicEndpoint} onChange={(e) => setPublicEndpoint(e.target.value)} required />
          </label>
          <label className="field">
            <span>VPN port</span>
            <input type="number" className="input" value={vpnPort} onChange={(e) => setVpnPort(e.target.value)} required />
          </label>
          <label className="field">
            <span>Capacity (users)</span>
            <input type="number" className="input" value={capacityUsers} onChange={(e) => setCapacityUsers(e.target.value)} required />
          </label>
          <button type="submit" className="btn btn-primary" disabled={submitting}>
            {submitting ? "Creating…" : "Create node"}
          </button>
        </form>
      </Modal>

      <ConfirmDialog
        open={Boolean(lifecycleTarget)}
        title="Change node lifecycle"
        description={
          lifecycleTarget
            ? `Changing "${lifecycleTarget.node.name}" to ${lifecycleTarget.status.replace(/_/g, " ")} affects how new VPN sessions are routed to this node.`
            : ""
        }
        confirmLabel="Apply lifecycle change"
        loading={submitting}
        onConfirm={() => void handleLifecycleChange()}
        onCancel={() => setLifecycleTarget(null)}
      />
    </div>
  );
}
