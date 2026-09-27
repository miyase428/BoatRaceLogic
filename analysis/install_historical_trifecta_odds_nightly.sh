#!/usr/bin/env bash
set -euo pipefail

# 毎日01:35にだけ、低速な過去オッズバックフィルを実行する。
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
JOB="35 1 * * * /bin/bash $ROOT_DIR/analysis/run_historical_trifecta_odds_nightly.sh"

CURRENT="$(crontab -l 2>/dev/null || true)"
if printf '%s\n' "$CURRENT" | grep -Fq "$ROOT_DIR/analysis/run_historical_trifecta_odds_nightly.sh"; then
    echo "夜間オッズ取込cronは登録済みです"
    exit 0
fi

{
    printf '%s\n' "$CURRENT"
    printf '%s\n' "$JOB"
} | crontab -

echo "夜間オッズ取込cronを登録しました: 01:35 / 60R / 1.2秒間隔"
