<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/FormalMeasurementWindowLifecycle.php';
require_once __DIR__ . '/../web/logic/OfficialEpochIncidentLifecycleStore.php';
require_once __DIR__ . '/../web/logic/OfficialEpochStartStore.php';
require_once __DIR__ . '/../web/logic/OfficialEpochIncidentPolicyV1.php';
require_once __DIR__ . '/../web/logic/MeasurementImplementationProvenance.php';
require_once __DIR__ . '/../web/logic/StrictForwardLateEligibilityScheduleContract.php';
require_once __DIR__ . '/../web/logic/FormalMeasurementWindowPrepareOnlyV1.php';

function recoveryGateAssert(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException('ASSERTION_FAILED:' . $label);
    echo "PASS {$label}\n";
}

function recoveryGateWrite(string $path, string $bytes): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new RuntimeException('fixture_directory_unavailable');
    if (file_put_contents($path, $bytes) === false) throw new RuntimeException('fixture_write_failed');
}

function recoveryGateRemove(string $path): void
{
    if (!file_exists($path)) return;
    if (!is_dir($path)) { unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') recoveryGateRemove($path . '/' . $entry);
    rmdir($path);
}

/** @return array{count:int,sha256:string} */
function recoveryGateTree(string $root): array
{
    if (!is_dir($root)) return ['count' => 0, 'sha256' => 'sha256:' . hash('sha256', '')];
    $rows = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        $relative = substr($file->getPathname(), strlen(rtrim($root, '/')) + 1);
        $rows[$relative] = hash_file('sha256', $file->getPathname());
    }
    ksort($rows, SORT_STRING);
    return ['count' => count($rows), 'sha256' => 'sha256:' . hash('sha256', OfficialEpochPolicyV1::canonicalJson($rows))];
}

function recoveryGateHash(string $seed): string { return 'sha256:' . hash('sha256', $seed); }

/** @return list<string> */
function recoveryGateRaces(): array
{
    return ['20261016TDA01','20261016TDA02','20261016TDA03','20261016TDA04','20261016TDA05','20261016TDA06','20261016TDA07','20261016TDA08'];
}

$repo = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/cc30c_recovery_implementation_gate_' . bin2hex(random_bytes(8));
$checks = [];

