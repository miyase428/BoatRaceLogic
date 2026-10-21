<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/TechnicalIncompleteEvidenceAttestation.php';
require_once __DIR__ . '/../web/logic/FormalMeasurementWindowLifecycle.php';
require_once __DIR__ . '/../web/logic/FormalEvidenceAggregate.php';

const CC30C_WINDOW_ID = 'successor-v3-prepared-20261015084342-8f2a97e7';
const CC30C_WINDOW_ROOT = __DIR__ . '/../var/strict_forward_live_readiness/windows/' . CC30C_WINDOW_ID;
const CC30C_START = '2026-10-16';
const CC30C_THROUGH = '2026-10-19';
const CC30C_IDENTITY = 'sha256:31aeae482187c5c901ec1a4bd7815a4b969bf155e3950d007f8b9d274a238dd7';
const CC30C_EPOCH = 'official-epoch-v1-bce9f9f6a9f27b7d';

/** @var array<string,mixed> */
$checks = [];

function tiAssert(bool $condition, string $label): void
{
    global $checks;
    $checks[$label] = $condition ? 'PASS' : 'FAIL';
    if (!$condition) throw new RuntimeException('ASSERTION_FAILED:' . $label);
}

function tiWriteJson(string $path, array $row): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new RuntimeException('MKDIR_FAILED');
    file_put_contents($path, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
}

/** @return list<string> */
function tiFixtureRaces(string $date): array
{
    $venues = ['AMG','ASY','EDG','GMG','HWJ','KRY','OMR','SMS'];
    $races = [];
    foreach ($venues as $venue) {
        for ($number = 1; $number <= 12; $number++) $races[] = str_replace('-', '', $date) . $venue . sprintf('%02d', $number);
    }
    sort($races, SORT_STRING);
    return array_values(array_filter($races, static fn(string $race): bool => !str_ends_with($race, 'AMG01')
        && !str_ends_with($race, 'AMG02') && !str_ends_with($race, 'AMG03')));
}

