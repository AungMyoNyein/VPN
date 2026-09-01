# Android VPN Client (Phase 5)

**Phase:** 5 — Android Native WireGuard VPN Client  
**Platform:** Android only (iOS deferred to Phase 6)

## Architecture

```
Flutter UI (Material 3)
        │ MethodChannel + EventChannel
        ▼
MainActivity (Kotlin)
        │ Intents
        ▼
VpnTunnelService (VpnService + Foreground)
        │ GoBackend
        ▼
WireGuard userspace tunnel (com.wireguard.android:tunnel)
        │
        ▼
Linux network stack → WireGuard Node Agent → WireGuard server
```

Backend path (unchanged):

```
Flutter → Laravel `/api/v1/vpn/*` → Control Plane → Node Agent
```

## Key Components

| Component | Path | Role |
|-----------|------|------|
| `NativeVpnService` | `lib/core/native_vpn_service.dart` | Flutter ↔ Kotlin bridge |
| `VpnManager` | `lib/state/vpn_manager.dart` | Connect flow orchestration |
| `WireguardKeyService` | `lib/core/wireguard_key_service.dart` | Key generation + Keystore |
| `VpnTunnelService` | `android/.../VpnTunnelService.kt` | Authoritative tunnel state |
| `AndroidKeystoreSecureStorage` | `android/.../SecureKeyStorage.kt` | AES-GCM wrapped WG private key |

## Platform Channel Contract

**Method channel:** `com.vpn.mobile/vpn_control`

| Method | Purpose |
|--------|---------|
| `prepareVpn` | Android VPN permission (`VpnService.prepare`) |
| `connect` | Start tunnel with `TunnelConfig` map |
| `disconnect` | Stop tunnel (does **not** revoke peer) |
| `getState` | Current native state string |
| `getStatistics` | RX/TX, handshake, connected-since |
| `generateKeyPair` | WireGuard `KeyPair` via official library |
| `savePrivateKey` / `getPrivateKey` / `deletePrivateKey` | Keystore ops |

**Event channels:**

- `com.vpn.mobile/vpn_state_stream` — state changes
- `com.vpn.mobile/vpn_stats_stream` — statistics updates

## Connection Flow

1. Validate device credential (Phase 2 bearer)
2. Check subscription entitlement (`entitlement_state == ACTIVE`)
3. Ensure WireGuard keypair (native `KeyPair`, private key in Keystore)
4. `POST /api/v1/vpn/provision` with `Idempotency-Key`
5. `prepareVpn()` → system VPN permission
6. Native `connect()` → WireGuard tunnel UP
7. Wait for WireGuard handshake (30s timeout)
8. `CONNECTED` — UI driven by native state stream

**Important:** Provisioning success ≠ connected. Native tunnel + handshake determines `CONNECTED`.

## Key Security

- WireGuard **private key never** sent to Laravel, Control Plane, Node Agent, logs, or analytics
- Device API credential and WireGuard key stored separately
- Private key encrypted at rest via Android Keystore AES-GCM wrapper
- `TunnelConfig.toString()` redacts private key on Kotlin side

## IPv4 Full Tunnel & IPv6 Policy

Phase 5 uses **IPv4-only full tunnel**:

- `allowed_ips`: `0.0.0.0/0` only
- IPv6 routes (`::/0`) stripped in `TunnelConfig.fromMap`
- No IPv6 client address assigned by IPAM

**IPv6 leak policy:** IPv6 is not routed through the tunnel. While connected, Android may still have IPv6 interfaces on the underlying network — validate with the IPv6 leak test in Phase 5 acceptance. Always-on VPN + block-without-VPN (system setting) provides strongest protection.

## DNS

DNS servers come from provisioning response (`config/vpn.php` defaults: `1.1.1.1`, `1.0.0.1`). Applied via WireGuard interface configuration in `VpnTunnelService`.

## Foreground Service

- Channel: `VPN Service` (`vpn_tunnel_status`)
- Persistent notification with **Disconnect** action
- `FOREGROUND_SERVICE_SPECIAL_USE` with VPN subtype declaration

## Network Changes

`ConnectivityManager.NetworkCallback` transitions:

- `CONNECTED` → network lost → `RECONNECTING`
- Network restored → tunnel restart with cached config

## Disconnect vs Revoke

| Action | Peer | IP | Keys |
|--------|------|-----|------|
| **Disconnect** | Kept | Kept | Kept |
| **Revoke** (`/vpn/revoke`) | Removed | Released | May require reprovision |

Normal disconnect does **not** call `/vpn/revoke`.

## Settings

| Setting | Storage |
|---------|---------|
| Auto Connect | `SharedPreferences` + native `auto_connect_enabled` |
| Connect on Launch | `SharedPreferences` |
| Allow Local Network | `SharedPreferences` (future native RFC1918 bypass) |
| Theme | `SharedPreferences` |

## Kill Switch / Always-on VPN

The app does **not** auto-enable system kill switch. Users configure **Always-on VPN** and **Block connections without VPN** in Android system VPN settings. Settings screen provides guidance.

## SDK Levels

See `android/app/build.gradle.kts`:

- `minSdk`, `compileSdk`, `targetSdk` — follow Play Store requirements in repo

## Troubleshooting

### VPN permission denied
Open app → Connect → grant system VPN dialog. If denied, Settings shows guidance.

### Tunnel starts but no handshake
Check server reachability, UDP 51820, MTU (default 1420). Handshake timeout = 30s.

### Handshake works but no Internet
Verify node NAT/forwarding, IP pool, `allowed_ips` on server peer.

### DNS fails
Confirm provisioning DNS values; verify full tunnel (`0.0.0.0/0`).

### Connection fails on cellular / Wi-Fi
Check network transition logs; tunnel should enter `RECONNECTING` and recover.

### Keystore / key missing
Settings → deactivate is separate from key loss. If private key missing, app generates new keypair and reprovisions peer.

### Subscription expired
Connect blocked at authorization step. Active tunnel loses peer when backend revokes.

## Testing

```bash
cd mobile
flutter analyze
flutter test
cd android && ./gradlew test
```

Real-device validation required for Phase 5 COMPLETE status (see `docs/PHASE_5_REPORT.md`).
