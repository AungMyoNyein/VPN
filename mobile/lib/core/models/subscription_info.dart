class SubscriptionInfo {
  const SubscriptionInfo({
    required this.planName,
    required this.status,
    required this.entitlementState,
    this.expiresAt,
  });

  final String planName;
  final String status;
  final String entitlementState;
  final DateTime? expiresAt;

  bool get isUsable => entitlementState.toUpperCase() == 'ACTIVE';

  factory SubscriptionInfo.fromJson(Map<String, dynamic> json) {
    final sub = json['subscription'] as Map<String, dynamic>?;
    return SubscriptionInfo(
      planName: sub?['plan']?['name']?.toString() ??
          json['plan_name']?.toString() ??
          json['plan']?.toString() ??
          'Unknown',
      status: sub?['status']?.toString() ?? json['status']?.toString() ?? 'UNKNOWN',
      entitlementState: json['entitlement_state']?.toString() ??
          json['status']?.toString() ??
          'UNKNOWN',
      expiresAt: sub?['expires_at'] != null
          ? DateTime.tryParse(sub!['expires_at'].toString())
          : json['expires_at'] != null
              ? DateTime.tryParse(json['expires_at'].toString())
              : json['ends_at'] != null
                  ? DateTime.tryParse(json['ends_at'].toString())
                  : null,
    );
  }
}