/** @return array{root:string,input:array<string,mixed>,missing_keys:list<string>,missing_races:list<string>} */
function tiFixture(string $name, bool $complete = false): array
{
    $root = sys_get_temp_dir() . '/cc30c-ti-attestation-' . $name . '-' . bin2hex(random_bytes(6));
    if (is_dir($root)) throw new RuntimeException('FIXTURE_PATH_EXISTS:' . $root);
    $windowId = 'fixture-window-' . $name;
    $identity = 'sha256:' . str_repeat('1', 64);
    tiWriteJson($root . '/validation_window_manifest.json', ['validation_window_id' => $windowId, 'status' => 'ACTIVE', 'activation_state' => 'ACTIVE']);
    tiWriteJson($root . '/activation_record.json', ['window_id' => $windowId, 'implementation_identity' => $identity,
        'manifest_ref' => 'implementation_manifests/' . str_repeat('1', 64) . '.json',
        'source_freeze_ref' => 'implementation_sources/' . str_repeat('1', 64) . '.json']);
    tiWriteJson($root . '/declared_implementation_identity.json', ['window_id' => $windowId, 'implementation_identity' => $identity,
        'manifest_ref' => 'implementation_manifests/' . str_repeat('1', 64) . '.json',
        'source_freeze_ref' => 'implementation_sources/' . str_repeat('1', 64) . '.json']);
    tiWriteJson($root . '/provenance/implementation_manifests/' . str_repeat('1', 64) . '.json', ['fixture_manifest' => true]);
    tiWriteJson($root . '/provenance/implementation_sources/' . str_repeat('1', 64) . '.json', ['fixture_source' => true]);
    tiWriteJson($root . '/provenance/implementation_manifests/' . str_repeat('2', 64) . '.json', ['auxiliary' => true]);
    tiWriteJson($root . '/provenance/implementation_sources/' . str_repeat('2', 64) . '.json', ['auxiliary' => true]);
    $missingRaces = [];
    foreach (['2026-10-16','2026-10-17','2026-10-18','2026-10-19'] as $date) {
        $entries = [];
        foreach (tiFixtureRaces($date) as $race) {
            $key = 'scheduler-opportunity|' . $race;
            $entries[] = ['stable_candidate_key' => $key, 'race_code' => $race, 'target_date' => $date];
            $file = hash('sha256', $key) . '.json';
            $base = ['stable_candidate_key' => $key, 'race_code' => $race, 'target_date' => $date];
            tiWriteJson($root . '/opportunity/candidates/' . str_replace('-', '', $date) . '/' . $file, $base + ['record_type' => 'SCHEDULER_CANDIDATE_UNIVERSE']);
            $isMissing = str_ends_with($race, 'SMS11') || str_ends_with($race, 'SMS12');
            if ($isMissing && !$complete) $missingRaces[] = $race;
            else tiWriteJson($root . '/opportunity/eligibility/' . str_replace('-', '', $date) . '/' . $file, $base + ['record_type' => 'SCHEDULER_ELIGIBILITY_RESOLUTION', 'eligibility_status' => 'ELIGIBLE_OBSERVED']);
        }
        tiWriteJson($root . '/opportunity/expected_inventory/' . str_replace('-', '', $date) . '.json', [
            'schema_version' => 'gate-a1-prospective-inventory/v1', 'window_id' => $windowId,
            'target_date' => $date, 'expected_entries' => $entries,
        ]);
    }
    sort($missingRaces, SORT_STRING);
    $missingKeys = array_map(static fn(string $race): string => 'scheduler-opportunity|' . $race, $missingRaces);
    $boundaryPath = __DIR__ . '/output/cc30c_formal_evidence_aggregate_contract_v2.json';
    $input = [
        'window_id' => $windowId, 'epoch_id' => 'fixture-epoch', 'attested_at' => '2026-10-20T09:00:00+09:00',
        'implementation_identity' => $identity, 'window_start_date' => CC30C_START, 'capture_through_date' => CC30C_THROUGH,
        'required_inventory_dates' => ['2026-10-16','2026-10-17','2026-10-18','2026-10-19'],
        'boundary_contract_path' => 'analysis/output/cc30c_formal_evidence_aggregate_contract_v2.json',
        'boundary_contract_sha256' => 'sha256:' . hash_file('sha256', $boundaryPath),
        'incident_id' => 'fixture-incident', 'incident_hash' => 'sha256:' . str_repeat('4', 64),
        'incident_type' => TechnicalIncompleteEvidenceAttestation::INCIDENT_TYPE,
        'severity' => TechnicalIncompleteEvidenceAttestation::SEVERITY,
        'repair_reconstructibility' => TechnicalIncompleteEvidenceAttestation::REPAIR_RECONSTRUCTIBILITY,
        'recovery_strategy' => TechnicalIncompleteEvidenceAttestation::RECOVERY_STRATEGY,
        'historical_repair_performed' => false, 'reconstruction_marker_detected' => false,
        'manual_contamination_detected' => false, 'fixture_contamination_detected' => false,
        'missing_resolution_keys' => $missingKeys, 'missing_resolution_race_codes' => $missingRaces,
    ];
    return ['root' => $root, 'input' => $input, 'missing_keys' => $missingKeys, 'missing_races' => $missingRaces];
}

/** @return string */
function tiExpectFailure(callable $callback): string
{
    try { $callback(); } catch (Throwable $error) { return $error->getMessage(); }
    return 'UNEXPECTED_PASS';
}

/** @return array{count:int,sha256:string} */
function tiTreeIdentity(string $root): array
{
    $resolvedRoot = realpath($root);
    if (!is_string($resolvedRoot)) throw new RuntimeException('TREE_ROOT_UNRESOLVED');
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolvedRoot, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && $file->isFile()) $files[] = $file->getPathname();
    }
    sort($files, SORT_STRING); $framed = '';
    foreach ($files as $path) $framed .= str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($path, strlen($resolvedRoot)), DIRECTORY_SEPARATOR)) . "\0" . hash_file('sha256', $path) . "\n";
    return ['count' => count($files), 'sha256' => 'sha256:' . hash('sha256', $framed)];
}

