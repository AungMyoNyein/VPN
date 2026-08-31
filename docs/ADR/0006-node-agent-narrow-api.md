# ADR-0006: Node Agent Narrow API (No Remote Shell)

## Status

Accepted

## Context

VPN node agents run with privileged access to WireGuard and host networking. A generic “execute command” or remote shell endpoint would create command-injection and lateral-movement risk if the control plane or agent credentials were compromised.

## Decision

- The node agent exposes **only** narrowly scoped operations, for example:
  - `AddPeer` / `UpdatePeer` / `RemovePeer` / `GetPeer`
  - `GetNodeHealth` / `GetStatistics`
- **Forbidden:** `POST /execute-command`, arbitrary shell, `system()`, or equivalent generic remote execution.
- Authentication: mTLS + node enrollment identity (Phase 2/3).
- Private-network reachability alone is **not** authentication.

## Consequences

- Control plane must express peer desired state through typed APIs.
- New operational capabilities require explicit API design and review, not “just run this script.”
- Reduces blast radius of control-plane compromise relative to full root shell on every node.
