#!/usr/bin/env bash
# Install API + CMS control server (Laravel API, React CRM, Control Plane, Postgres, Redis).
# Does NOT install VPN data plane (WireGuard/Xray) — use install-vpn-node.sh on edge nodes.
#
# Usage (as root on API/CMS host):
#   ./install-api-cms-server.sh \
#     --domain zentunnel.net \
#     --repo /root/VPN \
#     --db-password 'strong-db-password' \
#     --admin-email admin@example.com \
#     --admin-password 'ChangeMe!'
#
# Options:
#   --domain            Public FQDN (e.g. zentunnel.net)
#   --repo              Path to VPN git checkout
#   --db-password       PostgreSQL password for user vpn
#   --redis-password    Optional Redis password (default: none)
#   --cp-token          Control plane service token
#   --admin-email       Bootstrap CRM admin email
#   --admin-password    Bootstrap CRM admin password
#   --skip-ssl          Skip certbot (nginx HTTP only)
#   --skip-crm-build    Skip npm build (CRM already built)
#   -h|--help

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

DOMAIN=""
REPO_ROOT=""
DB_PASSWORD=""
REDIS_PASSWORD=""
CP_TOKEN=""
ADMIN_EMAIL="admin@vpn.local"
ADMIN_PASSWORD="ChangeMe_LocalOnly_1!"
SKIP_SSL="0"
SKIP_CRM_BUILD="0"

usage() {
  sed -n '2,22p' "$0"
  exit "${1:-0}"
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --domain) DOMAIN="$2"; shift 2 ;;
    --repo) REPO_ROOT="$2"; shift 2 ;;
    --db-password) DB_PASSWORD="$2"; shift 2 ;;
    --redis-password) REDIS_PASSWORD="$2"; shift 2 ;;
    --cp-token) CP_TOKEN="$2"; shift 2 ;;
    --admin-email) ADMIN_EMAIL="$2"; shift 2 ;;
    --admin-password) ADMIN_PASSWORD="$2"; shift 2 ;;
    --skip-ssl) SKIP_SSL="1"; shift ;;
    --skip-crm-build) SKIP_CRM_BUILD="1"; shift ;;
    -h|--help) usage 0 ;;
    *) die "Unknown option: $1" ;;
  esac
done

require_root
[[ -n "$DOMAIN" ]] || die "--domain is required"
[[ -n "$DB_PASSWORD" ]] || die "--db-password is required"

if [[ -z "$REPO_ROOT" ]]; then
  REPO_ROOT="$(detect_repo_root "$SCRIPT_DIR" || true)"
fi
[[ -n "$REPO_ROOT" && -f "${REPO_ROOT}/backend/artisan" ]] || die "--repo must point to VPN monorepo root"

if [[ -z "$CP_TOKEN" ]]; then
  CP_TOKEN="$(openssl rand -hex 24)"
  warn "Generated CONTROL_PLANE_SERVICE_TOKEN: ${CP_TOKEN}"
fi

INSTALL_ROOT="${VPN_INSTALL_ROOT:-/opt/vpn-platform}"
BACKEND_DIR="${INSTALL_ROOT}/backend"
CRM_DIR="${INSTALL_ROOT}/crm"
COMPOSE_DIR="${INSTALL_ROOT}/infrastructure/docker"

log "Installing API + CMS on ${DOMAIN} (repo: ${REPO_ROOT})"

install_apt_packages \
  ca-certificates curl gnupg lsb-release \
  nginx certbot python3-certbot-nginx \
  php8.3-cli php8.3-pgsql php8.3-redis php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath \
  composer git rsync nodejs npm

# Docker
if ! command -v docker >/dev/null 2>&1; then
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
    > /etc/apt/sources.list.d/docker.list
  install_apt_packages docker-ce docker-ce-cli containerd.io docker-compose-plugin
fi

mkdir -p "$INSTALL_ROOT"
if [[ "$(realpath "$REPO_ROOT")" != "$(realpath "$INSTALL_ROOT")" ]]; then
  log "Syncing repository to ${INSTALL_ROOT}"
  rsync -a --delete \
    --exclude node_modules --exclude vendor --exclude .git --exclude mobile/build \
    "${REPO_ROOT}/" "${INSTALL_ROOT}/"
fi