/** @return array{count:int,sha256:string,canonical_membership_count:int,canonical_membership_sha256:string} */
function tiTodaNaturalEvidenceIdentity(string $repoRoot): array
{
    $paths = [];
    foreach (glob(CC30C_WINDOW_ROOT . '/ledger/records/*/*.json') ?: [] as $path) {
        $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (str_starts_with((string)($row['race_code'] ?? ''), '20261016TDA')) $paths[] = $path;
    }
    sort($paths, SORT_STRING); $framed = '';
    foreach ($paths as $path) {
        $absolute = realpath($path);
        if (!is_string($absolute)) throw new RuntimeException('TODA_RAW_PATH_UNRESOLVED');
        $framed .= str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($absolute, strlen($repoRoot)), DIRECTORY_SEPARATOR)) . "\0" . hash_file('sha256', $absolute) . "\n";
    }
    $canonicalPath = __DIR__ . '/output/cc30c_toda_post12r_20261016_corrected_v2.json';
    $canonical = json_decode((string)file_get_contents($canonicalPath), true, 512, JSON_THROW_ON_ERROR);
    return ['count' => count($paths), 'sha256' => 'sha256:' . hash('sha256', $framed),
        'canonical_membership_count' => (int)($canonical['formal_membership_count'] ?? -1),
        'canonical_membership_sha256' => (string)($canonical['membership_sha256'] ?? '')];
}

/** @return array{input:array<string,mixed>,missing_keys:list<string>,missing_races:list<string>} */
function tiRealInput(): array
{
    $expected = []; $candidate = []; $eligibility = [];
    foreach (glob(CC30C_WINDOW_ROOT . '/opportunity/expected_inventory/*.json') ?: [] as $path) {
        $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        foreach ($row['expected_entries'] ?? [] as $entry) $expected[(string)$entry['stable_candidate_key']] = (string)$entry['race_code'];
    }
    foreach (glob(CC30C_WINDOW_ROOT . '/opportunity/candidates/*/*.json') ?: [] as $path) {
        $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); $candidate[(string)$row['stable_candidate_key']] = (string)$row['race_code'];
    }
    foreach (glob(CC30C_WINDOW_ROOT . '/opportunity/eligibility/*/*.json') ?: [] as $path) {
        $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); $eligibility[(string)$row['stable_candidate_key']] = (string)$row['race_code'];
    }
    $missingKeys = array_values(array_diff(array_keys($expected), array_keys($eligibility))); sort($missingKeys, SORT_STRING);
    $missingRaces = array_map(static fn(string $key): string => $expected[$key], $missingKeys); sort($missingRaces, SORT_STRING);
    $contractPath = __DIR__ . '/output/cc30c_formal_evidence_aggregate_contract_v2.json';
    $contract = json_decode((string)file_get_contents($contractPath), true, 512, JSON_THROW_ON_ERROR);
    tiAssert(($contract['required_dates'] ?? null) === ['2026-10-16','2026-10-17','2026-10-18','2026-10-19'], 'existing_boundary_contract_exact_required_dates');
    tiAssert(count($expected) === 372 && count($candidate) === 372 && count($eligibility) === 364, 'real_keyset_cardinality_372_372_364');
    return ['missing_keys' => $missingKeys, 'missing_races' => $missingRaces, 'input' => [
        'window_id' => CC30C_WINDOW_ID, 'epoch_id' => CC30C_EPOCH, 'attested_at' => '2026-10-20T09:00:00+09:00',
        'implementation_identity' => CC30C_IDENTITY, 'window_start_date' => CC30C_START, 'capture_through_date' => CC30C_THROUGH,
        'required_inventory_dates' => $contract['required_dates'],
        'boundary_contract_path' => 'analysis/output/cc30c_formal_evidence_aggregate_contract_v2.json',
        'boundary_contract_sha256' => 'sha256:' . hash_file('sha256', $contractPath),
        'incident_id' => 'SIMULATED_NOT_PERSISTED',
        'incident_hash' => 'sha256:' . hash('sha256', 'CC30C|SIMULATED|SCHEDULER_FAILURE_MISSED_OPPORTUNITY|MAJOR|' . implode("\n", $missingRaces)),
        'incident_type' => TechnicalIncompleteEvidenceAttestation::INCIDENT_TYPE,
        'severity' => TechnicalIncompleteEvidenceAttestation::SEVERITY,
        'repair_reconstructibility' => TechnicalIncompleteEvidenceAttestation::REPAIR_RECONSTRUCTIBILITY,
        'recovery_strategy' => TechnicalIncompleteEvidenceAttestation::RECOVERY_STRATEGY,
        'historical_repair_performed' => false, 'reconstruction_marker_detected' => false,
        'manual_contamination_detected' => false, 'fixture_contamination_detected' => false,
        'missing_resolution_keys' => $missingKeys, 'missing_resolution_race_codes' => $missingRaces,
    ]];
}

