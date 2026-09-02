# VPN Platform install scripts

Production-oriented installers for the control stack and edge VPN nodes.

| Script | Host role | Installs |
|--------|-----------|----------|
| `install-api-cms-server.sh` | API + CMS (`zentunnel.net`) | Postgres, Redis, control-plane (Docker), Laravel API, CRM static site, nginx |
| `install-vpn-node.sh` | VPN edge node | WireGuard, node-agent, VLESS/Xray, NAT/forward rules |
| `install-xray-vless.sh` | VPN edge (standalone) | Xray VLESS inbound only |
| `register-remote-node.sh` | API/CMS host | Registers a remote node with control plane |

Shared helpers live in `lib/common.sh`.

## 1. API + CMS server

Run on the **control host only** — do not install WireGuard/Xray here.

```bash
git clone <repo-url> /root/VPN
cd /root/VPN/infrastructure/scripts

sudo ./install-api-cms-server.sh \
  --domain zentunnel.net \
  --repo /root/VPN \
  --db-password 'change-me-strong' \
  --admin-email admin@example.com \
  --admin-password 'ChangeMe!'
```

After install:

- API: `https://zentunnel.net/api/v1/health`
- CRM: `https://zentunnel.net/`
- Control plane token: `/etc/vpn-platform/api.env` and `backend/.env`

Options: `--skip-ssl`, `--skip-crm-build`, `--cp-token`, `--redis-password`.

## 2. VPN node

Run on each **edge server** (Bangkok, Tokyo, Yangon, etc.).

```bash
sudo ./install-vpn-node.sh \
  --node-id 5 \
  --node-code VPS10-01 \
  --public-endpoint vps10.zentunnel.net \
  --wg-pool 10.200.30.0/24 \
  --wg-gateway 10.200.30.1 \
  --vless-domain vps10.zentunnel.net \
  --vless-port 443 \
  --repo /root/VPN
```

WireGuard-only (no VLESS): add `--skip-vless`.

No Let's Encrypt yet: add `--self-signed-tls` (mobile clients need a trusted cert for VLESS).

Then in CMS (`vpn_nodes` + IP pool) set:

- `public_key` — printed at end of install
- `agent_endpoint` — `http://<node-ip>:8082`
- `supported_protocols` — `wireguard,vless`
- `vless_port` — e.g. `443`

On the **API host**, register the node with control plane:

```bash
sudo ./register-remote-node.sh \
  --node-id 5 \
  --endpoint http://119.10.138.179:8082
```

Re-run registration after control-plane restarts (in-memory node registry).

## 3. TLS for VLESS

Point DNS at the node, then on the node:

```bash
certbot certonly --standalone -d vps10.zentunnel.net
ln -sf /etc/letsencrypt/live/vps10.zentunnel.net/fullchain.pem /etc/xray/cert.pem
ln -sf /etc/letsencrypt/live/vps10.zentunnel.net/privkey.pem /etc/xray/key.pem
systemctl restart xray-vless
```

## Files created on nodes

| Path | Purpose |
|------|---------|
| `/etc/vpn-platform/node-agent.env` | Node agent config |
| `/etc/wireguard/{private,public}.key` | WireGuard server keys |
| `/etc/vpn-platform/ensure-vpn-forward.sh` | iptables NAT/forward |
| `/etc/vpn-platform/sync-xray.sh` | Sync VLESS peers → Xray |
| `/usr/local/bin/vpn-node-agent` | Node agent binary |

## Files created on API/CMS host

| Path | Purpose |
|------|---------|
| `/opt/vpn-platform/` | Deployed repo copy |
| `/etc/vpn-platform/api.env` | Install secrets/metadata |
| `/var/www/vpn-crm/` | CRM static build |
| `systemd`: `vpn-api` | Laravel `artisan serve` on `:8000` |
