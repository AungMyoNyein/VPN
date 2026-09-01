/// Strict VPN connection state machine (Phase 0 definition).
enum VpnConnectionState {
  disconnected,
  preparing,
  authorizing,
  provisioning,
  requestingPermission,
  connecting,
  connected,
  reconnecting,
  disconnecting,
  error,
}

extension VpnConnectionStateX on VpnConnectionState {
  bool get isTerminal =>
      this == VpnConnectionState.disconnected ||
      this == VpnConnectionState.error;

  bool get isConnectedLike =>
      this == VpnConnectionState.connected ||
      this == VpnConnectionState.reconnecting;

  bool get isBusy =>
      this == VpnConnectionState.preparing ||
      this == VpnConnectionState.authorizing ||
      this == VpnConnectionState.provisioning ||
      this == VpnConnectionState.requestingPermission ||
      this == VpnConnectionState.connecting ||
      this == VpnConnectionState.disconnecting;

  /// Maps native Android tunnel state strings to Flutter enum.
  static VpnConnectionState fromNative(String? raw) {
    switch (raw?.toLowerCase()) {
      case 'disconnected':
        return VpnConnectionState.disconnected;
      case 'preparing':
        return VpnConnectionState.preparing;
      case 'authorizing':
        return VpnConnectionState.authorizing;
      case 'provisioning':
        return VpnConnectionState.provisioning;
      case 'requesting_permission':
        return VpnConnectionState.requestingPermission;
      case 'connecting':
        return VpnConnectionState.connecting;
      case 'connected':
        return VpnConnectionState.connected;
      case 'reconnecting':
        return VpnConnectionState.reconnecting;
      case 'disconnecting':
        return VpnConnectionState.disconnecting;
      case 'error':
        return VpnConnectionState.error;
      default:
        return VpnConnectionState.disconnected;
    }
  }
}