$repoRoot = realpath(__DIR__ . '/..');
if (!is_string($repoRoot)) throw new RuntimeException('REPO_ROOT_UNAVAILABLE');
$fixture = tiFixture('valid');
$builder = new TechnicalIncompleteEvidenceAttestation($repoRoot);
$attestation = $builder->build($fixture['root'], $fixture['input']);
tiAssert($builder->valid($attestation, (string)$fixture['input']['window_id']), 'fixture_1_attestation_valid');
tiAssert(($attestation['inventory_identity']['expected']['count'] ?? 0) === 372, 'fixture_1_expected_372');
tiAssert(($attestation['inventory_identity']['candidate']['count'] ?? 0) === 372, 'fixture_1_candidate_372');
tiAssert(($attestation['inventory_identity']['eligibility_resolution']['count'] ?? 0) === 364, 'fixture_1_eligibility_364');
tiAssert(($attestation['missing_resolution_count'] ?? 0) === 8, 'fixture_1_missing_8');

$abortInput = [
    'window_id' => $fixture['input']['window_id'], 'epoch_id' => 'fixture-epoch',
    'armed_at' => '2026-10-15T09:09:26+09:00', 'aborted_at' => '2026-10-20T09:10:00+09:00',
    'capture_through_date' => CC30C_THROUGH, 'window_start_date' => CC30C_START,
    'incident_id' => 'fixture-incident', 'incident_hash' => 'sha256:' . str_repeat('4', 64),
    'affected_keyset_hash' => TechnicalIncompleteEvidenceAttestation::keysetHash($fixture['missing_races']),
    'affected_race_codes' => $fixture['missing_races'], 'missing_resolution_count' => 8,
    'final_evidence_hash' => $attestation['attestation_sha256'], 'verified_final_evidence_hash' => $attestation['attestation_sha256'],
    'old_implementation_identity' => $fixture['input']['implementation_identity'], 'activation_implementation_identity' => $fixture['input']['implementation_identity'],
    'previous_pointer_identity' => 'sha256:' . str_repeat('5', 64), 'closing_tool_implementation_identity' => 'sha256:' . str_repeat('6', 64),
    'historical_repair_performed' => false, 'recovery_strategy' => FormalMeasurementWindowLifecycle::TECHNICAL_INCOMPLETE_RECOVERY_STRATEGY,
    'evidence_retention_policy' => FormalMeasurementWindowLifecycle::TECHNICAL_INCOMPLETE_EVIDENCE_RETENTION_POLICY,
    'successor_required' => true, 'repair_reconstructibility' => 'PARTIAL',
    'evidence_attestation_schema' => TechnicalIncompleteEvidenceAttestation::SCHEMA_VERSION,
    'evidence_attestation_sha256' => $attestation['attestation_sha256'],
    'missing_resolution_keyset_sha256' => $attestation['missing_resolution_keyset_sha256'],
    'completeness_status' => 'INCOMPLETE',
];
$lifecycle = new FormalMeasurementWindowLifecycle($repoRoot);
$abort = $lifecycle->technicalIncompleteAbortV2Record($abortInput);
tiAssert($abort['accepted'] && ($abort['abort']['evidence_basis'] ?? '') === 'TECHNICAL_INCOMPLETE_ATTESTATION', 'fixture_1_abort_v2_attestation_path');

