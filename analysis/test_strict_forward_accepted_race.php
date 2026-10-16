<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/StrictForwardAcceptedRace.php';

function acceptanceAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('ASSERTION_FAILED: ' . $message);
}

/** @return array<string,string> */
function acceptanceImplementation(): array
{
    $id = 'sha256:' . str_repeat('a', 64);
    return [
        'implementation_identity' => $id,
        'manifest_schema_version' => 'measurement-implementation-manifest/v1',
        'manifest_sha256' => 'sha256:' . str_repeat('b', 64),
        'manifest_ref' => 'implementation_manifests/' . str_repeat('a', 64) . '.json',
        'source_freeze_sha256' => 'sha256:' . str_repeat('c', 64),
        'source_freeze_ref' => 'implementation_sources/' . str_repeat('a', 64) . '.json',
    ];
}

/** @return array<string,mixed> */
function acceptanceRow(int $course, string $target, bool $deployed, array $implementation): array
{
    $raceCode = '20990101OMR01';
    $key = 'READINESS_COMPLETION|' . $raceCode . '|OMR|' . $course . '|' . $target . '|exhibition';
    $row = [
        'window_id' => 'fixture-window',
        'stable_unique_key' => $key,
        'scheduler_candidate_key' => 'scheduler-opportunity|' . $raceCode,
        'capture_mode' => 'LIVE_STRICT',
        'before_cutoff' => true,
        'captured_at' => '2099-01-01T10:00:00+09:00',
        'cutoff_timestamp' => '2099-01-01T10:15:00+09:00',
        'measurement_implementation_identity' => $implementation['implementation_identity'],
        'measurement_manifest_schema_version' => $implementation['manifest_schema_version'],
        'measurement_manifest_sha256' => $implementation['manifest_sha256'],
        'measurement_manifest_ref' => $implementation['manifest_ref'],
        'measurement_source_freeze_sha256' => $implementation['source_freeze_sha256'],
        'measurement_source_freeze_ref' => $implementation['source_freeze_ref'],
    ];
    if ($deployed) {
        return $row + [
            'eligible' => true, 'formal_candidate' => true, 'ml_selected' => false,
            'capture_status' => 'READY', 'eligibility_reason' => 'EXHIBITION_READY_AND_MODEL_DECISION_AVAILABLE',
            'artifact_identifier' => 'fixture.joblib', 'artifact_sha256' => str_repeat('d', 64),
            'ml_policy_version' => 'fixture_ml_v1', 'logic_version' => 'fixture_logic',
            'traditional_policy_version' => 'fixture_traditional', 'traditional_logic_version' => 'fixture_logic',
            'traditional_selected' => false,
        ];
    }
    return $row + [
        'eligible' => false, 'formal_candidate' => false, 'ml_selected' => null,
        'capture_status' => 'ML_TARGET_NOT_DEPLOYED', 'eligibility_reason' => 'ML_TARGET_NOT_DEPLOYED',
    ];
}

$implementation = acceptanceImplementation();
$expected = [];
$canonical = [];
foreach ([1, 2, 3, 4, 5, 6] as $course) {
    foreach (['top3', 'top2', 'first'] as $target) {
        $deployed = $course === 3 && $target === 'top3';
        $row = acceptanceRow($course, $target, $deployed, $implementation);
        $expected[] = $row;
        $canonical[] = $row;
    }
}
$context = [
    'formal_schedule_accepted' => true,
    'window_id' => 'fixture-window',
    'implementation' => $implementation,
    'scheduler_candidate_key' => 'scheduler-opportunity|20990101OMR01',
];

// CASE 8: 18 structural rows may include undeployed targets; only the real ML
// target is an accepted decision, and the race remains structurally valid.
$valid = StrictForwardAcceptedRace::evaluate($expected, $canonical, $context);
acceptanceAssert($valid['structural_complete'] && $valid['accepted'], 'valid deployed decision plus non-deploy structural rows');
acceptanceAssert($valid['accepted_ml_decision_count'] === 1, 'non-deployed rows are not accepted ML decisions');

// CASE 7: complete keys never turn an artifact error into an accepted race.
$artifactCanonical = $canonical;
$artifactCanonical[0]['capture_status'] = 'ARTIFACT_ERROR';
$artifactCanonical[0]['eligibility_reason'] = 'ARTIFACT_ERROR';
$artifactCanonical[0]['eligible'] = false;
$artifactCanonical[0]['formal_candidate'] = false;
$artifact = StrictForwardAcceptedRace::evaluate($expected, $artifactCanonical, $context);
acceptanceAssert($artifact['structural_complete'] && !$artifact['accepted'], 'artifact error rejects structurally complete race');

// Invalid live input is likewise never an accepted decision.
$inputCanonical = $canonical;
$inputCanonical[0]['capture_status'] = 'INFERENCE_INPUT_INCOMPLETE';
$inputCanonical[0]['eligibility_reason'] = 'INFERENCE_INPUT_INCOMPLETE';
$inputCanonical[0]['eligible'] = false;
$inputCanonical[0]['formal_candidate'] = false;
$input = StrictForwardAcceptedRace::evaluate($expected, $inputCanonical, $context);
acceptanceAssert($input['structural_complete'] && !$input['accepted'], 'input failure rejects race');

// CASE 9: both readers are statically bound to this same pure predicate.
$readModel = (string)file_get_contents(__DIR__ . '/../web/logic/ForwardStatusReadModel.php');
$inspector = (string)file_get_contents(__DIR__ . '/../web/logic/FormalMeasurementWindowStatusInspector.php');
$evaluator = (string)file_get_contents(__DIR__ . '/evaluate_operational_live_readiness.php');
acceptanceAssert(str_contains($readModel, 'FormalMeasurementWindowStatusInspector') && str_contains($inspector, 'StrictForwardAcceptedRace::evaluate'), 'TOP read model uses the inspector common predicate');
acceptanceAssert(str_contains($evaluator, 'StrictForwardAcceptedRace::evaluate'), 'formal evaluator uses common predicate');

// CASE 10: the production API still wraps ML failure with legacy fallback and
// passes the explicit failure reason only to the strict-forward ledger.
$api = (string)file_get_contents(__DIR__ . '/../web/tamagawa_lane4_star_api.php');
acceptanceAssert(str_contains($api, "'fallback' => true") && str_contains($api, "'ml_failure_reason' => \$strictForwardMlFailureReason"), 'production fallback and strict failure classification coexist');

echo json_encode(['status' => 'PASS', 'tests' => [
    'artifact_error_rejected', 'non_deploy_structural_rows_allowed',
    'input_failure_rejected', 'top_and_evaluator_share_predicate', 'production_fallback_preserved',
]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
