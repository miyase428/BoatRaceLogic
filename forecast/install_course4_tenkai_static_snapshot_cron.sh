#!/usr/bin/env bash
set -euo pipefail
ROOT_DIR="/var/www/html/boatrace"
RUNNER="$ROOT_DIR/forecast/run_course4_tenkai_static_snapshot_jobs.sh"
MARKER="# BOATRACE_COURSE4_TENKAI_STATIC_SNAPSHOT"
tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT
# The runner-path filter removes only a malformed prior installation of this
# same Phase 9-1h job; unrelated existing cron entries are retained.
(crontab -l 2>/dev/null || true) | grep -vF "$MARKER" | grep -vF "$RUNNER" > "$tmp"
# Static profile/engine inputs are available before exhibition; 15 minutes
# gives multiple pre-deadline retries without colliding with the 10-minute
# Attack Type capture cadence. A distinct flock isolates overlap.
printf '*/15 7-23 * * * /bin/bash %s %s\n' "$RUNNER" "$MARKER" >> "$tmp"
crontab "$tmp"
crontab -l | grep -F "$MARKER"
