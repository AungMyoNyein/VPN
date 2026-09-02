#!/usr/bin/env bash
# Install Xray-core VLESS inbound on a VPN node (Ubuntu).
# Called by install-vpn-node.sh or standalone:
#   sudo ./install-xray-vless.sh vps10.zentunnel.net 443

set -euo pipefail

DOMAIN="${1:-zentunnel.net}"
VLESS_PORT="${2:-443}"
INSTALL_DIR="/usr/local/bin"
CONFIG_DIR="/etc/xray"
DATA_DIR="/var/lib/vpn-platform"

echo "=== Installing Xray for VLESS ==="

if ! command -v xray >/dev/null 2>&1; then
  bash -c "$(curl -fsSL https://github.com/XTLS/Xray-install/raw/main/install-release.sh)" @ install
fi
mkdir -p "$CONFIG_DIR" "$DATA_DIR"
chmod 700 "$CONFIG_DIR"

if [[ ! -f "$CONFIG_DIR/cert.pem" ]]; then
  echo "=== Linking Let's Encrypt certs ==="
  if [[ -f "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" ]]; then
    ln -sf "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" "$CONFIG_DIR/cert.pem"
    ln -sf "/etc/letsencrypt/live/${DOMAIN}/privkey.pem" "$CONFIG_DIR/key.pem"
  else
    echo "WARN: No TLS cert at /etc/letsencrypt/live/${DOMAIN}/ — generate with certbot first"
  fi
fi

cat > "$CONFIG_DIR/config.json" << EOF
{
  "log": { "loglevel": "warning" },
  "inbounds": [
    {
      "tag": "vless-in",
      "port": ${VLESS_PORT},
      "protocol": "vless",
      "settings": {
        "clients": [],
        "decryption": "none"
      },
      "streamSettings": {
        "network": "tcp",
        "security": "tls",
        "tlsSettings": {
          "certificates": [
            {
              "certificateFile": "${CONFIG_DIR}/cert.pem",
              "keyFile": "${CONFIG_DIR}/key.pem"
            }
          ],
          "alpn": ["h2", "http/1.1"]
        }
      },
      "sniffing": {
        "enabled": true,
        "destOverride": ["http", "tls"]
      }
    }
  ],
  "outbounds": [
    { "protocol": "freedom", "tag": "direct" },
    { "protocol": "blackhole", "tag": "blocked" }
  ]
}
EOF

cat > /etc/systemd/system/xray-vless.service << EOF
[Unit]
Description=Xray VLESS Server
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=${INSTALL_DIR}/xray run -config ${CONFIG_DIR}/config.json
Restart=on-failure
RestartSec=3
LimitNOFILE=65535

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable xray-vless
systemctl restart xray-vless

echo "=== Xray VLESS listening on :${VLESS_PORT} for ${DOMAIN} ==="
systemctl is-active xray-vless
echo "Node agent manages VLESS users at ${DATA_DIR}/vless-peers.json"
