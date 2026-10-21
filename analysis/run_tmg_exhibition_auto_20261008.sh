#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="/var/www/html/boatrace"
LOG_DIR="$ROOT_DIR/logs"

mkdir -p "$LOG_DIR"
cd "$ROOT_DIR"
TZ=Asia/Tokyo /usr/bin/php analysis/fetch_tamagawa_exhibition_20261008.php "$@" >> "$LOG_DIR/tmg_exhibition_auto_20261008.log" 2>&1
