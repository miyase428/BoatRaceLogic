#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
LOG_DIR="$ROOT_DIR/logs"
LOG_FILE="$LOG_DIR/strict_forward_late_eligibility.log"

mkdir -p "$LOG_DIR"
{
    printf '[%s] late eligibility observer start\n' "$(TZ=Asia/Tokyo date '+%Y-%m-%d %H:%M:%S %Z')"
    cd "$ROOT_DIR"
    # DB-only eligibility check; it never discovers Candidates, fetches
    # exhibition externally, starts the collector, or writes predictions.
    TZ=Asia/Tokyo /usr/bin/php analysis/finalize_strict_forward_late_eligibility.php
    printf '[%s] late eligibility observer end\n' "$(TZ=Asia/Tokyo date '+%Y-%m-%d %H:%M:%S %Z')"
} >> "$LOG_FILE" 2>&1
