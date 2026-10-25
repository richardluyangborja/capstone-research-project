#!/usr/bin/env bash
#
# Start the whole local stack in the background:
#   Postgres (docker compose) -> Laravel API -> AI service -> Vite frontend.
#
# PIDs and logs live in .run/ so ./down.sh and ./status.sh can manage the stack.
# Usage: ./up.sh

set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
RUN_DIR="$ROOT_DIR/.run"
LOG_DIR="$RUN_DIR/logs"
mkdir -p "$LOG_DIR"
cd "$ROOT_DIR"

info() {
  printf '%s\n' "$*"
}

fail() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || fail "Missing required command: $1"
}

command_matches() {
  local cmd="$1"
  local pattern="$2"
  [[ -n "$cmd" && "$cmd" =~ $pattern ]]
}

pid_is_alive() {
  local pid="$1"
  [[ "$pid" =~ ^[0-9]+$ ]] && kill -0 "$pid" 2>/dev/null
}

tracked_is_running() {
  local pidfile="$1"
  local pattern="$2"
  local pid cmd

  [[ -f "$pidfile" ]] || return 1
  pid="$(tr -d '[:space:]' < "$pidfile")"
  pid_is_alive "$pid" || return 1
  cmd="$(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null || true)"
  command_matches "$cmd" "$pattern"
}

port_in_use() {
  local port="$1"
  ss -ltn 2>/dev/null | awk 'NR > 1 { print $4 }' | grep -Eq "[:.]${port}$"
}

start_service() {
  local name="$1"
  local pattern="$2"
  local port="$3"
  local workdir="$4"
  local log="$5"
  shift 5

  local pidfile="$RUN_DIR/$name.pid"

  if tracked_is_running "$pidfile" "$pattern"; then
    info "$name is already running (pid $(cat "$pidfile"))."
    return 0
  fi

  rm -f "$pidfile"

  if port_in_use "$port"; then
    fail "$name cannot start: port $port is already in use by an untracked process. Run ./down.sh first."
  fi

  : > "$log"
  info "Starting $name..."

  # setsid gives the service its own session/process group. The inner shell writes its PID
  # after setsid has detached, then execs the real command so that PID stays the group leader.
  setsid bash -c 'pidfile=$1; workdir=$2; shift 2; cd "$workdir" || exit 1; echo $$ > "$pidfile"; exec "$@"' \
    _ "$pidfile" "$workdir" "$@" >"$log" 2>&1 < /dev/null &

  local attempt
  for attempt in {1..30}; do
    [[ -s "$pidfile" ]] && break
    sleep 0.1
  done

  if [[ ! -s "$pidfile" ]]; then
    tail -n 30 "$log" >&2 || true
    fail "$name did not start (no PID file). See $log."
  fi

  local pid
  pid="$(tr -d '[:space:]' < "$pidfile")"

  sleep 0.2
  if ! pid_is_alive "$pid"; then
    tail -n 30 "$log" >&2 || true
    fail "$name exited immediately. See $log."
  fi

  info "$name started (pid $pid). Log: $log"
}

wait_for_http() {
  local name="$1"
  local url="$2"
  local timeout="${3:-30}"
  local deadline=$((SECONDS + timeout))

  while (( SECONDS < deadline )); do
    if curl -fsS --max-time 2 -o /dev/null "$url" 2>/dev/null; then
      info "$name is ready: $url"
      return 0
    fi
    sleep 0.5
  done

  return 1
}

# --- preflight -----------------------------------------------------------------
require_cmd docker
require_cmd php
require_cmd npm
require_cmd curl
require_cmd ss
require_cmd setsid
require_cmd bash

docker compose version >/dev/null 2>&1 || fail "Docker Compose is not available."

[[ -d "$ROOT_DIR/backend/vendor" ]] || fail "backend/vendor is missing. Run: cd backend && composer install"
[[ -d "$ROOT_DIR/frontend/node_modules" ]] || fail "frontend/node_modules is missing. Run: cd frontend && npm install"

if [[ ! -f "$ROOT_DIR/ai/.env" ]]; then
  info "Warning: ai/.env is missing; AI reports will use the rules fallback until it is configured."
fi

AI_COMMAND=()
if [[ -x "$ROOT_DIR/ai/.venv/bin/uvicorn" ]]; then
  AI_COMMAND=("$ROOT_DIR/ai/.venv/bin/uvicorn" app.main:app --host 127.0.0.1 --port 8001)
elif command -v uvicorn >/dev/null 2>&1; then
  AI_COMMAND=(uvicorn app.main:app --host 127.0.0.1 --port 8001)
else
  fail "uvicorn is missing. Run: cd ai && python3 -m venv .venv && .venv/bin/pip install -r requirements-dev.txt"
fi

# --- database ------------------------------------------------------------------
info "Starting Postgres..."
docker compose up -d --wait db

# --- application services ------------------------------------------------------
start_service \
  "backend" \
  'artisan serve|php -S 127\.0\.0\.1:8000' \
  8000 \
  "$ROOT_DIR/backend" \
  "$LOG_DIR/backend.log" \
  php artisan serve --host=127.0.0.1 --port=8000

if ! wait_for_http "Backend" "http://127.0.0.1:8000/api/v1/health" 45; then
  tail -n 40 "$LOG_DIR/backend.log" >&2 || true
  fail "Backend did not become healthy. See $LOG_DIR/backend.log. Run ./down.sh to stop partial services."
fi

start_service \
  "ai" \
  'uvicorn app\.main:app' \
  8001 \
  "$ROOT_DIR/ai" \
  "$LOG_DIR/ai.log" \
  "${AI_COMMAND[@]}"

if ! wait_for_http "AI service" "http://127.0.0.1:8001/health" 30; then
  tail -n 40 "$LOG_DIR/ai.log" >&2 || true
  fail "AI service did not become healthy. See $LOG_DIR/ai.log. Run ./down.sh to stop partial services."
fi

start_service \
  "frontend" \
  'npm run dev|node .*vite' \
  5173 \
  "$ROOT_DIR/frontend" \
  "$LOG_DIR/frontend.log" \
  npm run dev -- --host 127.0.0.1 --port 5173 --strictPort

if ! wait_for_http "Frontend" "http://127.0.0.1:5173/" 30; then
  tail -n 40 "$LOG_DIR/frontend.log" >&2 || true
  fail "Frontend did not become ready. See $LOG_DIR/frontend.log. Run ./down.sh to stop partial services."
fi

info ""
bash "$ROOT_DIR/status.sh"
