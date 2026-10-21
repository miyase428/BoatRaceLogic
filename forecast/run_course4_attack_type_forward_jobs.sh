#!/usr/bin/env bash
set -euo pipefail
ROOT_DIR="/var/www/html/boatrace"; LOG_DIR="$ROOT_DIR/logs"; mkdir -p "$LOG_DIR"
exec flock -n "$ROOT_DIR/var/course4_attack_type_forward_snapshots/forward.lock" bash -c '
cd /var/www/html/boatrace
/usr/bin/python3 forecast/update_course4_attack_history_ledger.py --json >> logs/course4_attack_type_ledger.log 2>&1
/usr/bin/python3 forecast/capture_course4_attack_type_daily.py --json >> logs/course4_attack_type_capture.log 2>&1
'
