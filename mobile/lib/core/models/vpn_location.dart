class VpnLocation {
  const VpnLocation({
    required this.id,
    required this.countryCode,
    required this.countryName,
    required this.city,
    required this.displayName,
    required this.serversCount,
    required this.available,
  });

  final int id;
  final String countryCode;
  final String countryName;
  final String city;
  final String displayName;
  final int serversCount;
  final bool available;

  factory VpnLocation.fromJson(Map<String, dynamic> json) {
    return VpnLocation(
      id: (json['id'] as num?)?.toInt() ?? 0,
      countryCode: json['country_code']?.toString() ?? '',
      countryName: json['country_name']?.toString() ?? '',
      city: json['city']?.toString() ?? '',
      displayName: json['display_name']?.toString() ?? '',
      serversCount: (json['servers_count'] as num?)?.toInt() ?? 0,
      available: json['available'] == true,
    );
  }
}
