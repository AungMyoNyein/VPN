# VPN CRM (Phase 1)

React + TypeScript admin UI for the commercial VPN platform, connected to the Laravel admin API.

## Quick start

```bash
npm install
npm run dev      # http://localhost:5173
npm run typecheck
npm test
npm run build
```

Ensure the Laravel backend is running at `http://127.0.0.1:8000` (see `/root/VPN/backend`).

## Login flow

1. Open the CRM — unauthenticated users are redirected to `/login`.
2. Submit admin email and password → `POST /api/admin/v1/auth/login`.
3. On success, the Sanctum bearer token is stored in `localStorage` (`vpn_crm_token`).
4. The app calls `GET /api/admin/v1/auth/me` to load the admin profile, roles, and permission codes.
5. Protected routes render inside the sidebar layout; nav items are hidden when the user lacks the required permission (backend still enforces RBAC).
6. **Sign out** calls `POST /api/admin/v1/auth/logout`, revokes the token, and clears local storage.

Default seeded admin (from backend `AdminUserSeeder`):

| Field    | Value                          |
|----------|--------------------------------|
| Email    | `admin@vpn.local`              |
| Password | `ChangeMe_LocalOnly_1!`        |

## Environment variables

| Variable              | Default              | Description                                      |
|-----------------------|----------------------|--------------------------------------------------|
| `VITE_API_BASE_URL`   | `/api/admin/v1`      | Admin API base path or full URL                  |

Create `.env` in this directory for overrides:

```env
VITE_API_BASE_URL=/api/admin/v1
```

In development, Vite proxies `/api` → `http://127.0.0.1:8000` (see `vite.config.ts`), so the default same-origin path works without CORS configuration.

For production, set `VITE_API_BASE_URL` to your deployed API origin, e.g. `https://admin-api.example.com/api/admin/v1`.

## Features (Phase 1)

- Dashboard KPIs (customers, subscriptions, expiring soon, devices, nodes, payments)
- Customers: list, create wizard with one-time activation key reveal, detail tabs and actions
- Plans, subscriptions, activation keys, payments, devices
- VPN locations and server lifecycle management
- Admin users, roles (read-only permissions), audit logs
- Settings (API base display, about)

## Design

See [docs/UI_UX.md](../docs/UI_UX.md) and [docs/wireframes/crm.md](../docs/wireframes/crm.md).
