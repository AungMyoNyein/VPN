import 'package:vpn_mobile/state/vpn_connection_state.dart';

/// Validates legal transitions. Native tunnel hooks land in Phase 4/5.
class VpnConnectionController {
  VpnConnectionState _state = VpnConnectionState.disconnected;

  VpnConnectionState get state => _state;

  static const Map<VpnConnectionState, Set<VpnConnectionState>> allowed = {
    VpnConnectionState.disconnected: {
      VpnConnectionState.preparing,
    },
    VpnConnectionState.preparing: {
      VpnConnectionState.authorizing,
      VpnConnectionState.error,
      VpnConnectionState.disconnected,
    },
    VpnConnectionState.authorizing: {
      VpnConnectionState.provisioning,
      VpnConnectionState.error,
      VpnConnectionState.disconnecting,
    },
    VpnConnectionState.provisioning: {
      VpnConnectionState.requestingPermission,
      VpnConnectionState.error,
      VpnConnectionState.disconnecting,
    },
    VpnConnectionState.requestingPermission: {
      VpnConnectionState.connecting,
      VpnConnectionState.error,
      VpnConnectionState.disconnected,
    },
    VpnConnectionState.connecting: {
      VpnConnectionState.connected,
      VpnConnectionState.error,
      VpnConnectionState.disconnecting,
    },
    VpnConnectionState.connected: {
      VpnConnectionState.reconnecting,
      VpnConnectionState.disconnecting,
      VpnConnectionState.error,
    },
    VpnConnectionState.reconnecting: {
      VpnConnectionState.connected,
      VpnConnectionState.error,
      VpnConnectionState.disconnecting,
    },
    VpnConnectionState.disconnecting: {
      VpnConnectionState.disconnected,
      VpnConnectionState.error,
    },
    VpnConnectionState.error: {
      VpnConnectionState.disconnected,
      VpnConnectionState.preparing,
    },
  };

  void transitionTo(VpnConnectionState next) {
    final ok = allowed[_state]?.contains(next) ?? false;
    if (!ok) {
      throw StateError('Illegal transition: $_state → $next');
    }
    _state = next;
  }
}
