#!/usr/bin/env bash
set -euo pipefail

# Installs only the additive late eligibility observer.  The existing daytime
# capture, collector, and validation cron entries remain byte-for-byte intact.
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
OUTPUT_DIR="$ROOT_DIR/analysis/output"
RUNNER="$ROOT_DIR/analysis/run_strict_forward_late_eligibility_cron.sh"
STAMP="$(TZ=Asia/Tokyo date '+%Y%m%dT%H%M%S%z')"
BEFORE="$OUTPUT_DIR/cc30c_late_eligibility_crontab_before_${STAMP}.txt"
AFTER="$OUTPUT_DIR/cc30c_late_eligibility_crontab_after_${STAMP}.txt"
DIFF="$OUTPUT_DIR/cc30c_late_eligibility_crontab_diff_${STAMP}.diff"
TMP_FILE="$(mktemp)"
trap 'rm -f "$TMP_FILE"' EXIT

mkdir -p "$OUTPUT_DIR"
(crontab -l 2>/dev/null || true) > "$BEFORE"
grep -vF '# BOATRACE_STRICT_FORWARD_LATE_ELIGIBILITY' "$BEFORE" > "$TMP_FILE" || true
# This narrow cadence is an outer wake-up only.  The PHP producer selects
# solely unresolved, deadline-bearing Candidates and reads the local DB.
printf '%s\n' "*/2 22-23 * * * TZ=Asia/Tokyo /usr/bin/bash $RUNNER # BOATRACE_STRICT_FORWARD_LATE_ELIGIBILITY" >> "$TMP_FILE"
crontab "$TMP_FILE"
crontab -l > "$AFTER"
if ! diff -u "$BEFORE" "$AFTER" > "$DIFF"; then
    status=$?
    if [[ $status -ne 1 ]]; then exit "$status"; fi
fi
printf 'before=%s\nafter=%s\ndiff=%s\n' "$BEFORE" "$AFTER" "$DIFF"
