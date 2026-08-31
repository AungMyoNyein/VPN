# ADR-0009: VPN Provisioning Control Plane and Fake Node Adapter

## Status

Accepted (Phase 3)

## Context

Phase 3 establishes the VPN control-plane architecture, peer provisioning workflow, idempotency, and reconciliation foundation. Deploying directly to real WireGuard kernel interfaces in dev/test environments carries risk and prevents fast, isolated CI/CD testing.

## Decision

1. **Go Control Plane + Adapter Interface**: The Go control-plane binary wraps node operations behind a narrow `NodeAdapter` interface (`AddPeer`, `RemovePeer`, `GetPeer`, `ListPeers`, `GetNode`, `ListNodes`, `SetDrain`, `SetMaintenance`, `Health`, `InjectFailure`, `ResetFailures`).
2. **Fake Node Adapter First**: In Phase 3, a Go in-memory fake node adapter simulates node mutations and supports controlled failure/timeout injection. No shell commands or `wg`/`ip`/`nft` utilities are executed.
3. **Internal Service API**: Laravel communicates with the Go control plane over authenticated HTTP (`/internal/v1/*`) using bearer service tokens and propagating `request_id`.
4. **Idempotency & Operations**: Provisioning operations are tracked in `provisioning_operations` with mandatory `Idempotency-Key` headers on `POST /api/v1/vpn/provision`.
5. **Phase 4 Transition**: Phase 4 will introduce the real node agent adapter implementing the identical `NodeAdapter` interface without requiring Laravel or mobile changes.

## Consequences

- End-to-end control-plane flows can be thoroughly tested with fault injection in CI.
- Clear decoupling between Laravel business logic and WireGuard system details.
