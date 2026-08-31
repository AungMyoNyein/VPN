class ApiException implements Exception {
  ApiException({required this.code, required this.message, this.requestId});

  final String code;
  final String message;
  final String? requestId;

  factory ApiException.fromJson(Map<String, dynamic> json) {
    final error = json['error'] as Map<String, dynamic>? ?? {};
    return ApiException(
      code: error['code'] as String? ?? 'INTERNAL_ERROR',
      message: error['message'] as String? ?? 'Unknown error',
      requestId: error['request_id'] as String?,
    );
  }

  @override
  String toString() => 'ApiException($code): $message';
}
