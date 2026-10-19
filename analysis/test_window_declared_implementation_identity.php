<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/StrictForwardReadinessLedger.php';

function declaredAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('ASSERTION_FAILED: ' . $message);
}

function declaredWrite(string $path, string $contents): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new RuntimeException('fixture_directory_unavailable');
    if (file_put_contents($path, $contents) === false) throw new RuntimeException('fixture_write_failed');
}

function declaredCandidate(string $raceCode): array
{
    return ['race_code' => $raceCode, 'venue' => 'TMG', 'cutoff_timestamp' => '2099-12-28T23:59:00+09:00'];
}

function declaredLiveContext(string $raceCode): array
{
    return [
        'date' => '2099-12-28', 'place' => 'TMG', 'phase' => 'live', 'late_replay' => false,
        'ready_race_codes' => [$raceCode], 'legacy_by_course' => [], 'traditional_evaluation_by_course' => [],
        'ml_decisions' => [$raceCode => ['1' => ['player_id' => '9999', 'targets' => [
            'top3' => ['decision_available' => true, 'selected' => false, 'threshold' => 0.42,
                'model_version' => 'fixture_ml_v1', 'artifact_identifier' => 'fixture.joblib', 'artifact_sha256' => str_repeat('a', 64)],
        ]]]], 'logic_version' => 'fixture_logic', 'traditional_policy_version' => 'fixture_traditional',
    ];
}

$root = sys_get_temp_dir() . '/boatrace_window_declared_fixture_' . bin2hex(random_bytes(8));
$repo = $root . '/repo';
$ledgerRoot = $root . '/ledger';
$source = $repo . '/critical/taint_guard.php';
declaredWrite($source, "<?php\nreturn 'X';\n");
foreach (['config/strict_forward_comparative_scope_v1.json', 'config/strict_forward_comparative_policy_v1.json'] as $path) {
    declaredWrite($repo . '/' . $path, (string)file_get_contents(dirname(__DIR__) . '/' . $path));
}
$windowPath = $root . '/window/validation_window_manifest.json';
declaredAssert(WindowActivationHold::createPrepared($windowPath, 'fixture-declared-window')['accepted'] === true, 'fixture PREPARED Window created');

$provenance = new MeasurementImplementationProvenance($repo, $ledgerRoot, ['critical/taint_guard.php']);
$declaration = new WindowDeclaredImplementationIdentity($windowPath, $provenance);
$results = [];

// No Expected/Canonical rows exist before declaration. The declaration is a
// first-write contract and does not create or relabel formal evidence.
$first = $declaration->declare();
declaredAssert($first['accepted'] === true && $first['status'] === 'DECLARED_CREATED', 'initial identity declaration succeeds');
$identityX = $first['implementation']['implementation_identity'];
declaredAssert(count(glob($ledgerRoot . '/expected/*/*.json') ?: []) === 0 && count(glob($ledgerRoot . '/records/*/*.json') ?: []) === 0, 'declaration does not create evidence rows');
$results[] = 'no_row_declaration_regression';

$activation = new WindowActivationHold($windowPath, $declaration, $ledgerRoot);
$activationResult = $activation->activate('fixture://preflight-pass');
declaredAssert($activationResult['accepted'] === true, 'fixture Window activates only after declaration/preflight');

$reloaded = new WindowDeclaredImplementationIdentity($windowPath, new MeasurementImplementationProvenance($repo, $ledgerRoot, ['critical/taint_guard.php']));
$readback = $reloaded->read();
declaredAssert($readback['accepted'] === true && $readback['implementation']['implementation_identity'] === $identityX, 'restart readback retains immutable declaration');
$results[] = 'restart_readback';

