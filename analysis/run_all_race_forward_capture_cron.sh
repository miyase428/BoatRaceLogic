#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
LOG_DIR="$ROOT_DIR/logs"
LOG_FILE="$LOG_DIR/all_race_forward_capture.log"

mkdir -p "$LOG_DIR"
{
    echo
    printf '[%s] 全レース前向き保存開始\n' "$(date '+%Y-%m-%d %H:%M:%S')"
    cd "$ROOT_DIR"
    # 日中cronは既存DBだけを使用し、競艇日和への自動アクセスは行わない。
    # 展示履歴の一括取得は夜間処理、必要時の取得は画面の手動更新に任せる。
    /usr/bin/php analysis/capture_all_race_forward.php --db-only
    printf '[%s] 全レース前向き保存終了\n' "$(date '+%Y-%m-%d %H:%M:%S')"
} >> "$LOG_FILE" 2>&1
