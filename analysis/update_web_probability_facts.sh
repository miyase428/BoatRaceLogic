#!/usr/bin/env bash
set -euo pipefail

# Web高速表示用Factをまとめて更新する。
# Usage:
#   bash analysis/update_web_probability_facts.sh
#   bash analysis/update_web_probability_facts.sh 7

LOOKBACK_DAYS="${1:-7}"
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

echo "================================================================================"
echo "Web確率Fact 一括更新"
echo "================================================================================"
echo "root     : $ROOT_DIR"
echo "lookback : ${LOOKBACK_DAYS}日"
echo

echo "[1/2] race_history_fact"
php analysis/update_race_history_fact.php "$LOOKBACK_DAYS"

echo
echo "[2/2] sum_history_fact"
python3 analysis/update_sum_history_fact.py "$LOOKBACK_DAYS"

echo
echo "================================================================================"
echo "Web確率Fact 更新完了"
echo "================================================================================"
