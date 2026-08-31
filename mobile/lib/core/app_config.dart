import 'dart:io' show Platform;

import 'package:flutter/foundation.dart';

/// API base URL configuration.
///
/// Override with `--dart-define=API_BASE_URL=http://host:port`.
/// Defaults to Android emulator loopback (`10.0.2.2`) on Android, otherwise
/// `127.0.0.1`.
class AppConfig {
  AppConfig._();

  static const String apiPrefix = '/api/v1';
  static const String appVersion = '0.1.0';

  static const String _envBaseUrl = String.fromEnvironment('API_BASE_URL');

  static String get apiBaseUrl {
    if (_envBaseUrl.isNotEmpty) {
      return _envBaseUrl;
    }
    if (!kIsWeb && Platform.isAndroid) {
      return 'http://10.0.2.2:8000';
    }
    return 'http://127.0.0.1:8000';
  }
}
