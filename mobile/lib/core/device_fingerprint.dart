import 'dart:io' show Platform;
import 'dart:math';

import 'package:flutter/foundation.dart';
import 'package:vpn_mobile/core/app_config.dart';
import 'package:vpn_mobile/core/models/device_registration.dart';

/// Stable device identity for activation requests.
class DeviceFingerprint {
  DeviceFingerprint({
    String? uuid,
    String? platform,
    String? name,
    String? osVersion,
    String? appVersion,
  })  : uuid = uuid ?? _randomUuid(),
        platform = platform ?? _defaultPlatform(),
        name = name ?? _defaultName(),
        osVersion = osVersion ?? _defaultOsVersion(),
        appVersion = appVersion ?? AppConfig.appVersion;

  final String uuid;
  final String platform;
  final String name;
  final String osVersion;
  final String appVersion;

  DeviceRegistration toRegistration() => DeviceRegistration(
        uuid: uuid,
        platform: platform,
        name: name,
        osVersion: osVersion,
        appVersion: appVersion,
      );

  /// RFC 4122-ish v4 UUID for activation device_uuid validation.
  static String _randomUuid() {
    final r = Random.secure();
    String hex(int bytes) => List.generate(
          bytes,
          (_) => r.nextInt(256).toRadixString(16).padLeft(2, '0'),
        ).join();
    final a = hex(4);
    final b = hex(2);
    final c = '4${hex(2).substring(1)}';
    final d = '${(8 + r.nextInt(4)).toRadixString(16)}${hex(2).substring(1)}';
    final e = hex(6);
    return '$a-$b-$c-$d-$e';
  }

  static String _defaultPlatform() {
    if (kIsWeb) return 'ANDROID'; // API only accepts ANDROID|IOS
    if (Platform.isAndroid) return 'ANDROID';
    if (Platform.isIOS) return 'IOS';
    return 'ANDROID';
  }

  static String _defaultName() {
    if (kIsWeb) return 'Web Client';
    if (Platform.isAndroid) return 'Android Device';
    if (Platform.isIOS) return 'iOS Device';
    return 'Unknown Device';
  }

  static String _defaultOsVersion() {
    if (kIsWeb) return 'web';
    return Platform.operatingSystemVersion;
  }
}
