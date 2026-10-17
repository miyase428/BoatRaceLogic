#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="/var/www/html/boatrace"
LOG_DIR="$ROOT_DIR/logs"
mkdir -p "$LOG_DIR"
cd "$ROOT_DIR"
TZ=Asia/Tokyo /usr/bin/php analysis/collect_strict_forward_exhibition.php "$@" >> "$LOG_DIR/strict_forward_exhibition_collector.log" 2>&1