$badMissing = $fixture['input']; array_pop($badMissing['missing_resolution_keys']);
tiAssert(tiExpectFailure(fn() => $builder->build($fixture['root'], $badMissing)) === 'ATTESTATION_MISSING_SET_MISMATCH', 'fixture_2_missing_set_mismatch_fail_closed');
$mismatch = tiFixture('expected-candidate');
$candidateFiles = glob($mismatch['root'] . '/opportunity/candidates/*/*.json') ?: []; unlink($candidateFiles[0]);
tiAssert(tiExpectFailure(fn() => $builder->build($mismatch['root'], $mismatch['input'])) === 'ATTESTATION_EXPECTED_CANDIDATE_MISMATCH', 'fixture_3_expected_candidate_mismatch_fail_closed');
$extra = tiFixture('eligibility-extra');
tiWriteJson($extra['root'] . '/opportunity/eligibility/20261016/extra.json', ['stable_candidate_key' => 'scheduler-opportunity|20261016ZZZ99', 'race_code' => '20261016ZZZ99', 'target_date' => '2026-10-16']);
tiAssert(tiExpectFailure(fn() => $builder->build($extra['root'], $extra['input'])) === 'ATTESTATION_UNEXPECTED_ELIGIBILITY_EXTRA', 'fixture_4_eligibility_extra_fail_closed');
$repair = $fixture['input']; $repair['historical_repair_performed'] = true;
tiAssert(tiExpectFailure(fn() => $builder->build($fixture['root'], $repair)) === 'ATTESTATION_HISTORICAL_REPAIR_DETECTED', 'fixture_5_historical_repair_fail_closed');
$manual = $fixture['input']; $manual['manual_contamination_detected'] = true;
tiAssert(tiExpectFailure(fn() => $builder->build($fixture['root'], $manual)) === 'ATTESTATION_MANUAL_OR_FIXTURE_CONTAMINATION', 'fixture_6_manual_contamination_fail_closed');
$badBoundaryHash = $fixture['input']; $badBoundaryHash['boundary_contract_sha256'] = 'sha256:' . str_repeat('9', 64);
tiAssert(tiExpectFailure(fn() => $builder->build($fixture['root'], $badBoundaryHash)) === 'ATTESTATION_BOUNDARY_CONTRACT_IDENTITY_MISMATCH', 'boundary_contract_hash_mismatch_fail_closed');
$tamperedAttestation = $attestation; $tamperedAttestation['missing_resolution_count'] = 7;
tiAssert(!$builder->valid($tamperedAttestation, (string)$fixture['input']['window_id']), 'attestation_hash_or_missing_set_tamper_fail_closed');
$missingInventory = tiFixture('inventory-missing'); unlink($missingInventory['root'] . '/opportunity/expected_inventory/20261018.json');
tiAssert(tiExpectFailure(fn() => $builder->build($missingInventory['root'], $missingInventory['input'])) === 'ATTESTATION_INVENTORY_INCOMPLETE:2026-10-18', 'fixture_7_required_inventory_missing_fail_closed');
$repeat = $builder->build($fixture['root'], $fixture['input']);
tiAssert($repeat === $attestation && hash_equals((string)$repeat['attestation_sha256'], (string)$attestation['attestation_sha256']), 'fixture_8_deterministic_payload_and_hash');
$storeRoot = sys_get_temp_dir() . '/cc30c-ti-attestation-store-' . bin2hex(random_bytes(6));
$store = new TechnicalIncompleteEvidenceAttestation($storeRoot);
$firstWrite = $store->persistCreateOnly($attestation);
$sameWrite = $store->persistCreateOnly($attestation);
$changedInput = $fixture['input']; $changedInput['attested_at'] = '2026-10-20T09:00:01+09:00';
$changedAttestation = $builder->build($fixture['root'], $changedInput);
$conflictingWrite = $store->persistCreateOnly($changedAttestation);
tiAssert($firstWrite['accepted'] && $firstWrite['status'] === 'ATTESTATION_CREATED'
    && $sameWrite['accepted'] && $sameWrite['status'] === 'ATTESTATION_ALREADY_EXISTS'
    && !$conflictingWrite['accepted'] && $conflictingWrite['reason'] === 'ATTESTATION_CONFLICT', 'first_write_immutable_idempotent_contract');

$complete = tiFixture('complete', true);
tiAssert(tiExpectFailure(fn() => $builder->build($complete['root'], $complete['input'])) === 'ATTESTATION_MISSING_RACE_SET_MISMATCH', 'complete_window_no_attestation_fallback');
$legacyHash = 'sha256:' . str_repeat('7', 64);
$legacyInput = $abortInput;
unset($legacyInput['evidence_attestation_schema'], $legacyInput['evidence_attestation_sha256'], $legacyInput['missing_resolution_keyset_sha256'], $legacyInput['completeness_status']);
$legacyInput['final_evidence_hash'] = $legacyHash; $legacyInput['verified_final_evidence_hash'] = $legacyHash;
$legacy = $lifecycle->technicalIncompleteAbortV2Record($legacyInput);
tiAssert($legacy['accepted'] && ($legacy['abort']['evidence_basis'] ?? '') === 'COMPLETE_FORMAL_AGGREGATE', 'abort_v2_complete_path_regression');
$v1Repo = sys_get_temp_dir() . '/cc30c-ti-abort-v1-' . bin2hex(random_bytes(6));
$v1Lifecycle = new FormalMeasurementWindowLifecycle($v1Repo);
tiWriteJson($v1Lifecycle->abortPath('legacy-window'), [
    'schema_version' => FormalMeasurementWindowLifecycle::ABORT_SCHEMA_VERSION,
    'window_id' => 'legacy-window',
    'abort_reason' => FormalMeasurementWindowLifecycle::ABORT_REASON_A1_EXPECTED_INVENTORY_BOOTSTRAP_INVALID,
    'official_epoch' => 'NO',
    'abort_record_hash' => 'sha256:' . str_repeat('8', 64),
]);
$v1State = $v1Lifecycle->effectiveState('legacy-window', ['activation_state' => 'ACTIVE', 'status' => 'ACTIVE']);
tiAssert(($v1State['effective_state'] ?? '') === 'ABORTED', 'abort_v1_regression');

