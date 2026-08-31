# Operations

**Phase:** 0 — operational design (tooling in Phase 7)  
**Last updated:** 2026-08-26

## 1. Environments

| Environment | Purpose |
|-------------|---------|
| local | Docker Compose developer stack |
| staging | Pre-prod; no production customer data |
| production | Commercial traffic |

Never deploy to production directly from an engineer workstation. Path: PR → CI → staging → approval → production.

## 2. Local Development Stack

Services (Compose):

- PostgreSQL
- Redis
- Laravel API (+ queue worker, scheduler profiles)
- Go control plane
- Fake node agent (dev)
- Mailpit (local email)
- Prometheus / Grafana / Loki (optional profile)

See `infrastructure/docker/docker-compose.yml`.

## 3. VPN Node Operations (design)

Initial regions: Bangkok, Singapore, Tokyo. Adding a region is **data + inventory**, not an app code change.

Per node:

- Ubuntu 24.04 LTS
- WireGuard
- nftables
- node-agent
- metrics exporter
- health agent

### Drain procedure

```
enable drain → stop new assignments → retain existing sessions
  → wait for session reduction → maintenance → change window
```

Do not immediately disconnect customers on drain.

### Maintenance vs drain vs retired

| Mode | New assignments | Existing sessions | Meaning |
|------|-----------------|-------------------|---------|
| **Drain** | Rejected | Remain | Soft exit / capacity / rolling change |
| **Maintenance** | Rejected | Ops-defined | Planned work window |
| **Retired** | Rejected | None | Permanently removed from inventory |

### Admin CRM node actions

enable, disable, maintenance, drain, add, retire — all audited.

## 4. Monitoring (Phase 7)

| Stack | Role | Phase 0 local |
|-------|------|---------------|
| Prometheus | Metrics | Compose profile `monitoring` |
| Grafana | Dashboards | Compose profile `monitoring` |
| Loki | Log aggregation | Reserved for Phase 7 (not required to close Phase 0) |

Reserved scrape targets: Laravel, PostgreSQL, Redis, Control Plane, Node Agents, VPN Nodes.

### Metrics hygiene

- Never place VPN private keys, tokens, passwords, or full customer emails in metric labels.
- Avoid high-cardinality labels (raw customer IDs, device UUIDs, peer IDs) unless explicitly justified.
- Prefer aggregated counters/histograms by node, endpoint class, and error code.

### Node metrics

CPU, RAM, disk, load, throughput, packet loss, WireGuard peer count, heartbeat age.

### Control plane

API latency, provisioning failures, sync errors, queue/backlog depth.

### Laravel

HTTP latency, API errors, queue failures, DB/Redis health, webhook failures.

### Alert examples

- Node down / stale heartbeat
- High CPU / memory / packet loss
- Capacity threshold
- Provisioning failure spike
- Database failure
- Payment webhook failure

## 5. Logging & Correlation

- Structured JSON logs
- `request_id` / `correlation_id` across Mobile → Laravel → CP → Agent
- No secrets in logs
- No DNS query / URL / payload logging by default

## 6. Backups & DR (Phase 7)

- PostgreSQL: automated backups + tested restore
- Redis: ephemeral OK; document what is durable
- Secrets: vault backup / break-glass procedure
- RPO/RTO targets documented before production launch
- Control-plane IP allocation state must be restorable or rebuildable from Laravel SoR

## 7. Secrets Management

| Env | Mechanism |
|-----|-----------|
| local | `.env` (gitignored), `.env.example` committed |
| staging/prod | Secrets manager / sealed secrets; rotate on schedule |

## 8. Rate Limits & Abuse Response

Baseline limits in API.md. Ops runbook (Phase 7):

- Identify abusive IP/customer
- Temporary block / suspend
- Audit review
- Node drain if capacity attack

## 9. On-Call & Runbooks (Phase 7 deliverables)

- Node down
- Mass provisioning failures
- Database failover
- Certificate expiry
- Payment provider outage
- Suspected credential leak

## 10. Data Retention (draft — configurable)

Retention targets are **policy-configurable**; finalize with legal before Phase 8.

| Data | Retention (draft) |
|------|-------------------|
| Audit logs | ≥ 1 year |
| VPN sessions metadata | 30–90 days |
| Application logs | 30 days (hot) + archive policy |
| Payment records | Per legal/accounting requirements |
| Support tickets | Per policy |

**Not collected / not retained as product data:** browsing history, visited URLs, DNS query contents, packet payloads.

## 11. Penetration Test Checklist (pre–Phase 8)

## 12. Operator Troubleshooting & Node Inspection Runbook

For debugging and inspection on a Linux VPN Node:

```bash
# Check Node Agent systemd service status and logs
systemctl status vpn-node-agent
journalctl -u vpn-node-agent -f

# Inspect WireGuard interface and applied peers
wg show wg0
wg show wg0 latest-handshakes
ip addr show dev wg0

# Inspect Linux routing and firewall rules
ip route
nft list table inet vpn_platform

# Test Node Agent health and statistics locally
curl -s http://127.0.0.1:8082/internal/v1/health
curl -s http://127.0.0.1:8082/metrics
```

Common issue triage:
* **Peer missing on node**: Trigger reconciliation via `php artisan vpn:reconcile`.
* **Handshake never occurs**: Verify UDP port 51820 reachability and check that client is sending packets with the matching public key.
* **Client connects but cannot browse**: Verify IPv4 forwarding (`sysctl net.ipv4.ip_forward`) and `table inet vpn_platform` postrouting masquerade rule.
- [ ] Provisioning entitlement bypass
- [ ] Webhook forgery / replay
- [ ] Management API exposure scan
- [ ] Mobile traffic interception (cert pinning policy)
- [ ] Secrets in mobile binaries / logs

## 12. Change Management

- Feature branches → PR
- Required CI: lint, unit, integration, security checks, build
- Staging soak + approval gate for production
- Migrations reviewed; rollback plan for risky DDL
