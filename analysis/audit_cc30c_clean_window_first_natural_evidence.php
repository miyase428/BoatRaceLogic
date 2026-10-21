<?php

declare(strict_types=1);

/**
 * CC30C Clean Window first-natural-evidence audit.
 *
 * This is deliberately read-only with respect to the measurement pipeline:
 * it opens no database connection, invokes no producer/collector/finalizer,
 * and only writes a create-only audit report when --write-report is supplied.
 */

$repo = dirname(__DIR__);
$output = $repo . '/analysis/output/cc30c_clean_window_first_natural_audit.json';
$writeReport = in_array('--write-report', $argv, true);

require_once $repo . '/web/logic/FormalMeasurementWindowContext.php';
require_once $repo . '/web/logic/StrictForwardLateEligibilityScheduleContract.php';

function cc30cReadJson(string $path): array
{
    try {
        $decoded = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    } catch (Throwable) {
        return [];
    }
}

/** @return list<array{path:string,row:array<string,mixed>}> */
function cc30cJsonFiles(string $root): array
{
    if (!is_dir($root)) return [];
    $rows = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.json')) continue;
        $row = cc30cReadJson($file->getPathname());
        if ($row !== []) $rows[] = ['path' => $file->getPathname(), 'row' => $row];
    }
    usort($rows, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
    return $rows;
}

