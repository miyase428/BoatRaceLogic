<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/TechnicalIncompleteEvidenceAttestation.php';
require_once __DIR__ . '/../web/logic/FormalMeasurementWindowLifecycle.php';
require_once __DIR__ . '/../web/logic/FormalMeasurementWindowPrepareOnlyV1.php';
require_once __DIR__ . '/../web/logic/OfficialEpochIncidentLifecycleStore.php';
require_once __DIR__ . '/../web/logic/OfficialEpochWindowBindingStore.php';
require_once __DIR__ . '/../web/logic/OfficialEpochWindowBindingRuntimeAuthorization.php';
require_once __DIR__ . '/../web/logic/StrictForwardLateEligibilityScheduleContract.php';

const CC30C_PLAN_V2_SOURCE_WINDOW = 'successor-v3-prepared-20261015084342-8f2a97e7';
const CC30C_PLAN_V2_EPOCH = 'official-epoch-v1-bce9f9f6a9f27b7d';

/** @param mixed $value */
function planV2Assert(bool $condition, string $label, $value = null): void
{
    if (!$condition) {
        $suffix = $value === null ? '' : ':' . json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        throw new RuntimeException('ASSERTION_FAILED:' . $label . $suffix);
    }
}

/** @return array<string,mixed> */
function planV2ReadJson(string $path): array
{
    $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($row)) throw new RuntimeException('JSON_OBJECT_REQUIRED:' . $path);
    return $row;
}

function planV2WriteJson(string $path, array $row): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new RuntimeException('DIRECTORY_CREATE_FAILED');
    if (file_put_contents($path, json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n") === false) {
        throw new RuntimeException('WRITE_FAILED:' . $path);
    }
}

function planV2Remove(string $path): void
{
    if (!file_exists($path)) return;
    if (!is_dir($path) || is_link($path)) { unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') planV2Remove($path . '/' . $entry);
    rmdir($path);
}

/** @return array{count:int,sha256:string} */
function planV2Tree(string $root): array
{
    if (!is_dir($root)) return ['count' => 0, 'sha256' => 'sha256:' . hash('sha256', '')];
    $rows = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) $rows[str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($root, '/')) + 1))] = hash_file('sha256', $file->getPathname());
    }
    ksort($rows, SORT_STRING);
    return ['count' => count($rows), 'sha256' => 'sha256:' . hash('sha256', OfficialEpochPolicyV1::canonicalJson($rows))];
}

/** @param list<string> $order @return array{accepted:bool,reason:string} */
function planV2ValidateOrder(array $order, array $graph): array
{
    $positions = [];
    foreach ($order as $index => $operation) {
        if (isset($positions[$operation])) return ['accepted' => false, 'reason' => 'DUPLICATE_OPERATION:' . $operation];
        $positions[$operation] = $index;
    }
    foreach ($graph['nodes'] as $operation => $node) {
        if (!isset($positions[$operation])) return ['accepted' => false, 'reason' => 'MISSING_OPERATION:' . $operation];
        foreach ($node['must_follow'] ?? [] as $dependency) {
            if (!isset($positions[$dependency]) || $positions[$dependency] >= $positions[$operation]) {
                return ['accepted' => false, 'reason' => 'MUST_FOLLOW_VIOLATION:' . $operation . ':' . $dependency];
            }
        }
        foreach ($node['must_precede'] ?? [] as $dependent) {
            if (!isset($positions[$dependent]) || $positions[$operation] >= $positions[$dependent]) {
                return ['accepted' => false, 'reason' => 'MUST_PRECEDE_VIOLATION:' . $operation . ':' . $dependent];
            }
        }
    }
    return ['accepted' => true, 'reason' => ''];
}

function planV2CopyCriticalSources(string $sourceRepo, string $fixtureRepo): void
{
    foreach (MeasurementImplementationProvenance::criticalPaths() as $relative) {
        $source = $sourceRepo . '/' . $relative;
        planV2Assert(is_file($source), 'critical_source_exists', $relative);
        $target = $fixtureRepo . '/' . $relative;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) throw new RuntimeException('COPY_DIRECTORY_FAILED');
        planV2Assert(copy($source, $target), 'critical_source_copy', $relative);
    }
    $boundary = 'analysis/output/cc30c_formal_evidence_aggregate_contract_v2.json';
    if (!in_array($boundary, MeasurementImplementationProvenance::criticalPaths(), true)) {
        $target = $fixtureRepo . '/' . $boundary;
        if (!is_dir(dirname($target))) mkdir(dirname($target), 0775, true);
        copy($sourceRepo . '/' . $boundary, $target);
    }
}

