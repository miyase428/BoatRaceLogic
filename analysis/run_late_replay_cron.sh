#!/usr/bin/env bash
set -euo pipefail

# ラズパイの23時展示履歴取得が完了した後に、当日分の未保存レースを夜間再現する。

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
LOG_DIR="$ROOT_DIR/logs"
LOG_FILE="$LOG_DIR/late_replay.log"
TARGET_DATE="${1:-$(date '+%Y-%m-%d')}"

mkdir -p "$LOG_DIR"
{
    echo
    printf '[%s] 夜間再現開始: %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$TARGET_DATE"
    cd "$ROOT_DIR"
    /usr/bin/php analysis/replay_missing_predictions.php "$TARGET_DATE"
    printf '[%s] 夜間再現終了: %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$TARGET_DATE"
} >> "$LOG_FILE" 2>&1
