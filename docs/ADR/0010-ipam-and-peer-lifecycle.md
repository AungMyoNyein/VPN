# ADR-0010: IPAM Strategy and One Active Peer Invariant

## Status

Accepted (Phase 3)

## Context

VPN peers require guaranteed unique tunnel IPv4 addresses allocated dynamically per VPN node. Race conditions during simultaneous provisioning requests could result in IP collisions. Devices must maintain a clean one-to-one relationship with active VPN sessions in MVP.

## Decision

1. **Transactional IPAM with Row Locking**: Allocation executes inside an atomic DB transaction locking the target `vpn_ip_pools` record (`lockForUpdate()`), calculating available IPs with reserved address exclusion (.0 network, .1 gateway, broadcast), and inserting into `vpn_ip_allocations`.
2. **Partial Unique Indexes**: Concurrency safety is enforced at the database level with partial unique indexes:
   - `vpn_ip_allocations (ip_address) WHERE released_at IS NULL`
   - `vpn_peers (device_id) WHERE status IN ('PENDING', 'ACTIVE', 'REVOKING')`
   - `vpn_peers (public_key) WHERE status IN ('PENDING', 'ACTIVE', 'REVOKING')`
3. **One Active Peer Invariant**: Each device is allowed at most one active peer (`PENDING`, `ACTIVE`, `REVOKING`). Re-provisioning or key rotation revokes previous active peers.
4. **Lifecycle & IP Release**: When a peer is revoked, its IP allocation is marked `RELEASED` with `released_at = now()`, allowing the address to be deterministically recycled for future peers.

## Consequences

- 100% race-safe allocations across concurrent workers without IP collisions.
- Clear audit trail of historical peer identities and IP allocations.
