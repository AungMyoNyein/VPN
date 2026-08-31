import { FormEvent, useCallback, useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { customersApi } from "../../api/endpoints";
import type { ActivationKey, Customer, Device, Payment, Subscription, VpnPeer } from "../../api/types";
import { ApiClientError } from "../../api/client";
import { ActivationKeyReveal } from "../../components/ActivationKeyReveal";
import { Badge } from "../../components/Badge";
import { ConfirmDialog } from "../../components/ConfirmDialog";
import { Modal } from "../../components/Modal";
import { ErrorState, LoadingState } from "../../components/StateBox";
import { PageHeader } from "../../components/PageHeader";
import { useAuth } from "../../auth/AuthContext";
import { PERMISSIONS } from "../../lib/permissions";
import { formatCurrency, formatDate } from "../../lib/format";
import {
  createKeyRevealState,
  markKeyAcknowledged,
  markKeyCopied,
} from "../../lib/activationKey";
import type { ActivationKeyRevealState } from "../../components/ActivationKeyReveal";

type Tab = "overview" | "subscription" | "keys" | "devices" | "vpn" | "payments" | "audit";

export function CustomerDetailPage() {
  const { id } = useParams<{ id: string }>();
  const customerId = Number(id);
  const { hasPermission } = useAuth();
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [tab, setTab] = useState<Tab>("overview");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const [suspendOpen, setSuspendOpen] = useState(false);
  const [renewOpen, setRenewOpen] = useState(false);
  const [paymentOpen, setPaymentOpen] = useState(false);
  const [keyReveal, setKeyReveal] = useState<ActivationKeyRevealState | null>(null);

  const [renewMode, setRenewMode] = useState("extend");
  const [renewExpires, setRenewExpires] = useState("");
  const [paymentMethod, setPaymentMethod] = useState("CASH");
  const [paymentAmount, setPaymentAmount] = useState("");
  const [paymentCurrency, setPaymentCurrency] = useState("USD");
  const [actionLoading, setActionLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await customersApi.get(customerId);
      setCustomer(result.customer);
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Failed to load customer.");
    } finally {
      setLoading(false);
    }
  }, [customerId]);

  useEffect(() => {
    void load();
  }, [load]);

  async function handleSuspend() {
    setActionLoading(true);
    setActionError(null);
    try {
      await customersApi.changeStatus(customerId, "SUSPENDED");
      setSuspendOpen(false);
      await load();
    } catch (err) {
      setActionError(err instanceof ApiClientError ? err.message : "Failed to suspend customer.");
    } finally {
      setActionLoading(false);
    }
  }

  async function handleRenew(e: FormEvent) {
    e.preventDefault();
    setActionLoading(true);
    setActionError(null);
    try {
      const payload: Record<string, unknown> = { mode: renewMode };
      if (renewMode === "custom") payload.expires_at = renewExpires;
      await customersApi.renew(customerId, payload);
      setRenewOpen(false);
      await load();
    } catch (err) {
      setActionError(err instanceof ApiClientError ? err.message : "Failed to renew subscription.");
    } finally {
      setActionLoading(false);
    }
  }

  async function handleGenerateKey() {
    setActionLoading(true);
    setActionError(null);
    try {
      const result = await customersApi.generateKey(customerId);
      setKeyReveal(createKeyRevealState(customer!.customer_code, result.plaintext_key));
      await load();
    } catch (err) {
      setActionError(err instanceof ApiClientError ? err.message : "Failed to generate key.");
    } finally {
      setActionLoading(false);
    }
  }

  async function handleAddPayment(e: FormEvent) {
    e.preventDefault();
    setActionLoading(true);
    setActionError(null);
    try {
      await customersApi.addPayment(customerId, {
        payment_method: paymentMethod,
        amount: parseFloat(paymentAmount),
        currency: paymentCurrency,
      });
      setPaymentOpen(false);
      setPaymentAmount("");
      await load();
    } catch (err) {
      setActionError(err instanceof ApiClientError ? err.message : "Failed to add payment.");
    } finally {
      setActionLoading(false);
    }
  }

  if (loading) return <LoadingState />;
  if (error || !customer) return <ErrorState message={error ?? "Customer not found."} onRetry={() => void load()} />;

  const subscriptions = customer.subscriptions ?? [];
  const activeSub = subscriptions.find((s) => s.status === "ACTIVE") ?? subscriptions[0];
  const keys = customer.activation_keys ?? [];
  const devices = customer.devices ?? [];
  const vpnPeers = customer.vpn_peers ?? [];
  const payments = customer.payments ?? [];

  const tabs: { id: Tab; label: string }[] = [
    { id: "overview", label: "Overview" },
    { id: "subscription", label: "Subscription" },
    { id: "keys", label: "Activation Keys" },
    { id: "devices", label: "Devices" },
    { id: "vpn", label: "VPN Access" },
    { id: "payments", label: "Payments" },
    { id: "audit", label: "Audit" },
  ];

  return (
    <div>
      <PageHeader
        title={customer.name}
        subtitle={customer.customer_code}
        actions={
          <div className="action-row">
            <Badge status={customer.status} />
            {hasPermission(PERMISSIONS.SUBSCRIPTIONS_RENEW) && (
              <button type="button" className="btn btn-secondary btn-sm" onClick={() => setRenewOpen(true)}>
                Renew
              </button>
            )}
            {hasPermission(PERMISSIONS.ACTIVATION_KEYS_MANAGE) && (
              <button
                type="button"
                className="btn btn-secondary btn-sm"
                disabled={actionLoading}
                onClick={() => void handleGenerateKey()}
              >
                Generate key
              </button>
            )}
            {hasPermission(PERMISSIONS.PAYMENTS_MANAGE) && (
              <button type="button" className="btn btn-secondary btn-sm" onClick={() => setPaymentOpen(true)}>
                Add payment
              </button>
            )}
            {hasPermission(PERMISSIONS.CUSTOMERS_MANAGE) && customer.status === "ACTIVE" && (
              <button type="button" className="btn btn-danger btn-sm" onClick={() => setSuspendOpen(true)}>
                Suspend
              </button>
            )}
            <Link to="/customers" className="btn btn-secondary btn-sm">
              Back
            </Link>
          </div>
        }
      />

      {actionError && (
        <div className="alert alert-error" role="alert">
          {actionError}
        </div>
      )}

      {keyReveal && (
        <div className="panel">
          <ActivationKeyReveal
            state={keyReveal}
            onCopy={() => {
              void navigator.clipboard.writeText(keyReveal.plaintextKey);
              setKeyReveal(markKeyCopied(keyReveal));
            }}
            onAcknowledge={() => setKeyReveal(markKeyAcknowledged(keyReveal))}
          />
          <button
            type="button"
            className="btn btn-secondary"
            disabled={!keyReveal.acknowledged}
            onClick={() => setKeyReveal(null)}
          >
            Dismiss
          </button>
        </div>
      )}

      <div className="tabs" role="tablist">
        {tabs.map((t) => (
          <button
            key={t.id}
            type="button"
            role="tab"
            className={`tab${tab === t.id ? " active" : ""}`}
            aria-selected={tab === t.id}
            onClick={() => setTab(t.id)}
          >
            {t.label}
          </button>
        ))}
      </div>

      <div className="panel">
        {tab === "overview" && <OverviewTab customer={customer} activeSub={activeSub} />}
        {tab === "subscription" && <SubscriptionTab subscriptions={subscriptions} />}
        {tab === "keys" && <KeysTab keys={keys} />}
        {tab === "devices" && <DevicesTab devices={devices} />}
        {tab === "vpn" && <VpnAccessTab peers={vpnPeers} />}
        {tab === "payments" && <PaymentsTab payments={payments} />}
        {tab === "audit" && (
          <p className="muted">
            Audit events for this customer are available in{" "}
            <Link to="/audit-logs">Audit Logs</Link> (filter by target in Phase 2).
          </p>
        )}
      </div>

      <ConfirmDialog
        open={suspendOpen}
        title="Suspend customer"
        description={`Suspending ${customer.name} (${customer.customer_code}) will block VPN access for all devices and activation keys tied to this account.`}
        confirmLabel="Suspend customer"
        destructive
        loading={actionLoading}
        onConfirm={() => void handleSuspend()}
        onCancel={() => setSuspendOpen(false)}
      />

      <Modal open={renewOpen} title="Renew subscription" onClose={() => setRenewOpen(false)}>
        <form className="form-stack" onSubmit={(e) => void handleRenew(e)}>
          <label className="field">
            <span>Renewal mode</span>
            <select className="input" value={renewMode} onChange={(e) => setRenewMode(e.target.value)}>
              <option value="extend">Extend from current expiry</option>
              <option value="from_now">Start from now</option>
              {hasPermission(PERMISSIONS.SUBSCRIPTIONS_CUSTOM_EXPIRY) && (
                <option value="custom">Custom expiry date</option>
              )}
            </select>
          </label>
          {renewMode === "custom" && (
            <label className="field">
              <span>Expires at</span>
              <input
                type="datetime-local"
                className="input"
                value={renewExpires}
                onChange={(e) => setRenewExpires(e.target.value)}
                required
              />
            </label>
          )}
          <button type="submit" className="btn btn-primary" disabled={actionLoading}>
            {actionLoading ? "Renewing…" : "Renew subscription"}
          </button>
        </form>
      </Modal>

      <Modal open={paymentOpen} title="Add payment" onClose={() => setPaymentOpen(false)}>
        <form className="form-stack" onSubmit={(e) => void handleAddPayment(e)}>
          <label className="field">
            <span>Method</span>
            <select className="input" value={paymentMethod} onChange={(e) => setPaymentMethod(e.target.value)}>
              <option value="CASH">Cash</option>
              <option value="BANK_TRANSFER">Bank transfer</option>
              <option value="KBZPAY">KBZ Pay</option>
              <option value="WAVEPAY">Wave Pay</option>
              <option value="MANUAL">Manual</option>
              <option value="OTHER">Other</option>
            </select>
          </label>
          <label className="field">
            <span>Amount</span>
            <input
              type="number"
              step="0.01"
              min="0"
              className="input"
              value={paymentAmount}
              onChange={(e) => setPaymentAmount(e.target.value)}
              required
            />
          </label>
          <label className="field">
            <span>Currency</span>
            <input
              className="input"
              maxLength={3}
              value={paymentCurrency}
              onChange={(e) => setPaymentCurrency(e.target.value.toUpperCase())}
              required
            />
          </label>
          <button type="submit" className="btn btn-primary" disabled={actionLoading}>
            {actionLoading ? "Saving…" : "Record payment"}
          </button>
        </form>
      </Modal>
    </div>
  );
}

