#!/usr/bin/env bash
set -euo pipefail

# 過去オッズの夜間バックフィル。
# 公式サイトへ負荷をかけないよう、1日60R・各リクエスト1.2秒間隔に固定する。

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
LOG_DIR="$ROOT_DIR/logs"
LOCK_PATH="/tmp/boatrace_historical_trifecta_odds_nightly.lock"

mkdir -p "$LOG_DIR"
exec 9>"$LOCK_PATH"
if ! flock -n 9; then
    exit 0
fi

# 半年分を新しい日付から遡る。直近の検証に必要な範囲を優先する。
END_DATE="$(date -d 'yesterday' '+%F')"
START_DATE="$(date -d '180 days ago' '+%F')"

{
    printf '\n[%s] 過去3連単オッズ 夜間取込開始: %s ～ %s\n' "$(date '+%F %T')" "$START_DATE" "$END_DATE"
    cd "$ROOT_DIR"
    /usr/bin/php analysis/import_historical_trifecta_odds.php \
        "$START_DATE" "$END_DATE" --limit 60 --sleep-ms 1200
    printf '[%s] 過去3連単オッズ 夜間取込終了\n' "$(date '+%F %T')"
} >> "$LOG_DIR/historical_trifecta_odds_nightly.log" 2>&1
