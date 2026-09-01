import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:vpn_mobile/core/models/vpn_protocol.dart';
import 'package:vpn_mobile/core/models/vpn_provision_result.dart';
import 'package:vpn_mobile/state/vpn_connection_state.dart';

/// Typed bridge to Android VpnService / WireGuard native layer.
class NativeVpnService {
  NativeVpnService({MethodChannel? methodChannel, EventChannel? stateChannel})
      : _methodChannel =
            methodChannel ?? const MethodChannel('com.vpn.mobile/vpn_control'),
        _stateChannel = stateChannel ??
            const EventChannel('com.vpn.mobile/vpn_state_stream');

  static const _statsChannel = EventChannel('com.vpn.mobile/vpn_stats_stream');

  final MethodChannel _methodChannel;
  final EventChannel _stateChannel;

  Stream<VpnConnectionState>? _stateStream;
  Stream<TunnelStatistics>? _statsStream;

  Stream<VpnConnectionState> get stateStream {
    _stateStream ??= _stateChannel
        .receiveBroadcastStream()
        .map((event) => VpnConnectionStateX.fromNative(event?.toString()));
    return _stateStream!;
  }

  Stream<TunnelStatistics> get statisticsStream {
    _statsStream ??= _statsChannel
        .receiveBroadcastStream()
        .map((event) => TunnelStatistics.fromMap(
              Map<String, dynamic>.from(event as Map),
            ));
    return _statsStream!;
  }

  Future<bool> isSupported() async {
    if (kIsWeb) return false;
    return defaultTargetPlatform == TargetPlatform.android;
  }

  Future<bool> prepareVpn() async {
    try {
      final result = await _methodChannel.invokeMethod<bool>('prepareVpn');
      return result ?? false;
    } on PlatformException catch (e) {
      throw NativeVpnException.fromPlatform(e);
    }
  }

  Future<void> connect(TunnelConnectConfig config) async {
    try {
      await _methodChannel.invokeMethod<void>('connect', config.toNativeMap());
    } on PlatformException catch (e) {
      throw NativeVpnException.fromPlatform(e);
    }
  }

  Future<void> disconnect() async {
    try {
      await _methodChannel.invokeMethod<void>('disconnect');
    } on PlatformException catch (e) {
      throw NativeVpnException.fromPlatform(e);
    }
  }

  Future<VpnConnectionState> getState() async {
    try {
      final raw = await _methodChannel.invokeMethod<String>('getState');
      return VpnConnectionStateX.fromNative(raw);
    } on PlatformException catch (e) {
      throw NativeVpnException.fromPlatform(e);
    }
  }

  Future<TunnelStatistics> getStatistics() async {
    try {
      final raw =
          await _methodChannel.invokeMethod<Map>('getStatistics');
      return TunnelStatistics.fromMap(Map<String, dynamic>.from(raw ?? {}));
    } on PlatformException catch (e) {
      throw NativeVpnException.fromPlatform(e);
    }
  }

  Future<void> savePrivateKey(String privateKey, {String alias = 'client_wg_key'}) async {
    try {
      await _methodChannel.invokeMethod<void>('savePrivateKey', {
        'alias': alias,
        'privateKey': privateKey,
      });
    } on PlatformException catch (e) {
      throw NativeVpnException.fromPlatform(e);
    }
  }

  Future<String?> getPrivateKey({String alias = 'client_wg_key'}) async {
    try {
      return await _methodChannel.invokeMethod<String>('getPrivateKey', {
        'alias': alias,
      });
    } on PlatformException catch (e) {
      throw NativeVpnException.fromPlatform(e);
    }
  }

  Future<void> deletePrivateKey({String alias = 'client_wg_key'}) async {
    try {
      await _methodChannel.invokeMethod<void>('deletePrivateKey', {
        'alias': alias,
      });
    } on PlatformException catch (e) {
      throw NativeVpnException.fromPlatform(e);
    }
  }