$realBefore = tiTreeIdentity(CC30C_WINDOW_ROOT);
$todaBefore = tiTodaNaturalEvidenceIdentity($repoRoot);
$real = tiRealInput();
$simulated = $builder->build(CC30C_WINDOW_ROOT, $real['input']);
$simulatedAgain = $builder->build(CC30C_WINDOW_ROOT, $real['input']);
$realAfter = tiTreeIdentity(CC30C_WINDOW_ROOT);
$todaAfter = tiTodaNaturalEvidenceIdentity($repoRoot);
tiAssert($builder->valid($simulated, CC30C_WINDOW_ID), 'real_window_simulated_attestation_valid');
tiAssert($simulated === $simulatedAgain, 'real_window_simulation_deterministic');
tiAssert(($simulated['missing_resolution_race_codes'] ?? null) === [
    '20261016SMS11','20261016SMS12','20261017SMS11','20261017SMS12',
    '20261018SMS11','20261018SMS12','20261019SMS11','20261019SMS12',
], 'real_window_exact_missing_8');
tiAssert($realBefore === $realAfter, 'real_window_evidence_tree_unchanged');
tiAssert($todaBefore === $todaAfter && $todaAfter['count'] === 216
    && $todaAfter['sha256'] === 'sha256:3964a998019aae8aeceddfa8a8a3639c0690782e1150ebe11f32408025d8672b'
    && $todaAfter['canonical_membership_count'] === 216
    && $todaAfter['canonical_membership_sha256'] === 'sha256:922330099a0e4d9620497b64d9afca14d012e0a9760e7bd318170a0b2c16673e', 'toda_raw_216_and_canonical_membership_preserved');
$aggregateFailure = tiExpectFailure(fn() => FormalEvidenceAggregate::calculateMultiDay(CC30C_WINDOW_ROOT, CC30C_WINDOW_ID, CC30C_START, CC30C_THROUGH));
tiAssert($aggregateFailure === 'PROVENANCE_EXPECTED_CANDIDATE_ELIGIBILITY_KEYSET_MISMATCH', 'complete_aggregate_remains_fail_closed');
tiAssert(!is_file($builder->path(CC30C_WINDOW_ID)), 'real_attestation_not_persisted');

