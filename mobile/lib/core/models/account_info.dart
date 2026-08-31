class AccountInfo {
  const AccountInfo({
    required this.customerId,
    required this.planName,
    required this.status,
    this.expiresAt,
    this.devicesUsed = 0,
    this.devicesLimit = 0,
  });

  final String customerId;
  final String planName;
  final String status;
  final DateTime? expiresAt;
  final int devicesUsed;
  final int devicesLimit;

  factory AccountInfo.fromJson(Map<String, dynamic> json) {
    final subscription = json['subscription'] as Map<String, dynamic>?;
    final entitlements = json['entitlements'] as Map<String, dynamic>? ??
        subscription?['entitlements'] as Map<String, dynamic>?;

    return AccountInfo(
      customerId: _string(json['customer_id'] ?? json['customer_code']),
      planName: _string(
        json['plan_name'] ??
            subscription?['plan_name'] ??
            json['plan'] ??
            'Unknown',
      ),
      status: _string(json['status'] ?? subscription?['status'] ?? 'UNKNOWN'),
      expiresAt: _parseDate(
        json['expires_at'] ?? subscription?['expires_at'] ?? subscription?['ends_at'],
      ),
      devicesUsed: _int(json['devices_used'] ?? entitlements?['devices_used']),
      devicesLimit: _int(
        json['devices_limit'] ??
            json['max_devices'] ??
            entitlements?['max_devices'],
      ),
    );
  }

  static String _string(Object? value) => value?.toString() ?? '';

  static int _int(Object? value) {
    if (value == null) return 0;
    if (value is int) return value;
    return int.tryParse(value.toString()) ?? 0;
  }

  static DateTime? _parseDate(Object? value) {
    if (value == null) return null;
    return DateTime.tryParse(value.toString());
  }
}