mkdir -p /etc/vpn-platform
cat > /etc/vpn-platform/api.env << EOF
DOMAIN=${DOMAIN}
INSTALL_ROOT=${INSTALL_ROOT}
DB_PASSWORD=${DB_PASSWORD}
REDIS_PASSWORD=${REDIS_PASSWORD}
CP_TOKEN=${CP_TOKEN}
ADMIN_EMAIL=${ADMIN_EMAIL}
ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOF
chmod 600 /etc/vpn-platform/api.env

# Docker stack: postgres, redis, control-plane (no VPN node-agent)
log "Starting Docker services (postgres, redis, control-plane)"
cd "$COMPOSE_DIR"

export POSTGRES_PASSWORD="$DB_PASSWORD"
cat > docker-compose.prod.yml << YAML
services:
  postgres:
    image: postgres:16-alpine
    restart: unless-stopped
    environment:
      POSTGRES_DB: vpn
      POSTGRES_USER: vpn
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    ports:
      - "127.0.0.1:15432:5432"
    volumes:
      - vpn_postgres_data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U vpn -d vpn"]
      interval: 5s
      timeout: 5s
      retries: 10

  redis:
    image: redis:7-alpine
    restart: unless-stopped
    ports:
      - "127.0.0.1:16379:6379"
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      timeout: 3s
      retries: 10

  control-plane:
    build:
      context: ../../control-plane
      dockerfile: ../infrastructure/docker/Dockerfile.control-plane
    restart: unless-stopped
    environment:
      CP_ENV: production
      CP_HTTP_ADDR: ":8081"
      CP_SERVICE_TOKEN: ${CP_TOKEN}
    ports:
      - "127.0.0.1:8081:8081"

volumes:
  vpn_postgres_data:
YAML

docker compose -f docker-compose.prod.yml up -d --build

log "Waiting for Postgres"
for _ in $(seq 1 30); do
  docker compose -f docker-compose.prod.yml exec -T postgres pg_isready -U vpn -d vpn >/dev/null 2>&1 && break
  sleep 2
done

# Laravel backend
cd "$BACKEND_DIR"
if [[ ! -f .env ]]; then
  cp .env.example .env
fi

php artisan key:generate --force --no-interaction 2>/dev/null || true

# shellcheck disable=SC2016
grep -q '^APP_URL=' .env && sed -i "s|^APP_URL=.*|APP_URL=https://${DOMAIN}|" .env || echo "APP_URL=https://${DOMAIN}" >> .env
grep -q '^APP_ENV=' .env && sed -i 's|^APP_ENV=.*|APP_ENV=production|' .env || echo 'APP_ENV=production' >> .env
grep -q '^APP_DEBUG=' .env && sed -i 's|^APP_DEBUG=.*|APP_DEBUG=false|' .env || echo 'APP_DEBUG=false' >> .env

grep -q '^DB_CONNECTION=' .env && sed -i 's|^DB_CONNECTION=.*|DB_CONNECTION=pgsql|' .env
grep -q '^DB_HOST=' .env && sed -i 's|^DB_HOST=.*|DB_HOST=127.0.0.1|' .env || echo 'DB_HOST=127.0.0.1' >> .env
grep -q '^DB_PORT=' .env && sed -i 's|^DB_PORT=.*|DB_PORT=15432|' .env || echo 'DB_PORT=15432' >> .env
grep -q '^DB_DATABASE=' .env && sed -i 's|^DB_DATABASE=.*|DB_DATABASE=vpn|' .env
grep -q '^DB_USERNAME=' .env && sed -i 's|^DB_USERNAME=.*|DB_USERNAME=vpn|' .env
grep -q '^DB_PASSWORD=' .env && sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD}|" .env || echo "DB_PASSWORD=${DB_PASSWORD}" >> .env

grep -q '^REDIS_HOST=' .env && sed -i 's|^REDIS_HOST=.*|REDIS_HOST=127.0.0.1|' .env || echo 'REDIS_HOST=127.0.0.1' >> .env
grep -q '^REDIS_PORT=' .env && sed -i 's|^REDIS_PORT=.*|REDIS_PORT=16379|' .env || echo 'REDIS_PORT=16379' >> .env