/** @return list<string> */
function planV2MissingRaces(): array
{
    return [
        '20261016SMS11','20261016SMS12','20261017SMS11','20261017SMS12',
        '20261018SMS11','20261018SMS12','20261019SMS11','20261019SMS12',
    ];
}

/** @return array{missing_keys:list<string>,missing_races:list<string>} */
function planV2SeedEvidence(string $root, string $windowId): array
{
    $missing = array_fill_keys(planV2MissingRaces(), true);
    $missingKeys = [];
    foreach (['2026-10-16','2026-10-17','2026-10-18','2026-10-19'] as $date) {
        $entries = [];
        foreach (['AMG01','SMS11','SMS12'] as $suffix) {
            $race = str_replace('-', '', $date) . $suffix;
            $key = 'scheduler-opportunity|' . $race;
            $entries[] = ['stable_candidate_key' => $key, 'race_code' => $race, 'target_date' => $date];
            $base = ['stable_candidate_key' => $key, 'race_code' => $race, 'target_date' => $date];
            $file = hash('sha256', $key) . '.json';
            planV2WriteJson($root . '/opportunity/candidates/' . str_replace('-', '', $date) . '/' . $file, $base + ['record_type' => 'SCHEDULER_CANDIDATE_UNIVERSE']);
            if (isset($missing[$race])) $missingKeys[] = $key;
            else planV2WriteJson($root . '/opportunity/eligibility/' . str_replace('-', '', $date) . '/' . $file, $base + ['record_type' => 'SCHEDULER_ELIGIBILITY_RESOLUTION']);
        }
        planV2WriteJson($root . '/opportunity/expected_inventory/' . str_replace('-', '', $date) . '.json', [
            'schema_version' => 'gate-a1-prospective-inventory/v1', 'window_id' => $windowId,
            'target_date' => $date, 'expected_entries' => $entries,
        ]);
    }
    sort($missingKeys, SORT_STRING);
    return ['missing_keys' => $missingKeys, 'missing_races' => planV2MissingRaces()];
}

/** @return array<string,mixed> */
function planV2Validation(string $fixtureRepo, string $identity, array $evidence): array
{
    $refs = []; $hashes = [];
    foreach (OfficialEpochWindowBindingV1::requirementNames() as $name) {
        $refs[$name] = 'fixture://' . $name;
        $hashes[$name] = 'sha256:' . hash('sha256', $name . '|' . $evidence['resolution_hash'] . '|' . $evidence['schedule_hash']);
    }
    return [
        'successor_semantics_classification' => OfficialEpochWindowBindingV1::SEMANTICS_EQUIVALENT,
        'no_semantic_contract_diff' => true, 'regressions_pass' => true, 'readiness_pass' => true,
        'numeric_policy_semantics_unchanged' => true,
        'epoch_policy_identity' => OfficialEpochPolicyV1::identity(),
        'incident_policy_identity' => OfficialEpochIncidentPolicyV1::identity(),
        'current_measurement_identity' => $identity,
        'formal_evidence_count' => 0, 'carryover_count' => 0, 'backfill_count' => 0, 'prior_evidence_reuse_count' => 0,
        'validation_evidence_refs' => $refs, 'validation_evidence_hashes' => $hashes,
    ];
}

/** @return int */
function planV2FormalEvidenceCount(string $root): int
{
    $count = 0;
    foreach (['ledger','opportunity','input_snapshots','comparative_pairs'] as $dir) {
        if (!is_dir($root . '/' . $dir)) continue;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.json')) $count++;
        }
    }
    return $count;
}

$repo = realpath(__DIR__ . '/..');
if (!is_string($repo)) throw new RuntimeException('REPO_UNAVAILABLE');
$planPath = __DIR__ . '/output/cc30c_window_rollover_mutation_plan_v2.json';
$graphPath = __DIR__ . '/output/cc30c_window_rollover_dependency_graph_v2.json';
$failurePath = __DIR__ . '/output/cc30c_window_rollover_failure_matrix_v2.json';
$plan = planV2ReadJson($planPath);
$graph = planV2ReadJson($graphPath);
$failure = planV2ReadJson($failurePath);
$planHash = 'sha256:' . hash_file('sha256', $planPath);

$operations = $plan['operations'] ?? [];
planV2Assert(is_array($operations) && count($operations) === 16, 'plan_operation_count');
$order = [];
foreach ($operations as $index => $operation) {
    planV2Assert(($operation['sequence_number'] ?? null) === $index + 1, 'contiguous_sequence', $operation);
    $order[] = (string)$operation['operation_id'];
}
$dependencyCheck = planV2ValidateOrder($order, $graph);
planV2Assert($dependencyCheck['accepted'], 'authoritative_order_satisfies_graph', $dependencyCheck);
planV2Assert(($failure['status'] ?? '') === 'COMPLETE', 'failure_matrix_complete');

