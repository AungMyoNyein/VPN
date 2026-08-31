# ADR-0004: WireGuard as Phase 1 Protocol

## Status

Accepted

## Context

The product needs a modern, audited VPN protocol with mobile-native integration paths (Android VpnService, iOS NetworkExtension).

## Decision

- Phase 1–5 data plane protocol is **WireGuard**
- Flutter handles UX/API; native layers own the tunnel
- Protocol abstraction in mobile settings may exist later; do not claim multi-protocol support until implemented and tested

## Consequences

- Node image and agent assume WireGuard + nftables
- Config returned to clients is WG-oriented (endpoint, server public key, allowed IPs, DNS, address)
