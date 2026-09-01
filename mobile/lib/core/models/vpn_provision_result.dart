import 'package:vpn_mobile/core/models/vpn_protocol.dart';

class VpnServerInfo {
  const VpnServerInfo({
    required this.id,
    required this.name,
    required this.location,
    required this.endpoint,
    this.host = '',
    this.port = 0,
    this.publicKey = '',
    this.protocol = VpnProtocol.wireguard,
    this.security,
    this.sni,
    this.flow,
    this.fingerprint,
    this.alpn,
  });

  final int id;
  final String name;
  final String location;
  final String endpoint;
  final String host;
  final int port;
  final String publicKey;
  final VpnProtocol protocol;
  final String? security;
  final String? sni;
  final String? flow;
  final String? fingerprint;
  final String? alpn;

  factory VpnServerInfo.fromJson(Map<String, dynamic> json) {
    final endpoint = json['endpoint']?.toString() ?? '';
    var host = json['host']?.toString() ?? '';
    var port = (json['port'] as num?)?.toInt() ?? 0;
    if (host.isEmpty && endpoint.contains(':')) {
      final parts = endpoint.split(':');
      host = parts.first;
      port = int.tryParse(parts.last) ?? port;
    }

    return VpnServerInfo(
      id: (json['id'] as num?)?.toInt() ??
          (json['node_id'] as num?)?.toInt() ??
          0,
      name: json['name']?.toString() ?? '',
      location: json['location']?.toString() ??
          json['location_name']?.toString() ??
          '',
      endpoint: endpoint,
      host: host,
      port: port,
      publicKey: json['public_key']?.toString() ?? '',
      protocol: VpnProtocol.fromString(json['protocol']?.toString()),
      security: json['security']?.toString(),
      sni: json['sni']?.toString(),
      flow: json['flow']?.toString(),
      fingerprint: json['fingerprint']?.toString(),
      alpn: json['alpn']?.toString(),
    );
  }
}

class VlessConfigDetails {
  const VlessConfigDetails({
    required this.uuid,
    this.encryption = 'none',
    this.transport = 'tcp',
    this.security = 'tls',
    this.sni = '',
    this.fingerprint = 'chrome',
    this.flow,
    this.alpn = const ['h2', 'http/1.1'],
  });

  final String uuid;
  final String encryption;
  final String transport;
  final String security;
  final String sni;
  final String fingerprint;
  final String? flow;
  final List<String> alpn;

  factory VlessConfigDetails.fromJson(Map<String, dynamic> json) {
    final alpnRaw = json['alpn'];
    List<String> alpn;
    if (alpnRaw is List) {
      alpn = alpnRaw.map((e) => e.toString()).toList();
    } else if (alpnRaw is String && alpnRaw.isNotEmpty) {
      alpn = alpnRaw.split(',').map((e) => e.trim()).where((e) => e.isNotEmpty).toList();
    } else {
      alpn = const ['h2', 'http/1.1'];
    }

    return VlessConfigDetails(
      uuid: json['uuid']?.toString() ?? '',
      encryption: json['encryption']?.toString() ?? 'none',
      transport: json['transport']?.toString() ?? 'tcp',
      security: json['security']?.toString() ?? 'tls',
      sni: json['sni']?.toString() ?? '',
      fingerprint: json['fingerprint']?.toString() ?? 'chrome',
      flow: json['flow']?.toString(),
      alpn: alpn,
    );
  }

  String get redactedUuid {
    if (uuid.length < 4) return '********';
    return '********-****-****-****-********${uuid.substring(uuid.length - 4)}';
  }
}

class VpnProvisionResult {
  const VpnProvisionResult({
    required this.protocol,
    required this.peerId,
    required this.dns,
    required this.server,
    this.connectionId = '',
    this.address = '',
    this.uuid = '',
    this.shareUrl = '',
    this.vless,
    this.allowedIps = const ['0.0.0.0/0'],
    this.persistentKeepalive = 25,
    this.mtu = 1420,
  });

  final VpnProtocol protocol;
  final String peerId;
  final String connectionId;
  final String address;
  final String uuid;
  final String shareUrl;
  final VlessConfigDetails? vless;
  final List<String> dns;
  final VpnServerInfo server;
  final List<String> allowedIps;
  final int persistentKeepalive;
  final int mtu;

  bool get isWireguard => protocol == VpnProtocol.wireguard;
  bool get isVless => protocol == VpnProtocol.vless;

  factory VpnProvisionResult.fromJson(Map<String, dynamic> json) {
    final protocol = VpnProtocol.fromString(json['protocol']?.toString());
    final dnsList = (json['dns'] as List<dynamic>?)
            ?.map((e) => e.toString())
            .toList() ??
        ['1.1.1.1'];
    final allowedIpsList = (json['allowed_ips'] as List<dynamic>?)
            ?.map((e) => e.toString())
            .toList() ??
        ['0.0.0.0/0'];

    final vlessJson = json['vless'] as Map<String, dynamic>?;
    final vless = vlessJson != null ? VlessConfigDetails.fromJson(vlessJson) : null;
    final uuid = json['uuid']?.toString() ?? vless?.uuid ?? '';

    return VpnProvisionResult(
      protocol: protocol,
      peerId: json['peer_id']?.toString() ?? '',
      connectionId: json['connection_id']?.toString() ??
          json['peer_id']?.toString() ??
          '',
      address: json['address']?.toString() ?? '',
      uuid: uuid,
      shareUrl: json['share_url']?.toString() ?? '',
      vless: vless,
      dns: dnsList,
      server: VpnServerInfo.fromJson(
        (json['server'] as Map<String, dynamic>?) ?? {},
      ),
      allowedIps: allowedIpsList,
      persistentKeepalive:
          (json['persistent_keepalive'] as num?)?.toInt() ?? 25,
      mtu: (json['mtu'] as num?)?.toInt() ?? (protocol == VpnProtocol.vless ? 1400 : 1420),
    );
  }
}
