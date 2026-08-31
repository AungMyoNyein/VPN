# ADR 0011: Real WireGuard Node Integration & Node Agent Architecture

## Status
Accepted (Phase 4)

## Context
Phase 3 established the control plane and IPAM foundations using an in-memory `FakeNodeAdapter`. Phase 4 introduces real WireGuard node integration without violating security boundaries or using insecure SSH commands from the backend.

## Decision

1. **RemoteNodeAdapter & Dual Adapter Strategy**:
   - Keep `FakeNodeAdapter` for CI, unit testing, and isolated development environments.
   - Introduce `RemoteNodeAdapter` communicating over HTTPS/mTLS with a dedicated Go `Node Agent` running natively on the VPN node host.
   - Route node operations via `MultiNodeAdapter` based on per-node `adapter_type` (`fake` | `remote`).

2. **Narrow Node Agent Boundary**:
   - The Node Agent exposes ONLY narrow peer and interface operations: `AddPeer`, `UpdatePeer`, `RemovePeer`, `GetPeer`, `ListPeers`, `Device` (stats/health).
   - Arbitrary command execution (`exec`, `bash`, `sh -c`) is strictly prohibited and not implemented.

3. **WireGuard Kernel Operations via `wgctrl`**:
   - The Go Node Agent utilizes `golang.zx2c4.com/wireguard/wgctrl` to directly communicate with the Linux WireGuard kernel device.

4. **Defense-in-Depth IPAM Validation**:
   - The Node Agent validates that any requested peer IP strictly belongs to the node's configured authorized IP pool before configuring the WireGuard interface.

5. **Firewall & Scoped NAT via nftables**:
   - A dedicated table `inet vpn_platform` isolates management traffic, implements client-to-client isolation, and provides SNAT/masquerade for customer egress traffic without interfering with host firewall rules.

## Consequences
- The control plane and Laravel backend never handle server private keys or issue shell commands over SSH.
- Desired peer state resides authoritatively in PostgreSQL / Control Plane; the Linux WireGuard runtime is applied state kept in sync via automated reconciliation.
