# API Contract

**Phase:** 3 — Control Plane, IPAM, and Provisioning implemented (public `/api/v1/vpn/*`, internal `/internal/v1/*`, CRM `/api/admin/v1/*`)  
**Base public path:** `/api/v1`  
**Internal control-plane path:** `/internal/v1`  
**Last updated:** 2026-08-28

## 1. Conventions

### Authentication by surface

| Surface | Consumers | Auth |
|---------|-----------|------|
| Public `/api/v1/*` | Android, iOS | Device credential bearer (issued at activation) |
| Admin / CRM | Operators | Admin session + RBAC (`AdminUser` Sanctum tokens) |
| Internal `/internal/v1/*` | Laravel → control plane | Service identity bearer token (`Bearer <token>`) + request_id; **not** “private IP alone” |

**There is no customer email/password/OTP/social login.** Customers activate with Customer ID + Activation Key once per device; subsequent calls use the device credential.

Internal APIs must never trust a request merely because it originates from a private IP. Missing or invalid service credentials → `401 UNAUTHENTICATED`.

### Success

```json
{
  "data": {},
  "meta": {}
}
```

### Error

```json
{
  "error": {
    "code": "SUBSCRIPTION_EXPIRED",
    "message": "Subscription has expired",
    "request_id": "550e8400-e29b-41d4-a716-446655440000"
  }
}
```

Clients MUST key behavior on `error.code`, not message text.

### Headers

| Header | Usage |
|--------|--------|
| `Authorization: Bearer <token>` | Customer / admin / internal API |
| `Idempotency-Key: <uuid>` | Required for `POST /api/v1/vpn/provision`, payment confirm, destructive CP ops |
| `X-Request-ID: <uuid>` | Client or middleware supplied, propagated throughout stack |
| `Accept: application/json` | Required for API |

### Versioning

Public APIs are versioned (`/api/v1`). Breaking changes require `/api/v2`. Within a version, maintain backward compatibility.

---

## 2. Public Customer API

### Activation & device credential (Phase 2 — implemented)

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| POST | `/activate` | No | **Rate limited** (`RateLimiter::for('activate')`, default 5/min per IP+customer_code); returns device credential once |
| POST | `/device/refresh` | Device credential (`device.auth`) | Rotates the credential (immediate rotation — old token invalidated) |
| POST | `/device/deactivate` | Device credential (`device.auth`) | Self-deactivate the current device (revokes device + credential + active peer) |
| GET | `/device` | Device credential (`device.auth`) | Current device + entitlement summary |

### Account & subscription (Phase 2 — implemented)

| Method | Path | Auth |
|--------|------|------|
| GET | `/account` | Device credential (`device.auth`) |
| GET | `/subscription` | Device credential (`device.auth`) |

Both remain readable even when the subscription has expired.

### VPN Provisioning & Infrastructure (Phase 3 — implemented)

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| GET | `/vpn/locations` | Device credential (`device.auth`) | Active locations with available capacity |
| GET | `/vpn/recommended-server` | Device credential (`device.auth`) | Best server by weight and lowest utilization |
| GET | `/vpn/status` | Device credential (`device.auth`) | Current device's active VPN peer status |
| POST | `/vpn/provision` | Device credential (`device.auth`) | Provision VPN configuration (`Idempotency-Key` supported) |
| POST | `/vpn/revoke` | Device credential (`device.auth`) | Revoke current device active VPN peer |

#### Provision Request

**WireGuard (default):**

```json
{
  "protocol": "wireguard",
  "location_id": 1,
  "client_public_key": "jXpGt9enG8oV8lxX4vwNBi1czL89KqL8ImmWToKVHyv="
}
```

**VLESS:**

```json
{
  "protocol": "vless",
  "location_id": 1,
  "client_uuid": "550e8400-e29b-41d4-a716-446655440000"
}
```

`client_uuid` is optional for VLESS — the server generates one if omitted.

#### Provision Response (WireGuard success)

