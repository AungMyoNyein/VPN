import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vpn_mobile/core/models/vpn_protocol.dart';
import 'package:vpn_mobile/core/native_vpn_service.dart';
import 'package:vpn_mobile/core/models/vpn_provision_result.dart';
import 'package:vpn_mobile/state/vpn_connection_state.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('TunnelConnectConfig', () {
    test('maps provision result to native map with IPv4-only allowed IPs', () {
      const result = VpnProvisionResult(
        protocol: VpnProtocol.wireguard,
        peerId: 'WG-PEER-001',
        address: '10.200.20.2/32',
        dns: ['1.1.1.1'],
        server: VpnServerInfo(
          id: 1,
          name: 'Singapore 01',
          location: 'Singapore',
          endpoint: 'sg.vpn.example.com:51820',
          publicKey: 'SERVER_KEY_BASE64_44_CHARS==================',
        ),
        allowedIps: ['0.0.0.0/0', '::/0'],
        persistentKeepalive: 25,
        mtu: 1420,
      );

      final config = TunnelConnectConfig.fromProvisionResult(
        result: result,
        privateKey: 'CLIENT_PRIVATE_KEY_BASE64_44_CHARS===========',
      );

      expect(config.allowedIps, ['0.0.0.0/0']);
      expect(config.toNativeMap()['peerId'], 'WG-PEER-001');
      expect(config.toNativeMap()['locationLabel'], 'Singapore 01');
    });

    test('maps VLESS provision result to native map', () {
      const result = VpnProvisionResult(
        protocol: VpnProtocol.vless,
        peerId: 'VLESS-PEER-001',
        connectionId: 'VLESS-PEER-001',
        uuid: '12345678-1234-1234-1234-123456789abc',
        dns: ['1.1.1.1'],
        mtu: 1400,
        server: VpnServerInfo(
          id: 1,
          name: 'Singapore',
          location: 'Singapore',
          endpoint: 'zentunnel.net:8443',
          host: 'zentunnel.net',
          port: 8443,
          protocol: VpnProtocol.vless,
          security: 'tls',
          sni: 'zentunnel.net',
        ),
        vless: VlessConfigDetails(
          uuid: '12345678-1234-1234-1234-123456789abc',
          sni: 'zentunnel.net',
        ),
      );

      final config = TunnelConnectConfig.fromProvisionResult(
        result: result,
        privateKey: '',
      );

      final map = config.toNativeMap();
      expect(map['protocol'], 'vless');
      expect(map['serverHost'], 'zentunnel.net');
      expect(map['serverPort'], 8443);
      expect(map.containsKey('privateKey'), isFalse);
    });
  });

  group('VpnConnectionStateX', () {
    test('maps native strings including requesting_permission', () {
      expect(
        VpnConnectionStateX.fromNative('requesting_permission'),
        VpnConnectionState.requestingPermission,
      );
      expect(
        VpnConnectionStateX.fromNative('connected'),
        VpnConnectionState.connected,
      );
    });
  });

  group('NativeVpnService', () {
    const channel = MethodChannel('com.vpn.mobile/vpn_control');

    setUp(() {
      TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
          .setMockMethodCallHandler(channel, (call) async {
        switch (call.method) {
          case 'getState':
            return 'disconnected';
          case 'getStatistics':
            return {
              'rxBytes': 100,
              'txBytes': 200,
              'latestHandshakeEpochMs': 0,
              'connectedSinceEpochMs': 0,
            };
          case 'prepareVpn':
            return true;
          default:
            return null;
        }
      });
    });

    tearDown(() {
      TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
          .setMockMethodCallHandler(channel, null);
    });

    test('getState returns mapped enum', () async {
      final service = NativeVpnService(methodChannel: channel);
      final state = await service.getState();
      expect(state, VpnConnectionState.disconnected);
    });
  });
}
