import { FormEvent, useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { customersApi, paymentsApi } from "../../api/endpoints";
import type { Customer, Payment } from "../../api/types";
import { ApiClientError } from "../../api/client";
import { Badge } from "../../components/Badge";
import { ListShell } from "../../components/ListHelpers";
import { Modal } from "../../components/Modal";
import { PageHeader } from "../../components/PageHeader";
import { Pagination } from "../../components/Pagination";
import { useAuth } from "../../auth/AuthContext";
import { PERMISSIONS } from "../../lib/permissions";
import { formatCurrency, formatDate } from "../../lib/format";

export function PaymentsPage() {
  const { hasPermission } = useAuth();
  const [payments, setPayments] = useState<Payment[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [modalOpen, setModalOpen] = useState(false);
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [customerId, setCustomerId] = useState("");
  const [paymentMethod, setPaymentMethod] = useState("CASH");
  const [amount, setAmount] = useState("");
  const [currency, setCurrency] = useState("USD");
  const [submitting, setSubmitting] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await paymentsApi.list({ page });
      setPayments(result.data);
      setLastPage(result.last_page);
      setTotal(result.total);
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Failed to load payments.");
    } finally {
      setLoading(false);
    }
  }, [page]);

  useEffect(() => {
    void load();
  }, [load]);

  async function openCreate() {
    setModalOpen(true);
    try {
      const result = await customersApi.list({ per_page: 100 });
      setCustomers(result.data);
    } catch {
      setCustomers([]);
    }
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    try {
      await paymentsApi.create({
        customer_id: Number(customerId),
        payment_method: paymentMethod,
        amount: parseFloat(amount),
        currency,
      });
      setModalOpen(false);
      await load();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Failed to create payment.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div>
      <PageHeader
        title="Payments"
        subtitle="Payment records across all customers."
        actions={
          hasPermission(PERMISSIONS.PAYMENTS_MANAGE) ? (
            <button type="button" className="btn btn-primary" onClick={() => void openCreate()}>
              Record payment
            </button>
          ) : undefined
        }
      />
      <ListShell
        loading={loading}
        error={error}
        empty={!loading && !error && payments.length === 0}
        emptyTitle="No payments"
        onRetry={() => void load()}
      >
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Customer</th>
                <th>Method</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Paid at</th>
              </tr>
            </thead>
            <tbody>
              {payments.map((p) => (
                <tr key={p.id}>
                  <td>
                    {p.customer ? (
                      <Link to={`/customers/${p.customer_id}`}>{p.customer.name}</Link>
                    ) : (
                      p.customer_id
                    )}
                  </td>
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
        <Pagination page={page} lastPage={lastPage} total={total} onPageChange={setPage} />
      </ListShell>

      <Modal open={modalOpen} title="Record payment" onClose={() => setModalOpen(false)}>
        <form className="form-stack" onSubmit={(e) => void handleSubmit(e)}>
          <label className="field">
            <span>Customer</span>
            <select className="input" value={customerId} onChange={(e) => setCustomerId(e.target.value)} required>
              <option value="">Select customer</option>
              {customers.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.customer_code} — {c.name}
                </option>
              ))}
            </select>
          </label>
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
            <input type="number" step="0.01" className="input" value={amount} onChange={(e) => setAmount(e.target.value)} required />
          </label>
          <label className="field">
            <span>Currency</span>
            <input className="input" maxLength={3} value={currency} onChange={(e) => setCurrency(e.target.value.toUpperCase())} required />
          </label>
          <button type="submit" className="btn btn-primary" disabled={submitting}>
            {submitting ? "Saving…" : "Save payment"}
          </button>
        </form>
      </Modal>
    </div>
  );
}
