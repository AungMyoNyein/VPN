#!/usr/bin/env bash
# Install a production VPN node: WireGuard + node-agent + VLESS (Xray) + routing/NAT.
#
# Usage (on the VPN node, as root):
#   curl -fsSL .../install-vpn-node.sh | bash -s -- \
#     --node-id 5 \
#     --node-code VPS10-01 \
#     --public-endpoint vps10.zentunnel.net \
#     --wg-pool 10.200.30.0/24 \
#     --wg-gateway 10.200.30.1 \
#     --vless-domain vps10.zentunnel.net \
#     --vless-port 443 \
#     --repo /root/VPN
#
# Options:
#   --node-id           CMS node id (must match vpn_nodes.id)
#   --node-code         Human-readable node code
#   --public-endpoint   WireGuard DNS or IP shown to clients
#   --wg-pool           Authorized client pool CIDR
#   --wg-gateway        WireGuard server gateway IP on wg0
#   --wg-port           WireGuard UDP port (default 51820)
#   --vless-domain      TLS certificate domain for VLESS SNI
#   --vless-port        VLESS TCP port (default 443)
#   --wan-iface         WAN interface (default: auto)
#   --repo              Path to VPN git checkout (to build node-agent)
#   --skip-vless        WireGuard only
#   --self-signed-tls   Use self-signed cert when Let's Encrypt is unavailable
#   -h|--help

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

NODE_ID=""
NODE_CODE=""
PUBLIC_ENDPOINT=""
WG_POOL=""
WG_GATEWAY=""
WG_PORT="51820"
VLESS_DOMAIN=""
VLESS_PORT="443"
WAN_IFACE=""
REPO_ROOT=""
SKIP_VLESS="0"
SELF_SIGNED_TLS="0"

usage() {
  sed -n '2,20p' "$0"
  exit "${1:-0}"
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --node-id) NODE_ID="$2"; shift 2 ;;
    --node-code) NODE_CODE="$2"; shift 2 ;;
    --public-endpoint) PUBLIC_ENDPOINT="$2"; shift 2 ;;
    --wg-pool) WG_POOL="$2"; shift 2 ;;
    --wg-gateway) WG_GATEWAY="$2"; shift 2 ;;
    --wg-port) WG_PORT="$2"; shift 2 ;;
    --vless-domain) VLESS_DOMAIN="$2"; shift 2 ;;
    --vless-port) VLESS_PORT="$2"; shift 2 ;;
    --wan-iface) WAN_IFACE="$2"; shift 2 ;;
    --repo) REPO_ROOT="$2"; shift 2 ;;
    --skip-vless) SKIP_VLESS="1"; shift ;;
    --self-signed-tls) SELF_SIGNED_TLS="1"; shift ;;
    -h|--help) usage 0 ;;
    *) die "Unknown option: $1" ;;
  esac
done

require_root

[[ -n "$NODE_ID" ]] || die "--node-id is required"
[[ -n "$NODE_CODE" ]] || die "--node-code is required"
[[ -n "$PUBLIC_ENDPOINT" ]] || die "--public-endpoint is required"
[[ -n "$WG_POOL" ]] || die "--wg-pool is required"
[[ -n "$WG_GATEWAY" ]] || die "--wg-gateway is required"

if [[ -z "$VLESS_DOMAIN" && "$SKIP_VLESS" != "1" ]]; then
  VLESS_DOMAIN="$PUBLIC_ENDPOINT"
fi

if [[ -z "$WAN_IFACE" ]]; then
  WAN_IFACE="$(detect_wan_iface || true)"
  [[ -n "$WAN_IFACE" ]] || die "Could not detect WAN interface; pass --wan-iface"
fi

if [[ -z "$REPO_ROOT" ]]; then
  REPO_ROOT="$(detect_repo_root "$SCRIPT_DIR" || true)"
fi

log "Installing VPN node ${NODE_CODE} (id=${NODE_ID}) on $(hostname)"

install_apt_packages \
  wireguard wireguard-tools nftables iproute2 curl ca-certificates \
  python3 openssl unzip iptables

enable_ip_forward

mkdir -p /etc/wireguard /etc/vpn-platform /var/lib/vpn-platform
chmod 700 /etc/wireguard /etc/vpn-platform /var/lib/vpn-platform

if [[ ! -f /etc/wireguard/private.key ]]; then
  umask 077
  wg genkey | tee /etc/wireguard/private.key | wg pubkey > /etc/wireguard/public.key
  log "Generated WireGuard keys"
fi
WG_PUBKEY="$(cat /etc/wireguard/public.key)"

# wg0 gateway IP (required for client routing)
GATEWAY_CIDR="${WG_GATEWAY}/$(echo "$WG_POOL" | awk -F/ '{print $2}')"

