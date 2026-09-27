#!/usr/bin/env bash
set -euo pipefail

# 過去2連単オッズの夜間バックフィル。公式への連続アクセスを抑え、
# 1日60R・各リクエスト1.2秒間隔に固定する。

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
LOG_DIR="$ROOT_DIR/logs"
LOCK_PATH="/tmp/boatrace_historical_exacta_odds_nightly.lock"

mkdir -p "$LOG_DIR"
exec 9>"$LOCK_PATH"
if ! flock -n 9; then
    exit 0
fi

END_DATE="$(date -d 'yesterday' '+%F')"
START_DATE="$(date -d '180 days ago' '+%F')"

{
    printf '\n[%s] 過去2連単オッズ 夜間取込開始: %s ～ %s\n' "$(date '+%F %T')" "$START_DATE" "$END_DATE"
    cd "$ROOT_DIR"
    /usr/bin/php analysis/import_historical_exacta_odds.php \
        "$START_DATE" "$END_DATE" --limit 60 --sleep-ms 1200
    printf '[%s] 過去2連単オッズ 夜間取込終了\n' "$(date '+%F %T')"
} >> "$LOG_DIR/historical_exacta_odds_nightly.log" 2>&1
