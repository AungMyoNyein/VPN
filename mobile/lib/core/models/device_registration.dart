class DeviceRegistration {
  const DeviceRegistration({
    required this.uuid,
    required this.platform,
    required this.name,
    required this.osVersion,
    required this.appVersion,
  });

  final String uuid;
  final String platform;
  final String name;
  final String osVersion;
  final String appVersion;

  Map<String, dynamic> toJson() => {
        'uuid': uuid,
        'device_uuid': uuid,
        'platform': platform,
        'name': name,
        'device_name': name,
        'os_version': osVersion,
        'app_version': appVersion,
      };
}