cat > /etc/vpn-platform/node-agent.env << EOF
AGENT_NODE_ID=${NODE_ID}
AGENT_NODE_CODE=${NODE_CODE}
AGENT_HTTP_ADDR=0.0.0.0:8082
AGENT_WG_IFACE=wg0
AGENT_WG_KEY_PATH=/etc/wireguard/private.key
AGENT_WG_PORT=${WG_PORT}
AGENT_PUBLIC_ENDPOINT=${PUBLIC_ENDPOINT}:${WG_PORT}
AGENT_WAN_IFACE=${WAN_IFACE}
AGENT_AUTHORIZED_POOLS=${WG_POOL}
AGENT_MTLS_ENABLED=false
AGENT_LOG_LEVEL=info
AGENT_VERSION=1.0.0
EOF

if [[ "$SKIP_VLESS" != "1" ]]; then
  cat >> /etc/vpn-platform/node-agent.env << EOF
AGENT_VLESS_ENABLED=true
AGENT_VLESS_STORE_PATH=/var/lib/vpn-platform/vless-peers.json
AGENT_XRAY_CONFIG_PATH=/etc/xray/config.json
AGENT_XRAY_SYSTEMD_UNIT=xray-vless
EOF
fi
chmod 600 /etc/vpn-platform/node-agent.env

write_vpn_forward_script
write_xray_sync_script

if [[ -n "$REPO_ROOT" ]]; then
  install_node_agent_binary "$REPO_ROOT"
else
  warn "No --repo path; expecting /usr/local/bin/vpn-node-agent to exist already"
  [[ -x /usr/local/bin/vpn-node-agent ]] || die "vpn-node-agent binary not found"
fi

cat > /etc/systemd/system/vpn-node-agent.service << UNIT
[Unit]
Description=VPN Platform Node Agent
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
EnvironmentFile=/etc/vpn-platform/node-agent.env
ExecStartPre=/bin/sh -c 'ip link show wg0 >/dev/null 2>&1 || ip link add dev wg0 type wireguard'
ExecStartPre=/bin/sh -c 'ip addr show wg0 | grep -q "${WG_GATEWAY}/" || ip addr add ${GATEWAY_CIDR} dev wg0'
ExecStart=/usr/local/bin/vpn-node-agent
Restart=on-failure
RestartSec=5s
LimitNOFILE=65535
NoNewPrivileges=yes
ProtectHome=yes
PrivateTmp=yes

[Install]
WantedBy=multi-user.target
UNIT

mkdir -p /etc/systemd/system/vpn-node-agent.service.d
cat > /etc/systemd/system/vpn-node-agent.service.d/override.conf << UNIT
[Service]
ProtectSystem=strict
ReadWritePaths=/etc/xray /var/lib/vpn-platform /etc/wireguard
ExecStartPost=/etc/vpn-platform/ensure-vpn-forward.sh
UNIT

if [[ "$SKIP_VLESS" != "1" ]]; then
  log "Installing VLESS (Xray) on port ${VLESS_PORT}"
  bash "${SCRIPT_DIR}/install-xray-vless.sh" "$VLESS_DOMAIN" "$VLESS_PORT" || true

  if [[ "$SELF_SIGNED_TLS" == "1" ]] && [[ ! -f /etc/xray/cert.pem ]]; then
    log "Creating self-signed TLS cert for ${VLESS_DOMAIN}"
    openssl req -x509 -newkey rsa:2048 -keyout /etc/xray/key.pem -out /etc/xray/cert.pem \
      -days 3650 -nodes -subj "/CN=${VLESS_DOMAIN}" 2>/dev/null || \
    openssl req -x509 -newkey rsa:2048 -keyout /etc/xray/key.pem -out /etc/xray/cert.pem \
      -days 3650 -nodes -subj "/CN=${VLESS_DOMAIN}"
    chmod 600 /etc/xray/key.pem
    systemctl restart xray-vless
  fi
fi

ensure_vpn_forward_iptables "$WAN_IFACE" wg0 "$WG_POOL"

systemctl daemon-reload
systemctl enable vpn-node-agent
systemctl restart vpn-node-agent

if [[ "$SKIP_VLESS" != "1" ]]; then
  systemctl enable xray-vless 2>/dev/null || true
  systemctl restart xray-vless 2>/dev/null || true
  /etc/vpn-platform/sync-xray.sh 2>/dev/null || true
fi

log "=== VPN node install complete ==="
log "WireGuard public key: ${WG_PUBKEY}"
log "WireGuard endpoint:   ${PUBLIC_ENDPOINT}:${WG_PORT}"
log "Client pool:          ${WG_POOL} (gateway ${WG_GATEWAY})"
log "Node agent health:    http://$(hostname -I | awk '{print $1}'):8082/internal/v1/health"
if [[ "$SKIP_VLESS" != "1" ]]; then
  log "VLESS endpoint:       ${VLESS_DOMAIN}:${VLESS_PORT}"
fi
log ""
log "Register in CMS (vpn_nodes) with:"
log "  public_key=${WG_PUBKEY}"
log "  agent_endpoint=http://$(curl -fsSL https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}'):8082"
log "  supported_protocols=[wireguard,vless]  vless_port=${VLESS_PORT}"
