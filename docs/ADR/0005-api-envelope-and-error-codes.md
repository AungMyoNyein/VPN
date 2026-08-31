# ADR-0005: API Response Envelope & Error Codes

## Status

Accepted

## Context

Mobile clients must branch on stable machine-readable errors across app versions and locales.

## Decision

- Success: `{ "data": ..., "meta": ... }`
- Error: `{ "error": { "code", "message", "request_id" } }`
- Clients depend on `error.code`, not message strings
- Public APIs versioned under `/api/v1`

## Consequences

- Shared error code catalog in docs/API.md
- Backend and Flutter contract tests should assert codes for critical paths
