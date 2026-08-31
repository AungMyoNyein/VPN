import 'package:vpn_mobile/core/api_exception.dart';

enum ActivationFormState {
  idle,
  validating,
  activating,
  success,
  error,
}

String normalizeCustomerId(String raw) => raw.trim().toUpperCase();

String normalizeActivationKey(String raw) {
  return raw.trim().toUpperCase().replaceAll(RegExp(r'[^A-Z0-9-]'), '');
}

/// Returns a user-facing validation message, or null when valid.
String? validateActivationInput({
  required String customerId,
  required String activationKey,
}) {
  final normalizedCustomerId = normalizeCustomerId(customerId);
  final normalizedKey = normalizeActivationKey(activationKey);

  if (normalizedCustomerId.isEmpty || normalizedKey.isEmpty) {
    return 'Activation details are invalid.';
  }

  if (!RegExp(r'^CUST-\d+$').hasMatch(normalizedCustomerId)) {
    return 'Activation details are invalid.';
  }

  if (!RegExp(r'^VPN-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$').hasMatch(normalizedKey)) {
    return 'Activation details are invalid.';
  }

  return null;
}

String mapActivationError(ApiException error) {
  switch (error.code) {
    case 'ACTIVATION_INVALID':
    case 'ACTIVATION_KEY_INVALID':
    case 'ACTIVATION_KEY_REVOKED':
    case 'ACTIVATION_KEY_EXPIRED':
    case 'VALIDATION_ERROR':
      return 'Activation details are invalid.';
    case 'SUBSCRIPTION_EXPIRED':
    case 'SUBSCRIPTION_REQUIRED':
      return 'Your subscription has expired.';
    case 'DEVICE_LIMIT_REACHED':
      return 'Your plan has reached its device limit.';
    case 'CUSTOMER_SUSPENDED':
    case 'CUSTOMER_BLOCKED':
      return 'This account is not available. Contact support.';
    case 'RATE_LIMITED':
      return 'Too many attempts. Please wait and try again.';
    default:
      return 'Something went wrong. Please try again.';
  }
}

const credentialInvalidCodes = {
  'UNAUTHENTICATED',
  'DEVICE_CREDENTIAL_INVALID',
  'DEVICE_CREDENTIAL_REVOKED',
  'DEVICE_CREDENTIAL_EXPIRED',
  'DEVICE_REVOKED',
  'DEVICE_BLOCKED',
};

bool shouldClearStoredCredential(String code) =>
    credentialInvalidCodes.contains(code);

String mapBootstrapError(ApiException error) {
  if (shouldClearStoredCredential(error.code)) {
    return 'This device is no longer authorized.';
  }
  return 'Something went wrong. Please try again.';
}
