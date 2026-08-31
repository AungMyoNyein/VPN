# Threat Model

**Phase:** 3 (Control Plane, IPAM, and Fake Node Provisioning)  
**Method:** STRIDE-oriented asset analysis  
**Last updated:** 2026-08-28

## 1. Assets

| Asset | Sensitivity |
|-------|-------------|
| Customer credentials & tokens | High |
| WireGuard client private keys (on device) | High |
| WireGuard public keys & peer mappings | Medium |
| Payment records & provider refs | High |
| Node management credentials / mTLS material | Critical |
| Control-plane API | Critical |
| Session metadata (IP, bytes, times) | Medium (privacy) |
| CRM admin accounts | Critical |
| Audit logs integrity | High |

## 2. Actors

- Anonymous internet attacker
- Authenticated customer (malicious or compromised)
- Compromised mobile device
- Malicious / compromised CRM operator
- Compromised VPN node
- Payment fraud actor (replay / forged webhooks)
- Insider with infra access

## 3. Trust Boundaries

Phase 0 documents the following **distinct** trust boundaries (compromise of one must not automatically imply compromise of another):

| # | Boundary | Notes |
|---|----------|-------|
| 1 | Untrusted mobile client | Device, app binary, local storage, client-reported “premium” flags |
| 2 | Public API edge | WAF / reverse proxy / TLS termination |
| 3 | Laravel application | Customer API, CRM, entitlement decisions |
| 4 | Control plane | Peer/IP/node orchestration — internal only |
| 5 | Node agent | Privileged WG apply surface on the node |
| 6 | VPN node | WireGuard host, nftables, customer data-plane traffic |
| 7 | PostgreSQL | Customer/CRM SoR and durable state |
| 8 | Redis | Cache/queues/sessions — treat as sensitive |
| 9 | Monitoring infrastructure | Prometheus / Grafana / Loki — no secrets in labels |
| 10 | Administrator / CRM users | Separate identity; RBAC; audited |

Key product boundary: **public API ≠ control plane ≠ node management**. See [ARCHITECTURE.md](./ARCHITECTURE.md) §3–4.

Mobile compromise must not yield credentials for managing VPN infrastructure.

## 4. STRIDE Summary

### Spoofing

| Threat | Mitigation |
|--------|------------|
| Stolen customer token | Short-lived access tokens, refresh rotation, logout/revoke, device binding signals |
| Fake payment success from app | Ignore client claims; verify webhooks |
| Rogue node registering | Pre-enrolled node inventory + mTLS + Node ID validation middleware |
| Customer accessing node management port | nftables `input` chain drops TCP {9443, 22, 8081} on `wg0` interface |
| Lateral client-to-client attack | nftables `forward` chain drops `iifname wg0 oifname wg0` |
| Admin impersonation | Strong admin auth, MFA (Phase 7), RBAC |

### Tampering

| Threat | Mitigation |
|--------|------------|
| Peer config tampering in transit | TLS/mTLS end-to-end on management path |
| Audit log alteration | Append-only table; restricted DB roles; export to WORM/Loki |
| Idempotency bypass creating duplicate peers | Idempotency store + unique public_key |

### Repudiation

| Threat | Mitigation |
|--------|------------|
| Admin denies destructive action | Immutable audit with actor, IP, correlation ID |
| Customer denies device revoke | Audit + device status timestamps |

### Information Disclosure

| Threat | Mitigation |
|--------|------------|
| Management IP leaked to app | Explicit DTO filtering; API contract tests |
| Private keys in logs/telemetry | Logging redaction; no private key server-side |
| Cross-customer device listing | Ownership checks on every query |
| Stack traces to clients | Generic errors + request_id |

### Denial of Service

| Threat | Mitigation |
|--------|------------|
| Auth brute force | Rate limits, lockouts |
| Provision spam | Per-customer rate limits + idempotency |
| Node overload | Capacity-aware selection, drain, alerts |
| Control plane flood | Private network only + service auth |

### Elevation of Privilege

| Threat | Mitigation |
|--------|------------|
| Support → SUPER_ADMIN | RBAC tests; no privilege self-escalation |
| Customer accesses admin API | Separate guards / middleware |
| Suspended user still provisions | Status checks on every provision |

## 5. Critical Abuse Cases (must have tests)

1. Expired subscription cannot provision  
2. Suspended customer cannot connect/provision  
3. Revoked device cannot reconnect  
4. Max-device limit enforced transactionally  
5. Unhealthy node cannot receive new sessions  
6. Drain mode blocks new assignments  
7. Duplicate provision with same Idempotency-Key is safe  
8. Payment callback replay cannot double-apply  
9. Users cannot access another user’s devices  
10. Support cannot perform SUPER_ADMIN operations  

## 6. Privacy Threats

| Threat | Mitigation |
|--------|------------|
| Browsing history collection | Not implemented; no tables/APIs for it |
| DNS logging by default | Disabled by design; ops exception requires ADR |
| Session metadata over-retention | Retention policy in OPERATIONS.md (configurable) |
| Secrets in metrics labels | Forbidden; avoid high-cardinality customer IDs in Prometheus labels |

## 7. Residual Risks (accepted for Phase 0)

- No production hardening yet
- Fake node adapter in Phase 2 before real WG
- Kill switch claims deferred until platform tests pass
- MFA for admins deferred to operations hardening phase

## 8. Review Cadence

Revisit this threat model at the start of Phases 2, 3, 6, and 7, and after any security incident.