$opportunity = new StrictForwardOpportunityLedger($root . '/opportunity');
$raceX = '20991228TMG01';
$raceY = '20991228TMG02';
$opportunity->recordCandidateUniverse([
    'date' => '2099-12-28', 'candidates' => [declaredCandidate($raceX), declaredCandidate($raceY)],
    'source' => 'fixture-official-cache', 'source_identity' => 'sha256:declared-fixture', 'source_generated_at' => '2099-12-28T08:00:00+09:00',
]);
$deadlineDir = sys_get_temp_dir() . '/boatrace_official_deadlines';
if (!is_dir($deadlineDir) && !mkdir($deadlineDir, 0775, true) && !is_dir($deadlineDir)) throw new RuntimeException('deadline_fixture_directory_unavailable');
declaredWrite($deadlineDir . '/deadlines_20991228.json', json_encode([
    'source' => 'FIXTURE_OFFICIAL_DEADLINE_CACHE', 'deadlines' => [$raceX => '23:59', $raceY => '23:59'],
], JSON_THROW_ON_ERROR));
$ledger = new StrictForwardReadinessLedger($ledgerRoot, false, $opportunity, $declaration, $activation);

// Stable X permits Expected and Canonical formal capture.
$expectedX = $ledger->recordExpectedLive(['date' => '2099-12-28', 'place' => 'TMG', 'races' => [array_merge(declaredCandidate($raceX), ['deadline_source' => 'FIXTURE'])]]);
declaredAssert($expectedX['expected'] === 18, 'declared X permits Expected');
$capturedX = $ledger->captureLive(declaredLiveContext($raceX));
declaredAssert($capturedX['records'] === 18, 'declared X permits Canonical population capture');
$samplePath = $ledgerRoot . '/records/20991228/' . hash('sha256', 'READINESS_COMPLETION|20991228TMG01|TMG|1|top3|exhibition') . '.json';
$sample = json_decode((string)file_get_contents($samplePath), true, 512, JSON_THROW_ON_ERROR);
declaredAssert($sample['measurement_implementation_identity'] === $identityX, 'formal Canonical row carries declared X');
$results[] = 'stable_identity_expected_and_canonical';

// A one-byte measurement-critical change creates Y. It cannot replace X,
// cannot create a new Expected/Canonical formal sample, and preserves X rows.
declaredWrite($source, "<?php\nreturn 'Y';\n");
$drift = $reloaded->assertCurrent();
declaredAssert($drift['accepted'] === false && $drift['reason'] === 'DECLARED_IMPLEMENTATION_IDENTITY_MISMATCH', 'current Y is blocked against declared X');
$repeatDeclare = $reloaded->declare();
declaredAssert($repeatDeclare['accepted'] === false && $repeatDeclare['reason'] === 'DECLARED_IMPLEMENTATION_IDENTITY_MISMATCH', 'declared X cannot be overwritten by Y');
$expectedY = $ledger->recordExpectedLive(['date' => '2099-12-28', 'place' => 'TMG', 'races' => [array_merge(declaredCandidate($raceY), ['deadline_source' => 'FIXTURE'])]]);
declaredAssert($expectedY['expected'] === 0 && $expectedY['reason'] === 'DECLARED_IMPLEMENTATION_IDENTITY_MISMATCH', 'drift blocks Expected formal evidence');
$capturedY = $ledger->captureLive(declaredLiveContext($raceY));
declaredAssert($capturedY['records'] === 0 && $capturedY['reason'] === 'DECLARED_IMPLEMENTATION_IDENTITY_MISMATCH', 'drift blocks Canonical formal evidence');
declaredAssert(is_file($samplePath) && count(glob($ledgerRoot . '/expected/20991228/*.json') ?: []) === 18, 'existing X history remains append-only after drift');
$results[] = 'expected_and_canonical_pre_drift_blocking';

// Restoring exact X demonstrates a stable identity after process reload; the
// persisted declaration remains the source of truth, not a new declaration.
declaredWrite($source, "<?php\nreturn 'X';\n");
$stableReload = (new WindowDeclaredImplementationIdentity($windowPath, new MeasurementImplementationProvenance($repo, $ledgerRoot, ['critical/taint_guard.php'])))->assertCurrent();
declaredAssert($stableReload['accepted'] === true && $stableReload['implementation']['implementation_identity'] === $identityX, 'restored X passes after reload without declaration rewrite');
$results[] = 'stable_identity_reload';

