# ADR-0003: Laravel vs Control Plane Boundaries

## Status

Accepted

## Context

Business systems (CRM, billing, identity) and VPN operational systems (peer apply, IP allocation, heartbeats) have different reliability and security profiles. SSH from the API to nodes during customer requests is unsafe and non-scalable.

## Decision

- **Laravel** is system of record for customers, plans, subscriptions, payments, devices (CRM), locations/node inventory metadata, tickets, RBAC, audit
- **Control plane (Go)** owns peer provisioning/revocation, tunnel IP allocation, node selection runtime, capacity/heartbeat processing, node sync
- Communication: private network + TLS/mTLS + service auth; never public
- Laravel never SSHs to VPN nodes on customer request paths
- Mobile never talks to control plane or node agents directly

Session metadata may be written by Laravel after CP confirmation and/or ingested from CP events; exact sync mechanism is implemented in Phase 2 with tests.

## Consequences

- Clear failure domains (billing up while a node is draining, etc.)
- Requires idempotent internal APIs
- Inventory fields like `management_ip` stay server-side only
