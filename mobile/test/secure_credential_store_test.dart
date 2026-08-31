import 'package:flutter_test/flutter_test.dart';
import 'package:vpn_mobile/core/secure_credential_store.dart';

void main() {
  group('SecureCredentialStore contract', () {
    test('in-memory implementation round-trips credential', () async {
      final store = InMemorySecureCredentialStore();

      await store.saveDeviceCredential('device-token-123');
      expect(await store.readDeviceCredential(), 'device-token-123');

      await store.deleteDeviceCredential();
      expect(await store.readDeviceCredential(), isNull);
    });
  });
}
