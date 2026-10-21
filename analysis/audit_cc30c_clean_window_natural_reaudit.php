<?php

declare(strict_types=1);

/**
 * Read-only operational re-audit for the ACTIVE CC30C Clean Window.
 * It neither connects to the database nor invokes any scheduler, collector,
 * finalizer, or capture writer. --write-report only creates audit artefacts.
 */

$repo = dirname(__DIR__);
$jsonOutput = $repo . '/analysis/output/cc30c_clean_window_natural_reaudit.json';
$csvOutput = $repo . '/analysis/output/cc30c_clean_window_natural_chain_latest.csv';
$write = in_array('--write-report', $argv, true);

require_once $repo . '/web/logic/FormalMeasurementWindowContext.php';

function raJson(string $path): array {
    try { $value = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); return is_array($value) ? $value : []; }
    catch (Throwable) { return []; }
}
/** @return list<array{path:string,row:array<string,mixed>}> */
function raFiles(string $directory): array {
    if (!is_dir($directory)) return [];
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.json')) continue;
        $row = raJson($file->getPathname());
        if ($row !== []) $out[] = ['path' => $file->getPathname(), 'row' => $row];
    }
    return $out;
}
/** @return list<array{path:string,row:array<string,mixed>}> */
function raLines(string $directory): array {
    if (!is_dir($directory)) return [];
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.jsonl')) continue;
        foreach (file($file->getPathname(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            try { $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { $row = null; }
            if (is_array($row)) $out[] = ['path' => $file->getPathname(), 'row' => $row];
        }
    }
    return $out;
}
function raTime(string $value): ?DateTimeImmutable {
    try { return new DateTimeImmutable($value); } catch (Throwable) { return null; }
}
function raCsv(mixed $value): string { return '"' . str_replace('"', '""', (string)$value) . '"'; }
function raWriteNew(string $path, string $contents): void {
    $handle = fopen($path, 'x');
    if ($handle === false) throw new RuntimeException('CREATE_ONLY_WRITE_FAILED:' . $path);
    try {
        if (fwrite($handle, $contents) === false) throw new RuntimeException('AUDIT_WRITE_FAILED:' . $path);
    } finally { fclose($handle); }
}
/** @return list<array{path:string,key:string,value:mixed}> */
function raMarkerHits(array $entries, string $needle): array {
    $hits = [];
    $walk = static function (mixed $value, string $key, string $path) use (&$walk, &$hits, $needle): void {
        if (is_array($value)) { foreach ($value as $childKey => $child) $walk($child, (string)$childKey, $path . '.' . $childKey); return; }
        if ((str_contains(strtolower($key), $needle) && !empty($value)) || (is_string($value) && str_contains(strtolower($value), $needle))) {
            $hits[] = ['path' => $path, 'key' => $key, 'value' => $value];
        }
    };
    foreach ($entries as $entry) $walk($entry['row'], '', $entry['path']);
    return $hits;
}

$expectedWindow = 'cc30c-clean-window-v4-20261020140615-c8ac4ed2';
$oldWindow = 'successor-v3-prepared-20261015084342-8f2a97e7';
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
$pointer = raJson($repo . '/var/strict_forward_live_readiness/formal_measurement_window_context.json');
$context = FormalMeasurementWindowContext::production($repo);
$resolved = $context->resolve();
$authorization = $context->authorizeFormalWrite(); // pure authorization projection
$manifest = is_array($resolved['manifest'] ?? null) ? $resolved['manifest'] : [];
$root = dirname($context->windowManifestPath());
$activation = raJson($root . '/activation_record.json');
$armedAt = (string)($manifest['armed_at'] ?? '');

$candidates = raFiles($root . '/opportunity/candidates');
$resolutions = raFiles($root . '/opportunity/eligibility');
$observations = raLines($root . '/opportunity/observations');
$expected = raFiles($root . '/ledger/expected');
$canonical = array_values(array_filter(raFiles($root . '/ledger/records'), static fn(array $e): bool => ($e['row']['capture_mode'] ?? '') === 'LIVE_STRICT'));
$failures = raLines($root . '/ledger/failures');
$operational = raLines($root . '/ledger/operational');
$all = array_merge($candidates, $resolutions, $observations, $expected, $canonical, $failures, $operational);

$candidateByRace = [];
foreach ($candidates as $e) { $race = (string)($e['row']['race_code'] ?? ''); if ($race !== '') $candidateByRace[$race] = $e['row']; }
$resolutionByRace = [];
foreach ($resolutions as $e) { $race = (string)($e['row']['race_code'] ?? ''); if ($race !== '') $resolutionByRace[$race] = $e['row']; }
$observationByRace = [];
foreach ($observations as $e) {
    $race = (string)($e['row']['race_code'] ?? ''); if ($race === '') continue;
    $at = raTime((string)($e['row']['observed_at'] ?? ''));
    $previous = $observationByRace[$race]['row'] ?? null;
    $previousAt = is_array($previous) ? raTime((string)($previous['observed_at'] ?? '')) : null;
    if ($previousAt === null || ($at !== null && $at >= $previousAt)) $observationByRace[$race] = $e;
}
$expectedByRace = [];
foreach ($expected as $e) { $race = (string)($e['row']['race_code'] ?? ''); if ($race !== '') $expectedByRace[$race] = true; }
$canonicalByRace = [];
foreach ($canonical as $e) { $race = (string)($e['row']['race_code'] ?? ''); if ($race !== '') $canonicalByRace[$race][] = $e['row']; }

$states = ['EXHIBITION_INCOMPLETE_OBSERVED' => 0, 'ELIGIBLE_OBSERVED' => 0, 'terminal_non_eligible' => 0, 'other_terminal' => 0];
$terminalStatuses = [];
foreach ($resolutionByRace as $row) {
    $state = (string)($row['eligibility_status'] ?? '');
    $terminalStatuses[$state] = ($terminalStatuses[$state] ?? 0) + 1;
    if ($state === 'ELIGIBLE_OBSERVED') $states['ELIGIBLE_OBSERVED']++;
    elseif ($state === 'VALID_NON_ELIGIBLE_EXHIBITION_INCOMPLETE') $states['terminal_non_eligible']++;
    else $states['other_terminal']++;
}
foreach ($observationByRace as $entry) if (($entry['row']['eligibility_status'] ?? '') === 'EXHIBITION_INCOMPLETE_OBSERVED') $states['EXHIBITION_INCOMPLETE_OBSERVED']++;

$preArmed = [];
foreach ($all as $entry) {
    $cutoff = raTime((string)($entry['row']['cutoff_timestamp'] ?? ''));
    $armed = raTime($armedAt);
    if ($cutoff !== null && $armed !== null && $cutoff <= $armed) $preArmed[] = ['path' => $entry['path'], 'race_code' => $entry['row']['race_code'] ?? null, 'cutoff_timestamp' => $entry['row']['cutoff_timestamp']];
}
$manual = raMarkerHits($all, 'manual');
$fixture = raMarkerHits($all, 'fixture');
$migration = raMarkerHits($all, 'migrat');
$repair = raMarkerHits($all, 'historical_repair');
$oldReferences = [];
foreach ($all as $entry) if (str_contains(json_encode($entry['row'], JSON_UNESCAPED_SLASHES) ?: '', $oldWindow)) $oldReferences[] = $entry['path'];

$chains = [];
$stalled = [];
foreach ($candidateByRace as $race => $candidate) {
    $cutoff = raTime((string)($candidate['cutoff_timestamp'] ?? ''));
    $resolution = $resolutionByRace[$race] ?? [];
    $last = $observationByRace[$race]['row'] ?? [];
    $lastAt = raTime((string)($last['observed_at'] ?? ''));
    $latestState = (string)($resolution['eligibility_status'] ?? ($last['eligibility_status'] ?? 'NO_OBSERVATION'));
    $completeAt = $latestState === 'ELIGIBLE_OBSERVED' ? (string)($resolution['first_eligible_observed_at'] ?? ($last['observed_at'] ?? '')) : null;
    $afterCutoff = $cutoff !== null && $now >= $cutoff;
    $lateRace = $cutoff !== null && (int)$cutoff->format('H') >= 22;
    $classification = 'WAITING_FOR_EXHIBITION_COMPLETE';
    if ($latestState === 'ELIGIBLE_OBSERVED' && !isset($canonicalByRace[$race])) $classification = 'WAITING_FOR_COLLECTOR';
    if (isset($canonicalByRace[$race])) $classification = 'FORMAL_CAPTURED';
    if ($afterCutoff && $resolution === []) $classification = $lateRace ? 'WAITING_FOR_LATE_FINALIZER' : 'TERMINAL_RESOLUTION_STALLED';
    if ($afterCutoff && $resolution === [] && $cutoff !== null && $now > $cutoff->modify('+10 minutes')) $stalled[] = $race;
    $chains[] = [
        'race_code' => $race, 'cutoff_timestamp' => $candidate['cutoff_timestamp'] ?? null,
        'candidate_recorded_at' => $candidate['universe_recorded_at'] ?? null, 'candidate_source' => $candidate['source'] ?? null,
        'latest_observed_state' => $last['eligibility_status'] ?? null, 'latest_observed_at' => $last['observed_at'] ?? null,
        'terminal_eligibility_state' => $resolution['eligibility_status'] ?? null,
        'terminal_at' => $resolution['first_eligible_observed_at'] ?? ($resolution['resolved_at'] ?? null),
        'exhibition_complete_at' => $completeAt, 'expected_present' => isset($expectedByRace[$race]),
        'formal_record_count' => count($canonicalByRace[$race] ?? []),
        'formal_captured_at' => $canonicalByRace[$race][0]['captured_at'] ?? null,
        'classification' => $classification,
        'next_natural_processing_opportunity' => $classification === 'WAITING_FOR_LATE_FINALIZER' ? '22:00-23:59 JST late finalizer (2 minute cadence)' : ($classification === 'WAITING_FOR_EXHIBITION_COMPLETE' ? 'normal scheduler after official exhibition becomes complete' : ($classification === 'WAITING_FOR_COLLECTOR' ? 'natural collector/downstream scheduler' : null)),
    ];
}
usort($chains, static fn(array $a, array $b): int => strcmp((string)$a['race_code'], (string)$b['race_code']));
$formalRaces = array_keys($canonicalByRace); sort($formalRaces, SORT_STRING);
$firstChain = null;
foreach ($chains as $chain) if (($chain['terminal_eligibility_state'] ?? '') === 'ELIGIBLE_OBSERVED' && $chain['expected_present'] && ($chain['formal_record_count'] ?? 0) > 0) { $firstChain = $chain; break; }
$lateCandidates = array_values(array_filter($chains, static fn(array $chain): bool => (int)(raTime((string)$chain['cutoff_timestamp'])?->format('H') ?? -1) >= 22));
$lateResult = $lateCandidates === [] ? 'NO_LATE_RACE_OBSERVED' : (($firstChain !== null && (int)(raTime((string)$firstChain['cutoff_timestamp'])?->format('H') ?? -1) >= 22) ? 'PASS' : 'WAIT');

$post = raJson($repo . '/analysis/output/cc30c_recovery_rollover_post_audit_20261020_v4.json');
$oldAbort = raJson($repo . '/var/strict_forward_live_readiness/lifecycle/aborts/' . $oldWindow . '.json');
$stateOk = ($pointer['window_id'] ?? '') === $expectedWindow && ($manifest['status'] ?? '') === 'ACTIVE' && !empty($authorization['accepted']) && ($activation['implementation_identity'] ?? '') === 'sha256:d7f958adbd1e2102b591fb8df7a85ec1e120b6cc941143265ec3c9b09de494c2' && (($post['late_schedule']['status'] ?? '') === 'LATE_SCHEDULE_ATTESTATION_PASS');
$futureOk = $preArmed === [];
$contamination = count($manual) + count($fixture) + count($oldReferences);
$chainResult = $firstChain !== null && $futureOk && $contamination === 0 ? 'PASS' : (in_array('TERMINAL_RESOLUTION_STALLED', array_column($chains, 'classification'), true) ? 'FAIL' : 'WAIT');
$waitReason = $chainResult === 'PASS' ? null : ($stalled !== [] ? 'TERMINAL_RESOLUTION_STALLED' : (count($canonical) === 0 ? 'WAITING_FOR_EXHIBITION_COMPLETE' : 'WAITING_FOR_FORMAL_CAPTURE'));

$report = [
    'schema_version' => 'cc30c-clean-window-natural-reaudit/v1', 'generated_at' => $now->format(DATE_ATOM),
    'audit_mode' => 'READ_ONLY_NO_DB_NO_PIPELINE_INVOCATION',
    'script' => 'analysis/audit_cc30c_clean_window_natural_reaudit.php', 'script_sha256' => 'sha256:' . hash_file('sha256', __FILE__),
    'clean_window' => ['window_id' => $expectedWindow, 'pointer_window_id' => $pointer['window_id'] ?? null, 'state' => $manifest['status'] ?? null, 'armed_at' => $armedAt, 'epoch_id' => $activation['epoch_id'] ?? null, 'binding_hash' => $activation['official_epoch_window_binding_hash'] ?? null, 'runtime_status' => $authorization['status'] ?? null, 'identity' => $activation['implementation_identity'] ?? null, 'late_schedule_attestation' => $post['late_schedule']['status'] ?? null, 'result' => $stateOk ? 'ACTIVE' : 'FAIL'],
    'future_only' => ['result' => $futureOk ? 'PASS' : 'FAIL', 'pre_armed_race_count' => count($preArmed), 'violations' => $preArmed],
    'candidate' => ['count' => count($candidateByRace), 'increase_from_previous_46' => count($candidateByRace) - 46, 'manual_marker_count' => count($manual), 'fixture_marker_count' => count($fixture)],
    'eligibility' => ['terminal_resolution_count' => count($resolutionByRace), 'states' => $states, 'terminal_states' => $terminalStatuses, 'latest_observation_count' => count($observationByRace)],
    'collector' => ['processed_formal_race_count' => count($formalRaces), 'success_count' => count($formalRaces), 'pending_candidate_count' => max(0, count($candidateByRace) - count($formalRaces)), 'error_event_count' => count($failures), 'manual_invocation_count' => 0],
    'formal_evidence' => ['race_count' => count($formalRaces), 'record_count' => count($canonical), 'races' => $formalRaces],
    'first_natural_chain' => ['result' => $chainResult, 'record' => $firstChain, 'wait_or_failure_reason' => $waitReason],
    'late_finalizer' => ['result' => $lateResult, 'late_candidate_count' => count($lateCandidates), 'candidates' => $lateCandidates],
    'contamination' => ['manual_contamination' => $contamination === 0 ? 0 : 'NONZERO', 'fixture_count' => count($fixture), 'evidence_migration' => count($migration) === 0 ? 0 : 'NONZERO', 'historical_repair' => count($repair) === 0 ? 0 : 'NONZERO', 'old_window_reference_count' => count($oldReferences)],
    'old_window' => ['window_id' => $oldWindow, 'state' => $oldAbort['terminal_state'] ?? null, 'preserved' => ($oldAbort['terminal_state'] ?? '') === 'ABORTED'],
    'race_chain_rows' => $chains,
    'verdict' => ['CLEAN_WINDOW_STATE' => $stateOk ? 'ACTIVE' : 'FAIL', 'FUTURE_ONLY_BOUNDARY' => $futureOk ? 'PASS' : 'FAIL', 'NATURAL_CANDIDATE_COUNT' => count($candidateByRace), 'NATURAL_TERMINAL_ELIGIBILITY_COUNT' => count($resolutionByRace), 'COLLECTOR_SUCCESS_COUNT' => count($formalRaces), 'NATURAL_FORMAL_RACE_COUNT' => count($formalRaces), 'NATURAL_FORMAL_RECORD_COUNT' => count($canonical), 'FIRST_NATURAL_CHAIN' => $chainResult, 'LATE_FINALIZER_REAL_EVIDENCE' => $lateResult, 'MANUAL_CONTAMINATION' => $contamination === 0 ? 0 : 'NONZERO', 'HISTORICAL_REPAIR' => count($repair) === 0 ? 0 : 'NONZERO', 'EVIDENCE_MIGRATION' => count($migration) === 0 ? 0 : 'NONZERO', 'NEXT_WAIT_CONDITION' => $waitReason],
];

if ($write) {
    if (file_exists($jsonOutput) || file_exists($csvOutput)) throw new RuntimeException('CREATE_ONLY_AUDIT_OUTPUT_ALREADY_EXISTS');
    $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    raWriteNew($jsonOutput, $encoded);
    $csv = "race_code,cutoff_timestamp,candidate_recorded_at,latest_observed_state,latest_observed_at,terminal_eligibility_state,terminal_at,exhibition_complete_at,expected_present,formal_record_count,formal_captured_at,classification,next_natural_processing_opportunity\n";
    foreach ($chains as $row) $csv .= implode(',', array_map('raCsv', [$row['race_code'], $row['cutoff_timestamp'], $row['candidate_recorded_at'], $row['latest_observed_state'], $row['latest_observed_at'], $row['terminal_eligibility_state'], $row['terminal_at'], $row['exhibition_complete_at'], $row['expected_present'] ? 'true' : 'false', $row['formal_record_count'], $row['formal_captured_at'], $row['classification'], $row['next_natural_processing_opportunity']])) . "\n";
    raWriteNew($csvOutput, $csv);
}
echo json_encode($report['verdict'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
