import 'dart:convert';
import 'dart:io' show Platform;
import 'dart:math';

import 'package:flutter/foundation.dart';
import 'package:vpn_mobile/core/native_vpn_service.dart';

abstract class WireguardKeyStore {
  Future<String?> getPrivateKey();
  Future<void> savePrivateKey(String key);
  Future<void> deletePrivateKey();
}

/// Android Keystore-backed storage via platform channel.
class PlatformWireguardKeyStore implements WireguardKeyStore {
  PlatformWireguardKeyStore({NativeVpnService? nativeVpn})
      : _nativeVpn = nativeVpn ?? NativeVpnService();

  final NativeVpnService _nativeVpn;

  @override
  Future<String?> getPrivateKey() => _nativeVpn.getPrivateKey();

  @override
  Future<void> savePrivateKey(String key) => _nativeVpn.savePrivateKey(key);

  @override
  Future<void> deletePrivateKey() => _nativeVpn.deletePrivateKey();
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
  WireguardKeyService({
    WireguardKeyStore? keyStore,
    NativeVpnService? nativeVpn,
  })  : _nativeVpn = nativeVpn ?? NativeVpnService(),
        _keyStore = keyStore ?? _defaultKeyStore(nativeVpn);

  final NativeVpnService _nativeVpn;
  final WireguardKeyStore _keyStore;

  static WireguardKeyStore _defaultKeyStore(NativeVpnService? nativeVpn) {
    if (!kIsWeb && Platform.isAndroid) {
      return PlatformWireguardKeyStore(nativeVpn: nativeVpn);
    }
    return InMemoryWireguardKeyStore();
  }

  /// Returns the WireGuard public key (44-char base64). Private key never leaves device.
  Future<String> getOrCreatePublicKey() async {
    var privateKey = await _keyStore.getPrivateKey();
    if (privateKey == null || privateKey.isEmpty) {
      if (!kIsWeb && Platform.isAndroid) {
        final pair = await _nativeVpn.generateKeyPair();
        privateKey = pair.privateKey;
        await _keyStore.savePrivateKey(privateKey);
        return pair.publicKey;
      }
      privateKey = _generateTestKeyBase64();
      await _keyStore.savePrivateKey(privateKey);
    }
    return _deriveTestPublicKey(privateKey);
  }

  Future<String?> getPrivateKey() => _keyStore.getPrivateKey();

  Future<void> deleteKeys() => _keyStore.deletePrivateKey();

  /// Test/dev fallback only — Android production uses native KeyPair generation.
  String _generateTestKeyBase64() {
    final random = Random.secure();
    final bytes = List<int>.generate(32, (_) => random.nextInt(256));
    return base64Encode(bytes);
  }

  String _deriveTestPublicKey(String privateKey) {
    final bytes = base64Decode(privateKey);
    final pubBytes = List<int>.generate(32, (i) => (bytes[i] ^ 0x5A) & 0xFF);
    return base64Encode(pubBytes);
  }
}
