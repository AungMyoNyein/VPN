# Commercial VPN Platform

Monorepo for a production-oriented commercial VPN platform (WireGuard).

**Current phase:** Phase 4 — Real WireGuard Node + Secure Node Agent (**complete**)  
**Status:** Real WireGuard Node Agent, RemoteNodeAdapter, mTLS, nftables scoped firewall/NAT, real handshake and RX/TX telemetry, CRM node/peer telemetry views live.

Customer mobile auth model: **Customer ID + Activation Key** (no email/password/OTP/social). See [ADR-0008](docs/ADR/0008-activation-key-auth.md).  
VPN Control Plane & IPAM model: **Transactional IPAM + Real Node Agent & Dual Adapter**. See [ADR-0009](docs/ADR/0009-vpn-provisioning-and-fake-node.md), [ADR-0010](docs/ADR/0010-ipam-and-peer-lifecycle.md), & [ADR-0011](docs/ADR/0011-real-wireguard-node-agent.md).  
CRM admins authenticate with email/password (Sanctum). See [docs/PHASE_1_REPORT.md](docs/PHASE_1_REPORT.md).

## Repository layout

```
vpn-platform/
├── docs/                 Architecture, API, UX, ERD, wireframes, ADRs
├── backend/              Laravel API (+ admin API for CRM)
├── crm/                  React + TypeScript admin UI
├── control-plane/        Go VPN control plane (internal API)
├── node-agent/           Go agent for VPN nodes
├── mobile/               Flutter client (Android/iOS)
├── infrastructure/       Docker Compose, Ansible, monitoring
└── .github/workflows/    CI
```

## Documentation

| Doc | Purpose |
|-----|---------|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | System context, planes, identities (**SoR**) |
| [docs/HLD.md](docs/HLD.md) | Optional narrative HLD/LLD (must not contradict SoR) |
| [docs/UI_UX.md](docs/UI_UX.md) | Mobile + CRM design system |
| [docs/wireframes/mobile.md](docs/wireframes/mobile.md) | Mobile ASCII wireframes |
| [docs/wireframes/crm.md](docs/wireframes/crm.md) | CRM ASCII wireframes |
| [docs/SECURITY.md](docs/SECURITY.md) | Security architecture |
| [docs/API.md](docs/API.md) | Public and internal API contracts |
| [docs/DATABASE.md](docs/DATABASE.md) | Logical ERD and integrity rules |
| [docs/THREAT_MODEL.md](docs/THREAT_MODEL.md) | STRIDE threat model |
| [docs/OPERATIONS.md](docs/OPERATIONS.md) | Environments, monitoring, runbooks |
| [docs/ADR/](docs/ADR/) | Architecture decision records |

## Local development

Prerequisites: Docker Compose, PHP 8.3+, Composer, Go 1.23+, Node 20+ (CRM), Flutter 3.24+ (mobile).

```bash
# From repository root
cd infrastructure/docker
docker compose up -d postgres redis mailpit control-plane
# Host ports (this machine): Postgres 15432, Redis 16379, CP 8081, Mailpit 8025
# Optional: api, workers, agents, monitoring profiles
docker compose --profile workers --profile agents --profile monitoring up -d
```

Laravel (host against Compose DB on this machine):

```bash
cd backend
cp .env.example .env   # if needed
# Host ports: DB 15432, Redis 16379 (see infrastructure/docker/docker-compose.yml)
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8000
php artisan test
```

Dev admin (local only; override via `ADMIN_EMAIL` / `ADMIN_PASSWORD`):

```
admin@vpn.local / ChangeMe_LocalOnly_1!
```

CRM:

```bash
cd crm
npm install
npm run dev   # proxies /api → http://127.0.0.1:8000
npm test
```

Control plane:

```bash
cd control-plane
go test ./...
go run ./cmd/control-plane
```

Node agent (fake adapter):

```bash
cd node-agent
go test ./...
go run ./cmd/node-agent
```

Mobile:

```bash
cd mobile
flutter pub get
flutter test
```

## Hard rules (Phase 0+)

- Laravel never SSHs to VPN nodes on customer request paths.
- Mobile never receives management IPs, SSH passwords, or control-plane credentials.
- Client WireGuard private keys stay on device; backend stores public keys only.
- Activation keys are hashed at rest; never logged in full.
- Never commit `.env` or real secrets.

## Phases

| Phase | Focus |
|-------|--------|
| 0 | Architecture, UX, docs, skeletons, Compose, CI — **closed** |
| 1 | CRM foundation — **closed** |
| 2 | Activation + device credentials — **complete** |
| 3 | Control plane + fake node adapter ← next |
| 4 | Real WireGuard node |
| 5 | Android |
| 6 | iOS |
| 7 | Billing + operations |
| 8 | Production / store release |

Do not begin the next phase until the current Phase Report is accepted.
