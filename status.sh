#!/usr/bin/env bash
#
# Check whether every local service is up and healthy.
# Exits 0 only when Postgres, the Laravel API, the AI service and the frontend are all reachable.
#
# Usage: ./status.sh

set -u

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

FAILED=0

info() {
  printf '%s\n' "$*"
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    printf 'ERROR: Missing required command: %s\n' "$1" >&2
    exit 1
  }
}

check_http() {
  local name="$1"
  local url="$2"
  local code

  code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 3 "$url" 2>/dev/null || true)"

  if [[ "$code" == "200" ]]; then
    printf '  %-10s UP      %s\n' "$name" "$url"
  else
    printf '  %-10s DOWN    %s (HTTP %s)\n' "$name" "$url" "${code:-000}"
    FAILED=1
  fi
}

check_database() {
  local cid health

  cid="$(docker compose ps -q db 2>/dev/null || true)"

  if [[ -z "$cid" ]]; then
    printf '  %-10s DOWN    postgres (container not running)\n' "Database"
    FAILED=1
    return
  fi

  health="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$cid" 2>/dev/null || true)"

  if [[ "$health" == "healthy" || "$health" == "running" ]]; then
    printf '  %-10s UP      postgres (%s)\n' "Database" "$health"
  else
    printf '  %-10s DOWN    postgres (%s)\n' "Database" "${health:-unknown}"
    FAILED=1
  fi
}

require_cmd curl
require_cmd docker

info "Local stack status"
info "------------------"
check_database
check_http "Backend" "http://127.0.0.1:8000/api/v1/health"
check_http "AI" "http://127.0.0.1:8001/health"
check_http "Frontend" "http://127.0.0.1:5173/"
info "------------------"

if (( FAILED == 0 )); then
  info "All services are up."
else
  info "Some services are down. Logs: .run/logs/"
fi

exit "$FAILED"
