#!/usr/bin/env bash
set -euo pipefail

ROOT=/var/www/html/boatrace
PYTHON=/tmp/boatrace-autogluon-ngboost/bin/python
TARGET_DATE="$(TZ=Asia/Tokyo date +%F)"

cd "$ROOT"
exec "$PYTHON" analysis/save_ai_winrate_blend_forward.py --date "$TARGET_DATE"