```json
{
  "data": {
    "peer_id": "WG-PEER-001",
    "address": "10.200.20.45/32",
    "server": {
      "id": 1,
      "name": "Singapore 01",
      "location": "Singapore",
      "endpoint": "vpn-sg01.example.com:51820",
      "public_key": "SERVER_WIREGUARD_PUBLIC_KEY"
    },
    "dns": ["1.1.1.1", "1.0.0.1"],
    "allowed_ips": ["0.0.0.0/0", "::/0"],
    "persistent_keepalive": 25,
    "mtu": 1420
  },
  "meta": {
    "request_id": "..."
  }
}
```

Never includes SSH passwords, management IPs, or control-plane credentials.

#### Provision Response (VLESS success)

```json
{
  "data": {
    "protocol": "vless",
    "peer_id": "VLESS-PEER-001",
    "uuid": "550e8400-e29b-41d4-a716-446655440000",
    "dns": ["1.1.1.1", "1.0.0.1"],
    "server": {
      "id": 1,
      "name": "Singapore 01",
      "location": "Singapore",
      "endpoint": "zentunnel.net:443",
      "security": "tls",
      "sni": "zentunnel.net",
      "flow": "xtls-rprx-vision",
      "fingerprint": "chrome",
      "alpn": "h2,http/1.1"
    },
    "share_url": "vless://550e8400-e29b-41d4-a716-446655440000@zentunnel.net:443?..."
  },
  "meta": { "request_id": "..." }
}
```

#### GET `/vpn/protocols`

Returns supported protocols for the mobile client.

```json
{
  "data": {
    "protocols": ["wireguard", "vless"],
    "default": "wireguard"
  }
}
```

#### GET `/vpn/recommended-server?protocol=vless`

Query `protocol` is optional (`wireguard` default).

### Stable Error Codes

| Code | Meaning |
|------|---------|
| `UNAUTHENTICATED` | Missing/invalid admin session or token |
| `FORBIDDEN` | Authenticated but not allowed |
| `VALIDATION_ERROR` | Input validation failed |
| `RATE_LIMITED` | Too many requests |
| `ACTIVATION_INVALID` | Unknown customer code or wrong activation key |
| `ACTIVATION_KEY_REVOKED` | Key revoked or suspended |
| `ACTIVATION_KEY_EXPIRED` | Key expired |
| `ACTIVATION_KEY_EXHAUSTED` | Key max activations reached |
| `CUSTOMER_SUSPENDED` | Customer account suspended |
| `CUSTOMER_BLOCKED` | Customer account blocked |
| `SUBSCRIPTION_REQUIRED` | Customer has no subscription |
| `SUBSCRIPTION_EXPIRED` | Subscription expired |
| `DEVICE_LIMIT_REACHED` | Max active devices reached |
| `DEVICE_BLOCKED` | Device administratively blocked |
| `DEVICE_REVOKED` | Device revoked |
| `DEVICE_CREDENTIAL_INVALID` | Invalid/missing bearer credential |
| `DEVICE_CREDENTIAL_REVOKED` | Credential revoked or expired |
| `NO_VPN_NODE_AVAILABLE` | No healthy eligible VPN node with available capacity |
| `IP_POOL_EXHAUSTED` | Target node IP pool has no available usable IPs |
| `VPN_PROVISIONING_FAILED` | Downstream node mutation failed |
| `INVALID_PUBLIC_KEY` | Malformed WireGuard client public key |
| `INVALID_IP_POOL` | Malformed CIDR network or gateway configuration |
| `INTERNAL_ERROR` | Unexpected server error |

---

## 3. Admin / CRM API

**Base path:** `/api/admin/v1`  
**Auth:** Sanctum personal access tokens on `AdminUser` (not the default `User` model).  
**Envelope:** Same ADR-0005 `{ data, meta }` / `{ error }` contract as public API.

