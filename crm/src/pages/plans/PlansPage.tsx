import { FormEvent, useCallback, useEffect, useState } from "react";
import { plansApi } from "../../api/endpoints";
import type { Plan } from "../../api/types";
import { ApiClientError } from "../../api/client";
import { Badge } from "../../components/Badge";
import { ConfirmDialog } from "../../components/ConfirmDialog";
import { ListShell } from "../../components/ListHelpers";
import { Modal } from "../../components/Modal";
import { PageHeader } from "../../components/PageHeader";
import { useAuth } from "../../auth/AuthContext";
import { PERMISSIONS } from "../../lib/permissions";
import { formatCurrency } from "../../lib/format";

export function PlansPage() {
  const { hasPermission } = useAuth();
  const [plans, setPlans] = useState<Plan[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<Plan | null>(null);
  const [disableTarget, setDisableTarget] = useState<Plan | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const [name, setName] = useState("");
  const [code, setCode] = useState("");
  const [price, setPrice] = useState("");
  const [currency, setCurrency] = useState("USD");
  const [durationDays, setDurationDays] = useState("30");
  const [maxDevices, setMaxDevices] = useState("2");

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await plansApi.list();
      setPlans(result.plans);
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Failed to load plans.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  function openCreate() {
    setEditing(null);
    setName("");
    setCode("");
    setPrice("");
    setCurrency("USD");
    setDurationDays("30");
    setMaxDevices("2");
    setModalOpen(true);
  }

  function openEdit(plan: Plan) {
    setEditing(plan);
    setName(plan.name);
    setCode(plan.code);
    setPrice(plan.price);
    setCurrency(plan.currency);
    setDurationDays(String(plan.duration_days));
    setMaxDevices(String(plan.max_devices));
    setModalOpen(true);
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    try {
      const payload = {
        name,
        code,
        price: parseFloat(price),
        currency,
        duration_days: parseInt(durationDays, 10),
        max_devices: parseInt(maxDevices, 10),
      };
      if (editing) {
        await plansApi.update(editing.id, payload);
      } else {
        await plansApi.create(payload);
      }
      setModalOpen(false);
      await load();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Failed to save plan.");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleDisable() {
    if (!disableTarget) return;
    setSubmitting(true);
    try {
      await plansApi.update(disableTarget.id, { active: false });
      setDisableTarget(null);
      await load();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Failed to disable plan.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div>
      <PageHeader
        title="Plans"
        subtitle="Subscription plan catalog."
        actions={
          hasPermission(PERMISSIONS.PLANS_MANAGE) ? (
            <button type="button" className="btn btn-primary" onClick={openCreate}>
              New plan
            </button>
          ) : undefined
        }
      />
      <ListShell
        loading={loading}
        error={error}
        empty={!loading && !error && plans.length === 0}
        emptyTitle="No plans"
        onRetry={() => void load()}
      >
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Code</th>
                <th>Price</th>
                <th>Duration</th>
                <th>Devices</th>
                <th>Status</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {plans.map((p) => (
                <tr key={p.id}>
                  <td>{p.name}</td>
                  <td>{p.code}</td>
                  <td>{formatCurrency(p.price, p.currency)}</td>
                  <td>{p.duration_days} days</td>
                  <td>{p.max_devices}</td>
                  <td>
                    <Badge status={p.active ? "ACTIVE" : "DISABLED"} label={p.active ? "Active" : "Disabled"} />
                  </td>
                  <td>
                    {hasPermission(PERMISSIONS.PLANS_MANAGE) && (
                      <div className="action-row">
                        <button type="button" className="btn btn-secondary btn-sm" onClick={() => openEdit(p)}>
                          Edit
                        </button>
                        {p.active && (
                          <button
                            type="button"
                            className="btn btn-danger btn-sm"
                            onClick={() => setDisableTarget(p)}
                          >
                            Disable
                          </button>
                        )}
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </ListShell>

      <Modal open={modalOpen} title={editing ? "Edit plan" : "Create plan"} onClose={() => setModalOpen(false)}>
        <form className="form-stack" onSubmit={(e) => void handleSubmit(e)}>
          <label className="field">
            <span>Name</span>
            <input className="input" value={name} onChange={(e) => setName(e.target.value)} required />
          </label>
          <label className="field">
            <span>Code</span>
            <input className="input" value={code} onChange={(e) => setCode(e.target.value)} required disabled={!!editing} />
          </label>
          <label className="field">
            <span>Price</span>
            <input type="number" step="0.01" className="input" value={price} onChange={(e) => setPrice(e.target.value)} required />
          </label>
          <label className="field">
            <span>Currency</span>
            <input className="input" maxLength={3} value={currency} onChange={(e) => setCurrency(e.target.value.toUpperCase())} required />
          </label>
          <label className="field">
            <span>Duration (days)</span>
            <input type="number" className="input" value={durationDays} onChange={(e) => setDurationDays(e.target.value)} required />
          </label>
          <label className="field">
            <span>Max devices</span>
            <input type="number" className="input" value={maxDevices} onChange={(e) => setMaxDevices(e.target.value)} required />
          </label>
          <button type="submit" className="btn btn-primary" disabled={submitting}>
            {submitting ? "Saving…" : "Save plan"}
          </button>
        </form>
      </Modal>

      <ConfirmDialog
        open={Boolean(disableTarget)}
        title="Disable plan"
        description={`Disabling "${disableTarget?.name}" prevents new subscriptions from using this plan. Existing subscriptions are not affected.`}
        confirmLabel="Disable plan"
        destructive
        loading={submitting}
        onConfirm={() => void handleDisable()}
        onCancel={() => setDisableTarget(null)}
      />
    </div>
  );
}