// A terminal lifecycle record must block before a Window-local provenance
// freeze, even when the Declaration already exists and the source identity
// has subsequently changed.
declaredWrite($source, "<?php\nreturn 'Z';\n");
$closedPath = $repo . '/var/strict_forward_live_readiness/lifecycle/closures/fixture-declared-window.json';
declaredWrite($closedPath, json_encode([
    'schema_version' => FormalMeasurementWindowLifecycle::CLOSURE_SCHEMA_VERSION,
    'window_id' => 'fixture-declared-window',
    'closure_record_hash' => 'sha256:' . str_repeat('c', 64),
], JSON_THROW_ON_ERROR));
$beforeClosedManifest = count(glob($ledgerRoot . '/implementation_manifests/*.json') ?: []);
$beforeClosedSource = count(glob($ledgerRoot . '/implementation_sources/*.json') ?: []);
$closed = (new WindowDeclaredImplementationIdentity($windowPath, new MeasurementImplementationProvenance($repo, $ledgerRoot, ['critical/taint_guard.php'])))->declare();
declaredAssert($closed['accepted'] === false && $closed['reason'] === 'WINDOW_CLOSED', 'CLOSED Window blocks declaration before freeze');
declaredAssert(count(glob($ledgerRoot . '/implementation_manifests/*.json') ?: []) === $beforeClosedManifest && count(glob($ledgerRoot . '/implementation_sources/*.json') ?: []) === $beforeClosedSource, 'CLOSED Window declare creates no provenance artifacts');
$closedCurrent = (new WindowDeclaredImplementationIdentity($windowPath, new MeasurementImplementationProvenance($repo, $ledgerRoot, ['critical/taint_guard.php'])))->assertCurrent();
declaredAssert($closedCurrent['accepted'] === false && $closedCurrent['reason'] === 'WINDOW_CLOSED', 'CLOSED Window current assertion blocks before freeze');
$results[] = 'closed_pre_freeze_guard';

// An ABORTED Window with no Declaration must also block before its first
// provenance artifact could be created.
$abortedPath = $root . '/aborted/validation_window_manifest.json';
declaredAssert(WindowActivationHold::createPrepared($abortedPath, 'fixture-aborted-window')['accepted'] === true, 'fixture ABORTED Window prepared');
$abortRecord = $repo . '/var/strict_forward_live_readiness/lifecycle/aborts/fixture-aborted-window.json';
declaredWrite($abortRecord, json_encode([
    'schema_version' => FormalMeasurementWindowLifecycle::ABORT_SCHEMA_VERSION,
    'window_id' => 'fixture-aborted-window',
    'abort_reason' => FormalMeasurementWindowLifecycle::ABORT_REASON_A1_DEADLINE_SNAPSHOT_SEMANTIC_CONFLICT,
    'official_epoch' => 'NO',
    'abort_record_hash' => 'sha256:' . str_repeat('d', 64),
], JSON_THROW_ON_ERROR));
$abortedStore = $root . '/aborted/provenance';
$aborted = (new WindowDeclaredImplementationIdentity($abortedPath, new MeasurementImplementationProvenance($repo, $abortedStore, ['critical/taint_guard.php'])))->declare();
declaredAssert($aborted['accepted'] === false && $aborted['reason'] === 'WINDOW_ABORTED', 'ABORTED Window blocks declaration before freeze');
declaredAssert(count(glob($abortedStore . '/implementation_manifests/*.json') ?: []) === 0 && count(glob($abortedStore . '/implementation_sources/*.json') ?: []) === 0, 'ABORTED Window declare creates no provenance artifacts');
$results[] = 'aborted_pre_freeze_guard';

$finalizer = (string)file_get_contents(__DIR__ . '/finalize_window_continuability_verification.php');
declaredAssert(str_contains($finalizer, 'WindowDeclaredImplementationIdentity') && str_contains($finalizer, 'assertCurrent') && str_contains($finalizer, 'BLOCKED_IMPLEMENTATION_PROVENANCE') && str_contains($finalizer, '$declaredIdentity'), 'Gate C independently requires the declared and current identity match');
$results[] = 'gate_c_declared_identity_guard';

echo json_encode([
    'status' => 'PASS', 'declared_identity' => $identityX, 'tests' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