  Future<WireGuardKeyPair> generateKeyPair() async {
    try {
      final raw =
          await _methodChannel.invokeMethod<Map>('generateKeyPair');
      final map = Map<String, dynamic>.from(raw ?? {});
      return WireGuardKeyPair(
        privateKey: map['privateKey']?.toString() ?? '',
        publicKey: map['publicKey']?.toString() ?? '',
      );
    } on PlatformException catch (e) {
      throw NativeVpnException.fromPlatform(e);
    }
  }

  Future<void> setPreference(String key, bool value) async {
    try {
      await _methodChannel.invokeMethod<void>('setPreference', {
        'key': key,
        'value': value,
      });
    } on PlatformException catch (e) {
      throw NativeVpnException.fromPlatform(e);
    }
  }

  Future<bool> getPreference(String key, {bool defaultValue = false}) async {
    try {
      final result = await _methodChannel.invokeMethod<bool>('getPreference', {
        'key': key,
        'defaultValue': defaultValue,
      });
      return result ?? defaultValue;
    } on PlatformException catch (e) {
      throw NativeVpnException.fromPlatform(e);
    }
  }
}

class WireGuardKeyPair {
  const WireGuardKeyPair({
    required this.privateKey,
    required this.publicKey,
  });

  final String privateKey;
  final String publicKey;
}

class TunnelConnectConfig {
  const TunnelConnectConfig({
    required this.protocol,
    required this.peerId,
    required this.dnsServers,
    required this.mtu,
    required this.locationLabel,
    this.privateKey = '',
    this.clientAddress = '',
    this.serverPublicKey = '',
    this.serverEndpoint = '',
    this.allowedIps = const ['0.0.0.0/0'],
    this.persistentKeepalive = 25,
    this.allowLocalNetwork = false,
    this.connectionId = '',
    this.serverHost = '',
    this.serverPort = 443,
    this.uuid = '',
    this.encryption = 'none',
    this.transport = 'tcp',
    this.security = 'tls',
    this.sni = '',
    this.fingerprint = 'chrome',
    this.flow,
    this.alpn = const ['h2', 'http/1.1'],
    this.protocolLabel = 'WireGuard',
  });

  final VpnProtocol protocol;
  final String peerId;
  final String connectionId;
  final String privateKey;
  final String clientAddress;
  final String serverPublicKey;
  final String serverEndpoint;
  final List<String> dnsServers;
  final List<String> allowedIps;
  final int persistentKeepalive;
  final int mtu;
  final bool allowLocalNetwork;
  final String locationLabel;
  final String protocolLabel;

  // VLESS fields
  final String serverHost;
  final int serverPort;
  final String uuid;
  final String encryption;
  final String transport;
  final String security;
  final String sni;
  final String fingerprint;
  final String? flow;
  final List<String> alpn;

  factory TunnelConnectConfig.fromProvisionResult({
    required VpnProvisionResult result,
    required String privateKey,
    bool allowLocalNetwork = false,
  }) {
    if (result.isVless) {
      final vless = result.vless;
      final host = result.server.host.isNotEmpty
          ? result.server.host
          : _hostFromEndpoint(result.server.endpoint);
      final port = result.server.port > 0
          ? result.server.port
          : _portFromEndpoint(result.server.endpoint);
      return TunnelConnectConfig(
        protocol: VpnProtocol.vless,
        peerId: result.peerId,
        connectionId: result.connectionId.isNotEmpty
            ? result.connectionId
            : result.peerId,
        dnsServers: result.dns,
        mtu: result.mtu,
        locationLabel: result.server.name.isNotEmpty
            ? result.server.name
            : result.server.location,
        protocolLabel: 'VLESS',
        serverHost: host,
        serverPort: port,
        uuid: result.uuid.isNotEmpty ? result.uuid : (vless?.uuid ?? ''),
        encryption: vless?.encryption ?? 'none',
        transport: vless?.transport ?? 'tcp',
        security: vless?.security ?? result.server.security ?? 'tls',
        sni: vless?.sni.isNotEmpty == true
            ? vless!.sni
            : (result.server.sni ?? host),
        fingerprint: vless?.fingerprint ??
            result.server.fingerprint ??
            'chrome',
        flow: vless?.flow ?? result.server.flow,
        alpn: vless?.alpn ??
            _parseAlpn(result.server.alpn),
      );
    }

    final allowedIps = result.allowedIps
        .where((ip) => !ip.contains(':'))
        .toList();
    return TunnelConnectConfig(
      protocol: VpnProtocol.wireguard,
      peerId: result.peerId,
      privateKey: privateKey,
      clientAddress: result.address,
      serverPublicKey: result.server.publicKey,
      serverEndpoint: result.server.endpoint,
      dnsServers: result.dns,
      allowedIps: allowedIps.isEmpty ? const ['0.0.0.0/0'] : allowedIps,
      persistentKeepalive: result.persistentKeepalive,
      mtu: result.mtu,
      allowLocalNetwork: allowLocalNetwork,
      locationLabel: result.server.name.isNotEmpty
          ? result.server.name
          : result.server.location,
      protocolLabel: 'WireGuard',
    );
  }

