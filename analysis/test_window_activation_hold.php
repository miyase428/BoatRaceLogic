<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/StrictForwardReadinessLedger.php';

function holdAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('ASSERTION_FAILED: ' . $message);
}

function holdWrite(string $path, string $contents): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new RuntimeException('fixture_directory_unavailable');
    if (file_put_contents($path, $contents) === false) throw new RuntimeException('fixture_write_failed');
}

/** @return array{root:string,repo:string,ledger:string,source:string,window:string,declaration:WindowDeclaredImplementationIdentity,hold:WindowActivationHold,opportunity:StrictForwardOpportunityLedger} */
function holdFixture(string $id): array
{
    $root = sys_get_temp_dir() . '/boatrace_activation_hold_' . $id . '_' . bin2hex(random_bytes(5));
    $repo = $root . '/repo';
    $ledger = $root . '/ledger';
    $source = $repo . '/critical/guard.php';
    holdWrite($source, "<?php\nreturn 'X';\n");
    foreach (['config/strict_forward_comparative_scope_v1.json', 'config/strict_forward_comparative_policy_v1.json'] as $path) {
        holdWrite($repo . '/' . $path, (string)file_get_contents(dirname(__DIR__) . '/' . $path));
    }
    $window = $root . '/window/validation_window_manifest.json';
    holdAssert(WindowActivationHold::createPrepared($window, 'fixture-' . $id)['accepted'] === true, 'PREPARED Window created: ' . $id);
    $provenance = new MeasurementImplementationProvenance($repo, $ledger, ['critical/guard.php']);
    $declaration = new WindowDeclaredImplementationIdentity($window, $provenance);
    $hold = new WindowActivationHold($window, $declaration, $ledger);
    return ['root' => $root, 'repo' => $repo, 'ledger' => $ledger, 'source' => $source, 'window' => $window,
        'declaration' => $declaration, 'hold' => $hold, 'opportunity' => new StrictForwardOpportunityLedger($root . '/opportunity')];
}

function holdCandidate(string $raceCode): array
{
    return ['race_code' => $raceCode, 'venue' => 'TMG', 'cutoff_timestamp' => '2099-12-28T23:59:00+09:00'];
}

function holdLiveContext(string $raceCode): array
{
    return ['date' => '2099-12-28', 'place' => 'TMG', 'phase' => 'live', 'late_replay' => false,
        'ready_race_codes' => [$raceCode], 'legacy_by_course' => [], 'traditional_evaluation_by_course' => [],
        'ml_decisions' => [$raceCode => ['1' => ['player_id' => '9999', 'targets' => [
            'top3' => ['decision_available' => true, 'selected' => false, 'threshold' => 0.42,
                'model_version' => 'fixture_ml_v1', 'artifact_identifier' => 'fixture.joblib', 'artifact_sha256' => str_repeat('a', 64)],
        ]]]], 'logic_version' => 'fixture_logic', 'traditional_policy_version' => 'fixture_traditional'];
}

function holdSeedSchedule(array $fixture, string $raceCode): void
{
    $fixture['opportunity']->recordCandidateUniverse([
        'date' => '2099-12-28', 'candidates' => [holdCandidate($raceCode)],
        'source' => 'fixture-official-cache', 'source_identity' => 'sha256:hold-fixture', 'source_generated_at' => '2099-12-28T08:00:00+09:00',
    ]);
    $dir = sys_get_temp_dir() . '/boatrace_official_deadlines';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('deadline_directory_unavailable');
    holdWrite($dir . '/deadlines_20991228.json', json_encode(['source' => 'FIXTURE_OFFICIAL_DEADLINE_CACHE', 'deadlines' => [$raceCode => '23:59']], JSON_THROW_ON_ERROR));
}

function holdActivate(array $fixture): void
{
    holdAssert($fixture['declaration']->declare()['accepted'] === true, 'declaration created');
    $activation = $fixture['hold']->activate('fixture://preflight-pass');
    holdAssert($activation['accepted'] === true && ($activation['manifest']['armed_at'] ?? '') !== '', 'activation succeeds only after preflight/declaration');
}

