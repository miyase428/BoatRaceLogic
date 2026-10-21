#!/usr/bin/env bash
set -euo pipefail
ROOT_DIR="/var/www/html/boatrace"; RUNNER="$ROOT_DIR/forecast/run_course4_attack_type_forward_jobs.sh"; MARKER="# BOATRACE_COURSE4_ATTACK_TYPE_FORWARD"
tmp=$(mktemp); trap 'rm -f "$tmp"' EXIT
(crontab -l 2>/dev/null || true) | grep -vF "$MARKER" > "$tmp"
printf '*/10 9-23 * * * %s %s %s\n' /bin/bash "$RUNNER" "$MARKER" >> "$tmp"
crontab "$tmp"; crontab -l | grep -F "$MARKER"
