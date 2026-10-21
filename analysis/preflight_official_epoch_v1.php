<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/OfficialEpochLifecycleV1.php';

/**
 * Official Epoch v1 command-line boundary.
 *
 * Without --commit this command is a pure read-only preflight.  The explicit
 * commit mode exists for a later separately authorized task and is never
 * selected by default.
 */
function epochRuntimeProbe(): array
{
    $crontab = (string)shell_exec('/usr/bin/crontab -l 2>/dev/null');
    $main = [];
    foreach (preg_split('/\R/', $crontab) ?: [] as $line) {
        // Match only the terminal cron tag.  The date-limited auto-hold
        // command quotes this tag internally; that historical command is
        // not the scheduler job being attested.
        if (preg_match('/#\s*BOATRACE_ALL_RACE_FORWARD_CAPTURE\s*$/', $line) === 1) $main[] = $line;
    }
    $enabled = array_filter($main, static fn(string $line): bool => preg_match('/^\s*#/', $line) !== 1);
    $held = count($enabled) === 0 && count($main) === 1 && preg_match('/^\s*#\s*MAINTENANCE_HOLD/', $main[0]) === 1;
    $processes = (string)shell_exec("ps -eo pid=,comm=,args= | awk '$0 ~ /run_all_race_forward_capture_cron\\.sh|capture_all_race_forward\\.php/ && $0 !~ /awk/ {print}'");
    $count = count(array_filter(preg_split('/\R/', trim($processes)) ?: [], static fn(string $line): bool => trim($line) !== ''));
    return [
        'scheduler_held' => $held,
        'capture_process_count' => $count,
        'scheduler_config_hash' => $main === [] ? '' : 'sha256:' . hash('sha256', implode("\n", $main) . "\n"),
    ];
}

$windowId = '';
$approvalKey = null;
$commit = in_array('--commit', $argv, true);
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--window-id=')) $windowId = substr($argument, strlen('--window-id='));
    if (str_starts_with($argument, '--approval-key=')) $approvalKey = substr($argument, strlen('--approval-key='));
}
if ($windowId === '') {
    fwrite(STDERR, "usage: preflight_official_epoch_v1.php --window-id=<closed-window-id> [--approval-key=sha256:...] [--commit]\n");
    exit(2);
}

$lifecycle = new OfficialEpochLifecycleV1(dirname(__DIR__));
$runtime = epochRuntimeProbe();
$preflight = $lifecycle->preflight($windowId, $runtime, $approvalKey);
if ($commit) {
    // Commit is intentionally impossible unless the independently supplied
    // approval record validates during both CAS checks.
    $preflight['commit_result'] = $lifecycle->start($preflight, 'epochRuntimeProbe');
}
echo json_encode($preflight, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
