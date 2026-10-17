<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/StrictForwardReadinessLedger.php';

function contextAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('ASSERTION_FAILED: ' . $message);
}

function contextWrite(string $path, string $contents): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new RuntimeException('fixture_directory_unavailable');
    if (file_put_contents($path, $contents) === false) throw new RuntimeException('fixture_write_failed');
}

/** @return array{root:string,repo:string,window:string,source:string,context:FormalMeasurementWindowContext,opportunity:StrictForwardOpportunityLedger} */
function contextFixture(string $name): array
{
    $root = sys_get_temp_dir() . '/boatrace_window_context_' . $name . '_' . bin2hex(random_bytes(5));
    $repo = $root . '/repo';
    $source = $repo . '/critical/guard.php';
    contextWrite($source, "<?php\nreturn '" . $name . "';\n");
    foreach (['strict_forward_comparative_scope_v1.json', 'strict_forward_comparative_policy_v1.json'] as $configName) {
        contextWrite($repo . '/config/' . $configName, (string)file_get_contents(dirname(__DIR__) . '/config/' . $configName));
    }
    $window = $repo . '/windows/' . $name;
    $manifest = $window . '/validation_window_manifest.json';
    contextAssert(WindowActivationHold::createPrepared($manifest, 'fixture-context-' . $name)['accepted'], 'prepared ' . $name);
    $context = FormalMeasurementWindowContext::fromWindowRoot($repo, $window, ['critical/guard.php']);
    contextAssert($context->declaration()->declare()['accepted'], 'declared ' . $name);
    contextAssert($context->activationHold()->activate('fixture://preflight-' . $name)['accepted'], 'activated ' . $name);
    return ['root' => $root, 'repo' => $repo, 'window' => $window, 'source' => $source, 'context' => $context,
        'opportunity' => new StrictForwardOpportunityLedger($context->opportunityRoot())];
}

function contextCandidate(string $raceCode): array
{
    return ['race_code' => $raceCode, 'venue' => 'TMG', 'cutoff_timestamp' => '2099-12-28T23:59:00+09:00'];
}

function contextSeed(FormalMeasurementWindowContext $context, StrictForwardOpportunityLedger $opportunity, string $raceCode): void
{
    $opportunity->recordCandidateUniverse([
        'date' => '2099-12-28', 'candidates' => [contextCandidate($raceCode)], 'source' => 'fixture-official-cache',
        'source_identity' => 'sha256:fixture-window-context', 'source_generated_at' => '2099-12-28T08:00:00+09:00',
    ]);
    $deadlineDir = sys_get_temp_dir() . '/boatrace_official_deadlines';
    contextWrite($deadlineDir . '/deadlines_20991228.json', json_encode([
        'source' => 'FIXTURE_OFFICIAL_DEADLINE_CACHE', 'deadlines' => [$raceCode => '23:59'],
    ], JSON_THROW_ON_ERROR));
}

function contextLive(string $raceCode): array
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

function contextLedger(array $fixture): StrictForwardReadinessLedger
{
    return new StrictForwardReadinessLedger(
        $fixture['context']->ledgerRoot(), false, $fixture['opportunity'], $fixture['context']->declaration(),
        $fixture['context']->activationHold(), null, $fixture['context'],
    );
}

$results = [];
$a = contextFixture('a');
$b = contextFixture('b');
$raceA = '20991228TMG01';
contextSeed($a['context'], $a['opportunity'], $raceA);

// Production resolution has no ambient legacy fallback.  A Window must be
// selected explicitly, then remains resolvable after a process reload.
try {
    FormalMeasurementWindowContext::production($a['repo']);
    throw new RuntimeException('missing production pointer unexpectedly resolved');
} catch (RuntimeException $error) {
    contextAssert($error->getMessage() === 'FORMAL_WINDOW_CONTEXT_POINTER_MISSING', 'missing pointer fails loud');
}
contextWrite($a['repo'] . '/' . FormalMeasurementWindowContext::POINTER_RELATIVE_PATH, json_encode([
    'schema_version' => FormalMeasurementWindowContext::POINTER_SCHEMA_VERSION,
    'window_id' => $a['context']->windowId(),
    'window_root' => 'windows/a',
], JSON_THROW_ON_ERROR));
$productionReload = FormalMeasurementWindowContext::production($a['repo']);
contextAssert($productionReload->windowId() === $a['context']->windowId(), 'explicit pointer resolves A after reload');
$results[] = 'production_pointer_required_without_legacy_fallback';