$results = [];

// PREPARED has a manifest but is incapable of formal Expected or Canonical writes.
$prepared = holdFixture('prepared');
holdSeedSchedule($prepared, '20991228TMG01');
$preparedLedger = new StrictForwardReadinessLedger($prepared['ledger'], false, $prepared['opportunity'], $prepared['declaration'], $prepared['hold']);
$preExpected = $preparedLedger->recordExpectedLive(['date' => '2099-12-28', 'place' => 'TMG', 'races' => [holdCandidate('20991228TMG01')]]);
$preCanonical = $preparedLedger->captureLive(holdLiveContext('20991228TMG01'));
holdAssert($preExpected['expected'] === 0 && $preCanonical['records'] === 0, 'PREPARED blocks scheduler and API formal writes');
holdAssert(count(glob($prepared['ledger'] . '/expected/*/*.json') ?: []) === 0 && count(glob($prepared['ledger'] . '/records/*/*.json') ?: []) === 0, 'PREPARED creates no formal row');
$results[] = 'prepared_expected_and_canonical_zero';

// Missing declaration and missing preflight cannot activate.
$missing = holdFixture('missing');
holdAssert($missing['hold']->activate('fixture://preflight-pass')['accepted'] === false, 'missing declaration blocks activation');
holdAssert(!is_file(dirname($missing['window']) . '/activation_record.json'), 'missing declaration creates no activation record');
$missingPreflight = holdFixture('missing-preflight');
holdAssert($missingPreflight['declaration']->declare()['accepted'] === true, 'missing-preflight declaration created');
holdAssert($missingPreflight['hold']->activate('', 'PENDING')['accepted'] === false, 'non-pass preflight blocks activation');
$results[] = 'missing_declaration_and_preflight_activation_rejection';

// Drift before activation cannot replace the declaration or create activation.
$driftActivation = holdFixture('drift-activation');
holdAssert($driftActivation['declaration']->declare()['accepted'] === true, 'drift declaration created');
holdWrite($driftActivation['source'], "<?php\nreturn 'Y';\n");
holdAssert($driftActivation['hold']->activate('fixture://preflight-pass')['accepted'] === false, 'identity drift blocks activation');
holdAssert(!is_file(dirname($driftActivation['window']) . '/activation_record.json'), 'identity drift creates no activation record');
$results[] = 'identity_drift_activation_rejection';

// ACTIVE permits the pre-existing formal paths and activation itself is immutable.
$active = holdFixture('active');
holdSeedSchedule($active, '20991228TMG01');
holdActivate($active);
$activationBytes = file_get_contents(dirname($active['window']) . '/activation_record.json');
holdAssert($active['hold']->activate('fixture://different-preflight')['accepted'] === false, 'activation overwrite rejected');
holdAssert($activationBytes === file_get_contents(dirname($active['window']) . '/activation_record.json'), 'activation record unchanged after overwrite attempt');
$activeLedger = new StrictForwardReadinessLedger($active['ledger'], false, $active['opportunity'], $active['declaration'], $active['hold']);
$activeExpected = $activeLedger->recordExpectedLive(['date' => '2099-12-28', 'place' => 'TMG', 'races' => [holdCandidate('20991228TMG01')]]);
$activeCanonical = $activeLedger->captureLive(holdLiveContext('20991228TMG01'));
holdAssert($activeExpected['expected'] === 18 && $activeCanonical['records'] === 18, 'ACTIVE preserves formal Expected/Canonical path');
$results[] = 'active_normal_path_and_first_write_activation';

