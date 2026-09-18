#!/usr/bin/env bash
set -u

# Raspberry Piの既存23時処理を1回だけ実行し、完了後に未保存レースを夜間再現する。

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
TARGET_DATE="$(date '+%Y-%m-%d')"

cd "$ROOT_DIR"
/usr/bin/php logic/scrape_exhibition.php
SCRAPE_EXIT=$?

# 一部欠測があっても、今回までに6艇分そろったレースは再現対象にする。
/usr/bin/bash analysis/run_late_replay_cron.sh "$TARGET_DATE"
REPLAY_EXIT=$?

if (( SCRAPE_EXIT != 0 )); then
    exit "$SCRAPE_EXIT"
fi
exit "$REPLAY_EXIT"
