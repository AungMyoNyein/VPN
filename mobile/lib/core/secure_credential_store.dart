import 'package:flutter_secure_storage/flutter_secure_storage.dart';

const _credentialKey = 'device_credential';

/// Persists the issued device credential (Keystore / Keychain in production).
abstract class SecureCredentialStore {
  Future<void> saveDeviceCredential(String credential);

  Future<String?> readDeviceCredential();

  Future<void> deleteDeviceCredential();
}

class FlutterSecureCredentialStore implements SecureCredentialStore {
  FlutterSecureCredentialStore({FlutterSecureStorage? storage})
      : _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(encryptedSharedPreferences: true),
            );

  final FlutterSecureStorage _storage;

  @override
  Future<void> saveDeviceCredential(String credential) {
    return _storage.write(key: _credentialKey, value: credential);
  }

  @override
  Future<String?> readDeviceCredential() {
    return _storage.read(key: _credentialKey);
  }

  @override
  Future<void> deleteDeviceCredential() {
    return _storage.delete(key: _credentialKey);
  }
}

/// In-memory store for unit/widget tests.
class InMemorySecureCredentialStore implements SecureCredentialStore {
  String? _credential;

  @override
  Future<void> deleteDeviceCredential() async {
    _credential = null;
  }

  @override
  Future<String?> readDeviceCredential() async {
    return _credential;
  }

  @override
  Future<void> saveDeviceCredential(String credential) async {
    _credential = credential;
  }
}