$fixtureRoot = sys_get_temp_dir() . '/cc30c-rollover-plan-v2-' . bin2hex(random_bytes(6));
$fixtureRepo = $fixtureRoot . '/repo';
$oldId = 'fixture-old-active';
$successorId = 'fixture-successor-v2';
$epochId = 'official-epoch-v1-' . substr(hash('sha256', $fixtureRoot), 0, 16);
$execution = [];
$state = ['scheduler_held' => false, 'capture_process_count' => 0, 'successor' => 'ABSENT'];
$fixtureResult = [];

try {
    planV2CopyCriticalSources($repo, $fixtureRepo);
    $oldCreate = (new FormalMeasurementWindowPrepareOnlyV1($fixtureRepo))->create(['window_id' => $oldId]);
    planV2Assert(!empty($oldCreate['accepted']), 'fixture_old_prepared', $oldCreate);
    $oldRoot = $fixtureRepo . '/var/strict_forward_live_readiness/windows/' . $oldId;
    $oldContext = FormalMeasurementWindowContext::fromWindowRoot($fixtureRepo, $oldRoot);
    $oldActivate = $oldContext->activationHold()->activate('fixture://old-preflight');
    planV2Assert(!empty($oldActivate['accepted']), 'fixture_old_active', $oldActivate);
    $pointerPath = $fixtureRepo . '/' . FormalMeasurementWindowContext::POINTER_RELATIVE_PATH;
    planV2WriteJson($pointerPath, ['schema_version' => FormalMeasurementWindowContext::POINTER_SCHEMA_VERSION, 'window_id' => $oldId, 'window_root' => 'var/strict_forward_live_readiness/windows/' . $oldId]);
    $evidence = planV2SeedEvidence($oldRoot, $oldId);
    $oldEvidenceBefore = planV2Tree($oldRoot);
    $comparative = ComparativeContractV1::production($fixtureRepo)->identities();
    $start = [
        'schema_version' => OfficialEpochStartStore::SCHEMA_VERSION, 'epoch_id' => $epochId,
        'epoch_key' => 'sha256:' . hash('sha256', 'fixture-epoch-key'),
        'authorization_hash' => 'sha256:' . hash('sha256', 'fixture-epoch-authorization'),
        'epoch_policy_identity' => OfficialEpochPolicyV1::identity(),
        'incident_policy_schema' => OfficialEpochIncidentPolicyV1::SCHEMA_VERSION,
        'incident_policy_identity' => OfficialEpochIncidentPolicyV1::identity(),
        'source_closed_window_id' => 'fixture-source-closed',
    ] + $comparative;
    $start['record_hash'] = OfficialEpochWindowBindingV1::hash($start);
    $lifecycleRoot = $fixtureRepo . '/var/strict_forward_live_readiness/lifecycle';
    planV2Assert((new OfficialEpochStartStore($lifecycleRoot))->create($start)['accepted'], 'fixture_epoch_active');

    // Simulate the already-deployed producer fix before vNext is frozen.
    $driftPath = $fixtureRepo . '/analysis/finalize_strict_forward_late_eligibility.php';
    file_put_contents($driftPath, (string)file_get_contents($driftPath) . "\n// fixture-vnext-root-cause-fix\n");

    $store = new OfficialEpochIncidentLifecycleStore($lifecycleRoot);
    $windowLifecycle = new FormalMeasurementWindowLifecycle($fixtureRepo);
    $successor = []; $incident = []; $attestation = []; $resolution = []; $binding = [];
    $scheduleHash = ''; $activation = []; $runtime = [];

    $handlers = [
        'ENTER_MAINTENANCE_HOLD' => function () use (&$state): array {
            $state['scheduler_held'] = true; $state['capture_process_count'] = 0;
            return ['accepted' => true, 'status' => 'FIXTURE_HOLD_ACTIVE'];
        },
        'CREATE_SUCCESSOR_PREPARED_MANIFEST' => function () use (&$successor, $fixtureRepo, $successorId): array {
            $successor = (new FormalMeasurementWindowPrepareOnlyV1($fixtureRepo))->create(['window_id' => $successorId]);
            return ['accepted' => !empty($successor['accepted']), 'status' => (string)($successor['status'] ?? ''), 'reason' => (string)($successor['reason'] ?? '')];
        },
        'FREEZE_SUCCESSOR_VNEXT_IDENTITY' => function () use (&$successor, $oldCreate): array {
            $newIdentity = (string)($successor['implementation']['implementation_identity'] ?? '');
            $oldIdentity = (string)($oldCreate['implementation']['implementation_identity'] ?? '');
            $ok = preg_match('/^sha256:[a-f0-9]{64}$/', $newIdentity) === 1 && !hash_equals($oldIdentity, $newIdentity)
                && is_array($successor['prepared_contract'] ?? null);
            return ['accepted' => $ok, 'status' => $ok ? 'FIXTURE_VNEXT_IDENTITY_FROZEN' : 'BLOCKED'];
        },
        'APPEND_MAJOR_INCIDENT_V1' => function () use (&$incident, $store, $epochId, $oldId, $evidence): array {
            $built = $store->technicalIncompleteIncidentRecord([
                'epoch_id' => $epochId, 'source_window_id' => $oldId, 'detected_at' => '2026-10-20T10:00:00+09:00',
                'affected_scope' => ['window_id' => $oldId, 'race_codes' => $evidence['missing_races']],
                'source_evidence_ref' => 'fixture://old-evidence', 'source_evidence_hash' => 'sha256:' . hash('sha256', implode("\n", $evidence['missing_keys'])),
                'incident_type' => 'SCHEDULER_FAILURE_MISSED_OPPORTUNITY', 'affected_scope_kind' => 'WINDOW',
                'formal_population_completeness_lost' => true, 'future_only_reconstruction_complete' => false,
                'requires_new_window' => true, 'bounded_scope' => true, 'measurement_semantics_unchanged' => true, 'affected_count' => 8,
            ]);
            if (empty($built['accepted'])) return $built;
            $incident = $built['record'];
            return $store->createIncident($incident);
        },
        'WRITE_TECHNICAL_INCOMPLETE_ATTESTATION' => function () use (&$attestation, $fixtureRepo, $oldRoot, $oldId, $epochId, $oldCreate, &$incident, $evidence): array {
            $boundaryPath = $fixtureRepo . '/analysis/output/cc30c_formal_evidence_aggregate_contract_v2.json';
            $builder = new TechnicalIncompleteEvidenceAttestation($fixtureRepo);
            $attestation = $builder->build($oldRoot, [
                'window_id' => $oldId, 'epoch_id' => $epochId, 'attested_at' => '2026-10-20T10:01:00+09:00',
                'implementation_identity' => (string)$oldCreate['implementation']['implementation_identity'],
                'window_start_date' => '2026-10-16', 'capture_through_date' => '2026-10-19',
                'required_inventory_dates' => ['2026-10-16','2026-10-17','2026-10-18','2026-10-19'],
                'boundary_contract_path' => 'analysis/output/cc30c_formal_evidence_aggregate_contract_v2.json',
                'boundary_contract_sha256' => 'sha256:' . hash_file('sha256', $boundaryPath),
                'incident_id' => (string)$incident['incident_id'], 'incident_hash' => (string)$incident['record_hash'],
                'incident_type' => TechnicalIncompleteEvidenceAttestation::INCIDENT_TYPE,
                'severity' => TechnicalIncompleteEvidenceAttestation::SEVERITY,
                'repair_reconstructibility' => TechnicalIncompleteEvidenceAttestation::REPAIR_RECONSTRUCTIBILITY,
                'recovery_strategy' => TechnicalIncompleteEvidenceAttestation::RECOVERY_STRATEGY,
                'historical_repair_performed' => false, 'reconstruction_marker_detected' => false,
                'manual_contamination_detected' => false, 'fixture_contamination_detected' => false,
                'missing_resolution_keys' => $evidence['missing_keys'], 'missing_resolution_race_codes' => $evidence['missing_races'],
            ]);
            return $builder->persistCreateOnly($attestation);
        },
        'ABORT_SOURCE_WINDOW_V2' => function () use ($windowLifecycle, $oldId, $epochId, $oldActivate, $oldCreate, &$incident, &$attestation, $pointerPath, $evidence): array {
            $identity = (string)$oldCreate['implementation']['implementation_identity'];
            return $windowLifecycle->createTechnicalIncompleteAbortV2([
                'window_id' => $oldId, 'epoch_id' => $epochId, 'armed_at' => (string)$oldActivate['manifest']['armed_at'],
                'aborted_at' => '2026-10-20T10:02:00+09:00', 'window_start_date' => '2026-10-16', 'capture_through_date' => '2026-10-19',
                'incident_id' => (string)$incident['incident_id'], 'incident_hash' => (string)$incident['record_hash'],
                'affected_keyset_hash' => TechnicalIncompleteEvidenceAttestation::keysetHash($evidence['missing_races']),
                'affected_race_codes' => $evidence['missing_races'], 'missing_resolution_count' => 8,
                'final_evidence_hash' => (string)$attestation['attestation_sha256'], 'verified_final_evidence_hash' => (string)$attestation['attestation_sha256'],
                'old_implementation_identity' => $identity, 'activation_implementation_identity' => $identity,
                'previous_pointer_identity' => 'sha256:' . hash_file('sha256', $pointerPath), 'closing_tool_implementation_identity' => $identity,
                'historical_repair_performed' => false, 'successor_required' => true, 'repair_reconstructibility' => 'PARTIAL',
                'evidence_attestation_schema' => TechnicalIncompleteEvidenceAttestation::SCHEMA_VERSION,
                'evidence_attestation_sha256' => (string)$attestation['attestation_sha256'],
                'missing_resolution_keyset_sha256' => (string)$attestation['missing_resolution_keyset_sha256'],
                'completeness_status' => TechnicalIncompleteEvidenceAttestation::COMPLETENESS_STATUS,
            ]);
        },
        'REGISTER_LATE_ELIGIBILITY_SCHEDULE' => function () use (&$state, $fixtureRepo): array {
            $loaded = StrictForwardLateEligibilityScheduleContract::load($fixtureRepo);
            if (empty($loaded['accepted'])) return $loaded;
            $contract = $loaded['contract'];
            $state['fixture_crontab'] = $contract['cron_expression'] . ' ' . $contract['runner']['command_prefix'] . ' ' . $fixtureRepo . '/' . $contract['runner']['repo_relative_path'] . ' # ' . $contract['runner']['marker'] . "\n";
            return ['accepted' => true, 'status' => 'FIXTURE_SCHEDULE_REGISTERED'];
        },
        'ATTEST_RUNTIME_LATE_SCHEDULE' => function () use (&$state, $fixtureRepo, &$scheduleHash): array {
            $result = StrictForwardLateEligibilityScheduleContract::attestCrontabText($fixtureRepo, (string)($state['fixture_crontab'] ?? ''));
            if (!empty($result['accepted'])) $scheduleHash = (string)$result['contract_sha256'];
            return $result;
        },
        'APPEND_TECHNICAL_INCOMPLETE_RESOLUTION_V3' => function () use (&$resolution, $store, $epochId, $oldId, $oldCreate, &$successor, &$incident, $evidence): array {
            $input = [
                'epoch_id' => $epochId, 'incident_id' => (string)$incident['incident_id'], 'incident_hash' => (string)$incident['record_hash'],
                'affected_window_id' => $oldId, 'detected_at' => '2026-10-20T10:00:00+09:00', 'resolved_at' => '2026-10-20T10:04:00+09:00',
                'root_cause' => 'late eligibility producer schedule gap', 'root_cause_fixed' => true,
                'old_implementation_identity' => (string)$oldCreate['implementation']['implementation_identity'],
                'new_implementation_identity' => (string)$successor['implementation']['implementation_identity'],
                'affected_race_codes' => $evidence['missing_races'], 'incident_type' => 'SCHEDULER_FAILURE_MISSED_OPPORTUNITY',
                'severity' => 'MAJOR', 'repair_reconstructibility' => 'PARTIAL', 'historical_repair_performed' => false,
                'requires_new_window' => true, 'requires_new_epoch' => false,
                'recovery_strategy' => OfficialEpochIncidentLifecycleStore::TECHNICAL_INCOMPLETE_RECOVERY_STRATEGY,
                'resolution_status' => 'RESOLVED',
            ];
            $built = $store->technicalIncompleteResolutionRecord($input);
            if (empty($built['accepted'])) return $built;
            $resolution = $built['record'];
            return $store->createTechnicalIncompleteResolution($resolution);
        },
        'CREATE_SUCCESSOR_BINDING' => function () use (&$binding, $fixtureRepo, $successorId, &$successor, &$resolution, &$scheduleHash): array {
            $validation = planV2Validation($fixtureRepo, (string)$successor['implementation']['implementation_identity'], [
                'resolution_hash' => (string)$resolution['record_hash'], 'schedule_hash' => $scheduleHash,
            ]);
            $result = (new OfficialEpochWindowBindingStore($fixtureRepo))->createForPrepared($successorId, $validation);
            if (!empty($result['accepted'])) $binding = $result['record'];
            return $result;
        },
        'POINTER_CAS_TO_BOUND_PREPARED_SUCCESSOR' => function () use ($windowLifecycle, $pointerPath, $oldId, $successorId): array {
            return $windowLifecycle->handoffPointerCas($pointerPath, $oldId, [
                'schema_version' => FormalMeasurementWindowContext::POINTER_SCHEMA_VERSION,
                'window_id' => $successorId, 'window_root' => 'var/strict_forward_live_readiness/windows/' . $successorId,
            ], 'fixture-plan-v2');
        },
        'CREATE_SUCCESSOR_ACTIVATION_RECORD' => function () use (&$activation, $fixtureRepo, $successorId): array {
            $context = FormalMeasurementWindowContext::fromWindowRoot($fixtureRepo, $fixtureRepo . '/var/strict_forward_live_readiness/windows/' . $successorId);
            $activation = $context->activationHold()->activate('fixture://plan-v2-preflight', 'PRECHECK_PASS');
            return $activation;
        },
        'TRANSITION_SUCCESSOR_ACTIVE' => function () use (&$activation): array {
            $ok = !empty($activation['accepted']) && ($activation['manifest']['status'] ?? '') === 'ACTIVE' && ($activation['manifest']['activation_state'] ?? '') === 'ACTIVE';
            return ['accepted' => $ok, 'status' => $ok ? 'FIXTURE_SUCCESSOR_ACTIVE' : 'BLOCKED'];
        },
        'VERIFY_SUCCESSOR_RUNTIME_AUTHORIZATION' => function () use (&$runtime, $fixtureRepo): array {
            $runtime = (new OfficialEpochWindowBindingRuntimeAuthorization($fixtureRepo))->authorizeOperationalPointerWindow();
            return ['accepted' => !empty($runtime['accepted']), 'status' => (string)($runtime['status'] ?? ''), 'reason' => (string)($runtime['reason'] ?? '')];
        },
        'RELEASE_MAINTENANCE_HOLD' => function () use (&$state, &$runtime): array {
            if (empty($runtime['accepted'])) return ['accepted' => false, 'status' => 'BLOCKED', 'reason' => 'RUNTIME_NOT_AUTHORIZED'];
            $state['scheduler_held'] = false;
            return ['accepted' => true, 'status' => 'FIXTURE_HOLD_RELEASED'];
        },
        'OPEN_FUTURE_ONLY_NATURAL_CAPTURE' => function () use ($fixtureRepo, $successorId): array {
            $count = planV2FormalEvidenceCount($fixtureRepo . '/var/strict_forward_live_readiness/windows/' . $successorId);
            return ['accepted' => $count === 0, 'status' => $count === 0 ? 'FIXTURE_FUTURE_ONLY_GATE_OPEN_NO_EVIDENCE' : 'BLOCKED', 'initial_evidence' => $count];
        },
    ];

    foreach ($operations as $operation) {
        $id = (string)$operation['operation_id'];
        planV2Assert(isset($handlers[$id]), 'fixture_handler_exists', $id);
        $result = $handlers[$id]();
        $execution[] = ['sequence_number' => $operation['sequence_number'], 'operation_id' => $id, 'accepted' => !empty($result['accepted']), 'status' => (string)($result['status'] ?? ''), 'reason' => (string)($result['reason'] ?? '')];
        planV2Assert(!empty($result['accepted']), 'fixture_operation_pass:' . $id, $result);
    }

    $oldState = $windowLifecycle->effectiveState($oldId, $oldContext->resolve(true)['manifest']);
    $successorContext = FormalMeasurementWindowContext::fromWindowRoot($fixtureRepo, $fixtureRepo . '/var/strict_forward_live_readiness/windows/' . $successorId);
    $successorResolved = $successorContext->resolve(true);
    $successorState = $successorContext->lifecycle()->effectiveState($successorId, $successorResolved['manifest']);
    $oldEvidenceAfter = planV2Tree($oldRoot);
    $fixtureResult = [
        'status' => 'PASS', 'old_window_state' => $oldState['effective_state'] ?? 'INVALID',
        'old_evidence_preserved' => $oldEvidenceBefore === $oldEvidenceAfter,
        'successor_window_state' => $successorState['effective_state'] ?? 'INVALID',
        'new_window_id' => $successorId !== $oldId,
        'same_epoch' => ($binding['epoch_id'] ?? '') === $epochId,
        'vnext_identity_valid' => preg_match('/^sha256:[a-f0-9]{64}$/', (string)($successor['implementation']['implementation_identity'] ?? '')) === 1,
        'binding_valid' => $binding !== [], 'activation_valid' => !empty($activation['accepted']),
        'runtime_authorization_valid' => !empty($runtime['accepted']),
        'schedule_attested' => $scheduleHash !== '', 'evidence_migration' => 0, 'historical_repair' => 0,
        'successor_initial_evidence' => planV2FormalEvidenceCount($fixtureRepo . '/var/strict_forward_live_readiness/windows/' . $successorId),
        'execution' => $execution,
    ];
    foreach (['old_window_state' => 'ABORTED', 'successor_window_state' => 'ACTIVE'] as $field => $expected) planV2Assert($fixtureResult[$field] === $expected, 'fixture_final_' . $field, $fixtureResult[$field]);
    foreach (['old_evidence_preserved','new_window_id','same_epoch','vnext_identity_valid','binding_valid','activation_valid','runtime_authorization_valid','schedule_attested'] as $field) planV2Assert($fixtureResult[$field] === true, 'fixture_final_' . $field);
    planV2Assert($fixtureResult['successor_initial_evidence'] === 0, 'fixture_successor_initial_evidence_zero');
} finally {
    planV2Remove($fixtureRoot);
}

