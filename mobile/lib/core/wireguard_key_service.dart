import 'dart:convert';
import 'dart:math';
import 'package:vpn_mobile/core/secure_credential_store.dart';

abstract class WireguardKeyStore {
  Future<String?> getPrivateKey();
  Future<void> savePrivateKey(String key);
  Future<void> deletePrivateKey();
}

class SecureWireguardKeyStore implements WireguardKeyStore {
  SecureWireguardKeyStore({SecureCredentialStore? credentialStore})
      : _credentialStore = credentialStore ?? FlutterSecureCredentialStore();

  final SecureCredentialStore _credentialStore;

  @override
  Future<String?> getPrivateKey() async {
    return _credentialStore.readDeviceCredential();
  }

  @override
  Future<void> savePrivateKey(String key) async {
    await _credentialStore.saveDeviceCredential(key);
  }

  @override
  Future<void> deletePrivateKey() async {
    await _credentialStore.deleteDeviceCredential();
  }
}

class InMemoryWireguardKeyStore implements WireguardKeyStore {
  String? _privateKey;

  @override
  Future<String?> getPrivateKey() async => _privateKey;

  @override
  Future<void> savePrivateKey(String key) async => _privateKey = key;

  @override
  Future<void> deletePrivateKey() async => _privateKey = null;
}

class WireguardKeyService {
  WireguardKeyService({WireguardKeyStore? keyStore})
      : _keyStore = keyStore ?? InMemoryWireguardKeyStore();

  final WireguardKeyStore _keyStore;

  /// Get or generate a 32-byte WireGuard public key (Base64 encoded, 44 chars).
  /// Note: The private key is NEVER sent to the backend.
  Future<String> getOrCreatePublicKey() async {
    var privateKey = await _keyStore.getPrivateKey();
    if (privateKey == null || privateKey.isEmpty) {
      privateKey = _generateRandomKeyBase64();
      await _keyStore.savePrivateKey(privateKey);
    }

    // Derive public key representation (for Phase 3 fake adapter, simulated 32-byte key)
    return _derivePublicKey(privateKey);
  }

  String _generateRandomKeyBase64() {
    final random = Random.secure();
    final bytes = List<int>.generate(32, (_) => random.nextInt(256));
    return base64Encode(bytes);
  }

  String _derivePublicKey(String privateKey) {
    final bytes = base64Decode(privateKey);
    final pubBytes = List<int>.generate(32, (i) => (bytes[i] ^ 0x5A) & 0xFF);
    return base64Encode(pubBytes);
  }
}
