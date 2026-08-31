# Commercial VPN Platform — HLD / LLD

**Phase:** 4 (Real WireGuard Node + Secure Node Agent)  
**Status:** Narrative synthesis — contracts in ARCHITECTURE / API / DATABASE / SECURITY win on conflict ([ADR-0007](./ADR/0007-documentation-sources-of-truth.md))  
**Last updated:** 2026-08-28

This document is optional long-form HLD/LLD narrative. For Phase gates and implementation contracts, prefer:

- [ARCHITECTURE.md](./ARCHITECTURE.md)
- [API.md](./API.md)
- [DATABASE.md](./DATABASE.md)
- [SECURITY.md](./SECURITY.md)


---

## 1. High-Level Architecture

```text
                    ┌──────────────────────┐
                    │      CUSTOMER        │
                    └──────────┬───────────┘
                               │
             ┌─────────────────┼─────────────────┐
             │                                   │
        Android App                          iOS App
      Flutter + Kotlin                    Flutter + Swift
       VpnService                         NetworkExtension
             │                                   │
             └─────────────────┬─────────────────┘
                               │
                            HTTPS
                               │
                       ┌───────▼────────┐
                       │ API / WAF / LB │
                       └───────┬────────┘
                               │
                ┌──────────────▼──────────────┐
                │        Laravel API          │
                │                            │
                │ Auth / CRM / Billing       │
                │ Subscription / Device      │
                │ Support / Administration   │
                └───────┬───────────┬────────┘
                        │           │
                ┌───────▼───┐ ┌────▼────┐
                │PostgreSQL │ │  Redis  │
                └───────────┘ └─────────┘
                        │
                 Internal mTLS
                        │
              ┌─────────▼─────────────┐
              │   VPN CONTROL PLANE   │
              │          Go           │
              └─────────┬─────────────┘
                        │
              Private Management Plane
                        │
         ┌──────────────┼───────────────┐
         │              │               │
 ┌───────▼──────┐ ┌─────▼────────┐ ┌────▼────────┐
 │ Bangkok Node │ │Singapore Node│ │ Tokyo Node  │
 │  WireGuard   │ │  WireGuard   │ │ WireGuard  │
 └──────────────┘ └──────────────┘ └─────────────┘
```

**Hard rule:** Laravel never SSHs to VPN nodes on customer request paths. Mutation path is Laravel → Control Plane → Node Agent.

---

## 2. Plane Separation

There are three architectural planes.

### Customer / Data Plane

Carries actual VPN customer traffic.

```text
Phone
  │
WireGuard
  │
Public Internet
  │
VPN Node
  │
Internet
```

### Control Plane

Controls:

* peer provisioning
* peer revocation
* VPN IP allocation
* server assignment
* node capacity
* node health

```text
Laravel
    │
    │ mTLS
    ▼
Control Plane
    │
    ▼
Node Agent
```

### Management Plane

Used by:

* CRM
* NOC
* monitoring
* deployment tooling

It must not be exposed as part of the customer VPN tunnel.

---

## 3. Trust Boundaries

Treat these as separate security boundaries:

```text
[UNTRUSTED]
Mobile device
      │
      ▼
[PUBLIC EDGE]
WAF / Reverse Proxy
      │
      ▼
[APPLICATION TRUST]
Laravel
      │
      ▼
[CONTROL TRUST]
Control Plane
      │
      ▼
[NETWORK INFRASTRUCTURE]
VPN Node
```

A compromise of the mobile application must not provide credentials for managing VPN infrastructure.

See also [SECURITY.md](./SECURITY.md) and [THREAT_MODEL.md](./THREAT_MODEL.md).

---

## 4. Core Entities

```text
Customer
   │
   ├── Subscription
   │       └── Plan
   │
   ├── Device
   │       └── VPN Peer
   │
   ├── Payment
   │
   ├── VPN Session
   │
   └── Support Ticket


Location
   │
   └── VPN Node
          │
          ├── VPN Peer
          ├── VPN Session
          └── Health Metrics
```

