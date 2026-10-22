#!/usr/bin/env bash
set -euo pipefail

# The scraper owns the single late-replay invocation. It invokes replay once
# on normal completion and also from its guarded shutdown handler on an early
# PHP exit. Calling run_late_replay_cron.sh here would run the same replay a
# second time.

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"
exec /usr/bin/bash analysis/run_official_amagasaki_nightly_backfill.sh
