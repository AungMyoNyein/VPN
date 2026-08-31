# ADR-0002: Device-Centric VPN Keys

## Status

Accepted

## Context

Customers may own multiple devices. Compromising or losing one device must not require rotating credentials for all devices. WireGuard authenticates peers by public key.

## Decision

- One WireGuard keypair per physical device
- Generate private key on the client; transmit only the public key
- Backend stores public key, peer ID, tunnel IP, node association, timestamps
- Do not store client private keys unless a future ADR justifies an exception
- Revoke by device/peer without affecting sibling devices

## Consequences

- Provisioning API must accept client public keys and validate uniqueness
- Device limit enforcement is separate from key material
- Key backup/migration UX is client responsibility (re-register generates new peer)
