# Architecture — Commercial VPN Platform

**Phase:** 4 (Real WireGuard Node + Secure Node Agent)  
**Status:** COMPLETE — Real Node Agent, RemoteNodeAdapter, mTLS, and nftables integration active  
**Last updated:** 2026-08-28

## 1. Purpose

This document defines the system architecture for a production-oriented commercial VPN platform. Phase 0 establishes boundaries, components, data ownership, and integration contracts. Business features are implemented in later phases.

Authoritative contracts: this file, [API.md](./API.md), [DATABASE.md](./DATABASE.md), [SECURITY.md](./SECURITY.md), [UI_UX.md](./UI_UX.md). Narrative synthesis: [HLD.md](./HLD.md) (see [ADR-0007](./ADR/0007-documentation-sources-of-truth.md)).

## 2. System Context

```
┌─────────────┐  ┌─────────────┐  ┌──────────────┐
│ Android App │  │  iOS App    │  │ Customer Web │
│  (Flutter)  │  │  (Flutter)  │  │   (future)   │
└──────┬──────┘  └──────┬──────┘  └──────┬───────┘
       │                │                │
       └────────────────┼────────────────┘
                        │ HTTPS / public API (/api/v1)
                        ▼
              ┌─────────────────────┐
              │ Reverse Proxy (TLS) │
              └──────────┬──────────┘
         ┌───────────────┼───────────────┐
         ▼               ▼               ▼
   Laravel API      Admin CRM       Health/Metrics
   (Sanctum)        (same app)      (restricted)
         │
         │ private network + mTLS
         ▼
   Go Control Plane  ←── internal only
         │
         │ mTLS / agent protocol
         ▼
   ┌─────┴──────┐
   VPN Nodes    (WireGuard + node-agent)
   BKK / SIN / TYO (+ future regions)
```

## 3. Component Responsibilities

| Component | Tech | Owns | Does not |
|-----------|------|------|----------|
| Mobile apps | Flutter + native WG | UI, local keys, tunnel UX, state machine | Subscription authority, peer provisioning |
| Laravel API / CRM | PHP 8.3+, PostgreSQL, Redis | Identity, CRM, plans, billing, entitlements, audit | SSH to nodes, WireGuard peer apply |
| Control plane | Go | Peer lifecycle, IP allocation, node selection, sync | Customer passwords, payment capture |
| Node agent | Go | Apply peers locally, heartbeat, metrics scrape hooks | Customer auth, billing |
| VPN data plane | WireGuard + nftables | Encrypted tunnel traffic | Business logic |
| Observability | Prometheus, Grafana, Loki | Metrics, logs, alerts | Business decisions |

**Hard rule:** Laravel MUST NOT SSH to VPN nodes during customer API requests. Node mutation goes Laravel → Control Plane → Node Agent.

### 3.1 Architectural planes

| Plane | Carries / controls | Exposed to customer? |
|-------|--------------------|----------------------|
| **Customer / data plane** | WireGuard tunnel traffic Phone → public endpoint → node → Internet | Yes (encrypted VPN) |
| **Application plane** | Laravel API, CRM, billing, entitlements (HTTPS via WAF/LB) | Public API only |
| **VPN control plane** | Peer lifecycle, IP allocation, node selection, sync (Go) | **No** — internal only |
| **VPN node / data plane host** | WireGuard + nftables + node agent on the VPN server | Public WG UDP only |
| **Management / operations** | CRM/NOC, monitoring, deploy tooling, agent mTLS channel | **No** — never inside customer tunnel |

Expected control flow:

```
Android/iOS
    │
  HTTPS (/api/v1)
    │
Laravel API
    │
  mTLS + service auth
    │
Go Control Plane
    │
authenticated management channel (mTLS)
    │
Node Agent
    │
WireGuard
```

- Mobile apps **cannot** call control plane or node agents.
- Laravel **does not** return node management IPs, SSH credentials, or agent tokens.
- Node management interfaces are **not** public customer APIs.

