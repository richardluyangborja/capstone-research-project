#!/usr/bin/env bash
#
# Stop the whole local stack and remove every background process started by ./up.sh.
# Tracked PIDs live in .run/; the port sweep is a safety net for orphaned children.
#
# Usage: ./down.sh

set -u

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
RUN_DIR="$ROOT_DIR/.run"
cd "$ROOT_DIR"

FAILED=0

info() {
  printf '%s\n' "$*"
}

warn() {
  printf 'WARNING: %s\n' "$*" >&2
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

kill_group() {
  local group="$1"
  local attempt

  kill -TERM -- "-$group" 2>/dev/null || true

  for attempt in {1..20}; do
    kill -0 -- "-$group" 2>/dev/null || return 0
    sleep 0.25
  done

  kill -KILL -- "-$group" 2>/dev/null || true
}

kill_tree() {
  local pid="$1"
  local pattern="$2"
  local pgid=""
  local sid=""
  local own_pgid=""
  local leader_cmd=""
  local attempt

  pid_is_alive "$pid" || return 0
  pgid="$(ps -o pgid= -p "$pid" 2>/dev/null | tr -d '[:space:]')"
  sid="$(ps -o sid= -p "$pid" 2>/dev/null | tr -d '[:space:]')"
  own_pgid="$(ps -o pgid= -p $$ 2>/dev/null | tr -d '[:space:]')"

  # A tracked service is a session leader (setsid), so kill its whole group.
  if [[ -n "$pgid" && "$pgid" == "$pid" ]]; then
    kill_group "$pgid"
    return 0
  fi

  # A listener may be a child (php -S, vite) whose group leader is the true service
  # process. Kill the group only when it is its own session and the leader is ours.
  if [[ -n "$pgid" && "$pgid" == "$sid" && "$pgid" != "$own_pgid" ]]; then
    leader_cmd="$(tr '\0' ' ' < "/proc/$pgid/cmdline" 2>/dev/null || true)"
    if [[ -z "$leader_cmd" ]] || command_matches "$leader_cmd" "$pattern"; then
      kill_group "$pgid"
      return 0
    fi
  fi

  kill -TERM "$pid" 2>/dev/null || true

  for attempt in {1..20}; do
    pid_is_alive "$pid" || return 0
    sleep 0.25
  done

  kill -KILL "$pid" 2>/dev/null || true
}

stop_tracked() {
  local name="$1"
  local pattern="$2"
  local pidfile="$RUN_DIR/$name.pid"
  local pid cmd

  if [[ ! -f "$pidfile" ]]; then
    info "$name: not running (no PID file)."
    return 0
  fi

  pid="$(tr -d '[:space:]' < "$pidfile")"

  if ! pid_is_alive "$pid"; then
    info "$name: stale PID file removed."
    rm -f "$pidfile"
    return 0
  fi

  cmd="$(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null || true)"

  if ! command_matches "$cmd" "$pattern"; then
    warn "$name: PID $pid does not match '$pattern'; leaving it alone."
    rm -f "$pidfile"
    return 0
  fi

  info "$name: stopping (pid $pid)..."
  kill_tree "$pid" "$pattern"
  rm -f "$pidfile"
  info "$name: stopped."
}

listeners_on_port() {
  local port="$1"
  ss -ltnp 2>/dev/null \
    | sed -n "s/.*:${port} .*pid=\([0-9][0-9]*\).*/\1/p" \
    | sort -u
}

cleanup_port() {
  local port="$1"
  local name="$2"
  local pattern="$3"
  local pid cmd

  while IFS= read -r pid; do
    [[ -n "$pid" ]] || continue
    pid_is_alive "$pid" || continue

    cmd="$(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null || true)"

    if ! command_matches "$cmd" "$pattern"; then
      warn "port $port is held by an unrelated process (pid $pid); not stopping it."
      continue
    fi

    info "$name: stopping leftover process on port $port (pid $pid)..."
    kill_tree "$pid" "$pattern"
  done < <(listeners_on_port "$port")
}

port_in_use() {
  local port="$1"
  ss -ltn 2>/dev/null | awk 'NR > 1 { print $4 }' | grep -Eq "[:.]${port}$"
}

# --- application processes -----------------------------------------------------
stop_tracked "frontend" 'npm run dev|node .*vite'
stop_tracked "backend" 'artisan serve|php -S 127\.0\.0\.1:8000'
stop_tracked "ai" 'uvicorn app\.main:app'

# Safety net for children that escaped a PID file or an older manual start.
cleanup_port 5173 "frontend" 'vite'
cleanup_port 8000 "backend" 'artisan serve|php -S 127\.0\.0\.1:8000'
cleanup_port 8001 "ai" 'uvicorn app\.main:app'

for port in 5173 8000 8001; do
  if port_in_use "$port"; then
    warn "port $port is still in use after cleanup."
    FAILED=1
  fi
done

# --- database ------------------------------------------------------------------
if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
  info "Stopping Postgres (docker compose)..."
  if ! docker compose down --remove-orphans; then
    warn "docker compose down failed."
    FAILED=1
  fi
else
  warn "Docker Compose is unavailable; skipped the database stop."
  FAILED=1
fi

rm -f "$RUN_DIR"/*.pid 2>/dev/null || true

if (( FAILED == 0 )); then
  info "All project services are stopped."
else
  warn "Some services could not be stopped cleanly."
fi

exit "$FAILED"
