#!/usr/bin/env bash
# Register a remote VPN node with the control plane (run on API/CMS host).
#
# Usage:
#   ./register-remote-node.sh --node-id 5 --endpoint http://119.10.138.179:8082
#   ./register-remote-node.sh --node-id 1 --endpoint http://157.85.104.187:8082 --token "$CP_TOKEN"

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

NODE_ID=""
ENDPOINT=""
CP_TOKEN=""
CP_URL="http://127.0.0.1:8081"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --node-id) NODE_ID="$2"; shift 2 ;;
    --endpoint) ENDPOINT="$2"; shift 2 ;;
    --token) CP_TOKEN="$2"; shift 2 ;;
    --cp-url) CP_URL="$2"; shift 2 ;;
    -h|--help)
      sed -n '2,6p' "$0"
      exit 0
      ;;
    *) die "Unknown option: $1" ;;
  esac
done

[[ -n "$NODE_ID" ]] || die "--node-id is required"
[[ -n "$ENDPOINT" ]] || die "--endpoint is required"

if [[ -z "$CP_TOKEN" ]]; then
  if [[ -f /etc/vpn-platform/api.env ]]; then
    # shellcheck disable=SC1091
    source /etc/vpn-platform/api.env
    CP_TOKEN="${CP_TOKEN:-}"
  fi
  if [[ -z "$CP_TOKEN" && -f /opt/vpn-platform/backend/.env ]]; then
    CP_TOKEN="$(grep '^CONTROL_PLANE_SERVICE_TOKEN=' /opt/vpn-platform/backend/.env | cut -d= -f2- | tr -d '"')"
  fi
fi
[[ -n "$CP_TOKEN" ]] || die "--token is required (or set in api.env / backend .env)"

require_cmd curl

log "Registering node ${NODE_ID} at ${ENDPOINT} with control plane ${CP_URL}"

curl -fsS -X POST "${CP_URL}/internal/v1/nodes/register" \
  -H "Authorization: Bearer ${CP_TOKEN}" \
  -H "Content-Type: application/json" \
  -d "{\"node_id\":\"${NODE_ID}\",\"endpoint\":\"${ENDPOINT}\",\"adapter_type\":\"remote\"}"

printf '\n'
log "Done. Verify: curl -s ${CP_URL}/internal/v1/nodes/${NODE_ID}/health -H \"Authorization: Bearer ...\""