// A active Window routes both scheduler-like Expected and API-like Canonical
// writes to A, and never to B.
$ledgerA = contextLedger($a);
$expectedA = $ledgerA->recordExpectedLive(['date' => '2099-12-28', 'place' => 'TMG', 'races' => [contextCandidate($raceA)]]);
$canonicalA = $ledgerA->captureLive(contextLive($raceA));
contextAssert($expectedA['expected'] === 18 && $canonicalA['records'] === 18, 'A receives Expected and Canonical');
contextAssert(count(glob($b['context']->ledgerRoot() . '/expected/*/*.json') ?: []) === 0, 'B Expected remains zero');
contextAssert(count(glob($b['context']->ledgerRoot() . '/records/*/*.json') ?: []) === 0, 'B Canonical remains zero');
$expectedRows = glob($a['context']->ledgerRoot() . '/expected/*/*.json') ?: [];
$canonicalRows = glob($a['context']->ledgerRoot() . '/records/*/*.json') ?: [];
foreach (array_merge($expectedRows, $canonicalRows) as $path) {
    $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    contextAssert(($row['window_id'] ?? '') === $a['context']->windowId(), 'formal row carries A window id');
}
$results[] = 'expected_and_canonical_route_to_target_window_only';

// A resolver must not search B's provenance store when A's immutable freeze
// is absent, even if B has an artifact with a usable-looking identity.
$missing = contextFixture('missing-freeze');
$missingDeclaration = json_decode((string)file_get_contents($missing['window'] . '/declared_implementation_identity.json'), true, 512, JSON_THROW_ON_ERROR);
@unlink($missing['window'] . '/provenance/' . $missingDeclaration['source_freeze_ref']);
$missingReloaded = FormalMeasurementWindowContext::fromWindowRoot($missing['repo'], $missing['window'], ['critical/guard.php']);
contextAssert(!$missingReloaded->resolve()['accepted'], 'missing local freeze fails without other-window fallback');
$results[] = 'missing_freeze_fails_loud_without_cross_window_fallback';

// A different ledger root cannot borrow A's activation/declaration.
$wrongRootLedger = new StrictForwardReadinessLedger(
    $b['context']->ledgerRoot(), false, $a['opportunity'], $a['context']->declaration(), $a['context']->activationHold(), null, $a['context'],
);
$wrongExpected = $wrongRootLedger->recordExpectedLive(['date' => '2099-12-28', 'place' => 'TMG', 'races' => [contextCandidate('20991228TMG02')]]);
$wrongCanonical = $wrongRootLedger->captureLive(contextLive('20991228TMG02'));
contextAssert($wrongExpected['expected'] === 0 && $wrongCanonical['records'] === 0, 'wrong target Window root is fail-loud');
contextAssert(count(glob($b['context']->ledgerRoot() . '/expected/*/*.json') ?: []) === 0 && count(glob($b['context']->ledgerRoot() . '/records/*/*.json') ?: []) === 0, 'wrong target produces no B evidence');
$results[] = 'ledger_target_mismatch_rejected_without_write';

// A Window declaration and another Window activation record cannot be mixed.
$mixed = FormalMeasurementWindowContext::fromBoundComponents(
    $a['repo'], $a['context']->ledgerRoot(), $a['context']->opportunityRoot(), $a['context']->declaration(), $b['context']->activationHold(),
);
contextAssert(!$mixed->authorizeFormalWrite()['accepted'], 'activation/capture Window mismatch fails loud');
$results[] = 'activation_capture_window_mismatch_rejected';

// A one-byte measurement-critical change is detected after a fresh context
// reconstruction; declaration remains immutable and writes stay blocked.
$drift = contextFixture('drift');
contextSeed($drift['context'], $drift['opportunity'], '20991228TMG03');
contextWrite($drift['source'], "<?php\nreturn 'drift-changed';\n");
$driftReloaded = FormalMeasurementWindowContext::fromWindowRoot($drift['repo'], $drift['window'], ['critical/guard.php']);
contextAssert(!$driftReloaded->authorizeFormalWrite()['accepted'], 'critical hash drift fails loud');
$driftLedger = new StrictForwardReadinessLedger($driftReloaded->ledgerRoot(), false, $drift['opportunity'], $driftReloaded->declaration(), $driftReloaded->activationHold(), null, $driftReloaded);
contextAssert($driftLedger->recordExpectedLive(['date' => '2099-12-28', 'place' => 'TMG', 'races' => [contextCandidate('20991228TMG03')]])['expected'] === 0, 'drift prevents Expected');
contextAssert($driftLedger->captureLive(contextLive('20991228TMG03'))['records'] === 0, 'drift prevents Canonical');
$results[] = 'hash_drift_rejected_for_expected_and_canonical';

// Restart/reload resolves A through A only and preserves the exact identity.
$reloadedA = FormalMeasurementWindowContext::fromWindowRoot($a['repo'], $a['window'], ['critical/guard.php']);
$reloadedAuth = $reloadedA->authorizeFormalWrite();
contextAssert($reloadedAuth['accepted'] && $reloadedAuth['window_id'] === $a['context']->windowId(), 'restart readback keeps A binding');
$results[] = 'restart_readback_preserves_window_provenance_and_routing';

echo json_encode(['status' => 'PASS', 'tests' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
