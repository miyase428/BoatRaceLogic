#!/usr/bin/env bash
# One-time Apache timing-log update for BoatRace exhibition refresh requests.
# Run only from an SSH terminal with root privileges. This script never starts
# or restarts Apache; it uses a config test and a graceful reload only.

set -Eeuo pipefail

CONFIG_FILE='/etc/apache2/conf-available/boatrace-app-performance.conf'
ENABLED_LINK='/etc/apache2/conf-enabled/boatrace-app-performance.conf'
LOG_FILE='/var/log/apache2/boatrace-app-performance.log'
OLD_LINE='SetEnvIf Request_URI "^/web/app\.php$" boatrace_app_perf_request=1'
NEW_LINE='SetEnvIf Request_URI "^(/web/app\.php|/update_exhibition\.php)$" boatrace_app_perf_request=1'
GLOBAL_LOG_LINE='GlobalLog "/var/log/apache2/boatrace-app-performance.log" "%t %m %U %>s %O %D" env=boatrace_app_perf_request'

say() {
    printf '[boatrace-apache-timing] %s\n' "$*"
}

fail() {
    say "ERROR: $*" >&2
    exit 1
}

count_exact_line() {
    local needle=$1
    grep -Fxc -- "$needle" "$CONFIG_FILE" || true
}

if [[ ${EUID} -ne 0 ]]; then
    fail 'Run with sudo from an SSH terminal; no changes were made.'
fi

[[ -f "$CONFIG_FILE" ]] || fail "Missing configuration: $CONFIG_FILE"
[[ -L "$ENABLED_LINK" ]] || fail "Configuration is not enabled: $ENABLED_LINK"
[[ "$(readlink -f -- "$ENABLED_LINK")" == "$CONFIG_FILE" ]] \
    || fail "Enabled link does not resolve to $CONFIG_FILE"
[[ -d /var/log/apache2 ]] || fail 'Missing Apache log directory: /var/log/apache2'

if ! systemctl is-active --quiet apache2; then
    fail 'Apache is not active; no configuration was changed.'
fi

old_count=$(count_exact_line "$OLD_LINE")
new_count=$(count_exact_line "$NEW_LINE")
global_count=$(count_exact_line "$GLOBAL_LOG_LINE")

[[ "$old_count" == '1' ]] \
    || fail "Expected exactly one existing app-only SetEnvIf line; found $old_count."
[[ "$new_count" == '0' ]] \
    || fail "The exhibition-aware SetEnvIf line already exists $new_count time(s); no change made."
[[ "$global_count" == '1' ]] \
    || fail "Expected exactly one dedicated GlobalLog line; found $global_count."

timestamp=$(date -u +%Y%m%dT%H%M%SZ)
backup_file="${CONFIG_FILE}.bak-${timestamp}"
temp_file=$(mktemp "${CONFIG_FILE}.tmp.XXXXXX")
cleanup() {
    rm -f -- "$temp_file"
}
trap cleanup EXIT

cp -p -- "$CONFIG_FILE" "$backup_file"
say "Backup created: $backup_file"

while IFS= read -r line || [[ -n "$line" ]]; do
    if [[ "$line" == "$OLD_LINE" ]]; then
        printf '%s\n' "$NEW_LINE"
    else
        printf '%s\n' "$line"
    fi
done < "$CONFIG_FILE" > "$temp_file"

chmod --reference="$CONFIG_FILE" "$temp_file"
chown --reference="$CONFIG_FILE" "$temp_file"
mv -- "$temp_file" "$CONFIG_FILE"

if ! apache2ctl configtest; then
    say 'Config test failed. Restoring the dedicated configuration file; Apache was not reloaded.' >&2
    cp -p -- "$backup_file" "$CONFIG_FILE"
    apache2ctl configtest || say 'WARNING: the restored configuration also failed configtest.' >&2
    exit 1
fi

if ! systemctl reload apache2; then
    say 'Graceful reload failed. Restoring the dedicated configuration file; Apache was not restarted.' >&2
    cp -p -- "$backup_file" "$CONFIG_FILE"
    apache2ctl configtest || say 'WARNING: the restored configuration also failed configtest.' >&2
    exit 1
fi

if ! systemctl is-active --quiet apache2; then
    say 'Apache is not active after reload. Restoring the dedicated configuration file; no restart will be attempted.' >&2
    cp -p -- "$backup_file" "$CONFIG_FILE"
    apache2ctl configtest || say 'WARNING: the restored configuration also failed configtest.' >&2
    exit 1
fi

say 'SUCCESS: Apache is active.'
say "Timed paths: /web/app.php and /update_exhibition.php"
say "Dedicated log: $LOG_FILE"
say "Rollback file retained: $backup_file"
say 'No request was sent by this script. Wait for natural application access before reading the log.'
