#!/usr/bin/env bash
# Shared helpers for VPN platform install scripts.

set -euo pipefail

log()  { printf '[vpn-platform] %s\n' "$*"; }
warn() { printf '[vpn-platform] WARN: %s\n' "$*" >&2; }
die()  { printf '[vpn-platform] ERROR: %s\n' "$*" >&2; exit 1; }

require_root() {
  if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    die "Run as root (sudo)."
  fi
}

require_cmd() {
  local cmd="$1"
  command -v "$cmd" >/dev/null 2>&1 || die "Missing required command: $cmd"
}

detect_wan_iface() {
  ip route show default 2>/dev/null | awk '{print $5; exit}'
}

detect_repo_root() {
  local script_dir="$1"
  local candidate
  candidate="$(cd "${script_dir}/../.." && pwd)"
  if [[ -f "${candidate}/node-agent/go.mod" && -f "${candidate}/backend/artisan" ]]; then
    printf '%s' "${candidate}"
    return 0
  fi
  if [[ -n "${VPN_REPO_ROOT:-}" && -f "${VPN_REPO_ROOT}/node-agent/go.mod" ]]; then
    printf '%s' "${VPN_REPO_ROOT}"
    return 0
  fi
  return 1
}

install_apt_packages() {
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  apt-get install -y -qq "$@"
}

enable_ip_forward() {
  install_apt_packages procps
  cat > /etc/sysctl.d/99-vpn-platform.conf << 'EOF'
net.ipv4.ip_forward = 1
net.ipv4.conf.all.forwarding = 1
EOF
  sysctl --system >/dev/null 2>&1 || sysctl -p /etc/sysctl.d/99-vpn-platform.conf
}

ensure_vpn_forward_iptables() {
  local wan="${1:?wan iface}"
  local wg="${2:-wg0}"
  local pool="${3:?pool cidr}"

  install_apt_packages iptables

  iptables -C FORWARD -i "$wg" -o "$wan" -j ACCEPT 2>/dev/null \
    || iptables -I FORWARD 1 -i "$wg" -o "$wan" -j ACCEPT
  iptables -C FORWARD -i "$wan" -o "$wg" -m state --state RELATED,ESTABLISHED -j ACCEPT 2>/dev/null \
    || iptables -I FORWARD 2 -i "$wan" -o "$wg" -m state --state RELATED,ESTABLISHED -j ACCEPT
  iptables -t nat -C POSTROUTING -s "$pool" -o "$wan" -j MASQUERADE 2>/dev/null \
    || iptables -t nat -A POSTROUTING -s "$pool" -o "$wan" -j MASQUERADE
}

write_vpn_forward_script() {
  cat > /etc/vpn-platform/ensure-vpn-forward.sh << 'SCRIPT'
#!/bin/bash
set -euo pipefail
# shellcheck disable=SC1091
source /etc/vpn-platform/node-agent.env
WAN="${AGENT_WAN_IFACE:-eth0}"
WG="${AGENT_WG_IFACE:-wg0}"
POOL="${AGENT_AUTHORIZED_POOLS:-10.200.0.0/16}"
POOL="${POOL%%,*}"
iptables -C FORWARD -i "$WG" -o "$WAN" -j ACCEPT 2>/dev/null \
  || iptables -I FORWARD 1 -i "$WG" -o "$WAN" -j ACCEPT
iptables -C FORWARD -i "$WAN" -o "$WG" -m state --state RELATED,ESTABLISHED -j ACCEPT 2>/dev/null \
  || iptables -I FORWARD 2 -i "$WAN" -o "$WG" -m state --state RELATED,ESTABLISHED -j ACCEPT
iptables -t nat -C POSTROUTING -s "$POOL" -o "$WAN" -j MASQUERADE 2>/dev/null \
  || iptables -t nat -A POSTROUTING -s "$POOL" -o "$WAN" -j MASQUERADE
SCRIPT
  chmod +x /etc/vpn-platform/ensure-vpn-forward.sh
}

write_xray_sync_script() {
  cat > /etc/vpn-platform/sync-xray.sh << 'SCRIPT'
#!/bin/bash
set -euo pipefail
PEERS="${AGENT_VLESS_STORE_PATH:-/var/lib/vpn-platform/vless-peers.json}"
CONFIG="${AGENT_XRAY_CONFIG_PATH:-/etc/xray/config.json}"
UNIT="${AGENT_XRAY_SYSTEMD_UNIT:-xray-vless}"
[[ -f "$PEERS" && -f "$CONFIG" ]] || exit 0
python3 << PY
import json, os
peers = json.load(open(os.environ.get("PEERS", "$PEERS")))
cfg = json.load(open(os.environ.get("CONFIG", "$CONFIG")))
clients = [{"id": p["client_uuid"], "email": p.get("email", p["peer_id"] + "@vpn-platform")} for p in peers]
for ib in cfg.get("inbounds", []):
    if ib.get("protocol") == "vless":
        ib.setdefault("settings", {})["clients"] = clients
        ib["settings"]["decryption"] = "none"
json.dump(cfg, open(os.environ.get("CONFIG", "$CONFIG"), "w"), indent=2)
print(f"synced {len(clients)} vless clients")
PY
systemctl restart "$UNIT"
SCRIPT
  chmod +x /etc/vpn-platform/sync-xray.sh
}

install_node_agent_binary() {
  local repo_root="$1"
  local dockerfile="${repo_root}/infrastructure/docker/Dockerfile.node-agent"

  if [[ -x /usr/local/bin/vpn-node-agent ]]; then
    log "vpn-node-agent already installed"
    return 0
  fi

  if [[ ! -f "$dockerfile" ]]; then
    die "Cannot build node-agent: missing ${dockerfile}"
  fi

  require_cmd docker
  log "Building node-agent from ${repo_root}/node-agent"
  docker build -f "$dockerfile" -t vpn-node-agent:build "${repo_root}/node-agent" -q
  docker rm -f vpn-node-agent-extract >/dev/null 2>&1 || true
  docker create --name vpn-node-agent-extract vpn-node-agent:build >/dev/null
  docker cp vpn-node-agent-extract:/usr/local/bin/node-agent /usr/local/bin/vpn-node-agent
  docker rm vpn-node-agent-extract >/dev/null
  chmod +x /usr/local/bin/vpn-node-agent
  log "Installed /usr/local/bin/vpn-node-agent"
}
