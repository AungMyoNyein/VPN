import { PageHeader } from "../../components/PageHeader";
import { getApiBaseUrl } from "../../api/client";

export function SettingsPage() {
  return (
    <div>
      <PageHeader title="Settings" subtitle="Application configuration and about." />
      <div className="panel settings-panel">
        <section>
          <h3>API configuration</h3>
          <dl className="detail-grid">
            <dt>API base URL</dt>
            <dd>
              <code>{getApiBaseUrl()}</code>
            </dd>
            <dt>Environment variable</dt>
            <dd>
              <code>VITE_API_BASE_URL</code>
            </dd>
          </dl>
          <p className="muted">
            Default is <code>/api/admin/v1</code> which proxies to the Laravel backend via Vite dev server.
            Set <code>VITE_API_BASE_URL</code> in <code>.env</code> for production builds.
          </p>
        </section>
        <section>
          <h3>About</h3>
          <p>VPN CRM — Phase 1 admin interface</p>
          <p className="muted">React 18 · TypeScript · Vite · Laravel Sanctum auth</p>
        </section>
      </div>
    </div>
  );
}
