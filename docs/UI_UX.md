# UI / UX Design System

**Phase:** 0 — design contracts (implementation follows phase map)  
**Last updated:** 2026-08-27  
**Wireframes:** [wireframes/mobile.md](./wireframes/mobile.md), [wireframes/crm.md](./wireframes/crm.md)

## 1. Product UX principles

- Clean, minimal, premium, calm, fast
- Large touch targets; clear status feedback
- Minimal text on primary screens
- One primary CTA on the VPN Home screen
- Never show stack traces, HTTP codes, or WireGuard internals to customers

Avoid: crowded dashboards, excessive cards/gradients/animations, tiny text, inconsistent icons.

## 2. Mobile design system

Foundation: Material 3, customized brand identity. Support light and dark mode.

### Typography

| Role | Use |
|------|-----|
| Display | Connection status headline (“VPN PROTECTED”) |
| Title | Screen titles (Locations, Account) |
| Body | Supporting copy, lists |
| Label | Form labels, meta (latency, expiry) |
| Caption | Help links, version |

Prefer a single readable sans family (platform defaults acceptable in Phase 0 skeleton; brand fonts locked before store release).

### Spacing scale

```
4 · 8 · 12 · 16 · 24 · 32
```

### Radii

| Token | Value |
|-------|-------|
| small | 10 |
| medium | 16 |
| large | 24 |

### Elevation

Subtle only. Prefer soft surface contrast over heavy multi-layer shadows.

### Color & status (not color-only)

| State | Text + icon required |
|-------|----------------------|
| Disconnected | “VPN NOT PROTECTED” + shield outline |
| Connecting | “CONNECTING” + soft pulse on control |
| Connected | “VPN PROTECTED” + filled shield |
| Error | “Something went wrong” + retry |

### Components

- Primary button (Connect / Disconnect / Activate)
- Secondary text button (Change location, Contact support)
- Text fields with clear validation
- Location list rows (flag/name/latency)
- Status chips (plan active/expired) — never alone for critical VPN state
- Bottom navigation (4 tabs)

### Navigation

Bottom tabs:

```
Home · Locations · Account · Settings
```

Avoid deep nested stacks. Activation is a gate **before** main shell when no device credential exists.

## 3. Mobile screens (behavior)

### Activation

- Customer ID + Activation Key only (no email/password/OTP/social)
- Paste-friendly key; normalize case/separators
- Loading on submit; friendly mapped errors only
- After success: store device credential (Keystore / Keychain); do not re-prompt every launch

Customer-facing error copy:

| Condition | Message |
|-----------|---------|
| Invalid ID/key | Activation details are invalid. |
| Expired subscription | Your subscription has expired. |
| Device limit | Your plan has reached its device limit. |
| Generic | Something went wrong. Please try again. |

### Home

- Centerpiece status + single Connect/Disconnect control
- Selected location summary with Change
- Session timer when connected
- Connection states: DISCONNECTED, PREPARING, AUTHORIZING, PROVISIONING, CONNECTING, CONNECTED, RECONNECTING, DISCONNECTING, ERROR
- Soft animation only during CONNECTING

### Locations

- Search, Smart/Fastest recommendation, country groups, latency
- Maintenance/unavailable + plan restriction states
- No management IPs or node internals

### Account (“My VPN”)

- Customer ID, plan, status, expiry, device count, app version, support
- Never show full activation key after activation (optional masked suffix only)

### Settings

Auto-connect, connect on launch, protocol, kill switch, DNS, theme, diagnostics, privacy, about. Hide unsupported platform options. Split tunneling = Android future, hidden until supported.

## 4. Empty / loading / error states

| State | Pattern |
|-------|---------|
| Empty locations | Friendly empty + retry |
| Loading | Skeleton or centered progress; keep layout stable |
| Offline | “No internet connection. Check your connection and try again.” |
| Node down | Offer auto-fallback copy; no panic text |
| Revoked device | “This device is no longer authorized.” |

## 5. Accessibility

- Contrast meeting WCAG AA where practical
- Semantic labels on icon-only controls
- Min touch target ≈ 48×48 dp
- Respect text scaling
- Do not rely on color alone for VPN state

## 6. CRM design system

Stack target: React + TypeScript + modern component library (Phase 1). Desktop-primary; tablet OK.

### Layout

- Collapsible sidebar
- Top bar: global search + admin identity
- Content: page title, filters, primary table/detail

### Navigation

```
Dashboard · Customers · Subscriptions · Activation Keys · Devices
VPN Locations · VPN Servers · Sessions · Payments · Support
Reports · Administrators · Audit Logs · Settings
```

### Dashboard

Few high-signal cards (active customers, subscriptions, connected users, nodes online). Secondary: expiring soon, health, recent payments/activity, alerts. Do not dump every metric.

### Customer detail

Header: customer code, name, status. Tabs: Overview, Subscription, Devices, Activation Keys, Sessions, Payments, Audit. Destructive actions require confirmation + RBAC.

### Customer create wizard

1. Customer info → 2. Plan → 3. Duration → 4. Device limit → 5. Generate key  
Result shows **Customer ID + Activation Key once** with copy/print; full key not re-shown later unless security policy explicitly allows.

### Tables

Search, filter, sort, pagination; avoid horizontal overflow where possible.

### Global search

Customer ID, name, phone, activation **key prefix**, device ID, payment reference. Never plaintext full-key search from storage.

## 7. Responsiveness

| Surface | Priority |
|---------|----------|
| Mobile app | Phone first; tablet comfortable |
| CRM | Desktop / laptop primary; tablet usable |

## 8. Dark mode

Mobile: system + in-app theme toggle (Settings). CRM: light default; dark optional in Phase 1+.

## 9. Out of scope (Phase 0)

No production visual polish pass, no store screenshots, no real native tunnel UI chrome beyond skeleton placeholders.
