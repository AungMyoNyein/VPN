import 'package:flutter_test/flutter_test.dart';
import 'package:vpn_mobile/core/api_exception.dart';
import 'package:vpn_mobile/features/auth/activation_helpers.dart';

void main() {
  group('normalizeActivationInput', () {
    test('normalizes customer id and key casing', () {
      expect(normalizeCustomerId(' cust-000125 '), 'CUST-000125');
      expect(
        normalizeActivationKey(' vpn-7kq2-f9px-w3mt '),
        'VPN-7KQ2-F9PX-W3MT',
      );
    });
  });

  group('validateActivationInput', () {
    test('rejects empty fields', () {
      expect(
        validateActivationInput(customerId: '', activationKey: ''),
        'Activation details are invalid.',
      );
    });

    test('rejects malformed customer id', () {
      expect(
        validateActivationInput(
          customerId: 'BAD-ID',
          activationKey: 'VPN-AAAA-BBBB-CCCC',
        ),
        'Activation details are invalid.',
      );
    });

    test('rejects malformed activation key', () {
      expect(
        validateActivationInput(
          customerId: 'CUST-000125',
          activationKey: 'VPN-SHORT',
        ),
        'Activation details are invalid.',
      );
    });

    test('accepts valid normalized input', () {
      expect(
        validateActivationInput(
          customerId: 'CUST-000125',
          activationKey: 'VPN-7KQ2-F9PX-W3MT',
        ),
        isNull,
      );
    });
  });

  group('mapActivationError', () {
    test('maps invalid activation codes', () {
      expect(
        mapActivationError(ApiException(code: 'ACTIVATION_INVALID', message: '')),
        'Activation details are invalid.',
      );
      expect(
        mapActivationError(ApiException(code: 'VALIDATION_ERROR', message: '')),
        'Activation details are invalid.',
      );
    });

    test('maps subscription and device limit codes', () {
      expect(
        mapActivationError(ApiException(code: 'SUBSCRIPTION_EXPIRED', message: '')),
        'Your subscription has expired.',
      );
      expect(
        mapActivationError(ApiException(code: 'DEVICE_LIMIT_REACHED', message: '')),
        'Your plan has reached its device limit.',
      );
    });

    test('maps unknown codes to generic message', () {
      expect(
        mapActivationError(ApiException(code: 'INTERNAL_ERROR', message: '')),
        'Something went wrong. Please try again.',
      );
    });
  });

  group('shouldClearStoredCredential', () {
    test('returns true for credential invalidation codes', () {
      expect(shouldClearStoredCredential('DEVICE_CREDENTIAL_REVOKED'), isTrue);
      expect(shouldClearStoredCredential('DEVICE_REVOKED'), isTrue);
      expect(shouldClearStoredCredential('UNAUTHENTICATED'), isTrue);
    });

    test('returns false for transient errors', () {
      expect(shouldClearStoredCredential('RATE_LIMITED'), isFalse);
      expect(shouldClearStoredCredential('INTERNAL_ERROR'), isFalse);
    });
  });
}
