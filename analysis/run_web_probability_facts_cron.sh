#!/usr/bin/env bash
set -euo pipefail

# cronからWeb高速表示用Factを安全に更新するランナー。
# - 二重起動防止
# - 実行ログ保存
# - 直近7日を再計算

LOOKBACK_DAYS="${1:-7}"
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
LOG_DIR="$ROOT_DIR/analysis/logs"
LOG_FILE="$LOG_DIR/web_probability_facts.log"
LOCK_FILE="/tmp/boatrace_web_probability_facts.lock"

mkdir -p "$LOG_DIR"

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    printf '[%s] SKIP: Web確率Fact更新が既に実行中です\n' "$(date '+%Y-%m-%d %H:%M:%S')" >> "$LOG_FILE"
    exit 0
fi

{
    echo
    echo "================================================================================"
    printf '[%s] Web確率Fact cron開始\n' "$(date '+%Y-%m-%d %H:%M:%S')"
    echo "================================================================================"
    cd "$ROOT_DIR"
    bash analysis/update_web_probability_facts.sh "$LOOKBACK_DAYS"
    printf '[%s] Web確率Fact cron完了\n' "$(date '+%Y-%m-%d %H:%M:%S')"
} >> "$LOG_FILE" 2>&1
