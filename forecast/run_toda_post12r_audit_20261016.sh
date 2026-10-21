#!/usr/bin/env bash
set -euo pipefail
cd /var/www/html/boatrace
TZ=Asia/Tokyo /usr/bin/php analysis/audit_cc30c_toda_post12r_20261016.php --watch >> logs/cc30c_toda_post12r_20261016.log 2>&1
