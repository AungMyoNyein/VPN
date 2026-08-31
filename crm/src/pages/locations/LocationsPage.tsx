import { FormEvent, useCallback, useEffect, useState } from "react";
import { locationsApi } from "../../api/endpoints";
import type { Location } from "../../api/types";
import { ApiClientError } from "../../api/client";
import { Badge } from "../../components/Badge";
import { ConfirmDialog } from "../../components/ConfirmDialog";
import { ListShell } from "../../components/ListHelpers";
import { Modal } from "../../components/Modal";
import { PageHeader } from "../../components/PageHeader";

export function LocationsPage() {
  const [locations, setLocations] = useState<Location[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<Location | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<Location | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const [countryCode, setCountryCode] = useState("");
  const [countryName, setCountryName] = useState("");
  const [city, setCity] = useState("");
  const [displayName, setDisplayName] = useState("");
  const [sortOrder, setSortOrder] = useState("0");
  const [active, setActive] = useState(true);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await locationsApi.list();
      setLocations(result.locations);
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Failed to load locations.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  function openCreate() {
    setEditing(null);
    setCountryCode("");
    setCountryName("");
    setCity("");
    setDisplayName("");
    setSortOrder("0");
    setActive(true);
    setModalOpen(true);
  }

  function openEdit(loc: Location) {
    setEditing(loc);
    setCountryCode(loc.country_code);
    setCountryName(loc.country_name);
    setCity(loc.city);
    setDisplayName(loc.display_name);
    setSortOrder(String(loc.sort_order));
    setActive(loc.active);
    setModalOpen(true);
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    try {
      const payload = {
        country_code: countryCode,
        country_name: countryName,
        city,
        display_name: displayName,
        sort_order: parseInt(sortOrder, 10),
        active,
      };
      if (editing) await locationsApi.update(editing.id, payload);
      else await locationsApi.create(payload);
      setModalOpen(false);
      await load();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Failed to save location.");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleDelete() {
    if (!deleteTarget) return;
    setSubmitting(true);
    try {
      await locationsApi.remove(deleteTarget.id);
      setDeleteTarget(null);
      await load();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : "Failed to delete location.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div>
      <PageHeader
        title="Locations"
        subtitle="VPN location catalog for customer selection."
        actions={
          <button type="button" className="btn btn-primary" onClick={openCreate}>
            New location
          </button>
        }
      />
      <ListShell
        loading={loading}
        error={error}
        empty={!loading && !error && locations.length === 0}
        emptyTitle="No locations"
        onRetry={() => void load()}
      >
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Display name</th>
                <th>Country</th>
                <th>City</th>
                <th>Sort</th>
                <th>Status</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {locations.map((loc) => (
                <tr key={loc.id}>
                  <td>{loc.display_name}</td>
                  <td>
                    {loc.country_name} ({loc.country_code})
                  </td>
                  <td>{loc.city}</td>
                  <td>{loc.sort_order}</td>
                  <td>
                    <Badge status={loc.active ? "ACTIVE" : "DISABLED"} label={loc.active ? "Active" : "Inactive"} />
                  </td>
                  <td>
                    <div className="action-row">
                      <button type="button" className="btn btn-secondary btn-sm" onClick={() => openEdit(loc)}>
                        Edit
                      </button>
                      <button type="button" className="btn btn-danger btn-sm" onClick={() => setDeleteTarget(loc)}>
                        Delete
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </ListShell>

      <Modal open={modalOpen} title={editing ? "Edit location" : "Create location"} onClose={() => setModalOpen(false)}>
        <form className="form-stack" onSubmit={(e) => void handleSubmit(e)}>
          <label className="field">
            <span>Country code</span>
            <input className="input" maxLength={2} value={countryCode} onChange={(e) => setCountryCode(e.target.value.toUpperCase())} required />
          </label>
          <label className="field">
            <span>Country name</span>
            <input className="input" value={countryName} onChange={(e) => setCountryName(e.target.value)} required />
          </label>
          <label className="field">
            <span>City</span>
            <input className="input" value={city} onChange={(e) => setCity(e.target.value)} required />
          </label>
          <label className="field">
            <span>Display name</span>
            <input className="input" value={displayName} onChange={(e) => setDisplayName(e.target.value)} required />
          </label>
          <label className="field">
            <span>Sort order</span>
            <input type="number" className="input" value={sortOrder} onChange={(e) => setSortOrder(e.target.value)} />
          </label>
          <label className="checkbox-row">
            <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} />
            Active
          </label>
          <button type="submit" className="btn btn-primary" disabled={submitting}>
            {submitting ? "Saving…" : "Save location"}
          </button>
        </form>
      </Modal>

      <ConfirmDialog
        open={Boolean(deleteTarget)}
        title="Delete location"
        description={`Deleting "${deleteTarget?.display_name}" removes it from the location list. VPN nodes at this location must be reassigned first.`}
        confirmLabel="Delete location"
        destructive
        loading={submitting}
        onConfirm={() => void handleDelete()}
        onCancel={() => setDeleteTarget(null)}
      />
    </div>
  );
}