Identity separation (critical):

```text
CUSTOMER IDENTITY
       ≠
DEVICE IDENTITY
       ≠
VPN PEER IDENTITY
       ≠
VPN NODE IDENTITY
       ≠
ADMIN IDENTITY
```

---

## 5. Database Relationships

```text
customers
   │1
   │
   │N
devices
   │1
   │
   │1
vpn_peers


customers
   │1
   │
   │N
subscriptions
   │N
   │
   │1
plans


locations
   │1
   │
   │N
vpn_nodes


devices
   │1
   │
   │N
vpn_sessions
   │N
   │
   │1
vpn_nodes
```

Logical table definitions, constraints, and ERD: [DATABASE.md](./DATABASE.md).

---

## 6. Tunnel IP Management

Do not randomly generate IPs independently on nodes.

Create centrally managed tunnel IP pools.

Example:

```text
VPN Overlay

10.200.0.0/16

Bangkok
10.200.10.0/24

Singapore
10.200.20.0/24

Tokyo
10.200.30.0/24
```

Tables (control-plane / shared operational store):

```text
vpn_ip_pools
-------------
id
node_id
network
prefix_length
gateway
active


vpn_ip_allocations
------------------
id
pool_id
device_id
vpn_peer_id
ip_address
allocated_at
released_at
```

Apply a unique database constraint to active IP allocations.

Provisioning must occur transactionally.

---

## 7. Peer Lifecycle

```text
NONE
 │
 ▼
PENDING
 │
 ▼
ACTIVE
 │
 ├─────────────┐
 ▼             ▼
REVOKING     ERROR
 │
 ▼
REVOKED
```

Do not immediately delete peer records.

Retain operational history while removing the actual peer from the node.

---

## 8. Provisioning Sequence

```text
Mobile
  │
  │ POST /vpn/provision
  │ PublicKey + Node Preference
  ▼
Laravel
  │
  │ validate customer
  │ validate subscription
  │ validate device
  │ validate entitlement
  │
  ▼
Control Plane
  │
  │ select node
  │ allocate VPN IP
  │ create peer transaction
  ▼
Node Agent
  │
  │ configure WireGuard peer
  ▼
WireGuard
  │
  │ success
  ▼
Control Plane
  │
  ▼
Laravel
  │
  ▼
Mobile
```

`Idempotency-Key` is required on provision and destructive control-plane actions. Propagate `X-Request-ID` / `request_id` end-to-end.

---

## 9. Example Provisioning Response

Return only required configuration. Canonical contract: [API.md](./API.md) § VPN provision.

```json
{
  "data": {
    "peer_id": "WG-PEER-001",
    "assigned_ip": "10.200.20.45/32",
    "dns": ["1.1.1.1"],
    "server": {
      "node_code": "SG-01",
      "endpoint": "vpn-sg01.example.com:51820",
      "public_key": "SERVER_PUBLIC_KEY",
      "allowed_ips": ["0.0.0.0/0", "::/0"]
    },
    "persistent_keepalive": 25,
    "mtu": 1420
  },
  "meta": {
    "request_id": "550e8400-e29b-41d4-a716-446655440000"
  }
}
```

Never return:

* server private key
* SSH credentials
* management API key
* management IP unless specifically required by customer traffic design
* client private key (stays on device)

---

## 10. Mobile Architecture

Recommended Flutter structure (see `mobile/`):

```text
mobile/
├── lib/
│   ├── app/
│   ├── core/
│   │   ├── api/
│   │   ├── auth/
│   │   ├── storage/
│   │   ├── errors/
│   │   └── logging/
│   │
│   ├── features/
│   │   ├── authentication/
│   │   ├── home/
│   │   ├── vpn/
│   │   ├── locations/
│   │   ├── subscription/
│   │   ├── devices/
│   │   ├── account/
│   │   └── support/
│   │
│   └── main.dart
│
├── android/
└── ios/
```