// The negative tests mutate only the in-memory plan order. The executor's
// dependency graph remains the sole order source; no alternate order is
// hard-coded as an executable path.
$negative = [];
$variants = [];
$withoutAbort = array_values(array_filter($order, static fn(string $id): bool => $id !== 'ABORT_SOURCE_WINDOW_V2'));
$variants['required_operation_missing'] = $withoutAbort;
$bindingBeforeIdentity = $order;
$bindingIndex = array_search('CREATE_SUCCESSOR_BINDING', $bindingBeforeIdentity, true);
$identityIndex = array_search('FREEZE_SUCCESSOR_VNEXT_IDENTITY', $bindingBeforeIdentity, true);
[$bindingBeforeIdentity[$bindingIndex], $bindingBeforeIdentity[$identityIndex]] = [$bindingBeforeIdentity[$identityIndex], $bindingBeforeIdentity[$bindingIndex]];
$variants['binding_before_identity_freeze'] = $bindingBeforeIdentity;
$activationBeforeSchedule = $order;
$activationIndex = array_search('CREATE_SUCCESSOR_ACTIVATION_RECORD', $activationBeforeSchedule, true);
$scheduleIndex = array_search('ATTEST_RUNTIME_LATE_SCHEDULE', $activationBeforeSchedule, true);
[$activationBeforeSchedule[$activationIndex], $activationBeforeSchedule[$scheduleIndex]] = [$activationBeforeSchedule[$scheduleIndex], $activationBeforeSchedule[$activationIndex]];
$variants['activation_before_schedule_attestation'] = $activationBeforeSchedule;
$pointerTooEarly = $order;
$pointerIndex = array_search('POINTER_CAS_TO_BOUND_PREPARED_SUCCESSOR', $pointerTooEarly, true);
$incidentIndex = array_search('APPEND_MAJOR_INCIDENT_V1', $pointerTooEarly, true);
[$pointerTooEarly[$pointerIndex], $pointerTooEarly[$incidentIndex]] = [$pointerTooEarly[$incidentIndex], $pointerTooEarly[$pointerIndex]];
$variants['pointer_cas_too_early'] = $pointerTooEarly;
$successorAfterIncident = $order;
$prepareIndex = array_search('CREATE_SUCCESSOR_PREPARED_MANIFEST', $successorAfterIncident, true);
$incidentIndex = array_search('APPEND_MAJOR_INCIDENT_V1', $successorAfterIncident, true);
[$successorAfterIncident[$prepareIndex], $successorAfterIncident[$incidentIndex]] = [$successorAfterIncident[$incidentIndex], $successorAfterIncident[$prepareIndex]];
$variants['irreversible_incident_before_successor_ready'] = $successorAfterIncident;
foreach ($variants as $name => $variant) {
    $result = planV2ValidateOrder($variant, $graph);
    planV2Assert(!$result['accepted'], 'negative_rejected:' . $name, $result);
    $negative[$name] = ['status' => 'PASS_FAIL_CLOSED', 'reason' => $result['reason']];
}

