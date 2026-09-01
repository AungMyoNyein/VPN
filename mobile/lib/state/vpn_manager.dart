import 'dart:async';
import 'dart:math';

import 'package:flutter/foundation.dart';
import 'package:vpn_mobile/core/api_client.dart';
import 'package:vpn_mobile/core/api_exception.dart';
import 'package:vpn_mobile/core/models/vpn_location.dart';
import 'package:vpn_mobile/core/models/vpn_protocol.dart';
import 'package:vpn_mobile/core/models/vpn_provision_result.dart';
import 'package:vpn_mobile/core/native_vpn_service.dart';
import 'package:vpn_mobile/core/vpn_preferences.dart';
import 'package:vpn_mobile/core/wireguard_key_service.dart';
import 'package:vpn_mobile/state/vpn_connection_controller.dart';
import 'package:vpn_mobile/state/vpn_connection_state.dart';

/// Orchestrates provisioning, permission, and native tunnel lifecycle.
class VpnManager extends ChangeNotifier {
  VpnManager({
    required ApiClient apiClient,
    required WireguardKeyService keyService,
    NativeVpnService? nativeVpn,
    VpnPreferences? preferences,
  })  : _apiClient = apiClient,
        _keyService = keyService,
        _nativeVpn = nativeVpn ?? NativeVpnService(),
        _preferences = preferences;

  final ApiClient _apiClient;
  final WireguardKeyService _keyService;
  final NativeVpnService _nativeVpn;
  VpnPreferences? _preferences;

  final VpnConnectionController _controller = VpnConnectionController();
  StreamSubscription<VpnConnectionState>? _stateSub;
  StreamSubscription<TunnelStatistics>? _statsSub;

  VpnConnectionState _state = VpnConnectionState.disconnected;
  TunnelStatistics _statistics = const TunnelStatistics();
  String? _errorCode;
  String? _userMessage;
  VpnProvisionResult? _lastProvision;
  VpnLocation? _selectedLocation;
  VpnServerInfo? _recommendedServer;
  String _locationLabel = 'Smart Location';
  int _retryCount = 0;
  bool _initialized = false;

  VpnConnectionState get state => _state;
  TunnelStatistics get statistics => _statistics;
  String? get errorCode => _errorCode;
  String? get userMessage => _userMessage;
  VpnProvisionResult? get lastProvision => _lastProvision;
  VpnLocation? get selectedLocation => _selectedLocation;
  VpnServerInfo? get recommendedServer => _recommendedServer;
  String get locationLabel => _locationLabel;
  VpnPreferences? get preferences => _preferences;
  VpnProtocol get selectedProtocol =>
      VpnProtocol.fromString(_preferences?.selectedProtocol);

  void setProtocol(VpnProtocol protocol) {
    _preferences?.selectedProtocol = protocol.value;
    _loadRecommendedServer().then((_) => notifyListeners());
  }

  bool get isProtected => _state.isConnectedLike;
  bool get isBusy => _state.isBusy;

  Future<void> initialize(VpnPreferences preferences) async {
    if (_initialized) return;
    _preferences = preferences;
    _initialized = true;

    if (!await _nativeVpn.isSupported()) return;

    _stateSub = _nativeVpn.stateStream.listen(_onNativeState);
    _statsSub = _nativeVpn.statisticsStream.listen((stats) {
      _statistics = stats;
      notifyListeners();
    });

    final nativeState = await _nativeVpn.getState();
    _applyNativeState(nativeState, fromNative: true);
    _statistics = await _nativeVpn.getStatistics();

    await _loadRecommendedServer();
    notifyListeners();
  }

  Future<void> disposeManager() async {
    await _stateSub?.cancel();
    await _statsSub?.cancel();
  }

  void selectLocation(VpnLocation? location) {
    _selectedLocation = location;
    _preferences?.smartLocation = location == null;
    _preferences?.selectedLocationId = location?.id;
    _locationLabel = location?.displayName ?? 'Smart Location';
    notifyListeners();
  }

  Future<void> _loadRecommendedServer() async {
    try {
      _recommendedServer =
          await _apiClient.getRecommendedServer(protocol: selectedProtocol);
      if (_preferences?.smartLocation ?? true) {
        _locationLabel =
            _recommendedServer?.name ?? _recommendedServer?.location ?? 'Smart Location';
      }
    } catch (_) {
      // Non-fatal during bootstrap.
    }
  }