grep -q '^QUEUE_CONNECTION=' .env && sed -i 's|^QUEUE_CONNECTION=.*|QUEUE_CONNECTION=redis|' .env
grep -q '^CACHE_STORE=' .env && sed -i 's|^CACHE_STORE=.*|CACHE_STORE=redis|' .env
grep -q '^SESSION_DRIVER=' .env && sed -i 's|^SESSION_DRIVER=.*|SESSION_DRIVER=redis|' .env

grep -q '^CONTROL_PLANE_BASE_URL=' .env \
  && sed -i 's|^CONTROL_PLANE_BASE_URL=.*|CONTROL_PLANE_BASE_URL=http://127.0.0.1:8081|' .env \
  || echo 'CONTROL_PLANE_BASE_URL=http://127.0.0.1:8081' >> .env
grep -q '^CONTROL_PLANE_SERVICE_TOKEN=' .env \
  && sed -i "s|^CONTROL_PLANE_SERVICE_TOKEN=.*|CONTROL_PLANE_SERVICE_TOKEN=${CP_TOKEN}|" .env \
  || echo "CONTROL_PLANE_SERVICE_TOKEN=${CP_TOKEN}" >> .env

grep -q '^ADMIN_EMAIL=' .env && sed -i "s|^ADMIN_EMAIL=.*|ADMIN_EMAIL=${ADMIN_EMAIL}|" .env || echo "ADMIN_EMAIL=${ADMIN_EMAIL}" >> .env
grep -q '^ADMIN_PASSWORD=' .env && sed -i "s|^ADMIN_PASSWORD=.*|ADMIN_PASSWORD=${ADMIN_PASSWORD}|" .env || echo "ADMIN_PASSWORD=${ADMIN_PASSWORD}" >> .env

composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction 2>/dev/null || php artisan migrate --force --no-interaction

# CRM static build
CRM_WEB_ROOT="/var/www/vpn-crm"
mkdir -p "$CRM_WEB_ROOT"
if [[ "$SKIP_CRM_BUILD" != "1" ]]; then
  require_cmd npm
  log "Building CRM"
  cd "$CRM_DIR"
  npm ci --no-audit --no-fund
  npm run build
  rsync -a dist/ "$CRM_WEB_ROOT/"
else
  log "Skipping CRM build (--skip-crm-build)"
fi

# systemd API service
cat > /etc/systemd/system/vpn-api.service << UNIT
[Unit]
Description=VPN Platform Laravel API
After=network-online.target docker.service
Wants=docker.service network-online.target

[Service]
Type=simple
User=root
WorkingDirectory=${BACKEND_DIR}
Environment=APP_ENV=production
ExecStart=/usr/bin/php artisan serve --host=127.0.0.1 --port=8000
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable vpn-api
systemctl restart vpn-api

cat > /etc/systemd/system/vpn-queue.service << UNIT
[Unit]
Description=VPN Platform Laravel Queue Worker
After=vpn-api.service docker.service
Wants=docker.service

[Service]
Type=simple
User=root
WorkingDirectory=${BACKEND_DIR}
Environment=APP_ENV=production
ExecStart=/usr/bin/php artisan queue:work redis --sleep=1 --tries=3 --max-time=3600
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable vpn-queue
systemctl restart vpn-queue

# Nginx
cat > /etc/nginx/sites-available/vpn-platform << NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};

    location /api/ {
        proxy_pass http://127.0.0.1:8000;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }

    location / {
        root ${CRM_WEB_ROOT};
        try_files \$uri \$uri/ /index.html;
    }
}
NGINX

ln -sf /etc/nginx/sites-available/vpn-platform /etc/nginx/sites-enabled/vpn-platform
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

if [[ "$SKIP_SSL" != "1" ]]; then
  certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$ADMIN_EMAIL" --redirect || \
    warn "certbot failed — configure TLS manually"
fi

log "=== API + CMS install complete ==="
log "API health:  https://${DOMAIN}/api/v1/health"
log "CRM:         https://${DOMAIN}/"
log "Admin login: ${ADMIN_EMAIL}"
log "Control plane token saved in ${BACKEND_DIR}/.env and /etc/vpn-platform/api.env"
log ""
log "Register VPN nodes after CMS entry:"
log "  ${INSTALL_ROOT}/infrastructure/scripts/register-remote-node.sh --node-id <id> --endpoint http://<node-ip>:8082"
