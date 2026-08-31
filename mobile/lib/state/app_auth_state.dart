import 'package:flutter/foundation.dart';
import 'package:vpn_mobile/core/api_client.dart';
import 'package:vpn_mobile/core/api_exception.dart';
import 'package:vpn_mobile/core/models/account_info.dart';
import 'package:vpn_mobile/core/models/subscription_info.dart';
import 'package:vpn_mobile/core/secure_credential_store.dart';
import 'package:vpn_mobile/features/auth/activation_helpers.dart';

enum AppAuthStatus {
  unknown,
  needsActivation,
  authenticating,
  ready,
  error,
}

class AppAuthState extends ChangeNotifier {
  AppAuthState({
    required ApiClient apiClient,
    required SecureCredentialStore credentialStore,
  })  : _apiClient = apiClient,
        _credentialStore = credentialStore;

  final ApiClient _apiClient;
  final SecureCredentialStore _credentialStore;

  ApiClient get apiClient => _apiClient;

  AppAuthStatus status = AppAuthStatus.unknown;
  AccountInfo? account;
  SubscriptionInfo? subscription;
  String? errorMessage;

  bool get isReady => status == AppAuthStatus.ready;

  Future<void> bootstrap() async {
    status = AppAuthStatus.authenticating;
    errorMessage = null;
    notifyListeners();

    final storedCredential = await _credentialStore.readDeviceCredential();
    if (storedCredential == null || storedCredential.isEmpty) {
      status = AppAuthStatus.needsActivation;
      account = null;
      subscription = null;
      notifyListeners();
      return;
    }

    try {
      await _restoreSession(storedCredential);
      status = AppAuthStatus.ready;
    } on ApiException catch (error) {
      if (shouldClearStoredCredential(error.code)) {
        await _credentialStore.deleteDeviceCredential();
        _apiClient.bearerToken = null;
        account = null;
        subscription = null;
        status = AppAuthStatus.needsActivation;
        errorMessage = mapBootstrapError(error);
      } else {
        status = AppAuthStatus.error;
        errorMessage = mapBootstrapError(error);
      }
    } catch (_) {
      status = AppAuthStatus.error;
      errorMessage = 'Something went wrong. Please try again.';
    }

    notifyListeners();
  }

  Future<void> activate({
    required String customerId,
    required String activationKey,
  }) async {
    status = AppAuthStatus.authenticating;
    errorMessage = null;
    notifyListeners();

    try {
      final result = await _apiClient.activate(
        customerId: customerId,
        activationKey: activationKey,
      );

      await _credentialStore.saveDeviceCredential(result.deviceCredential);
      account = result.account ?? await _apiClient.getAccount();
      subscription = await _safeGetSubscription();
      status = AppAuthStatus.ready;
      errorMessage = null;
    } on ApiException catch (error) {
      status = AppAuthStatus.needsActivation;
      errorMessage = mapActivationError(error);
    } catch (_) {
      status = AppAuthStatus.needsActivation;
      errorMessage = 'Something went wrong. Please try again.';
    }

    notifyListeners();
  }

  Future<void> deactivateCurrentDevice() async {
    status = AppAuthStatus.authenticating;
    errorMessage = null;
    notifyListeners();

    try {
      await _apiClient.deactivateDevice();
    } on ApiException catch (_) {
      // Local wipe still proceeds if the device is already invalid server-side.
    } catch (_) {
      // Best-effort remote revoke; always clear local state.
    } finally {
      await _credentialStore.deleteDeviceCredential();
      _apiClient.bearerToken = null;
      account = null;
      subscription = null;
      status = AppAuthStatus.needsActivation;
      notifyListeners();
    }
  }

  Future<void> refreshAccount() async {
    if (_apiClient.bearerToken == null) {
      return;
    }

    try {
      account = await _apiClient.getAccount();
      subscription = await _safeGetSubscription();
      notifyListeners();
    } on ApiException catch (error) {
      if (shouldClearStoredCredential(error.code)) {
        await _credentialStore.deleteDeviceCredential();
        _apiClient.bearerToken = null;
        account = null;
        subscription = null;
        status = AppAuthStatus.needsActivation;
        errorMessage = mapBootstrapError(error);
        notifyListeners();
      }
    }
  }

  Future<void> _restoreSession(String credential) async {
    _apiClient.bearerToken = credential;

    try {
      final refreshed = await _apiClient.refreshDevice();
      await _credentialStore.saveDeviceCredential(refreshed);
    } on ApiException catch (error) {
      if (shouldClearStoredCredential(error.code)) {
        rethrow;
      }
      // Fall back to existing credential when refresh is unavailable.
    }

    account = await _apiClient.getAccount();
    subscription = await _safeGetSubscription();
  }

  Future<SubscriptionInfo?> _safeGetSubscription() async {
    try {
      return await _apiClient.getSubscription();
    } on ApiException {
      return null;
    }
  }
}
