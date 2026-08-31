import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:vpn_mobile/core/api_exception.dart';
import 'package:vpn_mobile/core/app_config.dart';
import 'package:vpn_mobile/core/device_fingerprint.dart';
import 'package:vpn_mobile/core/models/account_info.dart';
import 'package:vpn_mobile/core/models/activation_result.dart';
import 'package:vpn_mobile/core/models/device_registration.dart';
import 'package:vpn_mobile/core/models/subscription_info.dart';
import 'package:vpn_mobile/core/models/vpn_location.dart';
import 'package:vpn_mobile/core/models/vpn_provision_result.dart';

class ApiClient {
  ApiClient({
    String? baseUrl,
    http.Client? httpClient,
    DeviceFingerprint? deviceFingerprint,
  })  : baseUrl = baseUrl ?? AppConfig.apiBaseUrl,
        _httpClient = httpClient ?? http.Client(),
        _deviceFingerprint = deviceFingerprint ?? DeviceFingerprint();

  final String baseUrl;
  final http.Client _httpClient;
  final DeviceFingerprint _deviceFingerprint;

  String? bearerToken;

  String get _apiRoot => '$baseUrl${AppConfig.apiPrefix}';

  Future<ActivationResult> activate({
    required String customerId,
    required String activationKey,
  }) async {
    final data = await _post(
      '/activate',
      body: {
        'customer_id': customerId,
        'activation_key': activationKey,
        'device': _deviceFingerprint.toRegistration().toJson(),
      },
      authenticated: false,
    );

    final result = ActivationResult.fromJson(data);
    if (result.deviceCredential.isEmpty) {
      throw ApiException(
        code: 'INTERNAL_ERROR',
        message: 'Activation succeeded without a device credential.',
      );
    }

    bearerToken = result.deviceCredential;
    return result;
  }

  Future<String> refreshDevice() async {
    final data = await _post('/device/refresh', authenticated: true);
    final credential = data['device_credential']?.toString() ??
        data['credential']?.toString() ??
        data['token']?.toString();

    if (credential == null || credential.isEmpty) {
      throw ApiException(
        code: 'INTERNAL_ERROR',
        message: 'Refresh succeeded without a device credential.',
      );
    }

    bearerToken = credential;
    return credential;
  }

  Future<void> deactivateDevice() async {
    await _post('/device/deactivate', authenticated: true);
    bearerToken = null;
  }

  Future<AccountInfo> getAccount() async {
    final data = await _get('/account', authenticated: true);
    return AccountInfo.fromJson(data);
  }

  Future<SubscriptionInfo> getSubscription() async {
    final data = await _get('/subscription', authenticated: true);
    return SubscriptionInfo.fromJson(data);
  }

  Future<DeviceRegistration> getDevice() async {
    final data = await _get('/device', authenticated: true);
    return DeviceRegistration(
      uuid: data['uuid']?.toString() ?? data['device_uuid']?.toString() ?? '',
      platform: data['platform']?.toString() ?? '',
      name: data['name']?.toString() ?? '',
      osVersion: data['os_version']?.toString() ?? '',
      appVersion: data['app_version']?.toString() ?? '',
    );
  }

  Future<List<VpnLocation>> getLocations() async {
    final data = await _getRaw('/vpn/locations', authenticated: true);
    if (data is List) {
      return data
          .map((e) => VpnLocation.fromJson(e as Map<String, dynamic>))
          .toList();
    }
    return [];
  }

  Future<VpnServerInfo?> getRecommendedServer() async {
    final data = await _get('/vpn/recommended-server', authenticated: true);
    return VpnServerInfo.fromJson(data);
  }

  Future<VpnProvisionResult> provisionVpn({
    int? locationId,
    required String clientPublicKey,
  }) async {
    final data = await _post(
      '/vpn/provision',
      body: {
        if (locationId != null) 'location_id': locationId,
        'client_public_key': clientPublicKey,
      },
      authenticated: true,
    );

    return VpnProvisionResult.fromJson(data);
  }

  Future<void> revokeVpn() async {
    await _post('/vpn/revoke', authenticated: true);
  }

  Future<Map<String, dynamic>> getVpnStatus() async {
    return _get('/vpn/status', authenticated: true);
  }

  Future<Map<String, dynamic>> _get(
    String path, {
    required bool authenticated,
  }) async {
    final response = await _httpClient.get(
      Uri.parse('$_apiRoot$path'),
      headers: _headers(authenticated: authenticated),
    );
    return _parseResponse(response);
  }

  Future<dynamic> _getRaw(
    String path, {
    required bool authenticated,
  }) async {
    final response = await _httpClient.get(
      Uri.parse('$_apiRoot$path'),
      headers: _headers(authenticated: authenticated),
    );
    return _parseRawResponse(response);
  }

  Future<Map<String, dynamic>> _post(
    String path, {
    Map<String, dynamic>? body,
    required bool authenticated,
  }) async {
    final response = await _httpClient.post(
      Uri.parse('$_apiRoot$path'),
      headers: _headers(authenticated: authenticated),
      body: body == null ? null : jsonEncode(body),
    );
    return _parseResponse(response);
  }

  Map<String, String> _headers({required bool authenticated}) {
    final headers = <String, String>{
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    };

    if (authenticated) {
      final token = bearerToken;
      if (token == null || token.isEmpty) {
        throw ApiException(
          code: 'UNAUTHENTICATED',
          message: 'Device credential is missing.',
        );
      }
      headers['Authorization'] = 'Bearer $token';
    }

    return headers;
  }

  Map<String, dynamic> _parseResponse(http.Response response) {
    final raw = _parseRawResponse(response);
    if (raw is Map<String, dynamic>) {
      return raw;
    }
    return <String, dynamic>{};
  }

  dynamic _parseRawResponse(http.Response response) {
    dynamic json;
    if (response.body.isNotEmpty) {
      final decoded = jsonDecode(response.body);
      json = decoded;
    }

    if (response.statusCode >= 200 && response.statusCode < 300) {
      if (json is Map<String, dynamic> && json.containsKey('data')) {
        return json['data'];
      }
      return json;
    }

    if (json is Map<String, dynamic> && json.containsKey('error')) {
      throw ApiException.fromJson(json);
    }

    throw ApiException(
      code: 'INTERNAL_ERROR',
      message: 'Unexpected server response (${response.statusCode}).',
    );
  }

  void close() => _httpClient.close();
}
