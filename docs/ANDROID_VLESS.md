# Android VLESS Tunnel (Phase 5.5)

Native VLESS connectivity uses **sing-box libbox** embedded in the Kotlin `VpnTunnelService`.

## Architecture

```
VpnTunnelService (single VpnService + state machine)
        |
        +---- WireGuardEngine (com.wireguard.android:tunnel)
        |
        +---- VlessEngine (io.nekohasekai.libbox / sing-box v1.11.4)
```

Protocol selection (`Settings → Protocol`) chooses the engine at connect time. Changing protocol while connected requires disconnect first.

## libbox dependency

| Item | Value |
|------|-------|
| Upstream | [SagerNet/sing-box](https://github.com/SagerNet/sing-box) |
| Pinned version | **v1.11.4** (tag) |
| Output | `mobile/android/app/libs/libbox.aar` (~39 MB) |
| Java package | `io.nekohasekai.libbox` |
| License | GPL-3.0 — review before commercial distribution |

### Build / update procedure

Requirements: Go 1.23+, Android SDK + NDK, JDK 17 (`javac` in `PATH`).

```bash
export ANDROID_HOME=~/Android/Sdk
export ANDROID_NDK_HOME=$ANDROID_HOME/ndk/<version>
export JAVA_HOME=/path/to/jdk-17
export PATH="$JAVA_HOME/bin:$PATH"
./scripts/build-libbox-android.sh
```

To upgrade: set `SING_BOX_VERSION=vX.Y.Z`, rebuild, run Android + device regression tests, update `VlessEngine.ENGINE_VERSION` and this document.

### Architectures

`libbox.aar` ships `armeabi-v7a`, `arm64-v8a`, `x86`, `x86_64`.

## VLESS readiness (CONNECTED)

`CONNECTED` is set only when:

1. `Libbox.newService()` + `BoxService.start()` succeed
2. `VpnService.Builder.establish()` returns a TUN fd (IPv4-only, no `::/0`)
3. TUN interface TX bytes increase within 30s (traffic entering the stack)

## Socket protection

`SingBoxPlatformInterface.autoDetectInterfaceControl()` calls `VpnService.protect(fd)` on outbound sockets to prevent routing loops.

## TLS

TLS verification is **enabled** (`insecure: false`). Failures surface as `VLESS_TLS_FAILED`.

## IPv6

IPv4-first: sing-box inbound uses `inet4_address` only; Android routes `0.0.0.0/0` without advertising IPv6 default route.

## Configuration source

Structured API fields from `POST /api/v1/vpn/provision` (`vless`, `server.host`, `server.port`). `share_url` is diagnostic-only (redacted in production UI).
