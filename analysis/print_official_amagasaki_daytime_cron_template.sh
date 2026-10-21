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
# The worker reads the shared database control row; cron contains no switch values.
# Prepare the single AMG deadline cache once in the morning. It makes no HTTP
# request until the shared route and approval switches are both enabled.
15 06 * * * $ROOT_DIR/analysis/prepare_official_amagasaki_deadline_cache.php --execute >> $ROOT_DIR/logs/official_amagasaki_deadline_cache.log 2>&1
* * * * * $ROOT_DIR/analysis/run_official_amagasaki_daytime_job.sh >> $ROOT_DIR/logs/official_amagasaki_daytime_cron.log 2>&1
EOF
