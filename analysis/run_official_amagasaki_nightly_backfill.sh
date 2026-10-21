#!/usr/bin/env bash
set -euo pipefail

# This is the sole supported route for post-cut-over Kyotei Biyori backfill.
# The PHP entry also requires the current JST 23:00 hour, same-day AMG race,
# the shared DB control row, no existing rows, and no official audit.
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"
exec /usr/bin/php logic/scrape_exhibition.php --amagasaki-nightly-backfill
