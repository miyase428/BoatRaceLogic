#!/usr/bin/env bash
set -euo pipefail

# Web高速表示用FactとSUMマスタをまとめて更新する。
# Usage:
#   bash analysis/update_web_probability_facts.sh
#   bash analysis/update_web_probability_facts.sh 7

LOOKBACK_DAYS="${1:-7}"
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

echo "================================================================================"
echo "Web確率Fact / SUMマスタ 一括更新"
echo "================================================================================"
echo "root     : $ROOT_DIR"
echo "lookback : ${LOOKBACK_DAYS}日"
echo

echo "[1/3] race_history_fact"
php analysis/update_race_history_fact.php "$LOOKBACK_DAYS"

echo
echo "[2/3] sum_history_fact"
# 住之江(SME)を直線タイム欠損許可で扱う運用設定ラッパーを使用する。
python3 analysis/update_sum_history_fact_configured.py "$LOOKBACK_DAYS"

echo
echo "[3/3] SUMマスタ stats_*.json"
# public/sum_api.php が最初のWebアクセス時に行っていた日次再生成をcron側へ前倒しする。
# 当日更新済みの場はSKIPされる。
python3 analysis/update_sum_master_stats.py

echo
echo "================================================================================"
echo "Web確率Fact / SUMマスタ 更新完了"
echo "================================================================================"
