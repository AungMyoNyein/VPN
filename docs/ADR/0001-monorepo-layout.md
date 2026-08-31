# ADR-0001: Monorepo Layout

## Status

Accepted

## Context

The platform spans Laravel, Go control plane, Go node agent, Flutter mobile, React CRM, and infrastructure. Teams need shared contracts (API, ERD, security, UX) and coordinated CI.

## Decision

Use a single monorepo:

```
backend/          Laravel API (+ admin API for CRM)
crm/              React + TypeScript admin UI
control-plane/    Go
node-agent/       Go
mobile/           Flutter
infrastructure/   Docker, Ansible, monitoring
docs/             Architecture, UX, ADRs, wireframes
.github/workflows CI
```

## Consequences

- Single source of truth for API contracts and ADRs
- CI can path-filter jobs by component
- Future split into multi-repo remains possible if organizational scale requires it
