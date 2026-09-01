enum VpnProtocol {
  wireguard('wireguard'),
  vless('vless');

  const VpnProtocol(this.value);
  final String value;

  String get label {
    switch (this) {
      case VpnProtocol.vless:
        return 'VLESS';
      case VpnProtocol.wireguard:
        return 'WireGuard';
    }
  }

  static VpnProtocol fromString(String? raw) {
    switch (raw?.toLowerCase()) {
      case 'vless':
        return VpnProtocol.vless;
      case 'wireguard':
      default:
        return VpnProtocol.wireguard;
    }
  }
}