// A source mutation immediately before persistence blocks every new formal row.
$expectedDrift = holdFixture('expected-drift');
holdSeedSchedule($expectedDrift, '20991228TMG01');
holdActivate($expectedDrift);
$expectedMutated = false;
$expectedLedger = new StrictForwardReadinessLedger($expectedDrift['ledger'], false, $expectedDrift['opportunity'], $expectedDrift['declaration'], $expectedDrift['hold'], function (string $kind) use (&$expectedMutated, $expectedDrift): void {
    if ($kind === 'EXPECTED' && !$expectedMutated) { holdWrite($expectedDrift['source'], "<?php\nreturn 'Y';\n"); $expectedMutated = true; }
});
$expectedResult = $expectedLedger->recordExpectedLive(['date' => '2099-12-28', 'place' => 'TMG', 'races' => [holdCandidate('20991228TMG01')]]);
holdAssert($expectedResult['status'] === 'SKIPPED' && $expectedResult['expected'] === 0 && count(glob($expectedDrift['ledger'] . '/expected/*/*.json') ?: []) === 0, 'Expected TOCTOU drift blocks persistence');
$results[] = 'expected_toctou_blocks_all_formal_rows';

$canonicalDrift = holdFixture('canonical-drift');
holdSeedSchedule($canonicalDrift, '20991228TMG01');
holdActivate($canonicalDrift);
$canonicalMutated = false;
$canonicalLedger = new StrictForwardReadinessLedger($canonicalDrift['ledger'], false, $canonicalDrift['opportunity'], $canonicalDrift['declaration'], $canonicalDrift['hold'], function (string $kind) use (&$canonicalMutated, $canonicalDrift): void {
    if ($kind === 'CANONICAL' && !$canonicalMutated) { holdWrite($canonicalDrift['source'], "<?php\nreturn 'Y';\n"); $canonicalMutated = true; }
});
$canonicalResult = $canonicalLedger->captureLive(holdLiveContext('20991228TMG01'));
holdAssert($canonicalResult['records'] === 0 && count(glob($canonicalDrift['ledger'] . '/records/*/*.json') ?: []) === 0, 'Canonical TOCTOU drift blocks persistence');
$results[] = 'canonical_toctou_blocks_all_formal_rows';

// Two independent scheduler/API-like callers remain held while PREPARED.
$concurrent = holdFixture('concurrent');
holdSeedSchedule($concurrent, '20991228TMG01');
$first = new StrictForwardReadinessLedger($concurrent['ledger'], false, $concurrent['opportunity'], $concurrent['declaration'], $concurrent['hold']);
$second = new StrictForwardReadinessLedger($concurrent['ledger'], false, $concurrent['opportunity'], $concurrent['declaration'], $concurrent['hold']);
holdAssert($first->recordExpectedLive(['date' => '2099-12-28', 'place' => 'TMG', 'races' => [holdCandidate('20991228TMG01')]])['expected'] === 0, 'first PREPARED caller held');
holdAssert($second->captureLive(holdLiveContext('20991228TMG01'))['records'] === 0, 'second PREPARED caller held');
$results[] = 'concurrent_prepared_access_zero_formal_rows';

// A candidate is never copied across fixture Window roots; the successor starts empty.
$old = holdFixture('old');
holdSeedSchedule($old, '20991228TMG01');
$new = holdFixture('new');
holdAssert(count(glob($new['ledger'] . '/expected/*/*.json') ?: []) === 0 && count(glob($new['ledger'] . '/records/*/*.json') ?: []) === 0, 'cross-window formal evidence carryover is zero');
holdAssert(count(glob($new['root'] . '/opportunity/candidates/*/*.json') ?: []) === 0, 'cross-window Candidate carryover is zero');
$results[] = 'cross_window_isolation';

// The hold wraps persistence only; it neither changes nor replaces the
// existing 40-use Traditional policy source/evaluation path.
$rules = __DIR__ . '/../config/course_signal_rules.json';
$api = (string)file_get_contents(__DIR__ . '/../web/tamagawa_lane4_star_api.php');
$ledgerSource = (string)file_get_contents(__DIR__ . '/../web/logic/StrictForwardReadinessLedger.php');
holdAssert(is_file($rules) && str_contains($api, 'strictForwardTraditionalEvaluationMap(') && str_contains($ledgerSource, 'formalTraditionalDecision('), 'Traditional comparison policy source/evaluation path remains available');
$results[] = 'traditional_scope_path_unmodified';

echo json_encode(['status' => 'PASS', 'fixtures' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