/** @return list<array{path:string,row:array<string,mixed>}> */
function cc30cJsonLines(string $root): array
{
    if (!is_dir($root)) return [];
    $rows = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.jsonl')) continue;
        foreach (file($file->getPathname(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            try { $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { $row = null; }
            if (is_array($row)) $rows[] = ['path' => $file->getPathname(), 'row' => $row];
        }
    }
    return $rows;
}

function cc30cTimestampAfter(string $left, string $right): ?bool
{
    try { return (new DateTimeImmutable($left)) > (new DateTimeImmutable($right)); }
    catch (Throwable) { return null; }
}

/** @return list<array{path:string,key:string,value:mixed}> */
function cc30cMarkerHits(array $rows, string $marker): array
{
    $hits = [];
    $walk = static function (mixed $value, string $key, string $path) use (&$walk, &$hits, $marker): void {
        if (is_array($value)) {
            foreach ($value as $childKey => $childValue) $walk($childValue, (string)$childKey, $path . '.' . $childKey);
            return;
        }
        $keyHasMarker = str_contains(strtolower($key), $marker);
        $valueMarker = is_string($value) && str_contains(strtolower($value), $marker);
        if (($keyHasMarker && !empty($value)) || $valueMarker) $hits[] = ['path' => $path, 'key' => $key, 'value' => $value];
    };
    foreach ($rows as $entry) $walk($entry['row'], '', $entry['path']);
    return $hits;
}

/** @return array{count:int,modified_after_activation:int,max_mtime:string|null} */
function cc30cOldTreeStatus(string $root, string $activation): array
{
    if (!is_dir($root)) return ['count' => 0, 'modified_after_activation' => -1, 'max_mtime' => null];
    $count = 0; $modified = 0; $max = null;
    $activationAt = new DateTimeImmutable($activation);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        $count++;
        $at = (new DateTimeImmutable('@' . $file->getMTime()))->setTimezone(new DateTimeZone('Asia/Tokyo'));
        if ($at > $activationAt) $modified++;
        if ($max === null || $at > $max) $max = $at;
    }
    return ['count' => $count, 'modified_after_activation' => $modified, 'max_mtime' => $max?->format(DATE_ATOM)];
}

function cc30cCsv(string $value): string { return '"' . str_replace('"', '""', $value) . '"'; }

$expectedWindow = 'cc30c-clean-window-v4-20261020140615-c8ac4ed2';
$oldWindow = 'successor-v3-prepared-20261015084342-8f2a97e7';
$pointer = cc30cReadJson($repo . '/var/strict_forward_live_readiness/formal_measurement_window_context.json');
$context = FormalMeasurementWindowContext::production($repo);
$resolved = $context->resolve();
$authorization = $context->authorizeFormalWrite(); // read-only authorization projection
$manifest = is_array($resolved['manifest'] ?? null) ? $resolved['manifest'] : [];
$activation = cc30cReadJson($context->windowManifestPath() ? dirname($context->windowManifestPath()) . '/activation_record.json' : '');
$root = dirname($context->windowManifestPath());
$armedAt = (string)($manifest['armed_at'] ?? '');
$identity = (string)($activation['implementation_identity'] ?? '');

$candidateEntries = cc30cJsonFiles($root . '/opportunity/candidates');
$eligibilityEntries = cc30cJsonFiles($root . '/opportunity/eligibility');
$observationEntries = cc30cJsonLines($root . '/opportunity/observations');
$expectedEntries = cc30cJsonFiles($root . '/ledger/expected');
$canonicalEntries = array_values(array_filter(cc30cJsonFiles($root . '/ledger/records'), static fn(array $entry): bool => ($entry['row']['capture_mode'] ?? '') === 'LIVE_STRICT'));
$failureEntries = cc30cJsonLines($root . '/ledger/failures');
$operationalEntries = cc30cJsonLines($root . '/ledger/operational');
$allEntries = array_merge($candidateEntries, $eligibilityEntries, $observationEntries, $expectedEntries, $canonicalEntries, $failureEntries, $operationalEntries);

$candidateByRace = [];
foreach ($candidateEntries as $entry) {
    $race = (string)($entry['row']['race_code'] ?? '');
    if ($race !== '') $candidateByRace[$race] = $entry['row'];
}
$eligibilityByRace = [];
foreach ($eligibilityEntries as $entry) {
    $race = (string)($entry['row']['race_code'] ?? '');
    if ($race !== '') $eligibilityByRace[$race] = $entry['row'];
}
$expectedByRace = [];
foreach ($expectedEntries as $entry) {
    $race = (string)($entry['row']['race_code'] ?? '');
    if ($race !== '') $expectedByRace[$race] = true;
}
$canonicalByRace = [];
foreach ($canonicalEntries as $entry) {
    $race = (string)($entry['row']['race_code'] ?? '');
    if ($race !== '') $canonicalByRace[$race][] = $entry['row'];
}

$preArmed = [];
foreach ($allEntries as $entry) {
    $row = $entry['row'];
    $cutoff = (string)($row['cutoff_timestamp'] ?? '');
    if ($cutoff === '') continue;
    $after = cc30cTimestampAfter($cutoff, $armedAt);
    if ($after !== true) $preArmed[] = ['path' => $entry['path'], 'race_code' => (string)($row['race_code'] ?? ''), 'cutoff_timestamp' => $cutoff];
}

$manualHits = cc30cMarkerHits($allEntries, 'manual');
$fixtureHits = cc30cMarkerHits($allEntries, 'fixture');
$migrationHits = cc30cMarkerHits($allEntries, 'migrat');
$repairHits = cc30cMarkerHits($allEntries, 'historical_repair');
$oldReferenceHits = [];
foreach ($allEntries as $entry) {
    if (str_contains(json_encode($entry['row'], JSON_UNESCAPED_SLASHES) ?: '', $oldWindow)) $oldReferenceHits[] = $entry['path'];
}

$terminalStates = [];
foreach ($eligibilityByRace as $race => $row) {
    $state = (string)($row['eligibility_status'] ?? '');
    $terminalStates[$state] = ($terminalStates[$state] ?? 0) + 1;
}
$eligibleCount = $terminalStates['ELIGIBLE_OBSERVED'] ?? 0;
$observationStates = [];
foreach ($observationEntries as $entry) {
    $state = (string)($entry['row']['eligibility_status'] ?? '');
    $observationStates[$state] = ($observationStates[$state] ?? 0) + 1;
}

$formalRaces = array_keys($canonicalByRace);
sort($formalRaces, SORT_STRING);
$formalDetails = [];
foreach ($formalRaces as $race) {
    $row = $canonicalByRace[$race][0];
    $formalDetails[] = [
        'race_code' => $race,
        'captured_at' => $row['captured_at'] ?? null,
        'cutoff_timestamp' => $row['cutoff_timestamp'] ?? null,
        'capture_mode' => $row['capture_mode'] ?? null,
        'capture_source' => $row['capture_source'] ?? null,
        'window_id' => $row['window_id'] ?? null,
        'implementation_identity' => $row['measurement_implementation_identity'] ?? null,
        'record_count' => count($canonicalByRace[$race]),
    ];
}

$chains = [];
foreach ($candidateByRace as $race => $candidate) {
    $resolution = $eligibilityByRace[$race] ?? [];
    $canonical = $canonicalByRace[$race][0] ?? [];
    $chains[] = [
        'race_code' => $race,
        'candidate_recorded_at' => $candidate['universe_recorded_at'] ?? null,
        'cutoff_timestamp' => $candidate['cutoff_timestamp'] ?? null,
        'eligibility_state' => $resolution['eligibility_status'] ?? null,
        'eligibility_observed_at' => $resolution['first_eligible_observed_at'] ?? ($resolution['resolved_at'] ?? null),
        'expected_present' => isset($expectedByRace[$race]),
        'formal_record_present' => isset($canonicalByRace[$race]),
        'formal_captured_at' => $canonical['captured_at'] ?? null,
        'natural_path' => 'scheduler_candidate->eligibility_observation/resolution->strict_downstream',
    ];
}
usort($chains, static fn(array $a, array $b): int => strcmp($a['race_code'], $b['race_code']));
$firstChain = null;
foreach ($chains as $chain) {
    if (($chain['eligibility_state'] ?? '') === 'ELIGIBLE_OBSERVED' && $chain['expected_present'] && $chain['formal_record_present']) { $firstChain = $chain; break; }
}

$lateRows = array_values(array_filter($chains, static function (array $chain): bool {
    try { return (new DateTimeImmutable((string)$chain['cutoff_timestamp']))->format('H') >= '22'; } catch (Throwable) { return false; }
}));
$lateStatus = $lateRows === [] ? 'NO_LATE_RACE_OBSERVED' : ($firstChain !== null && (new DateTimeImmutable((string)$firstChain['cutoff_timestamp']))->format('H') >= '22' ? 'PASS' : 'WAIT');

$oldAbort = cc30cReadJson($repo . '/var/strict_forward_live_readiness/lifecycle/aborts/' . $oldWindow . '.json');
$oldTree = cc30cOldTreeStatus($repo . '/var/strict_forward_live_readiness/windows/' . $oldWindow, (string)($activation['activated_at'] ?? $armedAt));
$lateContract = cc30cReadJson($repo . '/analysis/output/cc30c_late_eligibility_schedule_contract_v1.json');
$lateAttestation = cc30cReadJson($repo . '/analysis/output/cc30c_recovery_rollover_post_audit_20261020_v4.json');
$lateAttestationStatus = (string)($lateAttestation['late_schedule']['status'] ?? 'UNKNOWN');

$stateOk = ($pointer['window_id'] ?? '') === $expectedWindow
    && ($resolved['accepted'] ?? false)
    && ($resolved['window_id'] ?? '') === $expectedWindow
    && ($manifest['status'] ?? '') === 'ACTIVE'
    && ($authorization['accepted'] ?? false)
    && ($authorization['status'] ?? '') === 'FORMAL_WINDOW_CONTEXT_AUTHORIZED'
    && $identity === 'sha256:d7f958adbd1e2102b591fb8df7a85ec1e120b6cc941143265ec3c9b09de494c2'
    && $lateAttestationStatus === 'LATE_SCHEDULE_ATTESTATION_PASS';
$futureOnly = $preArmed === [];
$candidateState = $candidateEntries === [] ? 'WAIT' : ($futureOnly ? 'OBSERVED' : 'FAIL');
$eligibilityState = $eligibilityEntries === [] ? 'WAIT' : ($futureOnly ? 'OBSERVED' : 'FAIL');
$formalState = $canonicalEntries === [] ? 'WAIT' : ($futureOnly ? 'OBSERVED' : 'FAIL');
$contaminationCount = count($manualHits) + count($fixtureHits) + count($oldReferenceHits);
$chainState = $firstChain !== null && $futureOnly && $contaminationCount === 0 ? 'PASS' : (($candidateEntries === [] || $canonicalEntries === []) ? 'WAIT' : 'FAIL');
$captureState = $chainState === 'PASS' && count($migrationHits) === 0 && count($repairHits) === 0 ? 'CONFIRMED' : 'WAIT';

$report = [
    'schema_version' => 'cc30c-clean-window-first-natural-audit/v1',
    'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM),
    'audit_mode' => 'READ_ONLY_PIPELINE_AUDIT',
    'script' => 'analysis/audit_cc30c_clean_window_first_natural_evidence.php',
    'script_sha256' => 'sha256:' . hash_file('sha256', __FILE__),
    'clean_window' => [
        'expected_window_id' => $expectedWindow, 'pointer_window_id' => $pointer['window_id'] ?? null,
        'state' => $manifest['status'] ?? null, 'armed_at' => $armedAt,
        'epoch_id' => $activation['epoch_id'] ?? null, 'implementation_identity' => $identity,
        'binding_hash' => $activation['official_epoch_window_binding_hash'] ?? null,
        'context_status' => $authorization['status'] ?? null, 'context_reason' => $authorization['reason'] ?? null,
        'late_schedule_attestation' => $lateAttestationStatus,
        'late_schedule_contract' => ['mode' => $lateContract['mode'] ?? null, 'cadence_minutes' => $lateContract['cadence_minutes'] ?? null, 'external_fetch' => $lateContract['external_fetch'] ?? null, 'candidate_discovery' => $lateContract['candidate_discovery'] ?? null, 'collector_trigger' => $lateContract['collector_trigger'] ?? null],
        'result' => $stateOk ? 'ACTIVE' : 'FAIL',
    ],
    'future_only_boundary' => ['contract' => 'cutoff_timestamp > armed_at', 'result' => $futureOnly ? 'PASS' : 'FAIL', 'pre_armed_race_count' => count($preArmed), 'violations' => $preArmed],
    'candidate' => ['result' => $candidateState, 'count' => count($candidateEntries), 'race_codes' => array_keys($candidateByRace), 'records' => array_values($candidateByRace)],
    'eligibility' => ['result' => $eligibilityState, 'terminal_resolution_count' => count($eligibilityEntries), 'eligible_observed_count' => $eligibleCount, 'terminal_states' => $terminalStates, 'observation_count' => count($observationEntries), 'observation_states' => $observationStates, 'resolutions' => array_values($eligibilityByRace)],
    'collector' => ['processed_formal_race_count' => count($formalRaces), 'formal_record_count' => count($canonicalEntries), 'success' => count($formalRaces), 'pending_candidate_count' => max(0, count($candidateEntries) - count($formalRaces)), 'error_event_count' => count($failureEntries), 'manual_collector_invocation_count' => 0, 'evidence' => 'No collector or finalizer was invoked by this audit.'],
    'formal_evidence' => ['result' => $formalState, 'unique_race_count' => count($formalRaces), 'record_count' => count($canonicalEntries), 'records' => $formalDetails],
    'first_natural_chain' => ['result' => $chainState, 'record' => $firstChain, 'chain_rows' => $chains],
    'late_finalizer' => ['result' => $lateStatus, 'late_candidate_count' => count($lateRows), 'late_candidates' => $lateRows, 'contract_attestation' => $lateAttestationStatus],
    'contamination' => ['manual_marker_count' => count($manualHits), 'fixture_marker_count' => count($fixtureHits), 'old_window_reference_count' => count($oldReferenceHits), 'evidence_migration_count' => count($migrationHits), 'historical_repair_count' => count($repairHits), 'manual_contamination' => $contaminationCount === 0 ? 0 : $contaminationCount, 'manual_hits' => $manualHits, 'fixture_hits' => $fixtureHits, 'old_window_references' => $oldReferenceHits],
    'old_window_preservation' => ['window_id' => $oldWindow, 'abort_state' => $oldAbort['terminal_state'] ?? null, 'expected_file_count' => 15085, 'current_file_count' => $oldTree['count'], 'modified_after_clean_activation_count' => $oldTree['modified_after_activation'], 'last_file_mtime' => $oldTree['max_mtime'], 'result' => (($oldAbort['terminal_state'] ?? '') === 'ABORTED' && $oldTree['count'] === 15085 && $oldTree['modified_after_activation'] === 0) ? 'YES' : 'NO'],
    'formal_aggregate' => ['status' => $canonicalEntries === [] ? 'NOT_ENOUGH_NATURAL_EVIDENCE' : 'NOT_EVALUATED_BY_THIS_AUDIT', 'performance_evaluation_performed' => false],
    'verdict' => ['CLEAN_WINDOW_STATE' => $stateOk ? 'ACTIVE' : 'FAIL', 'FUTURE_ONLY_BOUNDARY' => $futureOnly ? 'PASS' : 'FAIL', 'NATURAL_CANDIDATE' => $candidateState, 'NATURAL_ELIGIBILITY' => $eligibilityState, 'NATURAL_FORMAL_EVIDENCE' => $formalState, 'FIRST_NATURAL_CHAIN' => $chainState, 'LATE_FINALIZER_REAL_EVIDENCE' => $lateStatus, 'OLD_WINDOW_PRESERVED' => (($oldAbort['terminal_state'] ?? '') === 'ABORTED' && $oldTree['count'] === 15085 && $oldTree['modified_after_activation'] === 0) ? 'YES' : 'NO', 'EVIDENCE_MIGRATION' => count($migrationHits) === 0 ? 0 : 'NONZERO', 'HISTORICAL_REPAIR' => count($repairHits) === 0 ? 0 : 'NONZERO', 'MANUAL_CONTAMINATION' => $contaminationCount === 0 ? 0 : 'NONZERO', 'CC30C_CLEAN_WINDOW_NATURAL_CAPTURE' => $captureState],
];

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
if ($writeReport) {
    if (file_exists($output)) throw new RuntimeException('CREATE_ONLY_REPORT_ALREADY_EXISTS:' . $output);
    $handle = fopen($output, 'x');
    if ($handle === false) throw new RuntimeException('REPORT_CREATE_FAILED');
    fwrite($handle, $json); fclose($handle);
}
echo $json;
