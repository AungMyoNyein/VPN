# ADR-0007: Documentation Sources of Truth

## Status

Accepted

## Context

Phase 0 accumulated both compact contracts (`ARCHITECTURE`, `API`, `DATABASE`, `SECURITY`) and a long-form narrative (`HLD.md`). Duplicate prose drifts and confuses Phase gates.

## Decision

| Document | Authority |
|----------|-----------|
| `ARCHITECTURE.md` | System context, planes, identities, phase map |
| `API.md` | Public and internal HTTP contracts |
| `DATABASE.md` | Logical schema, cardinality, constraints |
| `SECURITY.md` / `THREAT_MODEL.md` | Security controls and threats |
| `OPERATIONS.md` | Environments, monitoring, runbooks |
| `ADR/*` | Permanent decisions |
| `HLD.md` | Optional narrative synthesis — **must not contradict** the above; if conflict, contracts win |

Do not create a separate `LLD.md` unless a future phase needs implementation-level detail that does not belong in ADRs or module READMEs.

## Consequences

- Gap analyses and Phase gates cite contracts first.
- Updates to behavior land in the authoritative doc; HLD is updated only when narrative value remains.
