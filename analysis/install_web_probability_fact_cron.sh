#!/usr/bin/env bash
set -euo pipefail

# Web高速表示用Fact更新cronを現在ユーザーのcrontabへ登録する。
# 既存crontabは保持し、このジョブだけ置換する。
#
# Usage:
#   bash analysis/install_web_probability_fact_cron.sh
#   bash analysis/install_web_probability_fact_cron.sh 06:30

TIME_TEXT="${1:-06:30}"
if [[ ! "$TIME_TEXT" =~ ^([01][0-9]|2[0-3]):([0-5][0-9])$ ]]; then
    echo "時刻は HH:MM 形式で指定してください（例 06:30）" >&2
    exit 1
fi

HOUR="${BASH_REMATCH[1]}"
MINUTE="${BASH_REMATCH[2]}"
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
RUNNER="$ROOT_DIR/analysis/run_web_probability_facts_cron.sh"
MARKER="# BOATRACE_WEB_PROBABILITY_FACTS"

if ! command -v crontab >/dev/null 2>&1; then
    echo "crontab コマンドが見つかりません" >&2
    exit 1
fi
if ! command -v bash >/dev/null 2>&1; then
    echo "bash コマンドが見つかりません" >&2
    exit 1
fi
if ! command -v flock >/dev/null 2>&1; then
    echo "flock コマンドが見つかりません" >&2
    exit 1
fi

TMP_FILE="$(mktemp)"
trap 'rm -f "$TMP_FILE"' EXIT

(crontab -l 2>/dev/null || true) \
    | grep -vF "$MARKER" \
    > "$TMP_FILE"

printf '%d %d * * * %q %q 7 %s\n' \
    "$((10#$MINUTE))" \
    "$((10#$HOUR))" \
    "$(command -v bash)" \
    "$RUNNER" \
    "$MARKER" \
    >> "$TMP_FILE"

crontab "$TMP_FILE"

mkdir -p "$ROOT_DIR/analysis/logs"

echo "================================================================================"
echo "Web確率Fact cron 登録完了"
echo "================================================================================"
echo "実行時刻 : 毎日 $TIME_TEXT"
echo "更新範囲 : 直近7日"
echo "runner   : $RUNNER"
echo "log      : $ROOT_DIR/analysis/logs/web_probability_facts.log"
echo "--------------------------------------------------------------------------------"
echo "登録内容:"
crontab -l | grep -F "$MARKER" || true
echo "================================================================================"
