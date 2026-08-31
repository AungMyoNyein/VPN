import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:vpn_mobile/core/api_client.dart';
import 'package:vpn_mobile/core/device_fingerprint.dart';
import 'package:vpn_mobile/core/secure_credential_store.dart';
import 'package:vpn_mobile/state/app_auth_state.dart';

void main() {
  group('InMemorySecureCredentialStore', () {
    late InMemorySecureCredentialStore store;

    setUp(() {
      store = InMemorySecureCredentialStore();
    });

    test('returns null before save', () async {
      expect(await store.readDeviceCredential(), isNull);
    });

    test('persists and reads credential', () async {
      await store.saveDeviceCredential('secret-token');
      expect(await store.readDeviceCredential(), 'secret-token');
    });

    test('delete clears stored credential', () async {
      await store.saveDeviceCredential('secret-token');
      await store.deleteDeviceCredential();
      expect(await store.readDeviceCredential(), isNull);
    });
  });

  group('AppAuthState bootstrap', () {
    late InMemorySecureCredentialStore store;

    setUp(() {
      store = InMemorySecureCredentialStore();
    });

    test('without credential needs activation', () async {
      final auth = AppAuthState(
        apiClient: ApiClient(httpClient: MockClient((_) async {
          throw StateError('No HTTP calls expected');
        })),
        credentialStore: store,
      );

      await auth.bootstrap();

      expect(auth.status, AppAuthStatus.needsActivation);
      expect(auth.account, isNull);
    });

    test('with valid credential becomes ready', () async {
      await store.saveDeviceCredential('stored-token');

      final client = MockClient((request) async {
        if (request.url.path.endsWith('/device/refresh')) {
          return http.Response(
            jsonEncode({
              'data': {'device_credential': 'rotated-token'},
              'meta': {},
            }),
            200,
            headers: {'content-type': 'application/json'},
          );
        }
        if (request.url.path.endsWith('/account')) {
          return http.Response(
            jsonEncode({
              'data': {
                'customer_id': 'CUST-000125',
                'plan_name': 'Premium',
                'status': 'ACTIVE',
                'expires_at': '2026-09-25T00:00:00Z',
                'devices_used': 1,
                'devices_limit': 2,
              },
              'meta': {},
            }),
            200,
            headers: {'content-type': 'application/json'},
          );
        }
        if (request.url.path.endsWith('/subscription')) {
          return http.Response(
            jsonEncode({
              'data': {
                'plan_name': 'Premium',
                'status': 'ACTIVE',
                'expires_at': '2026-09-25T00:00:00Z',
              },
              'meta': {},
            }),
            200,
            headers: {'content-type': 'application/json'},
          );
        }
        return http.Response('not found', 404);
      });

      final auth = AppAuthState(
        apiClient: ApiClient(
          baseUrl: 'http://127.0.0.1:8000',
          httpClient: client,
          deviceFingerprint: DeviceFingerprint(uuid: 'test-uuid'),
        ),
        credentialStore: store,
      );

      await auth.bootstrap();

      expect(auth.status, AppAuthStatus.ready);
      expect(auth.account?.customerId, 'CUST-000125');
      expect(await store.readDeviceCredential(), 'rotated-token');
    });

    test('revoked credential clears store and needs activation', () async {
      await store.saveDeviceCredential('revoked-token');

      final client = MockClient((request) async {
        return http.Response(
          jsonEncode({
            'error': {
              'code': 'DEVICE_CREDENTIAL_REVOKED',
              'message': 'Credential revoked',
              'request_id': 'req-1',
            },
          }),
          401,
          headers: {'content-type': 'application/json'},
        );
      });

      final auth = AppAuthState(
        apiClient: ApiClient(
          baseUrl: 'http://127.0.0.1:8000',
          httpClient: client,
        ),
        credentialStore: store,
      );

      await auth.bootstrap();

      expect(auth.status, AppAuthStatus.needsActivation);
      expect(await store.readDeviceCredential(), isNull);
      expect(auth.errorMessage, 'This device is no longer authorized.');
    });
  });
}