Native VPN interface should expose a narrow Flutter bridge.

Conceptual API:

```text
initialize()
connect(configuration)
disconnect()
getState()
getStatistics()
```

Do not expose raw native implementation throughout Flutter business logic.

Phases: Android (4), iOS (5).

---

## 11. Android LLD

```text
Flutter
   │
MethodChannel/EventChannel
   │
Kotlin VPN Bridge
   │
VpnService
   │
WireGuard Engine
   │
TUN
```

The Android VPN service should handle:

* VPN permission
* foreground notification
* tunnel establishment
* network changes
* connection state
* route configuration
* DNS configuration
* reconnect
* shutdown

Android's VPN API provides a virtual network interface through `VpnService`; the app needs the relevant protected service declaration.

---

## 12. iOS LLD

```text
Flutter Host App
       │
       ▼
NETunnelProviderManager
       │
       ▼
Packet Tunnel Extension
       │
NEPacketTunnelProvider
       │
WireGuard Engine
       │
NetworkExtension Packet Flow
```

The host app handles:

* activation (Customer ID + Activation Key)
* device credential storage
* API
* UI
* subscriptions
* server selection

The Packet Tunnel extension handles:

* VPN engine
* tunnel configuration
* routing
* DNS
* tunnel lifecycle

Apple provides `NEPacketTunnelProvider` for custom packet-oriented VPN clients (virtual addresses, DNS, routes, MTU).

Do not place the full CRM API stack inside the extension.

---

## 13. Backend Modules

Use bounded business modules (modular monolith):

```text
Backend
├── Identity
├── Customers
├── Devices
├── Plans
├── Subscriptions
├── Payments
├── Locations
├── VPNNodes
├── VPNProvisioning
├── VPNSessions
├── Support
├── Notifications
├── Admin
└── Audit
```

Start as a modular monolith.

Do not prematurely split Laravel into microservices.

The Go control plane remains a separate service because it has a distinct security and operational boundary (ADR-0003).

---

## 14. Internal Control-Plane API

Example endpoints (full contract: [API.md](./API.md) § Internal):

```text
POST /internal/v1/peers
DELETE /internal/v1/peers/{id}

GET /internal/v1/nodes
GET /internal/v1/nodes/{id}

POST /internal/v1/nodes/{id}/drain
POST /internal/v1/nodes/{id}/maintenance

GET /internal/v1/health
```

Require:

* mTLS
* service identity
* authorization
* request ID
* structured audit logs

---

## 15. Node Agent

Each node agent should expose only the minimum actions required.

```text
Control Plane
      │
      ▼
Node Agent
      │
      ├── Add Peer
      ├── Update Peer
      ├── Remove Peer
      ├── Query Peer
      ├── Health
      └── Statistics
```

Avoid a generic "execute shell command" endpoint.

This dramatically reduces command-injection risk.

---

## 16. Node Selection

Suggested scoring model:

```text
eligible =
    healthy
    AND !maintenance
    AND !draining
    AND subscription_allows_location
    AND capacity_available

score =
    latency_weight
    + capacity_weight
    + geographic_weight
    + admin_weight
```

Never return a DOWN or MAINTENANCE node simply because it has the lowest latency.

Public surface: `GET /api/v1/vpn/recommended-server` (Phase 2+).

---

## 17. Subscription Authorization

Connection authorization must occur server-side.

```text
Customer
    │
    ▼
Subscription ACTIVE?
    │
    ├── NO → Reject
    │
    ▼
Device ACTIVE?
    │
    ├── NO → Reject
    │
    ▼
Device limit OK?
    │
    ├── NO → Reject
    │
    ▼
Location allowed?
    │
    ├── NO → Reject
    │
    ▼
Provision
```

Do not rely on a `premium=true` flag stored locally in Flutter.

---

## 18. VPN Node Network Design

Example:

```text
                   Internet
                      │
                Public Address
                      │
                ┌─────▼──────┐
                │ VPN Server │
                │ WireGuard  │
                └─────┬──────┘
                      │
                 wg0 interface
                      │
                  nftables
                      │
                   SNAT
                      │
                  Internet
```

