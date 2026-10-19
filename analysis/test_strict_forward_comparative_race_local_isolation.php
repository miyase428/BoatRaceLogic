<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/FormalMeasurementWindowContext.php';
require_once __DIR__ . '/../web/logic/StrictForwardOpportunityLedger.php';
require_once __DIR__ . '/../web/logic/StrictForwardReadinessLedger.php';
require_once __DIR__ . '/../web/logic/StrictForwardComparativeCapture.php';

/** Isolated CC21 fixture. It writes only below a new /tmp directory. */
$repo = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/cc21-race-local-' . bin2hex(random_bytes(6));
$window = $tmp . '/window';
$windowId = 'cc21-race-local-fixture';
$pass = 0; $fail = 0; $tests = [];
$ok = static function (bool $condition, string $name) use (&$pass, &$fail, &$tests): void {
    $tests[] = [$name, $condition];
    $condition ? $pass++ : $fail++;
};
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $item) if ($item !== '.' && $item !== '..') $remove($path . '/' . $item);
        rmdir($path);
    } elseif (is_file($path)) unlink($path);
};
$rows = static function (string $pattern, ?string $race = null): array {
    $out = [];
    foreach (glob($pattern) ?: [] as $path) {
        $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (is_array($row) && ($race === null || ($row['race_code'] ?? '') === $race)) $out[] = $row;
    }
    return $out;
};

