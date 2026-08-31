# VPN Mobile (Flutter)

Phase 2: activation + device credential auth. Native WireGuard tunnels arrive in Phases 4 (Android) and 5 (iOS).

## Structure

```
lib/
  main.dart
  app.dart
  core/          # API client, secure store, config, models
  state/         # App auth + VPN connection state machine
  features/      # splash, auth, home, locations, account, settings
android/         # Placeholder — VpnService in Phase 4
ios/             # Placeholder — NetworkExtension in Phase 5
test/
```

## Phase 2 behavior

- **Activation:** Customer ID + Activation Key → `POST /api/v1/activate` → secure credential stored locally.
- **Bootstrap:** Splash reads credential → refresh/account → Home, or Activation when missing/revoked.
- **Protected API:** Bearer device credential on `/account`, `/subscription`, `/device`, `/device/refresh`, `/device/deactivate`.
- **Home:** Shows “VPN Access Ready” with plan/expiry; Connect is disabled until a later phase.
- **Settings:** “Deactivate this device” revokes remotely and clears local credential.

## API base URL

Default:

- Android emulator: `http://10.0.2.2:8000`
- Other platforms: `http://127.0.0.1:8000`

Override:

```bash
flutter run --dart-define=API_BASE_URL=http://192.168.1.10:8000
```

## Run

```bash
cd mobile
flutter pub get
flutter analyze
flutter test
flutter run
```

## Connection states (ADR-aligned, Phase 3+)

`DISCONNECTED` → `PREPARING` → `AUTHORIZING` → `PROVISIONING` → `CONNECTING` → `CONNECTED`
Also: `RECONNECTING`, `DISCONNECTING`, `ERROR`

Do not use a single `isConnected` boolean as the source of truth.
