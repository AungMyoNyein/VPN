# Security Architecture

**Phase:** 3 — Control Plane, IPAM, and VPN Provisioning implemented  
**Last updated:** 2026-08-28

## 1. Principles

1. Treat all mobile clients as untrusted.
2. Authorize every sensitive action on the backend.
3. Never trust client-reported subscription or payment success.
4. Device-centric WireGuard identities; client-side private key generation only.
5. Secrets only via environment / secrets manager — never Git, logs, analytics, or unnecessary API fields.
6. Least-privilege RBAC for CRM operators.
7. Immutable audit trail for administrative, provisioning, and security-relevant actions.
8. Privacy by design: no browsing history, no default DNS query logging, no payload inspection logs.

## 2. Authentication (Customers)

| Mechanism | Phase |
|-----------|-------|
| Customer ID + Activation Key (one-time per device binding) | 2 — implemented |
| Secure hash of activation key at rest (never plaintext after create) | 1 — implemented |
| Device credential (high-entropy; hash stored server-side) | 2 — implemented |
| Android Keystore / iOS Keychain client storage | 5 / 6 |
| Device credential rotation + revocation | 2 — implemented |
| VPN Provisioning auth via Device Credential | 3 — implemented |
| Email / password / OTP / Google / Apple login | **Out of product scope** |

### Device credentials (Phase 2)

- Opaque, high-entropy token (32 random bytes, base64url-encoded — 256 bits of entropy), issued by `DeviceCredentialService::issue()` at successful activation.
- Only a hash (`Hash::make`) and an 8-character lookup prefix (`token_prefix`) are persisted (`device_credentials` table) — the plaintext is returned exactly once, in the activation / refresh response, and never logged or audited.
- **Immediate rotation:** issuing a new credential for a device revokes any previously active one in the same transaction; at most one active credential exists per device (enforced by a partial unique DB index).
- Lookup is prefix-candidate + `Hash::check` (never a raw hash-equality query), same pattern as `ActivationKeyService`.
- `AuthenticateDeviceCredential` middleware (alias `device.auth`) resolves `Authorization: Bearer <token>` → credential → device → customer for all public `/api/v1/*` endpoints except `/activate` and `/health`, rejecting with `DEVICE_CREDENTIAL_INVALID`, `DEVICE_CREDENTIAL_REVOKED`, `DEVICE_REVOKED`, `DEVICE_BLOCKED`, `CUSTOMER_SUSPENDED`, or `CUSTOMER_BLOCKED` as appropriate.
- Admin device revoke / block / reset-binding (`DeviceService`) all revoke the device's credentials as part of the same operation — an admin action takes effect on the client's next request without waiting for token expiry.

Controls:

- Rate limiting on `/activate` (`RateLimiter::for('activate')`, default 5/min, keyed by IP + `sha1(customer_code)`; configurable via `ACTIVATION_RATE_LIMIT_PER_MINUTE`)
- Key/token normalization before verify; hash comparison via `Hash::check` (bcrypt, constant-time)
- Activation keys are **not** long-lived API bearer tokens
- Device credentials scoped per device; cross-customer access forbidden
- Never log full activation keys or device credential plaintext
- **Anti-enumeration:** an unknown `customer_code` and a wrong/foreign activation key both return the same generic `ACTIVATION_INVALID` code; only once the customer is located AND the key is verified to belong to that customer are specific codes (`CUSTOMER_SUSPENDED`, `ACTIVATION_KEY_EXPIRED`, ...) returned
- **Device limit accounting:** only `ACTIVE` devices consume a plan's device-limit slot; `BLOCKED`/`REVOKED` devices never do (`DeviceService::activeDeviceCount`)

## 3. Authentication (Services)

| Path | Mechanism |
|------|-----------|
| Laravel → Control plane | mTLS + service credential / signed JWT |
| Control plane → Node agent | mTLS + node enrollment token |
| Payment webhooks | Provider signature verification |
| Admin CRM | Session + RBAC roles |

Control-plane and node-management APIs are **never** on the public internet.

**Do not treat private-network / private-IP origin as authentication.** Every internal call requires service identity (mTLS and/or service token) plus authorization. A request that merely arrives from a RFC1918 address is rejected if credentials are missing or invalid.

## 4. Authorization

### Customer API

- Scoped to authenticated customer.
- Device operations limited to own devices.
- VPN provision/revoke gated by: customer status, subscription, device limits, location entitlements.
- Never authorize from client-supplied “I am premium” flags.

### CRM Roles

| Role | Intent |
|------|--------|
| SUPER_ADMIN | Full platform administration |
| NOC | Nodes, sessions, health, drain/maintenance |
| SUPPORT | Customers, tickets, limited device revoke |
| FINANCE | Plans, payments, refunds (policy-bound) |
| CONTENT_ADMIN | Non-security content / display metadata |

Least privilege: each endpoint declares required role(s).

## 5. Cryptography & Keys

