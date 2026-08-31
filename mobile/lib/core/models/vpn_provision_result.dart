class VpnServerInfo {
  const VpnServerInfo({
    required this.id,
    required this.name,
    required this.location,
    required this.endpoint,
    required this.publicKey,
  });

  final int id;
  final String name;
  final String location;
  final String endpoint;
  final String publicKey;

  factory VpnServerInfo.fromJson(Map<String, dynamic> json) {
    return VpnServerInfo(
      id: (json['id'] as num?)?.toInt() ?? 0,
      name: json['name']?.toString() ?? '',
      location: json['location']?.toString() ?? '',
      endpoint: json['endpoint']?.toString() ?? '',
      publicKey: json['public_key']?.toString() ?? '',
    );
  }
}

class VpnProvisionResult {
  const VpnProvisionResult({
    required this.peerId,
    required this.address,
    required this.dns,
    required this.server,
    required this.allowedIps,
    required this.persistentKeepalive,
    required this.mtu,
  });

  final String peerId;
  final String address;
  final List<String> dns;
  final VpnServerInfo server;
  final List<String> allowedIps;
  final int persistentKeepalive;
  final int mtu;

  factory VpnProvisionResult.fromJson(Map<String, dynamic> json) {
    final dnsList = (json['dns'] as List<dynamic>?)
            ?.map((e) => e.toString())
            .toList() ??
        ['1.1.1.1'];
    final allowedIpsList = (json['allowed_ips'] as List<dynamic>?)
            ?.map((e) => e.toString())
            .toList() ??
        ['0.0.0.0/0'];

    return VpnProvisionResult(
      peerId: json['peer_id']?.toString() ?? '',
      address: json['address']?.toString() ?? '',
      dns: dnsList,
      server: VpnServerInfo.fromJson(
        (json['server'] as Map<String, dynamic>?) ?? {},
      ),
      allowedIps: allowedIpsList,
      persistentKeepalive:
          (json['persistent_keepalive'] as num?)?.toInt() ?? 25,
      mtu: (json['mtu'] as num?)?.toInt() ?? 1420,
    );
  }
}
