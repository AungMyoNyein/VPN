import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:vpn_mobile/core/api_client.dart';
import 'package:vpn_mobile/core/models/vpn_location.dart';
import 'package:vpn_mobile/core/models/vpn_provision_result.dart';
import 'package:vpn_mobile/core/wireguard_key_service.dart';

void main() {
  group('WireguardKeyService', () {
    test('generates valid 44-character Base64 WireGuard public key', () async {
      final keyStore = InMemoryWireguardKeyStore();
      final service = WireguardKeyService(keyStore: keyStore);

      final publicKey = await service.getOrCreatePublicKey();
      expect(publicKey.length, 44);
      expect(publicKey.endsWith('='), isTrue);

      final privateKey = await keyStore.getPrivateKey();
      expect(privateKey, isNotNull);
      expect(privateKey, isNot(equals(publicKey)));

      // Calling again returns the same public key
      final publicKey2 = await service.getOrCreatePublicKey();
      expect(publicKey2, equals(publicKey));
    });
  });

  group('VpnLocation JSON model', () {
    test('parses location correctly', () {
      final json = {
        'id': 2,
        'country_code': 'SG',
        'country_name': 'Singapore',
        'city': 'Singapore',
        'display_name': 'Singapore',
        'servers_count': 3,
        'available': true,
      };

      final location = VpnLocation.fromJson(json);
      expect(location.id, 2);
      expect(location.countryCode, 'SG');
      expect(location.displayName, 'Singapore');
      expect(location.serversCount, 3);
      expect(location.available, isTrue);
    });
  });

  group('VpnProvisionResult JSON model', () {
    test('parses provisioning result correctly', () {
      final json = {
        'peer_id': 'WG-PEER-001',
        'address': '10.200.20.2/32',
        'dns': ['1.1.1.1', '1.0.0.1'],
        'server': {
          'id': 1,
          'name': 'Singapore Dev 01',
          'location': 'Singapore',
          'endpoint': 'sg-dev-01.vpn.local:51820',
          'public_key': 'SG0111111111111111111111111111111111111111=',
        },
        'allowed_ips': ['0.0.0.0/0'],
        'persistent_keepalive': 25,
        'mtu': 1420,
      };

      final result = VpnProvisionResult.fromJson(json);
      expect(result.peerId, 'WG-PEER-001');
      expect(result.address, '10.200.20.2/32');
      expect(result.dns, ['1.1.1.1', '1.0.0.1']);
      expect(result.server.name, 'Singapore Dev 01');
      expect(result.server.endpoint, 'sg-dev-01.vpn.local:51820');
      expect(result.persistentKeepalive, 25);
    });
  });

  group('ApiClient VPN endpoints', () {
    test('getLocations fetches active locations', () async {
      final mockClient = MockClient((request) async {
        expect(request.url.path, '/api/v1/vpn/locations');
        expect(request.headers['Authorization'], 'Bearer test-device-token');

        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 1,
                'country_code': 'TH',
                'country_name': 'Thailand',
                'city': 'Bangkok',
                'display_name': 'Bangkok, Thailand',
                'servers_count': 1,
                'available': true,
              },
            ],
            'meta': {'request_id': 'req-123'},
          }),
          200,
        );
      });

      final apiClient = ApiClient(
        baseUrl: 'http://localhost:8000',
        httpClient: mockClient,
      );
      apiClient.bearerToken = 'test-device-token';

      final locations = await apiClient.getLocations();
      expect(locations.length, 1);
      expect(locations.first.countryCode, 'TH');
      expect(locations.first.displayName, 'Bangkok, Thailand');
    });

    test('provisionVpn sends client public key and receives tunnel config', () async {
      final mockClient = MockClient((request) async {
        expect(request.url.path, '/api/v1/vpn/provision');
        expect(request.headers['Authorization'], 'Bearer test-device-token');

        final body = jsonDecode(request.body) as Map<String, dynamic>;
        expect(body['client_public_key'], 'TEST_PUBLIC_KEY_BASE64_44_CHARS========');

        return http.Response(
          jsonEncode({
            'data': {
              'peer_id': 'WG-PEER-001',
              'address': '10.200.20.5/32',
              'dns': ['1.1.1.1'],
              'server': {
                'id': 1,
                'name': 'Singapore 01',
                'location': 'Singapore',
                'endpoint': 'sg-01.vpn.local:51820',
                'public_key': 'SERVER_KEY',
              },
              'allowed_ips': ['0.0.0.0/0'],
              'persistent_keepalive': 25,
              'mtu': 1420,
            },
            'meta': {'request_id': 'req-456'},
          }),
          201,
        );
      });

      final apiClient = ApiClient(
        baseUrl: 'http://localhost:8000',
        httpClient: mockClient,
      );
      apiClient.bearerToken = 'test-device-token';

      final result = await apiClient.provisionVpn(
        clientPublicKey: 'TEST_PUBLIC_KEY_BASE64_44_CHARS========',
      );

      expect(result.peerId, 'WG-PEER-001');
      expect(result.address, '10.200.20.5/32');
      expect(result.server.name, 'Singapore 01');
    });
  });
}
