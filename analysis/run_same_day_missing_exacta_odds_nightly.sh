#!/usr/bin/env bash
set -euo pipefail

# 当日分のみを23:37に補完する。過去日付のバックフィルは行わない。
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
LOG_DIR="$ROOT_DIR/logs"
LOCK_PATH="/tmp/boatrace_same_day_missing_exacta_odds.lock"

mkdir -p "$LOG_DIR"
exec 9>"$LOCK_PATH"
if ! flock -n 9; then
    exit 0
fi

{
    printf '\n[%s] 当日未記録2連単オッズ補完開始\n' "$(date '+%F %T')"
    cd "$ROOT_DIR"
    /usr/bin/php analysis/capture_same_day_missing_exacta_odds.php
    printf '[%s] 当日未記録2連単オッズ補完終了\n' "$(date '+%F %T')"
} >> "$LOG_DIR/same_day_missing_exacta_odds.log" 2>&1
