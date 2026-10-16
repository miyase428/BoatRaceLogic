<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/FormalMeasurementWindowContext.php';

// Legacy global arming is intentionally disabled.  A future formal Window
// must be selected by an explicit Context Pointer and activated only through
// WindowActivationHold; this script never creates or re-arms a Window.
try {
    $context = FormalMeasurementWindowContext::production(dirname(__DIR__));
    $resolved = $context->resolve();
    echo json_encode([
        'status' => 'FORMAL_WINDOW_CONTEXT_RESOLVED_NO_ARMING_PERFORMED',
        'window_id' => $resolved['window_id'] ?? '',
        'context_status' => $resolved['status'] ?? '',
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    echo json_encode([
        'status' => 'FORMAL_WINDOW_CONTEXT_REQUIRED',
        'reason' => $error->getMessage(),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

/**
 * Arms (but does not start) the outcome-blind Operational LIVE readiness
 * window. This script never invokes capture, reads the database, or examines
 * results. Its first-write manifest prevents pre-arm ledger evidence from
 * being promoted into the validation window.
 */

const LIVE_READINESS_STATE = __DIR__ . '/../var/strict_forward_live_readiness/validation_window_manifest.json';
const LIVE_READINESS_OUTPUT = __DIR__ . '/output/ml_forward_live_readiness';

function armWriteJson(string $path, array $payload, bool $firstWrite = false): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new RuntimeException('cannot_create_directory');
    if ($firstWrite) {
        $handle = @fopen($path, 'x');
        if (!is_resource($handle)) throw new RuntimeException('window_already_armed');
        fwrite($handle, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        fclose($handle);
        return;
    }
    if (file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) throw new RuntimeException('cannot_write_manifest');
}

function armContractHash(array $paths): string
{
    $parts = [];
    foreach ($paths as $path) $parts[] = basename($path) . ':' . (is_file($path) ? hash_file('sha256', $path) : 'MISSING');
    return 'sha256:' . hash('sha256', implode('|', $parts));
}

if (is_file(LIVE_READINESS_STATE)) {
    $existing = json_decode((string)file_get_contents(LIVE_READINESS_STATE), true);
    if (!is_array($existing)) throw new RuntimeException('invalid_existing_window_manifest');
    if (!is_dir(LIVE_READINESS_OUTPUT) && !mkdir(LIVE_READINESS_OUTPUT, 0775, true) && !is_dir(LIVE_READINESS_OUTPUT)) throw new RuntimeException('cannot_create_output');
    armWriteJson(LIVE_READINESS_OUTPUT . '/validation_window_manifest.json', $existing);
    echo json_encode(['status' => 'already_armed', 'validation_window_id' => $existing['validation_window_id'] ?? '', 'armed_at' => $existing['armed_at'] ?? ''], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$contractPaths = [
    __DIR__ . '/output/ml_forward_opportunity_denominator/scheduler_opportunity_denominator_summary.md',
    __DIR__ . '/output/ml_forward_opportunity_denominator/three_stage_operational_gate.csv',
    __DIR__ . '/output/ml_forward_operational_contract/operational_live_acceptance_contract.md',
    __DIR__ . '/output/ml_forward_epoch_launch_criteria/epoch_hard_gate_contract.csv',
];
$sourcePaths = [
    __DIR__ . '/capture_all_race_forward.php',
    __DIR__ . '/../web/logic/OfficialDeadlineLogic.php',
    __DIR__ . '/../web/logic/StrictForwardOpportunityLedger.php',
    __DIR__ . '/../web/logic/StrictForwardReadinessLedger.php',
];
$missingContracts = array_values(array_filter($contractPaths, static fn(string $path): bool => !is_file($path)));
if ($missingContracts !== []) throw new RuntimeException('required_contract_missing');

$armedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM);
$windowId = 'operational-live-' . (new DateTimeImmutable($armedAt))->format('YmdHis') . '-' . bin2hex(random_bytes(4));
$head = trim((string)shell_exec('git rev-parse HEAD'));
$manifest = [
    'validation_window_id' => $windowId,
    'armed_at' => $armedAt,
    'armed_by' => 'readiness validation',
    'status' => 'ARMED',
    'starting_head' => $head,
    'contract_version_identity' => armContractHash($contractPaths),
    'source_cache_contract_identity' => armContractHash($sourcePaths),
    'candidate_source_contract' => 'OfficialDeadlineLogic cache /tmp/boatrace_official_deadlines/deadlines_YYYYMMDD.json',
    'eligibility_contract' => 'append-only exhibition observations; unreadable input resolves only as ELIGIBILITY_UNRESOLVED',
    'strict_capture_contract' => 'LIVE_STRICT before official cutoff; expected and canonical rows trace via scheduler_candidate_key + race_code',
    'minimum_distinct_eligible_race_codes' => 2,
    'outcome_blind' => true,
    'forward_performance_inspected' => 'NO',
    'outcome_join_performed' => 'NO',
    'result_payout_roi_inspected' => 'NO',
    'official_epoch_started' => 'NO',
];
armWriteJson(LIVE_READINESS_STATE, $manifest, true);
if (!is_dir(LIVE_READINESS_OUTPUT) && !mkdir(LIVE_READINESS_OUTPUT, 0775, true) && !is_dir(LIVE_READINESS_OUTPUT)) throw new RuntimeException('cannot_create_output');
armWriteJson(LIVE_READINESS_OUTPUT . '/validation_window_manifest.json', $manifest);
echo json_encode(['status' => 'armed', 'validation_window_id' => $windowId, 'armed_at' => $armedAt, 'starting_head' => $head], JSON_UNESCAPED_SLASHES) . PHP_EOL;
