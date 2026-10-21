#!/usr/bin/env bash
set -euo pipefail

# Print only.  This script never reads or changes crontab.
if [[ "${1:-}" != "--print" || $# -ne 1 ]]; then
  echo "Usage: $0 --print" >&2
  exit 2
fi
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cat <<EOF
# REVIEW TEMPLATE ONLY: register only after approval, migration, backup, and rollback rehearsal.
# The worker itself makes no official HTTP request unless both values are explicitly set to 1.
* * * * * OFFICIAL_AMAGASAKI_ROUTE_ENABLED=1 OFFICIAL_AMAGASAKI_AUTOMATION_APPROVED=1 $ROOT_DIR/analysis/run_official_amagasaki_daytime_job.sh >> $ROOT_DIR/logs/official_amagasaki_daytime_cron.log 2>&1
EOF