Management access:

```text
Control Plane
      │
Private VPN / Management VLAN
      │
VPN Node Agent
```

Keep customer traffic and management traffic logically separated.

---

## 19. Observability Architecture

```text
Laravel ───────────┐
Control Plane ─────┤
VPN Nodes ─────────┤
PostgreSQL ────────┤
Redis ─────────────┤
                   ▼
               Prometheus
                   │
                   ▼
                Grafana


Application Logs
       │
       ▼
      Loki
       │
       ▼
    Grafana
```

Local Compose profile and runbooks: [OPERATIONS.md](./OPERATIONS.md).

---

## 20. Recommended Initial Infrastructure

### Development / testing

```text
VM-01
CRM/API
PostgreSQL
Redis
Control Plane
Grafana
Prometheus
Loki

VM-02
VPN Bangkok Test Node

VM-03
VPN Singapore Test Node
```

Local equivalent: Docker Compose under `infrastructure/docker/` (API, DB, Redis, control plane, optional agents + monitoring).

### Production

Separate database and control responsibilities progressively as traffic increases. Do not over-split early.

---

## 21. Scaling Model

Start:

```text
1 API
1 DB
1 Redis
1 Control Plane
3 VPN Nodes
```

Then:

```text
               Load Balancer
                  │      │
              API-01   API-02
                  │      │
                  └──┬───┘
                     │
                PostgreSQL
                     │
                   Redis
                     │
            ┌────────┴────────┐
        Control-01        Control-02
                     │
              VPN Node Fleet
```

Do not introduce Kubernetes merely because the project is commercial.

Use it when operational scale justifies orchestration complexity.

---

## 22. Recommended MVP

The first sellable MVP should include only:

* Android application
* iOS application
* WireGuard
* Customer ID + Activation Key activation
* server selection
* connect/disconnect
* automatic recommended server
* subscription
* device management
* three locations
* admin CRM
* node management
* payment abstraction
* basic support tickets
* metrics
* audit logging

Defer:

* complicated referral system
* gaming acceleration
* dedicated IP
* multi-hop
* ad blocker
* custom DNS filtering
* browser extension
* desktop apps
* sophisticated anti-censorship transports

Get the core tunnel, CRM, billing, security, and operations correct first.

---

## 23. MVP Success Criteria

Phase 1 production candidate (end of phases through store readiness) is successful when:

* Android reliably connects/disconnects
* iOS reliably connects/disconnects
* subscription enforcement works
* per-device revocation works
* unhealthy servers are excluded
* node drain works
* customer private keys are not exposed to backend unnecessarily
* payment processing is idempotent
* access control tests pass
* IPv4/DNS leak testing passes
* IPv6 behavior is explicitly defined and tested
* monitoring alerts work
* database backup restoration is tested
* VPN node replacement procedure is documented
* App Store / Play Store release requirements are satisfied

---

## 24. Critical Design Principle

The platform must always preserve this separation:

```text
CUSTOMER IDENTITY
       ≠
DEVICE IDENTITY
       ≠
VPN PEER IDENTITY
       ≠
VPN NODE IDENTITY
       ≠
ADMIN IDENTITY
```

This is fundamental to revocation, auditing, scalability, security, and troubleshooting.

---

## Related Documents

| Doc | Role |
|-----|------|
| [ARCHITECTURE.md](./ARCHITECTURE.md) | Compact system context and phase map |
| [API.md](./API.md) | Public and internal API contracts |
| [DATABASE.md](./DATABASE.md) | Logical ERD and integrity rules |
| [SECURITY.md](./SECURITY.md) | Security architecture |
| [THREAT_MODEL.md](./THREAT_MODEL.md) | STRIDE threat model |
| [OPERATIONS.md](./OPERATIONS.md) | Environments, monitoring, runbooks |
| [ADR/](./ADR/) | Architecture decision records |