function OverviewTab({ customer, activeSub }: { customer: Customer; activeSub?: Subscription }) {
  return (
    <dl className="detail-grid">
      <dt>Customer ID</dt>
      <dd>{customer.customer_code}</dd>
      <dt>Name</dt>
      <dd>{customer.name}</dd>
      <dt>Phone</dt>
      <dd>{customer.phone ?? "—"}</dd>
      <dt>Email</dt>
      <dd>{customer.email ?? "—"}</dd>
      <dt>Status</dt>
      <dd>
        <Badge status={customer.status} />
      </dd>
      <dt>Plan</dt>
      <dd>{activeSub?.plan?.name ?? "—"}</dd>
      <dt>Expires</dt>
      <dd>{activeSub ? formatDate(activeSub.expires_at) : "—"}</dd>
      <dt>Notes</dt>
      <dd>{customer.notes ?? "—"}</dd>
    </dl>
  );
}

function SubscriptionTab({ subscriptions }: { subscriptions: Subscription[] }) {
  if (!subscriptions.length) return <p className="muted">No subscriptions.</p>;
  return (
    <div className="table-wrap">
      <table className="data-table">
        <thead>
          <tr>
            <th>Plan</th>
            <th>Status</th>
            <th>Starts</th>
            <th>Expires</th>
            <th>Auto renew</th>
          </tr>
        </thead>
        <tbody>
          {subscriptions.map((s) => (
            <tr key={s.id}>
              <td>{s.plan?.name ?? s.plan_id}</td>
              <td>
                <Badge status={s.status} />
              </td>
              <td>{formatDate(s.starts_at)}</td>
              <td>{formatDate(s.expires_at)}</td>
              <td>{s.auto_renew ? "Yes" : "No"}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function KeysTab({ keys }: { keys: ActivationKey[] }) {
  if (!keys.length) return <p className="muted">No activation keys.</p>;
  return (
    <div className="table-wrap">
      <table className="data-table">
        <thead>
          <tr>
            <th>Prefix</th>
            <th>Status</th>
            <th>Activations</th>
            <th>Expires</th>
          </tr>
        </thead>
        <tbody>
          {keys.map((k) => (
            <tr key={k.id}>
              <td>{k.key_prefix}…</td>
              <td>
                <Badge status={k.status} />
              </td>
              <td>
                {k.activation_count}/{k.max_activations}
              </td>
              <td>{formatDate(k.expires_at)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function DevicesTab({ devices }: { devices: Device[] }) {
  if (!devices.length) return <p className="muted">No devices registered.</p>;
  return (
    <div className="table-wrap">
      <table className="data-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Platform</th>
            <th>Status</th>
            <th>Credential</th>
            <th>Activated</th>
            <th>Last seen</th>
          </tr>
        </thead>
        <tbody>
          {devices.map((d) => (
            <tr key={d.id}>
              <td>{d.device_name ?? d.device_uuid.slice(0, 8)}</td>
              <td>{d.platform}</td>
              <td>
                <Badge status={d.status} />
              </td>
              <td>{d.has_active_credential ? "Active" : "None"}</td>
              <td>{formatDate(d.activated_at)}</td>
              <td>{formatDate(d.last_seen_at)}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <p className="muted" style={{ marginTop: 12 }}>
        Raw device credentials are never shown. Revoke or reset binding invalidates mobile access.
      </p>
    </div>
  );
}

function formatBytes(bytes?: number): string {
  if (!bytes || bytes === 0) return "0 B";
  const k = 1024;
  const sizes = ["B", "KB", "MB", "GB", "TB"];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return `${parseFloat((bytes / Math.pow(k, i)).toFixed(2))} ${sizes[i]}`;
}

function VpnAccessTab({ peers }: { peers: VpnPeer[] }) {
  if (!peers.length) return <p className="muted">No VPN peers provisioned for this customer.</p>;
  return (
    <div className="table-wrap">
      <table className="data-table">
        <thead>
          <tr>
            <th>Peer ID</th>
            <th>Device</th>
            <th>Location / Node</th>
            <th>Assigned IP</th>
            <th>Status</th>
            <th>Latest Handshake</th>
            <th>Traffic (RX / TX)</th>
            <th>Provisioned</th>
            <th>Revoked</th>
          </tr>
        </thead>
        <tbody>
          {peers.map((p) => (
            <tr key={p.id}>
              <td>
                <code>{p.peer_code}</code>
              </td>
              <td>{p.device_name ?? p.platform ?? "—"}</td>
              <td>{p.location ? `${p.location} (${p.node_name})` : p.node_name ?? "—"}</td>
              <td>
                <code>{p.assigned_ip}</code>
              </td>
              <td>
                <Badge status={p.status} />
              </td>
              <td>{p.latest_handshake_at ? formatDate(p.latest_handshake_at) : <span className="muted">Never</span>}</td>
              <td>
                <small>
                  ↓ {formatBytes(p.rx_bytes)} / ↑ {formatBytes(p.tx_bytes)}
                </small>
              </td>
              <td>{formatDate(p.provisioned_at)}</td>
              <td>{formatDate(p.revoked_at)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function PaymentsTab({ payments }: { payments: Payment[] }) {
  if (!payments.length) return <p className="muted">No payments recorded.</p>;
  return (
    <div className="table-wrap">
      <table className="data-table">
        <thead>
          <tr>
            <th>Method</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Paid at</th>
          </tr>
        </thead>
        <tbody>
          {payments.map((p) => (
            <tr key={p.id}>
              <td>{p.payment_method}</td>
              <td>{formatCurrency(p.amount, p.currency)}</td>
              <td>
                <Badge status={p.status} />
              </td>
              <td>{formatDate(p.paid_at)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
