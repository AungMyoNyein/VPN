import 'package:shared_preferences/shared_preferences.dart';

/// User VPN preferences persisted locally (non-secret).
class VpnPreferences {
  VpnPreferences(this._prefs);

  static const _keyAutoConnect = 'auto_connect';
  static const _keyConnectOnLaunch = 'connect_on_launch';
  static const _keyAllowLocalNetwork = 'allow_local_network';
  static const _keyThemeMode = 'theme_mode';
  static const _keySelectedLocationId = 'selected_location_id';
  static const _keySmartLocation = 'smart_location';
  static const _keySelectedProtocol = 'selected_protocol';
  static const _keyIdempotencyKey = 'provision_idempotency_key';

  final SharedPreferences _prefs;

  static Future<VpnPreferences> load() async {
    final prefs = await SharedPreferences.getInstance();
    return VpnPreferences(prefs);
  }

  bool get autoConnect => _prefs.getBool(_keyAutoConnect) ?? false;
  set autoConnect(bool value) => _prefs.setBool(_keyAutoConnect, value);

  bool get connectOnLaunch => _prefs.getBool(_keyConnectOnLaunch) ?? false;
  set connectOnLaunch(bool value) => _prefs.setBool(_keyConnectOnLaunch, value);

  bool get allowLocalNetwork => _prefs.getBool(_keyAllowLocalNetwork) ?? false;
  set allowLocalNetwork(bool value) =>
      _prefs.setBool(_keyAllowLocalNetwork, value);

  String get themeMode => _prefs.getString(_keyThemeMode) ?? 'system';
  set themeMode(String value) => _prefs.setString(_keyThemeMode, value);

  int? get selectedLocationId => _prefs.getInt(_keySelectedLocationId);
  set selectedLocationId(int? value) {
    if (value == null) {
      _prefs.remove(_keySelectedLocationId);
    } else {
      _prefs.setInt(_keySelectedLocationId, value);
    }
  }

  bool get smartLocation => _prefs.getBool(_keySmartLocation) ?? true;
  set smartLocation(bool value) => _prefs.setBool(_keySmartLocation, value);

  String get selectedProtocol =>
      _prefs.getString(_keySelectedProtocol) ?? 'wireguard';
  set selectedProtocol(String value) =>
      _prefs.setString(_keySelectedProtocol, value);

  String? get provisionIdempotencyKey =>
      _prefs.getString(_keyIdempotencyKey);
  set provisionIdempotencyKey(String? value) {
    if (value == null || value.isEmpty) {
      _prefs.remove(_keyIdempotencyKey);
    } else {
      _prefs.setString(_keyIdempotencyKey, value);
    }
  }
}
