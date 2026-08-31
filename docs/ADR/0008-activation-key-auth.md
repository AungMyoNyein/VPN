# ADR-0008: Customer ID + Activation Key (No Password Login)

## Status

Accepted

## Context

The commercial VPN product is sold/reseller-driven. Customers receive a Customer ID and Activation Key offline or via CRM. Email/password, OTP, and social login add friction and support cost without matching the sales model.

## Decision

- Mobile authentication is **Customer ID + Activation Key** for first device binding only.
- After activation, the API issues a **device credential**; clients store it in Android Keystore / iOS Keychain.
- Subsequent API calls use the device credential, not the activation key.
- Activation keys are hashed at rest; full plaintext is shown once at CRM creation.
- Email/password/OTP/Google/Apple login are **out of scope** for the customer mobile app.

## Consequences

- CRM must generate and deliver keys securely.
- Activation endpoint requires strict rate limiting and audit.
- Device revoke/reset is the recovery path when a phone is lost.
- Docs (`API`, `SECURITY`, `DATABASE`, `UI_UX`) must not describe customer password login.