// Real Window dry-run: assess the current start state and the hypothetical
// post-state of each plan operation. No lifecycle mutator is called.
$realPointerPath = $repo . '/' . FormalMeasurementWindowContext::POINTER_RELATIVE_PATH;
$realPointerBefore = (string)file_get_contents($realPointerPath);
$realPointer = json_decode($realPointerBefore, true, 512, JSON_THROW_ON_ERROR);
$realContext = FormalMeasurementWindowContext::production($repo);
$realResolved = $realContext->resolve(true);
$realState = $realContext->lifecycle()->effectiveState((string)$realResolved['window_id'], $realResolved['manifest']);
$realEpoch = (new OfficialEpochStartStore($repo . '/var/strict_forward_live_readiness/lifecycle'))->existing();
$realProjection = (new OfficialEpochIncidentLifecycleStore($repo . '/var/strict_forward_live_readiness/lifecycle'))->project((string)($realEpoch['epoch_id'] ?? ''));
$realRoot = dirname($realContext->windowManifestPath());
$realTreeBefore = planV2Tree($realRoot);
$realAttestation = (new TechnicalIncompleteEvidenceAttestation($repo))->read(CC30C_PLAN_V2_SOURCE_WINDOW);
$realAbort = $realContext->lifecycle()->readAbort(CC30C_PLAN_V2_SOURCE_WINDOW);
$gateReport = planV2ReadJson(__DIR__ . '/output/cc30c_technical_incomplete_attestation_gate_report.json');
$realChecks = [
    'pointer_targets_source' => ($realPointer['window_id'] ?? '') === CC30C_PLAN_V2_SOURCE_WINDOW,
    'source_window_active' => ($realState['effective_state'] ?? '') === 'ACTIVE',
    'epoch_matches' => ($realEpoch['epoch_id'] ?? '') === CC30C_PLAN_V2_EPOCH,
    'epoch_active' => !empty($realProjection['valid']) && ($realProjection['state'] ?? '') === 'ACTIVE',
    'formal_attestation_absent' => $realAttestation === [],
    'source_abort_absent' => $realAbort === [],
    'technical_incomplete_abort_gate_pass' => ($gateReport['gate']['TECHNICAL_INCOMPLETE_ABORT_GATE'] ?? '') === 'PASS',
    'plan_start_operation_is_hold' => ($order[0] ?? '') === 'ENTER_MAINTENANCE_HOLD',
    'hold_is_planned_not_assumed' => true,
];
$realTreeAfter = planV2Tree($realRoot);
$realChecks['source_window_tree_unchanged'] = $realTreeBefore === $realTreeAfter;
$realChecks['pointer_unchanged'] = hash_equals($realPointerBefore, (string)file_get_contents($realPointerPath));
$realPreflight = !in_array(false, $realChecks, true) ? 'PASS' : 'FAIL';