- WireGuard Curve25519 keypairs generated on device for clients.
- WireGuard server private keys generated locally on VPN nodes with `0600` permissions (never exported or stored in database).
- Backend stores: peer ID, public key, tunnel IP, node association, timestamps, revocation.
- **Do not store client private keys** unless an ADR documents an unavoidable requirement.
- TLS 1.3/1.2 everywhere externally; mTLS on management plane.
- At-rest: disk encryption for DB volumes in production; app secrets encrypted at rest in vault.

## 6. WireGuard Node & Agent Security (Phase 4)

1. **Server Private Key Isolation**:
   - The WireGuard server private key is generated locally on the node during installation (`0600` permissions).
   - It is NEVER sent over the network, never stored in PostgreSQL, and never accessible via Control Plane or Laravel APIs.
2. **Narrow Agent Operations**:
   - The Node Agent Go daemon exposes only specific operations (`AddPeer`, `RemovePeer`, `GetPeer`, `ListPeers`, `Device`).
   - Shell execution, arbitrary commands, and remote bash calls are completely absent from the codebase.
3. **Defense-in-Depth IPAM Pool Validation**:
   - When receiving an `AddPeer` request, the Node Agent verifies that the requested `allowed_ip` belongs to one of the configured `authorized_pools` for that node, preventing rogue IP injection.
4. **Firewall & NAT Scoping**:
   - Node Agent manages a dedicated `table inet vpn_platform` in `nftables`.
   - Management ports (9443, 22, 8081) are explicitly dropped for traffic coming from the `wg0` client interface.
   - Client-to-client isolation is enforced by dropping traffic where incoming interface is `wg0` and outgoing interface is `wg0`.

## 7. API Security Baseline

Every endpoint must define:

- Authentication requirement
- Authorization rule
- Rate limit
- Input validation
- Audit requirement (yes/no)

Responses:

- Consistent error envelope with stable `error.code`
- No stack traces to clients
- `request_id` on errors

## 7. Secrets Handling

Never place in:

- Git repositories
- Debug logs
- Unnecessary API responses
- Analytics / crash telemetry
- Audit `old_values` / `new_values` for secret fields

Use `.env` locally (gitignored) and a secrets manager in staging/production.

## 8. Data Classification

| Class | Examples | Handling |
|-------|----------|----------|
| Secret | Private keys, DB passwords, API keys | Vault / env; never log |
| PII | Email, phone, name | Access-controlled; retention policy |
| Operational | Session bytes, node health | Ops retention; no browsing content |
| Public | Location display names, plan marketing | CDN-ok |

## 8.1 Privacy position

- Do **not** design browsing-history collection.
- Do **not** log by default: visited URLs, packet payloads, customer browsing history, DNS queries.
- Operational metadata may include: device, VPN node, connection timestamps, aggregate bytes, provisioning status, failure reason.
- Retention is configurable (see [OPERATIONS.md](./OPERATIONS.md)); finalize with legal before Phase 8.

## 8.2 Node agent surface

Node agents must not expose generic remote execution (`POST /execute-command` or equivalent). Allowed operations are narrowly typed peer and health APIs ([ADR-0006](./ADR/0006-node-agent-narrow-api.md)).

## 8.3 Observability hygiene

- Never put VPN/customer secrets in metrics labels or log fields.
- Avoid high-cardinality labels such as raw customer IDs / emails unless explicitly justified and approved.

## 9. Audit Logging

Immutable records for:

- User suspend/block/close
- Subscription changes
- Peer revoke
- Node disable / maintenance / drain
- Role changes
- Manual payment adjustments
- Customer activation success (`activation.succeeded`) and device credential issuance/rotation (`device_credential.issued`) — actor `SYSTEM` (customer self-service actions are not tied to an `AdminUser`)

Fields: actor, action, target type/id, old/new (sanitized), source IP, timestamp, correlation ID.

`AuditLogger` redacts (never persists in `before_data`/`after_data`): `password`, `key_hash`, `plaintext_key`, `activation_key`, `device_token_hash`, `token`, `token_hash`, `plaintext_token`, `device_credential`, `credential`, `bearer`, `secret`, `remember_token`, and the exact key `key`. `key_prefix` and `token_prefix` are intentionally **not** redacted (safe, non-secret lookup hints).

## 10. Network & Leak Protection (Client Design Goals)

Architecture must address (implementation + tests in later phases):

- DNS leakage
- IPv6 leakage
- Route leakage
- Tunnel failure handling
- Kill switch — **only claim after platform-level verification**

## 11. Payment Security

- Server-side webhook verification only
- Idempotent payment processing
- Replay protection on provider references
- Mobile “purchase success” is informational until webhook confirms

## 12. Secure Development

- CI: lint, unit/integration tests, dependency/security scans
- No direct deploy from engineer workstations
- Migrations over ad-hoc schema edits
- Penetration-test checklist before Phase 8

## 13. Related

- [THREAT_MODEL.md](./THREAT_MODEL.md)
- [ADR-0002](./ADR/0002-device-centric-vpn-keys.md)
- [ADR-0006](./ADR/0006-node-agent-narrow-api.md)
