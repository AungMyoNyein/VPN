import 'package:vpn_mobile/core/models/account_info.dart';
import 'package:vpn_mobile/core/models/device_registration.dart';

class ActivationResult {
  const ActivationResult({
    required this.deviceCredential,
    this.account,
    this.device,
  });

  final String deviceCredential;
  final AccountInfo? account;
  final DeviceRegistration? device;

  factory ActivationResult.fromJson(Map<String, dynamic> json) {
    final deviceJson = json['device'] as Map<String, dynamic>?;
    return ActivationResult(
      deviceCredential: json['device_credential']?.toString() ??
          json['credential']?.toString() ??
          json['token']?.toString() ??
          '',
      account: json['account'] is Map<String, dynamic>
          ? AccountInfo.fromJson(json['account'] as Map<String, dynamic>)
          : null,
      device: deviceJson == null
          ? null
          : DeviceRegistration(
              uuid: deviceJson['uuid']?.toString() ?? '',
              platform: deviceJson['platform']?.toString() ?? '',
              name: deviceJson['name']?.toString() ?? '',
              osVersion: deviceJson['os_version']?.toString() ?? '',
              appVersion: deviceJson['app_version']?.toString() ?? '',
            ),
    );
  }
}
