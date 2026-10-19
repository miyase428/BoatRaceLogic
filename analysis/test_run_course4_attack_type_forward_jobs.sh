#!/usr/bin/env bash
set -euo pipefail

REPO=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
RUNNER="$REPO/forecast/run_course4_attack_type_forward_jobs.sh"
TMP_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/attack-type-runner-XXXXXX")
trap 'rm -rf "$TMP_ROOT"' EXIT

FAKE_PYTHON="$TMP_ROOT/fake-python"
cat > "$FAKE_PYTHON" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
target=$1
printf '%s\n' "$(basename "$target")" >> "$EVENT_LOG"
case "$(basename "$target")" in
  update_course4_attack_history_ledger.py)
    if [[ ${LEDGER_SLEEP_SECONDS:-0} != 0 ]]; then sleep "$LEDGER_SLEEP_SECONDS"; fi
    exit "${LEDGER_EXIT_CODE:-0}"
    ;;
  capture_course4_attack_type_daily.py)
    if [[ ${CAPTURE_SLEEP_SECONDS:-0} != 0 ]]; then sleep "$CAPTURE_SLEEP_SECONDS"; fi
    exit "${CAPTURE_EXIT_CODE:-0}"
    ;;
  *) exit 65 ;;
esac
EOF
chmod 700 "$FAKE_PYTHON"

new_case() {
    CASE_ROOT=$(mktemp -d "$TMP_ROOT/case-XXXXXX")
    EVENT_LOG="$CASE_ROOT/events.log"
    export EVENT_LOG
}

run_runner() {
    BOATRACE_ROOT_DIR="$CASE_ROOT" \
    BOATRACE_PYTHON_BIN="$FAKE_PYTHON" \
    BOATRACE_JOB_TIMEOUT_SECONDS="${TEST_TIMEOUT_SECONDS:-2}" \
    BOATRACE_JOB_KILL_AFTER_SECONDS=1 \
    "$RUNNER"
}

expect_status() {
    local expected=$1
    shift
    set +e
    "$@"
    local actual=$?
    set -e
    [[ $actual -eq $expected ]] || { echo "expected status $expected, got $actual" >&2; exit 1; }
}

# 1. A normal run creates the lock parent and runs ledger before capture.
new_case
run_runner
[[ -f "$CASE_ROOT/var/course4_attack_type_forward_snapshots/forward.lock" ]]
[[ $(cat "$EVENT_LOG") == $'update_course4_attack_history_ledger.py\ncapture_course4_attack_type_daily.py' ]]

# 2. A ledger failure stops before capture and returns the ledger status.
new_case
LEDGER_EXIT_CODE=17 expect_status 17 run_runner
[[ $(cat "$EVENT_LOG") == 'update_course4_attack_history_ledger.py' ]]

# 3. A capture failure is propagated after a successful ledger update.
new_case
CAPTURE_EXIT_CODE=23 expect_status 23 run_runner
[[ $(cat "$EVENT_LOG") == $'update_course4_attack_history_ledger.py\ncapture_course4_attack_type_daily.py' ]]

# 4. A timed-out ledger is terminated and capture does not start.
new_case
TEST_TIMEOUT_SECONDS=1 LEDGER_SLEEP_SECONDS=5 expect_status 124 run_runner
[[ $(cat "$EVENT_LOG") == 'update_course4_attack_history_ledger.py' ]]

# 5. A second process cannot enter while the first holds the same lock.
new_case
TEST_TIMEOUT_SECONDS=5 LEDGER_SLEEP_SECONDS=2 run_runner & first_pid=$!
for _ in $(seq 1 30); do [[ -s "$EVENT_LOG" ]] && break; sleep 0.1; done
[[ -s "$EVENT_LOG" ]]
expect_status 1 run_runner
wait "$first_pid"
[[ $(grep -c '^update_course4_attack_history_ledger.py$' "$EVENT_LOG") -eq 1 ]]

echo 'PASS: 5 isolated runner guard cases'
