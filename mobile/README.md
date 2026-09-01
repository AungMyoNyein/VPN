# VPN Mobile (Flutter)

Phase 5: Android native WireGuard VPN tunnel. iOS NetworkExtension arrives in Phase 6.

## Structure

```
lib/
  main.dart
  app.dart
  core/          # API client, secure store, native VPN bridge, keys
  state/         # App auth + VpnManager connection orchestration
  features/      # splash, auth, home, locations, account, settings, diagnostics
  widgets/       # connection control, timer
android/         # VpnService + WireGuard GoBackend + platform channels
ios/             # Placeholder — Phase 6
test/
```

## Phase 5 behavior

- **Activation:** Customer ID + Activation Key → secure device credential (Phase 2)
- **Connect:** Provisions peer via API → Android VPN permission → WireGuard tunnel
- **Disconnect:** Stops tunnel only — peer remains provisioned for reconnect
- **Locations:** Smart Location or manual country selection
- **Keys:** WireGuard private key in Android Keystore; never sent to backend
- **State:** Native `VpnTunnelService` is authoritative; Flutter observes via EventChannel

## API base URL

Default:

- Android emulator: `http://10.0.2.2:8000`
- Other platforms: `http://127.0.0.1:8000`

Override:

```bash
flutter run --dart-define=API_BASE_URL=http://192.168.1.10:8000
```

## Run (Android)

```bash
cd mobile
flutter pub get
flutter analyze
flutter test
flutter run
```

Requires a real Android device or emulator with VPN capability for tunnel validation.

## Connection states

`DISCONNECTED` → `PREPARING` → `AUTHORIZING` → `PROVISIONING` → `REQUESTING_PERMISSION` → `CONNECTING` → `CONNECTED`

Also: `RECONNECTING`, `DISCONNECTING`, `ERROR`

See [docs/ANDROID_VPN.md](../docs/ANDROID_VPN.md) for native architecture details.