try {
    $prepared = WindowActivationHold::createPrepared($window . '/validation_window_manifest.json', $windowId);
    $context = FormalMeasurementWindowContext::fromWindowRoot($repo, $window);
    $declared = $context->declaration()->declare();
    $activated = $context->activationHold()->activate('cc21-fixture-preflight', 'PRECHECK_PASS');
    $ok($prepared['accepted'] && $declared['accepted'] && $activated['accepted'], 'active_fixture_window_created');

    $date = '2098-12-31';
    $rejectFirst = '20981231TDA08';
    $validAfterFirst = '20981231TDA09';
    $rejectMiddle = '20981231TDA10';
    $validAfterMiddle = '20981231TDA11';
    $inputIncomplete = '20981231TDA12';
    $cutoffs = [
        $rejectFirst => '2098-12-31T09:00:00+09:00',
        $validAfterFirst => '2098-12-31T12:00:00+09:00',
        $rejectMiddle => '2098-12-31T09:30:00+09:00',
        $validAfterMiddle => '2098-12-31T13:00:00+09:00',
        $inputIncomplete => '2098-12-31T14:00:00+09:00',
    ];
    $candidates = [];
    $schedules = [];
    foreach ($cutoffs as $race => $cutoff) {
        $candidates[] = ['race_code' => $race, 'venue' => 'TDA', 'cutoff_timestamp' => $cutoff];
        $schedules[] = ['race_code' => $race, 'cutoff_timestamp' => $cutoff, 'official_status' => 'ACTIVE'];
    }
    $opportunity = new StrictForwardOpportunityLedger($context->opportunityRoot(), $context);
    $opportunity->recordCandidateUniverse([
        'date' => $date, 'source' => 'fixture-official',
        'source_identity' => 'sha256:' . str_repeat('a', 64),
        'source_generated_at' => '2098-12-31T07:00:00+09:00', 'candidates' => $candidates,
    ]);
    $opportunity->recordOfficialScheduleSnapshot([
        'date' => $date, 'raw_source_identity' => 'sha256:' . str_repeat('b', 64),
        'source_generated_at' => '2098-12-31T07:00:00+09:00', 'schedules' => $schedules,
    ]);
    $opportunity->observeEligibility([
        'date' => $date, 'observed_at' => '2098-12-31T08:00:00+09:00',
        'ready_race_codes' => [], 'input_availability' => 'AVAILABLE', 'reason' => 'FIXTURE_PRE_EXHIBITION',
    ]);
    $opportunity->observeEligibility([
        'date' => $date, 'observed_at' => '2098-12-31T10:00:00+09:00',
        'ready_race_codes' => array_keys($cutoffs), 'input_availability' => 'AVAILABLE',
        'reason' => 'FIXTURE_POST_EXHIBITION',
    ]);

    $resolutions = [];
    foreach ($opportunity->resolutionRows($date) as $row) $resolutions[(string)$row['race_code']] = $row;
    $ok(($resolutions[$rejectFirst]['eligibility_status'] ?? '') === 'VALID_NON_ELIGIBLE_EXHIBITION_INCOMPLETE', 'cutoff_after_exhibition_race_is_noneligible');
    $ok(($resolutions[$validAfterFirst]['eligibility_status'] ?? '') === 'ELIGIBLE_OBSERVED', 'following_race_is_eligible');

    $ledger = new StrictForwardReadinessLedger(
        $context->ledgerRoot(), false, $opportunity, $context->declaration(), $context->activationHold(), null, $context,
    );
    $eligibleRaces = [$validAfterFirst, $validAfterMiddle, $inputIncomplete];
    $expected = $ledger->recordExpectedLive([
        'date' => $date, 'place' => 'TDA', 'races' => array_map(
            static fn(string $race): array => ['race_code' => $race, 'cutoff_timestamp' => $cutoffs[$race], 'deadline_source' => 'fixture-official'],
            $eligibleRaces,
        ),
    ]);
    $ok($expected['expected'] === 54, 'three_eligible_races_have_structural_expected_rows');

    $contract = ComparativeContractV1::production($repo);
    $uses = array_values(array_filter($contract->scope()['uses'], static fn(array $use): bool => $use['venue'] === 'TDA'));
    $ml = []; $traditionalEvaluation = [];
    foreach ($eligibleRaces as $race) foreach ($uses as $use) {
        $course = (string)$use['course']; $target = (string)$use['target'];
        $ml[$race][$course]['targets'][$target] = [
            'decision_available' => true, 'selected' => $target === 'top3', 'threshold' => 0.5,
            'model_version' => 'toda-course-signal-fixture', 'artifact_identifier' => 'toda-fixture.joblib',
            'artifact_sha256' => 'sha256:' . str_repeat('c', 64),
        ];
        if ($use['scope_class'] === 'COMPARABLE') {
            $traditionalEvaluation[(int)$use['course']][$race] = ['policy_exists' => true, 'evaluated' => true, 'reason' => ''];
        }
    }
    $deadlineDir = sys_get_temp_dir() . '/boatrace_official_deadlines';
    if (!is_dir($deadlineDir) && !mkdir($deadlineDir, 0775, true) && !is_dir($deadlineDir)) throw new RuntimeException('deadline_fixture_dir_unavailable');
    file_put_contents($deadlineDir . '/deadlines_20981231.json', json_encode([
        'source' => 'FIXTURE_OFFICIAL_DEADLINE_CACHE',
        'deadlines' => array_map(static fn(string $value): string => substr($value, 11, 5), $cutoffs),
    ], JSON_THROW_ON_ERROR));
    $canonical = $ledger->captureLive([
        'date' => $date, 'place' => 'TDA', 'phase' => 'live', 'late_replay' => false,
        'ready_race_codes' => $eligibleRaces, 'legacy_by_course' => [],
        'traditional_evaluation_by_course' => $traditionalEvaluation, 'ml_decisions' => $ml,
        'logic_version' => 'fixture-logic-v1', 'traditional_policy_version' => 'fixture-traditional-v1',
        'ml_policy_version' => 'toda-course-signal-fixture',
    ]);
    $ok($canonical['records'] === 54, 'three_eligible_races_have_structural_canonical_rows');

    $entries = $exhibition = [];
    for ($course = 1; $course <= 6; $course++) {
        $entries[] = ['player_id' => (string)(100 + $course), 'course' => $course, 'lane_number' => $course];
        $exhibition[] = ['player_id' => (string)(100 + $course), 'entry_course' => $course, 'exhibition_time' => 6.70 + ($course / 100)];
    }
    $input = ['entries' => $entries, 'exhibition' => $exhibition, 'ml_child' => [
        'runtime' => 'fixture', 'uses' => ['4|top3' => [
            'feature_schema_identity' => 'sha256:' . str_repeat('d', 64), 'feature_names' => ['c4_rate'],
            'feature_values' => ['c4_rate' => 1.25], 'model_version' => 'toda-course-signal-fixture',
            'artifact_sha256' => 'sha256:' . str_repeat('c', 64),
        ]],
    ], 'missing_markers' => []];
    $inputByRace = [$validAfterFirst => $input, $validAfterMiddle => $input];
    $runtime = [
        'date' => $date, 'venue' => 'TDA', 'phase' => 'live',
        'ready_race_codes' => [$rejectFirst, $validAfterFirst, $rejectMiddle, $inputIncomplete, $validAfterMiddle],
        'input_by_race' => $inputByRace, 'ml_decisions' => $ml, 'legacy_by_course' => [],
        'traditional_evaluation_by_course' => $traditionalEvaluation,
        'traditional_policy_version' => 'fixture-traditional-v1', 'traditional_runtime_input' => ['fixture' => true],
    ];
    $capture = (new StrictForwardComparativeCapture(
        $context, $contract, new DateTimeZone('Asia/Tokyo'), new DateTimeImmutable('2098-12-31T10:00:00+09:00'),
    ))->capture($runtime);
    $ok($capture['status'] === 'COMPLETE' && $capture['race_local_rejects'] === 3 && $capture['global_fatals'] === 0, 'multiple_race_local_rejects_do_not_abort_batch');
    $ok($capture['snapshots'] === 2 && $capture['pairs'] === 22, 'only_two_eligible_complete_races_are_captured');

    $tda08Snapshots = $rows($window . '/input_snapshots/*/*.json', $rejectFirst);
    $tda08Pairs = $rows($window . '/comparative/pairs/*/*.json', $rejectFirst);
    $tda09Snapshots = $rows($window . '/input_snapshots/*/*.json', $validAfterFirst);
    $tda09Pairs = $rows($window . '/comparative/pairs/*/*.json', $validAfterFirst);
    $tda11Snapshots = $rows($window . '/input_snapshots/*/*.json', $validAfterMiddle);
    $tda12Snapshots = $rows($window . '/input_snapshots/*/*.json', $inputIncomplete);
    $ok(count($tda08Snapshots) === 0 && count($tda08Pairs) === 0, 'cutoff_after_exhibition_race_writes_no_snapshot_or_pair');
    $ok(count($tda09Snapshots) === 1 && count($tda09Pairs) === 11, 'leading_reject_does_not_block_tda09');
    $ok(count($tda11Snapshots) === 1, 'middle_reject_does_not_block_following_eligible_race');
    $ok(count($tda12Snapshots) === 0, 'runtime_input_incomplete_is_race_local_without_snapshot');
    $ok(count(array_filter($tda09Pairs, static fn(array $row): bool => $row['scope_class'] === 'COMPARABLE' && $row['pair_status'] === 'PAIRED')) === 5, 'tda09_comparable_five_are_paired');
    $ok(count(array_filter($tda09Pairs, static fn(array $row): bool => $row['scope_class'] === 'ML_ONLY' && $row['pair_status'] === 'ML_ONLY_EXCLUDED')) === 6, 'tda09_ml_only_six_are_excluded');
    $ok(count(array_filter($tda09Pairs, static fn(array $row): bool => !in_array($row['scope_class'], ['COMPARABLE', 'ML_ONLY'], true))) === 0, 'nondeploy_rows_create_no_pair');
    $ok(count(array_filter($tda09Pairs, static fn(array $row): bool => (int)$row['course'] === 2 && $row['traditional_decision'] === 'NONE')) === 3, 'course2_traditional_is_none_without_synthetic_decision');

    $failureRows = [];
    foreach (file($window . '/comparative/failures/20981231.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $failureRows[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    }
    $ok(count($failureRows) === 3 && count(array_filter($failureRows, static fn(array $row): bool => ($row['failure_scope'] ?? '') === 'RACE_LOCAL_REJECT')) === 3, 'race_local_rejects_are_auditable_and_not_global');

    $changedSnapshot = $runtime;
    $changedSnapshot['ready_race_codes'] = [$validAfterFirst];
    $changedSnapshot['input_by_race'][$validAfterFirst]['ml_child']['uses']['4|top3']['feature_values']['c4_rate'] = 9.99;
    $snapshotConflict = (new StrictForwardComparativeCapture(
        $context, $contract, new DateTimeZone('Asia/Tokyo'), new DateTimeImmutable('2098-12-31T10:00:00+09:00'),
    ))->capture($changedSnapshot);
    $ok($snapshotConflict['status'] === 'CONFLICT' && $snapshotConflict['global_fatals'] === 1, 'snapshot_first_write_conflict_is_global_fatal');

    $changedPair = $runtime;
    $changedPair['ready_race_codes'] = [$validAfterFirst];
    $firstUse = $uses[0];
    $course = (string)$firstUse['course']; $target = (string)$firstUse['target'];
    $changedPair['ml_decisions'][$validAfterFirst][$course]['targets'][$target]['selected'] = !$changedPair['ml_decisions'][$validAfterFirst][$course]['targets'][$target]['selected'];
    $pairConflict = (new StrictForwardComparativeCapture(
        $context, $contract, new DateTimeZone('Asia/Tokyo'), new DateTimeImmutable('2098-12-31T10:00:00+09:00'),
    ))->capture($changedPair);
    $ok($pairConflict['status'] === 'CONFLICT' && $pairConflict['global_fatals'] === 1, 'pair_first_write_conflict_is_global_fatal');

    $identityRepo = $tmp . '/identity-repo';
    mkdir($identityRepo, 0775, true);
    mkdir($identityRepo . '/config', 0775, true);
    copy($repo . '/config/strict_forward_comparative_scope_v1.json', $identityRepo . '/config/strict_forward_comparative_scope_v1.json');
    copy($repo . '/config/strict_forward_comparative_policy_v1.json', $identityRepo . '/config/strict_forward_comparative_policy_v1.json');
    file_put_contents($identityRepo . '/critical.php', "<?php return 1;\n");
    $identityWindow = $identityRepo . '/window';
    WindowActivationHold::createPrepared($identityWindow . '/validation_window_manifest.json', 'cc21-identity-fixture');
    $identityContext = FormalMeasurementWindowContext::fromWindowRoot($identityRepo, $identityWindow, ['critical.php']);
    $identityContext->declaration()->declare();
    $identityContext->activationHold()->activate('fixture-preflight', 'PRECHECK_PASS');
    file_put_contents($identityRepo . '/critical.php', "<?php return 2;\n");
    $identityFatal = (new StrictForwardComparativeCapture($identityContext, $contract))->capture($runtime);
    $ok($identityFatal['status'] === 'GLOBAL_FATAL' && $identityFatal['global_fatals'] === 1, 'measurement_identity_mismatch_is_batch_global_fatal');

    $scope = json_decode((string)file_get_contents($repo . '/config/strict_forward_comparative_scope_v1.json'), true, 512, JSON_THROW_ON_ERROR);
    $scope['comparative_scope_identity'] = 'sha256:' . str_repeat('0', 64);
    $badScopePath = $tmp . '/bad-scope.json';
    $badPolicyPath = $tmp . '/bad-policy.json';
    file_put_contents($badScopePath, json_encode($scope, JSON_THROW_ON_ERROR));
    copy($repo . '/config/strict_forward_comparative_policy_v1.json', $badPolicyPath);
    $scopeFatal = false;
    try {
        (new StrictForwardComparativeCapture($context, new ComparativeContractV1($badScopePath, $badPolicyPath)))->capture($runtime);
    } catch (Throwable) {
        $scopeFatal = true;
    }
    $ok($scopeFatal, 'scope_policy_identity_corruption_is_fail_loud');
} finally {
    $remove($tmp);
}

echo json_encode(['total' => $pass + $fail, 'pass' => $pass, 'fail' => $fail, 'tests' => $tests], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($fail === 0 ? 0 : 1);
