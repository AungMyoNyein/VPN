import { FormEvent, useEffect, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { customersApi, plansApi } from "../../api/endpoints";
import type { Plan } from "../../api/types";
import { ApiClientError } from "../../api/client";
import { ActivationKeyReveal } from "../../components/ActivationKeyReveal";
import { PageHeader } from "../../components/PageHeader";
import {
  createKeyRevealState,
  markKeyAcknowledged,
  markKeyCopied,
  shouldShowKeyWarning,
} from "../../lib/activationKey";
import type { ActivationKeyRevealState } from "../../components/ActivationKeyReveal";

export function CustomerCreatePage() {
  const navigate = useNavigate();
  const [plans, setPlans] = useState<Plan[]>([]);
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [email, setEmail] = useState("");
  const [notes, setNotes] = useState("");
  const [planId, setPlanId] = useState("");
  const [generateKey, setGenerateKey] = useState(false);
  const [autoRenew, setAutoRenew] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [keyReveal, setKeyReveal] = useState<ActivationKeyRevealState | null>(null);
  const [createdCustomerId, setCreatedCustomerId] = useState<number | null>(null);

  useEffect(() => {
    void plansApi.list(true).then((r) => setPlans(r.plans)).catch(() => undefined);
  }, []);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    if (shouldShowKeyWarning(keyReveal)) return;
    setSubmitting(true);
    setError(null);
    try {
      const result = await customersApi.create({
        name,
        phone: phone || undefined,
        email: email || undefined,
        notes: notes || undefined,
        plan_id: planId ? Number(planId) : undefined,
        auto_renew: autoRenew,
        generate_activation_key: generateKey,
      });
      setCreatedCustomerId(result.customer.id);
      const plaintext = result.activation_key?.plaintext_key;
      if (plaintext) {
        setKeyReveal(createKeyRevealState(result.customer.customer_code, plaintext));
      } else {
        navigate(`/customers/${result.customer.id}`);
      }
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Failed to create customer.");
    } finally {
      setSubmitting(false);
    }
  }

  function handleFinish() {
    if (createdCustomerId) navigate(`/customers/${createdCustomerId}`);
  }

  return (
    <div>
      <PageHeader
        title="Create customer"
        subtitle="Step through customer info, optional plan, and activation key."
        actions={
          <Link to="/customers" className="btn btn-secondary">
            Cancel
          </Link>
        }
      />
      {error && (
        <div className="alert alert-error" role="alert">
          {error}
        </div>
      )}
      {keyReveal ? (
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
            className="btn btn-primary"
            disabled={!keyReveal.acknowledged}
            onClick={handleFinish}
          >
            Continue to customer
          </button>
        </div>
      ) : (
        <form className="panel form-grid" onSubmit={(e) => void handleSubmit(e)}>
          <fieldset>
            <legend>Customer info</legend>
            <label className="field">
              <span>Name *</span>
              <input className="input" value={name} onChange={(e) => setName(e.target.value)} required />
            </label>
            <label className="field">
              <span>Phone</span>
              <input className="input" value={phone} onChange={(e) => setPhone(e.target.value)} />
            </label>
            <label className="field">
              <span>Email</span>
              <input type="email" className="input" value={email} onChange={(e) => setEmail(e.target.value)} />
            </label>
            <label className="field">
              <span>Notes</span>
              <textarea className="input" value={notes} onChange={(e) => setNotes(e.target.value)} rows={3} />
            </label>
          </fieldset>
          <fieldset>
            <legend>Plan (optional)</legend>
            <label className="field">
              <span>Plan</span>
              <select className="input" value={planId} onChange={(e) => setPlanId(e.target.value)}>
                <option value="">No plan</option>
                {plans.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name} ({p.duration_days}d, {p.max_devices} devices)
                  </option>
                ))}
              </select>
            </label>
            <label className="checkbox-row">
              <input type="checkbox" checked={autoRenew} onChange={(e) => setAutoRenew(e.target.checked)} />
              Auto renew
            </label>
          </fieldset>
          <fieldset>
            <legend>Activation key (optional)</legend>
            <label className="checkbox-row">
              <input type="checkbox" checked={generateKey} onChange={(e) => setGenerateKey(e.target.checked)} />
              Generate activation key
            </label>
          </fieldset>
          <button type="submit" className="btn btn-primary" disabled={submitting}>
            {submitting ? "Creating…" : "Create customer"}
          </button>
        </form>
      )}
    </div>
  );
}