try {
    // Gate C/E are source and synthetic-crontab checks only: no installed
    // cron, process, DB, or production Window is touched.
    $schedule = StrictForwardLateEligibilityScheduleContract::load($repo);
    recoveryGateAssert($schedule['accepted'], 'late schedule contract validates its runner source hash');
    $contract = $schedule['contract'];
    $expectedLine = $contract['cron_expression'] . ' ' . $contract['runner']['command_prefix'] . ' ' . $repo . '/' . $contract['runner']['repo_relative_path'] . ' # ' . $contract['runner']['marker'];
    recoveryGateAssert(StrictForwardLateEligibilityScheduleContract::attestCrontabText($repo, $expectedLine . "\n")['accepted'], 'exact 22-23 two-minute schedule attests');
    foreach ([
        '*/2 22 * * * TZ=Asia/Tokyo /usr/bin/bash ' . $repo . '/' . $contract['runner']['repo_relative_path'] . ' # ' . $contract['runner']['marker'],
        '*/5 22-23 * * * TZ=Asia/Tokyo /usr/bin/bash ' . $repo . '/' . $contract['runner']['repo_relative_path'] . ' # ' . $contract['runner']['marker'],
        '*/2 22-23 * * * TZ=Asia/Tokyo /usr/bin/bash ' . $repo . '/analysis/not_the_runner.sh # ' . $contract['runner']['marker'],
    ] as $invalid) recoveryGateAssert(!StrictForwardLateEligibilityScheduleContract::attestCrontabText($repo, $invalid . "\n")['accepted'], 'invalid schedule fails closed');
    $checks['schedule_contract'] = 'PASS';
    $checks['runtime_schedule_attestation'] = 'PASS';

    // The narrow classifier admits only proven, bounded Window-level
    // completeness loss. Missing semantics are deliberately CRITICAL/BLOCKED.
    $classificationInput = [
        'incident_type' => 'SCHEDULER_FAILURE_MISSED_OPPORTUNITY', 'affected_scope_kind' => 'WINDOW',
        'formal_population_completeness_lost' => true, 'future_only_reconstruction_complete' => false,
        'requires_new_window' => true, 'bounded_scope' => true, 'measurement_semantics_unchanged' => true,
        'affected_count' => 8,
    ];
    $major = OfficialEpochIncidentPolicyV1::classifyTechnicalIncompleteSchedulerFailure($classificationInput);
    recoveryGateAssert($major['accepted'] && $major['severity'] === 'MAJOR' && $major['requires_new_window'] && !$major['requires_new_epoch'], 'proven technical incomplete incident is bounded MAJOR');
    unset($classificationInput['formal_population_completeness_lost']);
    $blocked = OfficialEpochIncidentPolicyV1::classifyTechnicalIncompleteSchedulerFailure($classificationInput);
    recoveryGateAssert(!$blocked['accepted'] && $blocked['severity'] === 'CRITICAL' && $blocked['classification'] === 'BLOCKED_UNCLASSIFIED', 'unproven technical incomplete incident fails closed');
    $checks['major_classifier'] = 'PASS';

    $fixtureRepo = $fixture . '/repo';
    foreach (MeasurementImplementationProvenance::criticalPaths() as $relative) {
        $source = $repo . '/' . $relative;
        recoveryGateAssert(is_file($source), 'critical vNext dependency exists: ' . $relative);
        recoveryGateWrite($fixtureRepo . '/' . $relative, (string)file_get_contents($source));
    }
    $checks['vnext_dependency_identity'] = 'PASS';

    $lifecycle = new FormalMeasurementWindowLifecycle($fixtureRepo);
    $oldWindow = 'fixture-old-window';
    $oldRoot = $fixtureRepo . '/var/strict_forward_live_readiness/windows/' . $oldWindow;
    recoveryGateWrite($oldRoot . '/validation_window_manifest.json', json_encode(['window_id' => $oldWindow, 'activation_state' => 'ACTIVE', 'status' => 'ACTIVE'], JSON_THROW_ON_ERROR) . "\n");
    foreach (['raw/evidence.json' => '{"natural":true}', 'opportunity/candidate.json' => '{"candidate":true}', 'opportunity/eligibility.json' => '{"eligibility":"EXHIBITION_INCOMPLETE_OBSERVED"}', 'records/formal.json' => '{"formal":true}'] as $path => $bytes) recoveryGateWrite($oldRoot . '/' . $path, $bytes . "\n");
    $evidenceBefore = recoveryGateTree($oldRoot);

    // Preserve v1 parsing exactly while adding the v2 terminal contract.
    $legacyWindow = 'fixture-legacy-v1';
    recoveryGateWrite($fixtureRepo . '/var/strict_forward_live_readiness/lifecycle/aborts/' . $legacyWindow . '.json', json_encode([
        'schema_version' => FormalMeasurementWindowLifecycle::ABORT_SCHEMA_VERSION, 'window_id' => $legacyWindow,
        'abort_reason' => FormalMeasurementWindowLifecycle::ABORT_REASON_A1_DEADLINE_SNAPSHOT_SEMANTIC_CONFLICT,
        'official_epoch' => 'NO', 'abort_record_hash' => recoveryGateHash('legacy-v1'),
    ], JSON_THROW_ON_ERROR) . "\n");
    recoveryGateAssert($lifecycle->effectiveState($legacyWindow, ['activation_state' => 'ACTIVE', 'status' => 'ACTIVE'])['effective_state'] === 'ABORTED', 'abort v1 remains readable and terminal');

    // Build an active fixture Epoch and append the immutable incident/resolution
    // records there only; no production lifecycle path is ever used.
    $lifecycleRoot = $fixtureRepo . '/var/strict_forward_live_readiness/lifecycle';
    $epochId = 'official-epoch-v1-0123456789abcdef';
    $start = [
        'schema_version' => OfficialEpochStartStore::SCHEMA_VERSION, 'epoch_id' => $epochId,
        'epoch_key' => recoveryGateHash('epoch-key'), 'authorization_hash' => recoveryGateHash('epoch-auth'),
        'incident_policy_schema' => OfficialEpochIncidentPolicyV1::SCHEMA_VERSION,
        'incident_policy_identity' => OfficialEpochIncidentPolicyV1::identity(),
    ];
    $start['record_hash'] = 'sha256:' . hash('sha256', OfficialEpochPolicyV1::canonicalJson($start));
    recoveryGateAssert((new OfficialEpochStartStore($lifecycleRoot))->create($start)['accepted'], 'fixture official Epoch start is create-only');
    $store = new OfficialEpochIncidentLifecycleStore($lifecycleRoot);
    $incidentInput = [
        'epoch_id' => $epochId, 'source_window_id' => $oldWindow, 'detected_at' => '2026-10-20T09:00:00+09:00',
        'affected_scope' => ['window_id' => $oldWindow, 'race_codes' => recoveryGateRaces()],
        'source_evidence_ref' => 'fixture://technical-incomplete', 'source_evidence_hash' => recoveryGateHash('source-evidence'),
    ] + [
        'incident_type' => 'SCHEDULER_FAILURE_MISSED_OPPORTUNITY', 'affected_scope_kind' => 'WINDOW',
        'formal_population_completeness_lost' => true, 'future_only_reconstruction_complete' => false,
        'requires_new_window' => true, 'bounded_scope' => true, 'measurement_semantics_unchanged' => true, 'affected_count' => 8,
    ];
    $incidentBuilt = $store->technicalIncompleteIncidentRecord($incidentInput);
    recoveryGateAssert($incidentBuilt['accepted'] && $incidentBuilt['record']['severity'] === 'MAJOR', 'incident v3 input is represented by an immutable MAJOR incident envelope');
    recoveryGateAssert($store->createIncident($incidentBuilt['record'])['accepted'], 'fixture incident append succeeds');
    $incident = $incidentBuilt['record'];

    $resolution = $store->technicalIncompleteResolutionRecord([
        'epoch_id' => $epochId, 'incident_id' => $incident['incident_id'], 'incident_hash' => $incident['record_hash'],
        'affected_window_id' => $oldWindow, 'detected_at' => '2026-10-20T09:00:00+09:00', 'resolved_at' => '2026-10-20T09:05:00+09:00',
        'root_cause' => 'late eligibility producer schedule gap', 'old_implementation_identity' => recoveryGateHash('old-identity'),
        'affected_race_codes' => recoveryGateRaces(), 'incident_type' => 'SCHEDULER_FAILURE_MISSED_OPPORTUNITY',
        'severity' => 'MAJOR', 'repair_reconstructibility' => 'PARTIAL', 'historical_repair_performed' => false,
        'requires_new_window' => true, 'requires_new_epoch' => false, 'recovery_strategy' => 'FREEZE_CURRENT_AND_START_CLEAN_WINDOW',
        'resolution_status' => 'PREPARED_FOR_ROLLOVER', 'new_implementation_identity' => null,
    ]);
    recoveryGateAssert($resolution['accepted'] && $resolution['record']['schema_version'] === OfficialEpochIncidentLifecycleStore::TECHNICAL_INCOMPLETE_RESOLUTION_SCHEMA, 'incident resolution v3 validates clean-window semantics');
    recoveryGateAssert($store->createTechnicalIncompleteResolution($resolution['record'])['accepted'], 'fixture resolution v3 append succeeds');
    $checks['incident_v3'] = 'PASS';

    $abortInput = [
        'window_id' => $oldWindow, 'epoch_id' => $epochId, 'armed_at' => '2026-10-15T08:00:00+09:00',
        'aborted_at' => '2026-10-20T09:10:00+09:00', 'capture_through_date' => '2026-10-16', 'window_start_date' => '2026-10-15',
        'incident_id' => $incident['incident_id'], 'incident_hash' => $incident['record_hash'], 'affected_keyset_hash' => recoveryGateHash('affected-races'),
        'final_evidence_hash' => recoveryGateHash('final-evidence'), 'verified_final_evidence_hash' => recoveryGateHash('final-evidence'),
        'old_implementation_identity' => recoveryGateHash('old-identity'), 'activation_implementation_identity' => recoveryGateHash('activation-identity'),
        'previous_pointer_identity' => recoveryGateHash('old-pointer'), 'closing_tool_implementation_identity' => recoveryGateHash('closing-tool'),
        'affected_race_codes' => recoveryGateRaces(), 'missing_resolution_count' => 8, 'historical_repair_performed' => false,
        'recovery_strategy' => FormalMeasurementWindowLifecycle::TECHNICAL_INCOMPLETE_RECOVERY_STRATEGY,
        'evidence_retention_policy' => FormalMeasurementWindowLifecycle::TECHNICAL_INCOMPLETE_EVIDENCE_RETENTION_POLICY,
        'successor_required' => true, 'repair_reconstructibility' => 'PARTIAL',
    ];
    $abort = $lifecycle->technicalIncompleteAbortV2Record($abortInput);
    recoveryGateAssert($abort['accepted'] && $abort['abort']['schema_version'] === FormalMeasurementWindowLifecycle::TECHNICAL_INCOMPLETE_ABORT_SCHEMA_VERSION, 'abort v2 validates all technical-incomplete safeguards');
    $tamperedAbort = $abort['abort'];
    $tamperedAbort['window_id'] = 'fixture-invalid-v2'; // Hash deliberately remains bound to the old identity.
    recoveryGateWrite($lifecycle->abortPath('fixture-invalid-v2'), OfficialEpochPolicyV1::canonicalJson($tamperedAbort) . "\n");
    recoveryGateAssert($lifecycle->effectiveState('fixture-invalid-v2', ['activation_state' => 'ACTIVE', 'status' => 'ACTIVE'])['effective_state'] === 'ACTIVE', 'tampered abort v2 fails closed without terminalizing a Window');
    recoveryGateWrite($lifecycle->abortPath($oldWindow), OfficialEpochPolicyV1::canonicalJson($abort['abort']) . "\n");
    recoveryGateAssert($lifecycle->effectiveState($oldWindow, ['activation_state' => 'ACTIVE', 'status' => 'ACTIVE'])['effective_state'] === 'ABORTED', 'fixture old active Window becomes externally ABORTED');
    recoveryGateAssert(recoveryGateTree($oldRoot) === $evidenceBefore, 'abort preserves raw candidate eligibility and formal evidence bytes');
    $checks['abort_v2'] = 'PASS';
    $checks['evidence_preservation'] = 'PASS';

    // The successor creation is intentionally prepare-only: no pointer,
    // binding, activation, runtime authorization, or evidence write occurs.
    $prepare = new FormalMeasurementWindowPrepareOnlyV1($fixtureRepo, null, new DateTimeImmutable('2026-10-20T09:15:00+09:00'));
    $successor = $prepare->create(['window_id' => 'fixture-clean-successor']);
    recoveryGateAssert($successor['accepted'] && $successor['status'] === 'PREPARE_ONLY_CREATED', 'fixture successor is pristine PREPARED with vNext identity');
    recoveryGateAssert(($successor['pointer_writes'] ?? -1) === 0 && ($successor['activation_creates'] ?? -1) === 0 && ($successor['normal_binding_creates'] ?? -1) === 0, 'fixture successor has no binding activation or pointer mutation');
    recoveryGateAssert(!is_dir($successor['window_root'] . '/records') && !is_dir($successor['window_root'] . '/opportunity'), 'successor receives no copied evidence');
    $frozenManifest = json_decode((string)file_get_contents($successor['window_root'] . '/provenance/' . $successor['implementation']['manifest_ref']), true, 512, JSON_THROW_ON_ERROR);
    $frozenPaths = array_map(static fn(array $row): string => (string)$row['path'], $frozenManifest['files']);
    foreach ([
        'analysis/finalize_strict_forward_late_eligibility.php',
        'analysis/run_strict_forward_late_eligibility_cron.sh',
        'analysis/output/cc30c_late_eligibility_schedule_contract_v1.json',
        'web/logic/StrictForwardExhibitionEligibilityState.php',
        'web/logic/StrictForwardLateEligibilityScheduleContract.php',
    ] as $dependency) recoveryGateAssert(in_array($dependency, $frozenPaths, true), 'vNext identity freeze includes late dependency: ' . $dependency);
    $checks['rollover_fixture'] = 'PASS';

    echo json_encode([
        'status' => 'PASS', 'fixture_only' => true, 'production_state_mutations' => 0,
        'checks' => $checks, 'old_evidence_before' => $evidenceBefore, 'old_evidence_after' => recoveryGateTree($oldRoot),
        'successor_state' => 'PREPARED', 'successor_formal_evidence_count' => 0,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    recoveryGateRemove($fixture);
}
