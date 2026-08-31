class SubscriptionInfo {
  const SubscriptionInfo({
    required this.planName,
    required this.status,
    this.expiresAt,
  });

  final String planName;
  final String status;
  final DateTime? expiresAt;

  factory SubscriptionInfo.fromJson(Map<String, dynamic> json) {
    return SubscriptionInfo(
      planName: json['plan_name']?.toString() ?? json['plan']?.toString() ?? 'Unknown',
      status: json['status']?.toString() ?? 'UNKNOWN',
      expiresAt: json['expires_at'] != null
          ? DateTime.tryParse(json['expires_at'].toString())
          : json['ends_at'] != null
              ? DateTime.tryParse(json['ends_at'].toString())
              : null,
    );
  }
}