  Future<void> refreshLocationsMetadata() => _loadRecommendedServer();

  Future<void> connect() async {
    if (_state.isBusy || _state.isConnectedLike) return;
    if (!await _nativeVpn.isSupported()) {
      _setError('UNSUPPORTED_PLATFORM', 'VPN tunnel is only available on Android.');
      return;
    }

    _errorCode = null;
    _userMessage = null;
    _retryCount = 0;

    try {
      _transition(VpnConnectionState.preparing);

      _transition(VpnConnectionState.authorizing);
      final subscription = await _apiClient.getSubscription();
      if (!subscription.isUsable) {
        throw ApiException(
          code: 'SUBSCRIPTION_EXPIRED',
          message: 'Your subscription has expired.',
        );
      }

      _transition(VpnConnectionState.provisioning);
      final protocol = selectedProtocol;
      final locationId = (_preferences?.smartLocation ?? true)
          ? null
          : _selectedLocation?.id;
      final idempotencyKey = _ensureIdempotencyKey();

      if (protocol == VpnProtocol.vless) {
        final provision = await _apiClient.provisionVpn(
          locationId: locationId,
          protocol: VpnProtocol.vless,
          idempotencyKey: idempotencyKey,
        );
        _lastProvision = provision;
        _locationLabel = provision.server.name.isNotEmpty
            ? provision.server.name
            : provision.server.location;

        _transition(VpnConnectionState.requestingPermission);
        await _nativeVpn.prepareVpn();

        _transition(VpnConnectionState.connecting);
        final config = TunnelConnectConfig.fromProvisionResult(
          result: provision,
          privateKey: '',
          allowLocalNetwork: _preferences?.allowLocalNetwork ?? false,
        );
        await _nativeVpn.connect(config);
        return;
      }

      final publicKey = await _keyService.getOrCreatePublicKey();
      final privateKey = await _keyService.getPrivateKey();
      if (privateKey == null || privateKey.isEmpty) {
        throw const NativeVpnException(
          code: 'KEYSTORE_ERROR',
          message: 'WireGuard private key is missing.',
        );
      }

      final provision = await _apiClient.provisionVpn(
        locationId: locationId,
        clientPublicKey: publicKey,
        protocol: VpnProtocol.wireguard,
        idempotencyKey: idempotencyKey,
      );

      _lastProvision = provision;
      _locationLabel = provision.server.name.isNotEmpty
          ? provision.server.name
          : provision.server.location;

      _transition(VpnConnectionState.requestingPermission);
      await _nativeVpn.prepareVpn();

      _transition(VpnConnectionState.connecting);
      final config = TunnelConnectConfig.fromProvisionResult(
        result: provision,
        privateKey: privateKey,
        allowLocalNetwork: _preferences?.allowLocalNetwork ?? false,
      );
      await _nativeVpn.connect(config);
    } on ApiException catch (e) {
      await _handleConnectFailure(e.code, _friendlyApiMessage(e));
    } on NativeVpnException catch (e) {
      await _handleConnectFailure(e.code, _friendlyNativeMessage(e));
    } catch (e) {
      await _handleConnectFailure('VPN_PROVISIONING_FAILED', 'Connection failed. Please try again.');
    }
  }

  Future<void> disconnect() async {
    if (_state == VpnConnectionState.disconnected ||
        _state == VpnConnectionState.disconnecting) {
      return;
    }
    try {
      _transition(VpnConnectionState.disconnecting);
      await _nativeVpn.disconnect();
    } catch (e) {
      _setError('TUNNEL_STOP_FAILED', 'Failed to disconnect cleanly.');
    }
  }

  String _ensureIdempotencyKey() {
    final prefs = _preferences;
    var key = prefs?.provisionIdempotencyKey;
    if (key == null || key.isEmpty) {
      key = _generateIdempotencyKey();
      if (prefs != null) prefs.provisionIdempotencyKey = key;
    }
    return key;
  }

  String _generateIdempotencyKey() {
    final random = Random.secure();
    final bytes = List<int>.generate(16, (_) => random.nextInt(256));
    return bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
  }

  void _onNativeState(VpnConnectionState nativeState) {
    _applyNativeState(nativeState, fromNative: true);
  }

