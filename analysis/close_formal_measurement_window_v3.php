<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/IdentitySeparatedFormalCloseV3.php';

function closeV3Usage(): never
{
    fwrite(STDERR, "usage: php analysis/close_formal_measurement_window_v3.php --window-id=<id> --as-of=<ISO-8601> --dry-run [--commit]\n");
    exit(64);
}

/** @return array{scheduler_held:bool,capture_process_count:int,scheduler_config_hash:string} */
function closeV3RuntimeAttestation(): array
{
    $cron = shell_exec('/usr/bin/crontab -l 2>/dev/null');
    if (!is_string($cron)) $cron = '';
    $held = preg_match('/^#\s*MAINTENANCE_HOLD[^\n]*#\s*BOATRACE_ALL_RACE_FORWARD_CAPTURE\s*$/m', $cron) === 1;
    $processes = shell_exec('/bin/ps -eo pid=,comm=,args= 2>/dev/null');
    $count = 0;
    foreach (preg_split('/\R/', is_string($processes) ? $processes : '') ?: [] as $line) {
        if (preg_match('/\bphp\b.*capture_all_race_forward\.php/', $line)) $count++;
    }
    return [
        'scheduler_held' => $held,
        'capture_process_count' => $count,
        'scheduler_config_hash' => 'sha256:' . hash('sha256', $cron),
        'capture_lock_path' => '/tmp/boatrace_all_race_forward_capture.lock',
    ];
}

$windowId = '';
$asOf = '';
$dryRun = false;
$commit = false;
$failedArtifacts = [];
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--window-id=')) $windowId = substr($argument, strlen('--window-id='));
    if (str_starts_with($argument, '--as-of=')) $asOf = substr($argument, strlen('--as-of='));
    if ($argument === '--dry-run') $dryRun = true;
    if ($argument === '--commit') $commit = true;
    if (str_starts_with($argument, '--failed-close-artifact=')) $failedArtifacts[] = substr($argument, strlen('--failed-close-artifact='));
}
if ($windowId === '' || $asOf === '' || (!$dryRun && !$commit)) closeV3Usage();

$service = new IdentitySeparatedFormalCloseV3(dirname(__DIR__));
$runtime = closeV3RuntimeAttestation();
$preflight = $service->preflight($windowId, $runtime, $asOf, $failedArtifacts);
if ($dryRun && !$commit) {
    echo json_encode($preflight, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(!empty($preflight['accepted']) ? 0 : 1);
}
if (empty($preflight['accepted'])) {
    echo json_encode($preflight, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
$result = $service->commit((array)$preflight['preflight'], 'closeV3RuntimeAttestation');
echo json_encode(['preflight' => $preflight, 'commit' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(!empty($result['accepted']) ? 0 : 1);
