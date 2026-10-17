#!/usr/bin/env bash
set -euo pipefail
ROOT_DIR="/var/www/html/boatrace"
LOG_DIR="$ROOT_DIR/logs"
mkdir -p "$LOG_DIR" "$ROOT_DIR/var/course4_tenkai_input_snapshots"
exec flock -n "$ROOT_DIR/var/course4_tenkai_input_snapshots/static_capture.lock" bash -c '
cd /var/www/html/boatrace
/usr/bin/python3 forecast/capture_course4_tenkai_static_input_daily.py >> logs/course4_tenkai_static_snapshot_capture.log 2>&1
'