  void _applyNativeState(VpnConnectionState nativeState, {required bool fromNative}) {
    if (fromNative) {
      if (_state == VpnConnectionState.error &&
          nativeState == VpnConnectionState.disconnected) {
        return;
      }
      try {
        if (_state != nativeState) {
          _controller.transitionTo(nativeState);
          _state = nativeState;
          if (nativeState == VpnConnectionState.connected) {
            _retryCount = 0;
            _errorCode = null;
            _userMessage = null;
          }
          notifyListeners();
        }
      } on StateError {
        _state = nativeState;
        notifyListeners();
      }
      return;
    }
    _transition(nativeState);
  }

  void _transition(VpnConnectionState next) {
    _controller.transitionTo(next);
    _state = next;
    notifyListeners();
  }

  Future<void> _handleConnectFailure(String code, String message) async {
    _errorCode = code;
    _userMessage = message;
    try {
      _transition(VpnConnectionState.error);
    } catch (_) {
      _state = VpnConnectionState.error;
    }
    notifyListeners();

    if (_shouldAutoRetry(code)) {
      _retryCount++;
      final delayMs = min(8000, 1000 * (1 << (_retryCount - 1)));
      await Future<void>.delayed(Duration(milliseconds: delayMs));
      if (_state == VpnConnectionState.error) {
        await connect();
      }
    }
  }

  bool _shouldAutoRetry(String code) {
    if (_retryCount >= 4) return false;
    return code == 'NETWORK_UNAVAILABLE' ||
        code == 'HANDSHAKE_TIMEOUT' ||
        code == 'TUNNEL_START_FAILED' ||
        code == 'VLESS_CONNECTION_TIMEOUT' ||
        code == 'VLESS_SERVER_UNREACHABLE' ||
        code == 'NETWORK_LOST';
  }

  void _setError(String code, String message) {
    _errorCode = code;
    _userMessage = message;
    try {
      _transition(VpnConnectionState.error);
    } catch (_) {
      _state = VpnConnectionState.error;
    }
    notifyListeners();
  }

  String _friendlyApiMessage(ApiException e) {
    switch (e.code) {
      case 'DEVICE_REVOKED':
        return 'This device is no longer authorized. Please activate again.';
      case 'SUBSCRIPTION_EXPIRED':
        return 'Your subscription has expired.';
      case 'NO_VPN_NODE_AVAILABLE':
        return 'No VPN server is currently available.';
      case 'VPN_CAPACITY_EXHAUSTED':
      case 'IP_POOL_EXHAUSTED':
        return 'VPN capacity is full. Try another location.';
      case 'VPN_PROVISIONING_FAILED':
        return 'VPN provisioning failed. Please try again.';
      case 'INVALID_PUBLIC_KEY':
        return 'Device key configuration is invalid.';
      default:
        return e.message.isNotEmpty ? e.message : 'Connection failed.';
    }
  }

  String _friendlyNativeMessage(NativeVpnException e) {
    switch (e.code) {
      case 'PERMISSION_DENIED':
        return 'VPN permission is required to connect.';
      case 'HANDSHAKE_TIMEOUT':
        return 'Could not establish a secure tunnel. Check your network.';
      case 'KEYSTORE_ERROR':
        return 'Secure key storage error. Try resetting VPN keys in Settings.';
      case 'INVALID_CONFIG':
        return 'Invalid VPN configuration received.';
      case 'TUNNEL_START_FAILED':
        return 'Failed to start VPN tunnel.';
      case 'VLESS_TLS_FAILED':
        return 'Secure VLESS connection failed TLS validation.';
      case 'VLESS_CONNECTION_TIMEOUT':
        return 'VLESS tunnel timed out. Check your network.';
      case 'VLESS_CONFIG_INVALID':
        return 'Invalid VLESS configuration received.';
      case 'VLESS_ENGINE_START_FAILED':
        return 'Failed to start VLESS tunnel engine.';
      case 'VLESS_SERVER_UNREACHABLE':
        return 'VLESS server is unreachable.';
      case 'NETWORK_LOST':
        return 'Network connection lost.';
      default:
        return 'VPN connection failed.';
    }
  }

  Future<Map<String, dynamic>> fetchVpnStatus() =>
      _apiClient.getVpnStatus();

  Future<void> handleDeviceRevoked() async {
    await disconnect();
    _setError('DEVICE_REVOKED', 'This device has been revoked.');
  }

  Future<void> handleSubscriptionExpired() async {
    await disconnect();
    _setError('SUBSCRIPTION_EXPIRED', 'Your subscription has expired.');
  }
}