$report = [
    'schema_version' => 'cc30c-technical-incomplete-attestation-gate-report/v1',
    'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DateTimeInterface::ATOM),
    'source_git_commit' => trim((string)shell_exec('git rev-parse HEAD')),
    'window_id' => CC30C_WINDOW_ID,
    'source_artifacts' => [
        'attestation_contract_implementation' => ['path' => 'web/logic/TechnicalIncompleteEvidenceAttestation.php', 'sha256' => 'sha256:' . hash_file('sha256', __DIR__ . '/../web/logic/TechnicalIncompleteEvidenceAttestation.php')],
        'abort_v2_integration' => ['path' => 'web/logic/FormalMeasurementWindowLifecycle.php', 'sha256' => 'sha256:' . hash_file('sha256', __DIR__ . '/../web/logic/FormalMeasurementWindowLifecycle.php')],
        'contract_document' => ['path' => 'analysis/output/cc30c_technical_incomplete_evidence_attestation_contract_v1.json', 'sha256' => 'sha256:' . hash_file('sha256', __DIR__ . '/output/cc30c_technical_incomplete_evidence_attestation_contract_v1.json')],
        'fixture_test' => ['path' => 'analysis/test_cc30c_technical_incomplete_attestation.php', 'sha256' => 'sha256:' . hash_file('sha256', __FILE__)],
    ],
    'contract' => ['schema_version' => TechnicalIncompleteEvidenceAttestation::SCHEMA_VERSION, 'status' => 'READY'],
    'window_boundary' => [
        'window_start_date' => CC30C_START, 'capture_through_date' => CC30C_THROUGH,
        'required_dates' => ['2026-10-16','2026-10-17','2026-10-18','2026-10-19'],
        'october_15_classification' => 'C_OLD_WINDOW_BOUNDARY_VS_V2_DISCOVERY_SEMANTICS',
        'october_15_required' => false,
        'basis' => 'The approved formal-evidence-aggregate expected-inventory v2 contract fixes required_dates to 2026-10-16..2026-10-19; armed_at remains the per-race cutoff filter and is not the daily inventory range start.',
        'boundary_contract_path' => $real['input']['boundary_contract_path'],
        'boundary_contract_sha256' => $real['input']['boundary_contract_sha256'],
    ],
    'simulation' => [
        'status' => 'VALID_NOT_PERSISTED', 'attestation_sha256' => $simulated['attestation_sha256'],
        'expected_count' => $simulated['inventory_identity']['expected']['count'],
        'candidate_count' => $simulated['inventory_identity']['candidate']['count'],
        'eligibility_count' => $simulated['inventory_identity']['eligibility_resolution']['count'],
        'missing_resolution_count' => $simulated['missing_resolution_count'],
        'missing_resolution_race_codes' => $simulated['missing_resolution_race_codes'],
        'missing_resolution_keyset_sha256' => $simulated['missing_resolution_keyset_sha256'],
        'formal_evidence_identity' => array_diff_key($simulated['evidence_identity']['formal_evidence'], ['entries' => true]),
        'raw_evidence_identity' => array_diff_key($simulated['evidence_identity']['raw_evidence'], ['entries' => true]),
        'authoritative_provenance_identity' => array_diff_key($simulated['evidence_identity']['authoritative_provenance'], ['entries' => true]),
        'excluded_auxiliary_provenance_identity' => array_diff_key($simulated['evidence_identity']['excluded_auxiliary_provenance'], ['entries' => true]),
        'physical_tree_identity' => array_diff_key($simulated['evidence_identity']['physical_tree'], ['entries' => true]),
        'complete_aggregate_result' => 'FAIL_CLOSED:' . $aggregateFailure,
    ],
    'evidence_preservation' => ['before' => $realBefore, 'after' => $realAfter, 'unchanged' => $realBefore === $realAfter,
        'toda_natural_evidence_before' => $todaBefore, 'toda_natural_evidence_after' => $todaAfter,
        'formal_attestation_written' => false, 'incident_appended' => false, 'abort_created' => false],
    'checks' => $checks,
    'gate' => [
        'ATTESTATION_CONTRACT' => 'READY', 'EXPECTED_CANDIDATE_IDENTITY' => 'PASS',
        'EXPLICIT_MISSING_SET' => 'PASS', 'EXTRA_SET_GUARD' => 'PASS',
        'REQUIRED_INVENTORY_GUARD' => 'PASS', 'DETERMINISTIC_HASH' => 'PASS',
        'FIRST_WRITE_CONTRACT' => 'PASS', 'ABORT_V2_ATTESTATION_INTEGRATION' => 'READY',
        'COMPLETE_WINDOW_REGRESSION' => 'PASS', 'ABORT_V1_REGRESSION' => 'PASS',
        'EVIDENCE_PRESERVATION' => 'PASS', 'SIMULATED_ATTESTATION_VALID' => 'YES',
        'TECHNICAL_INCOMPLETE_ABORT_GATE' => 'PASS',
    ],
    'production_mutations' => [
        'attestation_write' => 0, 'incident_append' => 0, 'abort_v2' => 0,
        'successor_window' => 0, 'pointer_cas' => 0, 'binding_or_activation' => 0,
        'cron_change' => 0, 'historical_repair' => 0, 'evidence_change' => 0,
    ],
];
$out = __DIR__ . '/output/cc30c_technical_incomplete_attestation_gate_report.json';
tiWriteJson($out, $report);
$readBack = json_decode((string)file_get_contents($out), true, 512, JSON_THROW_ON_ERROR);
tiAssert(($readBack['simulation']['attestation_sha256'] ?? '') === $simulated['attestation_sha256'], 'gate_report_readback');
echo json_encode(['status' => 'PASS', 'report' => $out, 'attestation_sha256' => $simulated['attestation_sha256'], 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
