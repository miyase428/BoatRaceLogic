#!/usr/bin/env bash
set -euo pipefail
cd /var/www/html/boatrace
TZ=Asia/Tokyo /usr/bin/php analysis/fetch_toda_exhibition_20261016.php "$@" >> logs/tda_exhibition_auto_20261016.log 2>&1
