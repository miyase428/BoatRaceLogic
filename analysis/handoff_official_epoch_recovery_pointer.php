<?php

declare(strict_types=1);

/**
 * Recovery pointer handoff entrypoint.
 *
 * Argument parsing intentionally completes before any repository, pointer,
 * lifecycle, process, or provenance access.  --help, --version, and every
 * invalid invocation are consequently mutation-free.
 */

function recovery_handoff_usage(): string
{
    return "Usage: php analysis/handoff_official_epoch_recovery_pointer.php --dry-run --expected-current=<superseded-window> --target=<prepared-window>\n"
        . "       php analysis/handoff_official_epoch_recovery_pointer.php --execute --expected-current=<superseded-window> --target=<prepared-window>\n";
}

$args = array_slice($argv, 1);
if (in_array('--help', $args, true) || in_array('-h', $args, true)) { echo recovery_handoff_usage(); exit(0); }
if (in_array('--version', $args, true)) { echo "official-epoch-recovery-pointer-handoff/v1\n"; exit(0); }

$mode = null; $source = null; $target = null; $invalid = false;
foreach ($args as $arg) {
    if ($arg === '--dry-run') { if ($mode !== null) $invalid = true; $mode = 'dry-run'; continue; }
    if ($arg === '--execute') { if ($mode !== null) $invalid = true; $mode = 'execute'; continue; }
    if (str_starts_with($arg, '--expected-current=')) { $source = substr($arg, strlen('--expected-current=')); continue; }
    if (str_starts_with($arg, '--target=')) { $target = substr($arg, strlen('--target=')); continue; }
    $invalid = true;
}
if ($invalid || $mode === null || $source === null || $target === null || $source === '' || $target === '') { fwrite(STDERR, recovery_handoff_usage()); exit(64); }

require_once __DIR__ . '/../web/logic/OfficialEpochRecoveryPointerHandoffV1.php';
require_once __DIR__ . '/../web/logic/MeasurementImplementationProvenance.php';

$repoRoot = dirname(__DIR__);
$cron = []; $cronCode = 1;
@exec('crontab -l 2>/dev/null', $cron, $cronCode);
$cronText = implode("\n", $cron);
$schedulerHeld = $cronCode === 0
    && preg_match('/^#\\s*MAINTENANCE_HOLD_[^\\n]*BOATRACE_ALL_RACE_FORWARD_CAPTURE\\s*$/m', $cronText) === 1
    && preg_match('/^[^#\\n].*BOATRACE_ALL_RACE_FORWARD_CAPTURE\\s*$/m', $cronText) !== 1;
$processes = []; $processCode = 1;
@exec('pgrep -f "run_all_race_forward_capture_cron\\.sh|capture_all_race_forward\\.php" 2>/dev/null', $processes, $processCode);
$captureCount = $processCode === 0 ? count($processes) : 0;
$currentIdentity = (new MeasurementImplementationProvenance($repoRoot, $repoRoot . '/var/strict_forward_live_readiness/recovery_pointer_readonly'))->currentIdentityReadOnly();
$service = new OfficialEpochRecoveryPointerHandoffV1($repoRoot);
$input = [
    'source_window_id' => $source,
    'target_window_id' => $target,
    'scheduler_held' => $schedulerHeld,
    'capture_process_count' => $captureCount,
    'production_routing_unchanged' => true,
    'current_measurement_identity' => $currentIdentity,
];
$result = $mode === 'execute' ? $service->execute($input) : $service->preflight($input);
$result['mode'] = $mode;
$result['scheduler_held'] = $schedulerHeld;
$result['capture_process_count'] = $captureCount;
$result['current_measurement_identity'] = $currentIdentity;
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
exit(!empty($result['accepted']) ? 0 : 2);
