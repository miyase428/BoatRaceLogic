#!/usr/bin/env bash
set -euo pipefail

# This wrapper does not enable the official route.  The PHP entry point exits
# before any official request unless the operator has explicitly supplied both
# required environment switches after approval.
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
LOG_DIR="${OFFICIAL_AMAGASAKI_LOG_DIR:-$ROOT_DIR/logs}"
LOCK_DIR="${OFFICIAL_AMAGASAKI_LOCK_DIR:-$ROOT_DIR/var/official_amagasaki}"
mkdir -p "$LOG_DIR" "$LOCK_DIR"
exec 9>"$LOCK_DIR/daytime.lock"
flock -n 9 || exit 0

DATE_ARG="${1:-}"
if [[ -n "$DATE_ARG" && ! "$DATE_ARG" =~ ^[0-9]{8}$ ]]; then
  echo "DATE_INVALID" >&2
  exit 2
fi
ARGS=("$ROOT_DIR/analysis/run_official_amagasaki_daytime_job.php" --execute)
if [[ -n "$DATE_ARG" ]]; then ARGS+=("--date=$DATE_ARG"); fi

{
  printf '[%s] official_amagasaki daytime job started\n' "$(date -Is)"
  /usr/bin/php "${ARGS[@]}"
  printf '[%s] official_amagasaki daytime job finished\n' "$(date -Is)"
} >> "$LOG_DIR/official_amagasaki_daytime.log" 2>&1
