/// Strict VPN connection state machine (Phase 0 definition).
enum VpnConnectionState {
  disconnected,
  preparing,
  authorizing,
  provisioning,
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
}
