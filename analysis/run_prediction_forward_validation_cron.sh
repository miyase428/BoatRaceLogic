#!/usr/bin/env bash
set -euo pipefail

# 前日までの表示スナップショットを採点してから、当日朝版サインを固定保存する。

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
LOG_DIR="$ROOT_DIR/logs"
LOG_FILE="$LOG_DIR/prediction_forward_validation.log"
LOCK_FILE="/tmp/boatrace_prediction_forward_validation.lock"
THROUGH_DATE="$(date -d yesterday '+%Y-%m-%d')"
TODAY_DATE="$(date '+%Y-%m-%d')"

mkdir -p "$LOG_DIR"
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    printf '[%s] SKIP: 自動前向き検証は実行中です\n' "$(date '+%Y-%m-%d %H:%M:%S')" >> "$LOG_FILE"
    exit 0
fi

{
    echo
    printf '[%s] 自動前向き検証開始\n' "$(date '+%Y-%m-%d %H:%M:%S')"
    cd "$ROOT_DIR"
    /usr/bin/php analysis/grade_prediction_forward_snapshots.php --through "$THROUGH_DATE"
    /usr/bin/php analysis/prewarm_home_course_signals.php "$TODAY_DATE"
    printf '[%s] 自動前向き検証完了\n' "$(date '+%Y-%m-%d %H:%M:%S')"
} >> "$LOG_FILE" 2>&1
