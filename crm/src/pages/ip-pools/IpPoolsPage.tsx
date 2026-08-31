import { FormEvent, useCallback, useEffect, useState } from "react";
import { ipPoolsApi, vpnNodesApi } from "../../api/endpoints";
import type { IpPool, VpnNode } from "../../api/types";
import { ApiClientError } from "../../api/client";
import { Badge } from "../../components/Badge";
import { ListShell } from "../../components/ListHelpers";
import { Modal } from "../../components/Modal";
import { PageHeader } from "../../components/PageHeader";
import { useAuth } from "../../auth/AuthContext";
import { PERMISSIONS } from "../../lib/permissions";

export function IpPoolsPage() {
  const { hasPermission } = useAuth();
  const [pools, setPools] = useState<IpPool[]>([]);
  const [nodes, setNodes] = useState<VpnNode[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [modalOpen, setModalOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  const [nodeId, setNodeId] = useState("");
  const [network, setNetwork] = useState("");
  const [prefixLength, setPrefixLength] = useState("24");
  const [gateway, setGateway] = useState("");

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const [poolsResult, nodesResult] = await Promise.all([
        ipPoolsApi.list(),
        vpnNodesApi.list(),
      ]);
      setPools(poolsResult.ip_pools);
      setNodes(nodesResult.vpn_nodes);
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Failed to load IP pools.");
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
      await ipPoolsApi.create({
        node_id: Number(nodeId),
        network,
        prefix_length: parseInt(prefixLength, 10),
        gateway,
      });
      setModalOpen(false);
      setNetwork("");
      setGateway("");
      await load();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Failed to create IP pool.");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleToggle(pool: IpPool) {
    try {
      await ipPoolsApi.toggleActive(pool.id);
      await load();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Failed to toggle IP pool.");
    }
  }

  return (
    <div>
      <PageHeader
        title="VPN IP Pools"
        subtitle="Overlay network IP address allocation pools by node."
        actions={
          hasPermission(PERMISSIONS.NODES_MANAGE) ? (
            <button type="button" className="btn btn-primary" onClick={() => setModalOpen(true)}>
              Add IP Pool
            </button>
          ) : undefined
        }
      />

      <ListShell
        loading={loading}
        error={error}
        empty={!loading && !error && pools.length === 0}
        emptyTitle="No IP pools"
        onRetry={() => void load()}
      >
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Node</th>
                <th>Network (CIDR)</th>
                <th>Prefix</th>
                <th>Gateway</th>
                <th>Capacity</th>
                <th>Allocated</th>
                <th>Available</th>
                <th>Status</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {pools.map((p) => (
                <tr key={p.id}>
                  <td>{p.node?.name ?? `Node #${p.node_id}`}</td>
                  <td>
                    <code>{p.network}</code>
                  </td>
                  <td>/{p.prefix_length}</td>
                  <td>
                    <code>{p.gateway}</code>
                  </td>
                  <td>{p.capacity ?? "—"}</td>
                  <td>{p.allocated_count ?? 0}</td>
                  <td>{p.available_count ?? "—"}</td>
                  <td>
                    <Badge status={p.active ? "ACTIVE" : "INACTIVE"} />
                  </td>
                  <td>
                    {hasPermission(PERMISSIONS.NODES_MANAGE) && (
                      <button
                        type="button"
                        className="btn btn-secondary btn-sm"
                        onClick={() => void handleToggle(p)}
                      >
                        {p.active ? "Deactivate" : "Activate"}
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </ListShell>

      <Modal open={modalOpen} title="Add IP Pool" onClose={() => setModalOpen(false)}>
        <form className="form-stack" onSubmit={(e) => void handleCreate(e)}>
          <label className="field">
            <span>VPN Node</span>
            <select className="input" value={nodeId} onChange={(e) => setNodeId(e.target.value)} required>
              <option value="">Select node</option>
              {nodes.map((n) => (
                <option key={n.id} value={n.id}>
                  {n.name} ({n.location?.display_name ?? n.hostname})
                </option>
              ))}
            </select>
          </label>
          <label className="field">
            <span>Network CIDR</span>
            <input
              className="input"
              placeholder="10.200.20.0/24"
              value={network}
              onChange={(e) => setNetwork(e.target.value)}
              required
            />
          </label>
          <label className="field">
            <span>Prefix Length</span>
            <input
              type="number"
              className="input"
              min="16"
              max="30"
              value={prefixLength}
              onChange={(e) => setPrefixLength(e.target.value)}
              required
            />
          </label>
          <label className="field">
            <span>Gateway IP</span>
            <input
              className="input"
              placeholder="10.200.20.1"
              value={gateway}
              onChange={(e) => setGateway(e.target.value)}
              required
            />
          </label>
          <button type="submit" className="btn btn-primary" disabled={submitting}>
            {submitting ? "Creating…" : "Create pool"}
          </button>
        </form>
      </Modal>
    </div>
  );
}