$report = [
    'schema_version' => 'cc30c-window-rollover-plan-v2-gate-report/v1',
    'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM),
    'source_git_commit' => trim((string)shell_exec('git rev-parse HEAD')),
    'scope' => 'plan design, isolated fixture, permutation negatives, and production read-only preflight only',
    'plan' => ['path' => 'analysis/output/cc30c_window_rollover_mutation_plan_v2.json', 'sha256' => $planHash, 'operation_count' => count($operations)],
    'dependency_graph' => ['status' => 'COMPLETE', 'path' => 'analysis/output/cc30c_window_rollover_dependency_graph_v2.json', 'sha256' => 'sha256:' . hash_file('sha256', $graphPath)],
    'failure_matrix' => ['status' => 'COMPLETE', 'path' => 'analysis/output/cc30c_window_rollover_failure_matrix_v2.json', 'sha256' => 'sha256:' . hash_file('sha256', $failurePath)],
    'single_sequence' => ['status' => 'DEFINED', 'operations' => $order],
    'fixture' => $fixtureResult,
    'permutation_negative_tests' => ['status' => 'PASS', 'tests' => $negative],
    'real_window_preflight' => ['status' => $realPreflight, 'checks' => $realChecks, 'current_scheduler_hold' => 'NOT_ASSUMED; operation 1 must establish it before any subsequent mutation'],
    'gates' => [
        'DEPENDENCY_GRAPH' => 'COMPLETE', 'SINGLE_SEQUENCE' => 'DEFINED', 'PLAN_V2' => 'READY',
        'PLAN_V2_HASH' => 'FIXED', 'ROLLOVER_FIXTURE_FROM_PLAN' => ($fixtureResult['status'] ?? '') === 'PASS' ? 'PASS' : 'FAIL',
        'PERMUTATION_NEGATIVE_TESTS' => 'PASS', 'REAL_WINDOW_PLAN_V2_PREFLIGHT' => $realPreflight,
        'RECOVERY_MUTATION_PLAN_V2_GATE' => $realPreflight === 'PASS' ? 'PASS' : 'FAIL',
    ],
    'production_mutations' => [
        'attestation_write' => 0, 'incident_append' => 0, 'abort_v2' => 0, 'successor_window' => 0,
        'schedule_or_cron_change' => 0, 'binding' => 0, 'pointer_cas' => 0, 'activation' => 0,
        'runtime_authorization_change' => 0, 'historical_repair' => 0, 'evidence_migration' => 0,
    ],
    'next_execution_contract' => ['required_plan_sha256' => $planHash, 'production_execution_authorized_this_turn' => false],
];
$reportPath = __DIR__ . '/output/cc30c_window_rollover_plan_v2_gate_report.json';
planV2WriteJson($reportPath, $report);
$readBack = planV2ReadJson($reportPath);
planV2Assert(($readBack['gates']['RECOVERY_MUTATION_PLAN_V2_GATE'] ?? '') === 'PASS', 'gate_report_readback');
echo json_encode(['status' => 'PASS', 'plan_sha256' => $planHash, 'report' => $reportPath, 'gates' => $report['gates']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
