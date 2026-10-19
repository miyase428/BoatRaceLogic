<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/FormalMeasurementWindowContext.php';
require_once __DIR__ . '/../web/logic/StrictForwardOpportunityLedger.php';
require_once __DIR__ . '/../web/logic/StrictForwardReadinessLedger.php';
require_once __DIR__ . '/../web/logic/StrictForwardComparativeCapture.php';
require_once __DIR__ . '/../web/logic/FormalComparativeReadinessEvaluator.php';

$repo = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/comparative-runtime-' . bin2hex(random_bytes(6));
$window = $tmp . '/window';
$windowId = 'runtime-wiring-fixture';
$passed = 0; $failed = 0; $tests = [];
$ok = static function (bool $condition, string $name) use (&$passed, &$failed, &$tests): void {
    $tests[] = [$name, $condition]; $condition ? $passed++ : $failed++;
};
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path)) { foreach (scandir($path) ?: [] as $item) if ($item !== '.' && $item !== '..') $remove($path . '/' . $item); rmdir($path); }
    elseif (is_file($path)) unlink($path);
};

try {
    $prepared = WindowActivationHold::createPrepared($window . '/validation_window_manifest.json', $windowId);
    $context = FormalMeasurementWindowContext::fromWindowRoot($repo, $window);
    $declared = $context->declaration()->declare();
    $activated = $context->activationHold()->activate('fixture-preflight', 'PRECHECK_PASS');
    $ok($prepared['accepted'] && $declared['accepted'] && $activated['accepted'], 'fixture_active_window_created');

    $date = '2099-01-01'; $race = '20990101TMG01'; $cutoff = '2099-01-01T12:00:00+09:00';
    $opportunity = new StrictForwardOpportunityLedger($context->opportunityRoot(), $context);
    $sourceAt = '2099-01-01T08:00:00+09:00';
    $opportunity->recordCandidateUniverse([
        'date' => $date,
        'source' => 'fixture-official',
        'source_identity' => 'sha256:' . str_repeat('a', 64),
        'source_generated_at' => $sourceAt,
        'candidates' => [['race_code' => $race, 'venue' => 'TMG', 'cutoff_timestamp' => $cutoff]],
    ]);
    $opportunity->recordOfficialScheduleSnapshot([
        'date' => $date,
        'raw_source_identity' => 'sha256:' . str_repeat('b', 64),
        'source_generated_at' => $sourceAt,
        'schedules' => [['race_code' => $race, 'cutoff_timestamp' => $cutoff, 'official_status' => 'ACTIVE']],
    ]);
    $opportunity->observeEligibility([
        'date' => $date, 'observed_at' => '2099-01-01T09:00:00+09:00',
        'ready_race_codes' => [$race], 'input_availability' => 'AVAILABLE',
        'reason' => 'FIXTURE_PRE_CUTOFF_EXHIBITION_READ',
    ]);
    $ledger = new StrictForwardReadinessLedger(
        $context->ledgerRoot(), false, $opportunity, $context->declaration(), $context->activationHold(), null, $context,
    );
    $expected = $ledger->recordExpectedLive(['date' => $date, 'place' => 'TMG', 'races' => [[
        'race_code' => $race, 'cutoff_timestamp' => $cutoff, 'deadline_source' => 'fixture-official',
    ]]]);
    $ok($expected['expected'] === 18, 'structural_18_expected_rows_preserved');

    $contract = ComparativeContractV1::production($repo);
    $uses = array_values(array_filter($contract->scope()['uses'], static fn(array $use): bool => $use['venue'] === 'TMG'));
    $ml = []; $traditionalEvaluation = [];
    foreach ($uses as $use) {
        $course = (string)$use['course']; $target = (string)$use['target'];
        $ml[$race][$course]['targets'][$target] = [
            'decision_available' => true, 'selected' => $target === 'top3', 'threshold' => 0.5,
            'model_version' => 'fixture-model', 'artifact_identifier' => 'fixture.joblib',
            'artifact_sha256' => 'sha256:' . str_repeat('c', 64),
        ];
        if ($use['scope_class'] === 'COMPARABLE') {
            $traditionalEvaluation[(int)$use['course']][$race] = ['policy_exists' => true, 'evaluated' => true, 'reason' => ''];
        }
    }
    $entries = [];
    $exhibition = [];
    for ($course = 1; $course <= 6; $course++) {
        $entries[] = ['player_id' => (string)(100 + $course), 'course' => $course, 'lane_number' => $course];
        $exhibition[] = ['player_id' => (string)(100 + $course), 'entry_course' => $course, 'exhibition_time' => 6.70 + ($course / 100)];
    }
    $input = ['entries' => $entries, 'exhibition' => $exhibition, 'ml_child' => [
        'runtime' => 'fixture', 'uses' => ['3|top3' => [
            'feature_schema_identity' => 'sha256:' . str_repeat('d', 64),
            'feature_names' => ['c3_rate'], 'feature_values' => ['c3_rate' => 1.25],
            'model_version' => 'fixture-model', 'artifact_sha256' => 'sha256:' . str_repeat('c', 64),
        ]],
    ], 'missing_markers' => []];
    $deadlineDir = sys_get_temp_dir() . '/boatrace_official_deadlines';
    if (!is_dir($deadlineDir) && !mkdir($deadlineDir, 0775, true) && !is_dir($deadlineDir)) {
        throw new RuntimeException('fixture_deadline_directory_unavailable');
    }
    file_put_contents($deadlineDir . '/deadlines_20990101.json', json_encode([
        'source' => 'FIXTURE_OFFICIAL_DEADLINE_CACHE', 'deadlines' => [$race => '12:00'],
    ], JSON_THROW_ON_ERROR));
    $canonical = $ledger->captureLive([
        'date' => $date, 'place' => 'TMG', 'phase' => 'live', 'late_replay' => false,
        'ready_race_codes' => [$race], 'legacy_by_course' => [],
        'traditional_evaluation_by_course' => $traditionalEvaluation,
        'ml_decisions' => $ml, 'logic_version' => 'fixture-logic-v1',
        'traditional_policy_version' => 'fixture-traditional-v1', 'ml_policy_version' => 'fixture-model',
    ]);
    $ok($canonical['records'] === 18, 'formal_18_canonical_rows_ready_before_comparative_capture');
    $captureInput = [
        'date' => $date, 'venue' => 'TMG', 'phase' => 'live', 'ready_race_codes' => [$race],
        'input_by_race' => [$race => $input], 'ml_decisions' => $ml,
        'legacy_by_course' => [], 'traditional_evaluation_by_course' => $traditionalEvaluation,
        'traditional_policy_version' => 'fixture-traditional-v1',
        'traditional_runtime_input' => ['fixture' => true],
    ];
    $capture = (new StrictForwardComparativeCapture($context, $contract))->capture($captureInput);
    $ok($capture['status'] === 'COMPLETE', 'formal_runtime_capture_reaches_snapshot_and_pair_stores');
    $second = (new StrictForwardComparativeCapture($context, $contract))->capture($captureInput);
    $ok($second['status'] === 'COMPLETE' && $second['duplicates'] === count($uses) + 1, 'retry_same_payload_idempotent');

    $windowEvaluation = (new FormalComparativeReadinessEvaluator($contract))->evaluateWindow($window);
    $ok($windowEvaluation['status'] === 'PASS', 'window_evaluator_accepts_complete_runtime_capture');
    $ok((int)$windowEvaluation['snapshot_count'] === 1 && (int)$windowEvaluation['completion_count'] === 1, 'one_parent_snapshot_and_one_completion_per_race');
    $ok((int)$windowEvaluation['paired_denominator'] === 7 && (int)$windowEvaluation['ml_only_excluded'] === 4, 'only_11_deployed_uses_paired_or_excluded');
    $ok(count(glob($window . '/comparative/pairs/20990101/*.json') ?: []) === 11, 'nondeploy_structural_rows_do_not_create_pairs');

    $pairPath = (glob($window . '/comparative/pairs/20990101/*.json') ?: [])[0] ?? '';
    if ($pairPath !== '') unlink($pairPath);
    $missingPair = (new FormalComparativeReadinessEvaluator($contract))->evaluateWindow($window);
    $ok($missingPair['status'] === 'BLOCKED' && in_array('COMPARATIVE_CAPTURE_INCOMPLETE', $missingPair['errors'], true), 'missing_pair_blocks_comparative_readiness');

    // A changed parent input under the same stable key must be fail-loud and
    // leaves an auditable conflict instead of overwriting the original.
    $changed = $captureInput;
    $changed['input_by_race'][$race]['ml_child']['uses']['3|top3']['feature_values']['c3_rate'] = 9.99;
    $conflict = (new StrictForwardComparativeCapture($context, $contract))->capture($changed);
    $ok($conflict['status'] === 'CONFLICT', 'snapshot_conflict_is_fail_loud');

    $source = (string)file_get_contents($repo . '/web/tamagawa_lane4_star_api.php');
    $ok(str_contains($source, 'BOATRACE_STRICT_FORWARD_FORMAL_CAPTURE') && str_contains($source, 'StrictForwardComparativeCapture'), 'scheduler_only_runtime_wiring_present');
    $ok(!str_contains($source, 'result / payout'), 'production_api_response_contract_not_rewritten');
} finally {
    $remove($tmp);
}

echo json_encode(['total' => $passed + $failed, 'pass' => $passed, 'fail' => $failed, 'tests' => $tests], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($failed === 0 ? 0 : 1);