## 4. Trust Boundaries

Detailed actor/boundary analysis: [THREAT_MODEL.md](./THREAT_MODEL.md) §3.

Summary:

1. Untrusted mobile client  
2. Public API edge (WAF / reverse proxy)  
3. Laravel application  
4. Control plane  
5. Node agent  
6. VPN node (WireGuard host)  
7. PostgreSQL  
8. Redis  
9. Monitoring infrastructure  
10. Administrator / CRM users  

## 5. Identity Separation

These identities are **never** conflated:

```
Customer ID (CUST-…)
        ≠
Activation Key (VPN-…)
        ≠
Device ID / UUID
        ≠
Device Credential
        ≠
VPN Peer ID (WG-PEER-…)
        ≠
WireGuard keypair
        ≠
Administrator Identity
        ≠
VPN Node Identity
```

### 5.1 Activation then device credential

```
First launch: Customer ID + Activation Key
  → validate → register device → issue device credential
  → store in Android Keystore / iOS Keychain

Later launches: read device credential → API validate → home
```

Do not ask for Customer ID + Activation Key on every launch. Do not use the activation key as a WireGuard key or permanent API bearer.

### 5.2 Device-centric VPN keys

```
Customer (CUST-…)
  └── Device (DEV-ANDROID-… / DEV-IOS-…)
        └── VPN Peer (WG-PEER-…)
              ├── client public key (server-side only)
              ├── assigned tunnel IP
              └── node association

Administrator (ADMIN-…)  — separate authN/authZ (CRM RBAC)
VPN Node (e.g. SG-01)    — infrastructure identity, not a customer
```

- One VPN peer identity per device; no shared customer-wide WireGuard private key.
- Private key generated client-side; backend stores **public key only** ([ADR-0002](./ADR/0002-device-centric-vpn-keys.md)).
- A device can be revoked independently of sibling devices and of the customer account.
- Admin compromise must not yield customer WireGuard private keys (they are not stored server-side).

## 6. Request Correlation

Every request carries / propagates a `request_id` (UUID):

```
Mobile → Laravel → Control Plane → Node Agent
```

Returned in API error bodies and written to audit/application logs (never with secrets).

## 7. Public vs Internal APIs

### Public (versioned): `/api/v1/*`

Consumed by mobile apps. Authenticated with **device credentials** issued after Customer ID + Activation Key activation. Rate-limited. Returns entitlements; never returns management IPs, agent credentials, private keys, or full activation keys.

### Internal: Control Plane `/internal/v1/*`

Consumed only by Laravel (and ops tooling on the private network). Service authentication + mTLS. Not exposed on the public reverse proxy.

**Internal callers must authenticate.** Presence on a private IP / VPC is **not** sufficient trust ([SECURITY.md](./SECURITY.md), [API.md](./API.md)).

See [API.md](./API.md) for contracts.

## 8. Data Ownership

| Domain | System of record |
|--------|------------------|
| Customers, plans, subscriptions, payments, tickets, RBAC, audit | Laravel / PostgreSQL |
| Devices (CRM view) + public keys mirrored for entitlements | Laravel / PostgreSQL |
| Peer provisioning state, tunnel IPs, node capacity runtime | Control plane (may sync summaries to Laravel) |
| Node health heartbeats | Control plane ← node agents |
| Session operational metadata | Laravel and/or control plane (see ADR-0003) |

## 9. Smart Server Selection

`GET /api/v1/vpn/recommended-server` (implemented Phase 2+) considers:

- node health and maintenance / drain
- capacity and weight
- subscription entitlements (locations, plan limits)
- geographic preference
- observed latency when available

Not random selection.

## 10. Provisioning Sequence (Phase 2+)

```
Mobile
  │  POST /api/v1/vpn/provision  (+ Idempotency-Key, public key)
  ▼
Laravel — authenticate; validate customer, subscription, device, entitlements
  ▼
Control Plane — node selection; IP allocation; peer create; sync to agent
  ▼
Node Agent — narrow WireGuard apply (no remote shell)
  ▼
WireGuard → success → config metadata back to Mobile
```

