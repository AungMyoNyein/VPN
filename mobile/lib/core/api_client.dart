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
import 'package:vpn_mobile/core/models/vpn_protocol.dart';
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

  Future<VpnServerInfo?> getRecommendedServer({VpnProtocol? protocol}) async {
    final query = protocol != null ? '?protocol=${protocol.value}' : '';
    final data = await _get('/vpn/recommended-server$query', authenticated: true);
    return VpnServerInfo.fromJson(data);
  }

  Future<VpnProvisionResult> provisionVpn({
    int? locationId,
    String? clientPublicKey,
    String? clientUuid,
    VpnProtocol protocol = VpnProtocol.wireguard,
    String? idempotencyKey,
  }) async {
    final body = <String, dynamic>{
      'protocol': protocol.value,
      if (locationId != null) 'location_id': locationId,
    };

    if (protocol == VpnProtocol.wireguard) {
      body['client_public_key'] = clientPublicKey ?? '';
    } else {
      if (clientUuid != null && clientUuid.isNotEmpty) {
        body['client_uuid'] = clientUuid;
      }
    }

    final data = await _post(
      '/vpn/provision',
      body: body,
      authenticated: true,
      idempotencyKey: idempotencyKey,
    );

    return VpnProvisionResult.fromJson(data);
  }

  Future<List<VpnProtocol>> getProtocols() async {
    final data = await _get('/vpn/protocols', authenticated: true);
    final raw = data['protocols'];
    if (raw is List) {
      return raw
          .map((e) => VpnProtocol.fromString(e.toString()))
          .toList();
    }
    return [VpnProtocol.wireguard];
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
    String? idempotencyKey,
  }) async {
    final response = await _httpClient.post(
      Uri.parse('$_apiRoot$path'),
      headers: _headers(
        authenticated: authenticated,
        idempotencyKey: idempotencyKey,
      ),
      body: body == null ? null : jsonEncode(body),
    );
    return _parseResponse(response);
  }

  Map<String, String> _headers({
    required bool authenticated,
    String? idempotencyKey,
  }) {
    final headers = <String, String>{
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    };

    if (idempotencyKey != null && idempotencyKey.isNotEmpty) {
      headers['Idempotency-Key'] = idempotencyKey;
    }

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