| Method | Path | Permission (examples) |
|--------|------|------------------------|
| POST | `/auth/login` | Public (rate limited) |
| POST | `/auth/logout` | Authenticated admin |
| GET | `/auth/me` | Authenticated admin |
| GET | `/dashboard` | `dashboard.view` |
| CRUD | `/customers`, `/plans`, `/subscriptions`, `/activation-keys`, `/devices`, `/locations`, `/vpn-nodes`, `/payments`, `/admin-users`, `/roles` | Role-gated per resource |
| GET | `/audit-logs` | `audit.view` |
| POST | `/customers/{id}/renew` | `subscriptions.renew` |
| POST | `/customers/{id}/activation-keys` | `activation_keys.manage` |
| PATCH | `/vpn-nodes/{id}/lifecycle` | `nodes.lifecycle` |

RBAC roles seeded: `SUPER_ADMIN`, `NOC`, `SUPPORT`, `FINANCE`. Admin device revoke/block/reset-binding also revoke the device's `device_credentials` (see [DATABASE.md](./DATABASE.md)).

---

## 4. Internal Control Plane API

**Not publicly routed.** Laravel is the primary client.

Base: `/internal/v1`

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/peers` | Provision peer (idempotent) |
| DELETE | `/peers/{peer_id}` | Revoke peer |
| POST | `/nodes/register` | Register / update node |
| POST | `/nodes/{id}/heartbeat` | Agent heartbeat |
| GET | `/nodes` | Inventory + health |
| POST | `/nodes/{id}/drain` | Stop new assignments |
| POST | `/nodes/{id}/maintenance` | Maintenance mode |
| POST | `/selection/recommend` | Server selection helper |
| GET | `/health` | Liveness |

Auth: mTLS + service token. All mutating calls accept `Idempotency-Key` and `X-Request-ID`. Private network placement is necessary but **not** sufficient.

### Node Agent Protocol (Phase 4 Implementation)

Node Agent runs natively on Linux VPN nodes. Control plane communicates with Node Agent via `RemoteNodeAdapter` over HTTPS/mTLS.

Endpoints exposed on Node Agent (`/internal/v1/*`):
* `GET    /internal/v1/health` - Interface and service health status
* `GET    /internal/v1/status` - Node runtime configuration, WireGuard interface details, authorized pools
* `POST   /internal/v1/peers` - Add/update peer with WireGuard public key and assigned IP
* `GET    /internal/v1/peers` - List applied peers with latest handshake and rx/tx byte counters
* `GET    /internal/v1/peers/{id}` - Get single peer runtime state
* `DELETE /internal/v1/peers/{id}` - Idempotent peer revocation
* `GET    /internal/v1/statistics` - Device statistics and active peer count
* `GET    /metrics` - Prometheus metrics format

**Allowed operations:** `AddPeer`, `UpdatePeer`, `RemovePeer`, `GetPeer`, `ListPeers`, `Device` (stats/health).

**Forbidden:** generic remote shell / `execute-command` ([ADR-0006](./ADR/0006-node-agent-narrow-api.md)).

### Idempotency (Phase 0 requirement for later implementation)

Required (or strongly recommended) for:

- VPN provisioning
- Peer revocation
- Payment callbacks
- Subscription mutations where retries are expected

Retrying `POST /vpn/provision` with the same `Idempotency-Key` and same payload must return the original outcome and must **not** create duplicate peers or duplicate IP allocations.

---

## 5. What Must Never Be Returned to Mobile

- `management_ip`
- Node SSH credentials
- Control-plane URLs/tokens
- Other customers’ devices or sessions
- Raw payment provider secrets
- Client private keys

---

## 6. Rate Limiting (baseline targets)

| Class | Target |
|-------|--------|
| Activation (`POST /activate`) | **Implemented**: `activate_per_minute` (default 5/min), keyed by IP + `sha1(customer_code)`, config `config/activation.php` / env `ACTIVATION_RATE_LIMIT_PER_MINUTE` |
| Device refresh | Moderate per device (Phase 3+ tuning) |
| Provision / session | Moderate per device |
| Read APIs | Higher, per device credential |
| Internal CP | Per-service quota + alerting |

Further numbers tuned in Phase 3/7.
