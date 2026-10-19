#!/usr/bin/env bash
set -euo pipefail

# Defaults are production paths; overrides exist only for isolated verification.
ROOT_DIR="${BOATRACE_ROOT_DIR:-/var/www/html/boatrace}"
LOG_DIR="${BOATRACE_LOG_DIR:-$ROOT_DIR/logs}"
LOCK_DIR="${BOATRACE_LOCK_DIR:-$ROOT_DIR/var/course4_attack_type_forward_snapshots}"
PYTHON_BIN="${BOATRACE_PYTHON_BIN:-/usr/bin/python3}"
FLOCK_BIN="${BOATRACE_FLOCK_BIN:-/usr/bin/flock}"
TIMEOUT_BIN="${BOATRACE_TIMEOUT_BIN:-/usr/bin/timeout}"
MKDIR_BIN="${BOATRACE_MKDIR_BIN:-/usr/bin/mkdir}"
JOB_TIMEOUT_SECONDS="${BOATRACE_JOB_TIMEOUT_SECONDS:-300}"
JOB_KILL_AFTER_SECONDS="${BOATRACE_JOB_KILL_AFTER_SECONDS:-15}"

case "$JOB_TIMEOUT_SECONDS" in ''|*[!0-9]*) echo "invalid BOATRACE_JOB_TIMEOUT_SECONDS" >&2; exit 64;; esac
case "$JOB_KILL_AFTER_SECONDS" in ''|*[!0-9]*) echo "invalid BOATRACE_JOB_KILL_AFTER_SECONDS" >&2; exit 64;; esac

"$MKDIR_BIN" -p "$LOG_DIR" "$LOCK_DIR"
exec 9>"$LOCK_DIR/forward.lock"
"$FLOCK_BIN" -n 9

run_job() {
    local log_name="$1"
    shift
    "$TIMEOUT_BIN" --foreground --kill-after="${JOB_KILL_AFTER_SECONDS}s" "${JOB_TIMEOUT_SECONDS}s" \
        "$PYTHON_BIN" "$@" --json >> "$LOG_DIR/$log_name" 2>&1
}

cd "$ROOT_DIR"
run_job course4_attack_type_ledger.log forecast/update_course4_attack_history_ledger.py
run_job course4_attack_type_capture.log forecast/capture_course4_attack_type_daily.py