  static String _hostFromEndpoint(String endpoint) {
    if (!endpoint.contains(':')) return endpoint;
    return endpoint.substring(0, endpoint.lastIndexOf(':'));
  }

  static int _portFromEndpoint(String endpoint) {
    if (!endpoint.contains(':')) return 443;
    return int.tryParse(endpoint.substring(endpoint.lastIndexOf(':') + 1)) ?? 443;
  }

  static List<String> _parseAlpn(String? raw) {
    if (raw == null || raw.isEmpty) return const ['h2', 'http/1.1'];
    return raw.split(',').map((e) => e.trim()).where((e) => e.isNotEmpty).toList();
  }

  Map<String, dynamic> toNativeMap() {
    final map = <String, dynamic>{
      'protocol': protocol.value,
      'peerId': peerId,
      'dnsServers': dnsServers,
      'mtu': mtu,
      'allowLocalNetwork': allowLocalNetwork,
      'locationLabel': locationLabel,
      'protocolLabel': protocolLabel,
    };

    if (protocol == VpnProtocol.vless) {
      map.addAll({
        'connectionId': connectionId,
        'serverHost': serverHost,
        'serverPort': serverPort,
        'uuid': uuid,
        'encryption': encryption,
        'transport': transport,
        'security': security,
        'sni': sni,
        'fingerprint': fingerprint,
        if (flow != null && flow!.isNotEmpty) 'flow': flow,
        'alpn': alpn,
      });
    } else {
      map.addAll({
        'privateKey': privateKey,
        'clientAddress': clientAddress,
        'serverPublicKey': serverPublicKey,
        'serverEndpoint': serverEndpoint,
        'allowedIps': allowedIps,
        'persistentKeepalive': persistentKeepalive,
      });
    }

    return map;
  }
}

class TunnelStatistics {
  const TunnelStatistics({
    this.rxBytes = 0,
    this.txBytes = 0,
    this.latestHandshakeEpochMs = 0,
    this.connectedSinceEpochMs = 0,
  });

  final int rxBytes;
  final int txBytes;
  final int latestHandshakeEpochMs;
  final int connectedSinceEpochMs;

  factory TunnelStatistics.fromMap(Map<String, dynamic> map) {
    return TunnelStatistics(
      rxBytes: (map['rxBytes'] as num?)?.toInt() ?? 0,
      txBytes: (map['txBytes'] as num?)?.toInt() ?? 0,
      latestHandshakeEpochMs:
          (map['latestHandshakeEpochMs'] as num?)?.toInt() ?? 0,
      connectedSinceEpochMs:
          (map['connectedSinceEpochMs'] as num?)?.toInt() ?? 0,
    );
  }

  Duration get connectedDuration {
    if (connectedSinceEpochMs <= 0) return Duration.zero;
    final now = DateTime.now().millisecondsSinceEpoch;
    return Duration(milliseconds: now - connectedSinceEpochMs);
  }
}

class NativeVpnException implements Exception {
  const NativeVpnException({required this.code, this.message});

  final String code;
  final String? message;

  factory NativeVpnException.fromPlatform(PlatformException e) {
    return NativeVpnException(
      code: e.code.isNotEmpty ? e.code : 'UNKNOWN',
      message: e.message,
    );
  }

  @override
  String toString() => 'NativeVpnException($code${message != null ? ': $message' : ''})';
}
