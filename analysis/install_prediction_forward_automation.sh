#!/usr/bin/env bash
set -euo pipefail

# 前向き検証の保存・夜間再現・採点を、既存crontabを保ったまま登録する。
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
CAPTURE="$ROOT_DIR/analysis/run_all_race_forward_capture_cron.sh"
REPLAY="$ROOT_DIR/analysis/run_late_replay_cron.sh"
GRADE="$ROOT_DIR/analysis/run_prediction_forward_validation_cron.sh"
TMP_FILE="$(mktemp)"
trap 'rm -f "$TMP_FILE"' EXIT

(crontab -l 2>/dev/null || true) \
    | grep -vE '# BOATRACE_(ALL_RACE_FORWARD_CAPTURE|LATE_REPLAY|PREDICTION_FORWARD_VALIDATION)' \
    > "$TMP_FILE"

# DBへ入った展示を締切前に保存するため日中は2分ごとに巡回する。
# 外部展示の自動取得は行わず、夜間取得または画面の手動更新に任せる。
printf '%s\n' "*/2 8-21 * * * /usr/bin/bash $CAPTURE # BOATRACE_ALL_RACE_FORWARD_CAPTURE" >> "$TMP_FILE"
# 23時取得の展示履歴を用い、締切前保存ができなかったレースは結果を遮断して再現する。
printf '%s\n' "10 23 * * * /usr/bin/bash $REPLAY # BOATRACE_LATE_REPLAY" >> "$TMP_FILE"
# 前日確定分を採点し、当日朝のコースサインも固定保存する。
printf '%s\n' "35 8 * * * /usr/bin/bash $GRADE # BOATRACE_PREDICTION_FORWARD_VALIDATION" >> "$TMP_FILE"

crontab "$TMP_FILE"
crontab -l | grep -E 'BOATRACE_(ALL_RACE_FORWARD_CAPTURE|LATE_REPLAY|PREDICTION_FORWARD_VALIDATION)'
