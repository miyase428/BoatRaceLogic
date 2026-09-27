#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
JOB="37 23 * * * /bin/bash $ROOT_DIR/analysis/run_same_day_missing_exacta_odds_nightly.sh"
CURRENT="$(crontab -l 2>/dev/null || true)"

if printf '%s\n' "$CURRENT" | grep -Fq "$ROOT_DIR/analysis/run_same_day_missing_exacta_odds_nightly.sh"; then
    echo "当日未記録2連単オッズ補完cronは登録済みです"
    exit 0
fi
{
    printf '%s\n' "$CURRENT"
    printf '%s\n' "$JOB"
} | crontab -
echo "当日未記録2連単オッズ補完cronを登録しました: 23:37 / 1.5秒間隔"