| Component | Responsibilities |
|-----------|------------------|
| **Laravel** | Authenticate customer; validate customer/subscription/device state; entitlement & device-limit checks; call CP; return safe config DTO |
| **Control plane** | Node selection; address allocation; peer lifecycle; provisioning/revocation; synchronization |
| **Node agent** | Narrow WG ops (add/update/remove/get peer); node health; peer statistics — **never** generic shell ([ADR-0006](./ADR/0006-node-agent-narrow-api.md)) |

Idempotency-Key required on provision and destructive CP actions. Retrying the same key must not create duplicate peers or duplicate IP allocations.

### 10.1 Subscription authorization (server-side)

Mobile is untrusted. Flutter must **never** be authoritative for premium status, expiry, device limit, or location access.

```
customer ACTIVE?
  → subscription ACTIVE?
    → device ACTIVE?
      → device limit OK?
        → location entitled?
          → provision
```

### 10.2 Peer lifecycle

`NONE → PENDING → ACTIVE → REVOKING → REVOKED` (or `ERROR`). Do not hard-delete peers immediately; retain history for audit and troubleshooting. See [DATABASE.md](./DATABASE.md).

### 10.3 Node lifecycle (selection-facing)

`HEALTHY | DEGRADED | DOWN | DRAINING | MAINTENANCE | RETIRED`

| State | New assignments | Existing sessions | Intent |
|-------|-----------------|-------------------|--------|
| **DRAINING** | Rejected | Remain | Capacity/change window |
| **MAINTENANCE** | Rejected | Ops-defined | Planned work |
| **RETIRED** | Rejected | None (decommissioned) | Permanent removal |

Column mapping: [DATABASE.md](./DATABASE.md) § vpn_nodes.

### 10.4 Mobile connection state machine

Do not model tunnel UX as only `isConnected` true/false:

```
DISCONNECTED → PREPARING → AUTHORIZING → PROVISIONING
  → CONNECTING → CONNECTED
Also: RECONNECTING | DISCONNECTING | ERROR
```

Defined in `mobile/lib/state/vpn_connection_state.dart` (native engines Phases 5–6).

## 11. Deployment Topology (Phase 1+ target)

```
Reverse Proxy
      |
┌─────┴──────────────┐
Laravel API          CRM (React)
      |
PostgreSQL
      |
Redis
      |
Go Control Plane
      |
Private Management Network
      |
VPN1  VPN2  VPN3
```

Containers: API, queue worker, scheduler, CRM assets, control plane, monitoring.  
VPN kernel networking may run on the host (not necessarily containerized).

## 12. Phase Map

| Phase | Focus |
|-------|--------|
| 0 | Architecture, UX, docs, skeletons, Compose, CI |
| 1 | CRM foundation (admins, RBAC, customers, plans, keys, devices, payments, audit) |
| 2 | Activation API + device credentials + limits |
| 3 | Control plane (IPAM, peers, selection, fake adapter) |
| 4 | Real WireGuard nodes + node agent |
| 5 | Android (VpnService + Keystore) |
| 6 | iOS (NetworkExtension + Keychain) |
| 7 | Billing workflows + monitoring/ops |
| 8 | Production hardening + store release |

## 13. Non-Goals (Phase 0)

- No production VPN node configuration
- No real peer provisioning
- No Android/iOS native tunnel engines
- No payment gateway integration
- No store-ready mobile builds

## 14. Related Documents

- [HLD.md](./HLD.md) — optional narrative (ADR-0007)
- [UI_UX.md](./UI_UX.md)
- [SECURITY.md](./SECURITY.md)
- [API.md](./API.md)
- [DATABASE.md](./DATABASE.md)
- [THREAT_MODEL.md](./THREAT_MODEL.md)
- [OPERATIONS.md](./OPERATIONS.md)
- [ADR/](./ADR/) including 0006–0008
