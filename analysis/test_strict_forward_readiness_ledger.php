<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/StrictForwardReadinessLedger.php';

/**
 * Deterministic, outcome-blind fixture tests for the strict-forward readiness
 * ledger.  This intentionally writes only to a new /tmp directory and never
 * touches production snapshots, outcomes, or the scheduler.
 */
function readinessAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERTION_FAILED: ' . $message);
    }
}

/** @return array<string,mixed> */
function readinessFixture(array $override = []): array
{
    return array_replace([
        'epoch_status' => 'IGNORED_BY_NORMALIZER',
        'capture_mode' => 'LIVE_STRICT',
        'capture_source' => 'fixture',
        'capture_stage' => 'exhibition',
        'race_code' => '20990101TMG01',
        'venue' => 'TMG',
        'course' => 3,
        'target' => 'top3',
        'meet_id' => '2099-01-01:TMG',
        'captured_at' => '2099-01-01T10:00:00+09:00',
        'prediction_timestamp' => '2099-01-01T10:00:00+09:00',
        'cutoff_timestamp' => '2099-01-01T10:15:00+09:00',
        'timezone' => 'Asia/Tokyo',
        'deadline_source' => 'FIXTURE_OFFICIAL_CACHE',
        'eligible' => true,
        'eligibility_reason' => 'FIXTURE_ELIGIBLE',
        'exclusion_reason' => null,
        'selection_subject_id' => '9999',
        'ml_selected' => false,
        'ml_policy_version' => 'fixture_ml_v1',
        'artifact_identifier' => 'fixture.joblib',
        'artifact_sha256' => str_repeat('a', 64),
        'ml_threshold' => 0.42,
        'logic_version' => 'fixture_logic_sha256',
        'measurement_implementation_identity' => 'sha256:' . str_repeat('c', 64),
        'measurement_manifest_schema_version' => MeasurementImplementationProvenance::MANIFEST_SCHEMA_VERSION,
        'measurement_manifest_sha256' => 'sha256:' . str_repeat('d', 64),
        'measurement_manifest_ref' => 'implementation_manifests/' . str_repeat('c', 64) . '.json',
        'measurement_source_freeze_sha256' => 'sha256:' . str_repeat('e', 64),
        'measurement_source_freeze_ref' => 'implementation_sources/' . str_repeat('c', 64) . '.json',
        'traditional_selected' => false,
        'traditional_policy_version' => 'fixture_traditional_v1',
        'traditional_logic_version' => 'fixture_logic_sha256',
        'traditional_decision_status' => 'OBSERVED_LEGACY_SIGNAL',
        'capture_status' => 'READY',
        'missing_reason' => null,
    ], $override);
}

/** @return array<string,mixed> */
function readinessCandidate(string $raceCode, string $cutoff): array
{
    return ['race_code' => $raceCode, 'venue' => substr($raceCode, 8, 3), 'cutoff_timestamp' => $cutoff];
}

/** @return array<string,mixed> */
function readinessLiveContext(string $date, string $raceCode): array
{
    return [
        'date' => $date, 'place' => 'TMG', 'phase' => 'live', 'late_replay' => false,
        'ready_race_codes' => [$raceCode], 'legacy_by_course' => [], 'ml_decisions' => [
            $raceCode => ['1' => ['player_id' => '9999', 'targets' => [
                'top3' => ['decision_available' => true, 'selected' => false, 'threshold' => 0.42,
                    'model_version' => 'fixture_ml_v1', 'artifact_identifier' => 'fixture.joblib', 'artifact_sha256' => str_repeat('a', 64)],
            ]]],
        ], 'logic_version' => 'fixture_logic_sha256', 'traditional_policy_version' => 'fixture_traditional_v1',
    ];
}

$root = sys_get_temp_dir() . '/boatrace_strict_forward_fixture_' . bin2hex(random_bytes(8));
$opportunityRoot = sys_get_temp_dir() . '/boatrace_strict_forward_opportunity_fixture_' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700, true) && !is_dir($root)) {
    throw new RuntimeException('Unable to create readiness fixture root: ' . $root);
}
$opportunity = new StrictForwardOpportunityLedger($opportunityRoot);
$opportunity->recordCandidateUniverse([
    'date' => '2099-01-01', 'candidates' => [readinessCandidate('20990101TMG01', '2099-01-01T10:15:00+09:00')],
    'source' => 'fixture-official-cache', 'source_identity' => 'sha256:fixture-20990101', 'source_generated_at' => '2099-01-01T08:00:00+09:00',
]);
$opportunity->recordCandidateUniverse([
    'date' => '2099-12-31', 'candidates' => [
        readinessCandidate('20991231TMG01', '2099-12-31T23:59:00+09:00'),
        readinessCandidate('20991231TMG02', '2099-12-31T23:59:00+09:00'),
        readinessCandidate('20991231TMG03', '2099-12-31T23:59:00+09:00'),
    ], 'source' => 'fixture-official-cache', 'source_identity' => 'sha256:fixture-20991231', 'source_generated_at' => '2099-12-31T08:00:00+09:00',
]);
$windowPath = $root . '/validation_window_manifest.json';
readinessAssert(WindowActivationHold::createPrepared($windowPath, 'fixture-window-main')['accepted'] === true, 'main fixture PREPARED Window created');
$mainProvenance = new MeasurementImplementationProvenance(dirname(__DIR__), $root);
$mainDeclaration = new WindowDeclaredImplementationIdentity($windowPath, $mainProvenance);
$mainDeclared = $mainDeclaration->declare();
readinessAssert($mainDeclared['accepted'] === true, 'fixture Window declared identity created');
$mainActivation = new WindowActivationHold($windowPath, $mainDeclaration, $root);
readinessAssert($mainActivation->activate('fixture://main-preflight')['accepted'] === true, 'main fixture Window activated');
$ledger = new StrictForwardReadinessLedger($root, false, $opportunity, $mainDeclaration, $mainActivation);
$results = [];

$first = $ledger->writeRecords([readinessFixture()]);
readinessAssert($first['records'] === 1 && $first['duplicates'] === 0 && $first['conflicts'] === 0, 'initial save');
$path = $root . '/records/20990101/' . hash('sha256', 'READINESS_COMPLETION|20990101TMG01|TMG|3|top3|exhibition') . '.json';
readinessAssert(is_file($path), 'first-write record path');
$stored = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
readinessAssert($stored['epoch_status'] === 'READINESS_COMPLETION', 'completion epoch status');
readinessAssert($stored['before_cutoff'] === true && $stored['formal_candidate'] === true, 'strict cutoff metadata');
readinessAssert($stored['ml_selected'] === false && $stored['traditional_selected'] === false, 'both-0 retained');
foreach (['target_outcome', 'actual_result', 'payout', 'roi', 'hit_rate'] as $forbidden) {
    readinessAssert(!array_key_exists($forbidden, $stored), 'no outcome field: ' . $forbidden);
}
$results[] = 'initial_first_write_and_both_zero';

$identityMissing = $ledger->writeRecords([readinessFixture([
    'course' => 1,
    'target' => 'first',
    'measurement_implementation_identity' => '',
])]);
readinessAssert($identityMissing['records'] === 0 && $identityMissing['conflicts'] === 0, 'formal LIVE_STRICT row without implementation identity is rejected');
$results[] = 'formal_identity_missing_rejected';

$expected = $ledger->recordExpectedLive([
    'date' => '2099-01-01',
    'place' => 'TMG',
    'races' => [[
        'race_code' => '20990101TMG01',
        'cutoff_timestamp' => '2099-01-01T10:15:00+09:00',
        'deadline_source' => 'FIXTURE_OFFICIAL_CACHE',
    ]],
]);
readinessAssert($expected['expected'] === 18 && $expected['duplicates'] === 0, 'full expected 18-key universe');
$expectedPath = $root . '/expected/20990101/' . hash('sha256', 'READINESS_COMPLETION|20990101TMG01|TMG|3|top3|exhibition') . '.json';
$expectedStored = json_decode((string)file_get_contents($expectedPath), true, 512, JSON_THROW_ON_ERROR);
readinessAssert(isset($expectedStored['measurement_implementation_identity'], $expectedStored['measurement_manifest_ref'], $expectedStored['measurement_source_freeze_ref']), 'Expected row receives measurement implementation provenance');
$expectedDuplicate = $ledger->recordExpectedLive([
    'date' => '2099-01-01', 'place' => 'TMG',
    'races' => [['race_code' => '20990101TMG01', 'cutoff_timestamp' => '2099-01-01T10:15:00+09:00']],
]);
readinessAssert($expectedDuplicate['expected'] === 0 && $expectedDuplicate['duplicates'] === 18, 'expected universe first-write-wins');
$results[] = 'expected_universe_and_duplicate_accounting';

$duplicate = $ledger->writeRecords([readinessFixture([
    'captured_at' => '2099-01-01T10:01:00+09:00',
    'prediction_timestamp' => '2099-01-01T10:01:00+09:00',
])]);
readinessAssert($duplicate['records'] === 0 && $duplicate['duplicates'] === 1, 'same decision is a duplicate despite capture timestamp');
$results[] = 'duplicate_detection';

$conflict = $ledger->writeRecords([readinessFixture(['ml_selected' => true])]);
readinessAssert($conflict['records'] === 0 && $conflict['conflicts'] === 1, 'changed decision is conflict');
$stillStored = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
readinessAssert($stillStored['ml_selected'] === false, 'first-write-wins retained');
$results[] = 'conflict_detection_and_first_write_wins';

$notEligible = $ledger->writeRecords([readinessFixture([
    'course' => 4,
    'target' => 'top2',
    'eligible' => false,
    'ml_selected' => null,
    'traditional_selected' => null,
    'eligibility_reason' => 'NOT_ELIGIBLE',
    'exclusion_reason' => 'INPUT_MISSING',
    'traditional_decision_status' => 'TRADITIONAL_DECISION_UNAVAILABLE',
    'capture_status' => 'INPUT_MISSING',
    'missing_reason' => 'INPUT_MISSING',
])]);
readinessAssert($notEligible['records'] === 1, 'not eligible preserved as a population row');
$results[] = 'not_eligible_population_row';

$traditionalUnavailable = $ledger->writeRecords([readinessFixture([
    'course' => 5,
    'target' => 'first',
    'traditional_selected' => null,
    'traditional_decision_status' => 'TRADITIONAL_DECISION_UNAVAILABLE',
    'capture_status' => 'TRADITIONAL_DECISION_UNAVAILABLE',
    'missing_reason' => 'TRADITIONAL_DECISION_UNAVAILABLE',
])]);
readinessAssert($traditionalUnavailable['records'] === 1, 'traditional unavailable is not inferred as false');
$results[] = 'traditional_unavailable_without_false_inference';

// Exercise the same context boundary used by the API, without using a real
// date, database, race result, or production deadline cache.
$deadlineDir = sys_get_temp_dir() . '/boatrace_official_deadlines';
if (!is_dir($deadlineDir) && !mkdir($deadlineDir, 0775, true) && !is_dir($deadlineDir)) {
    throw new RuntimeException('fixture_deadline_directory_unavailable');
}
$deadlineFixture = $deadlineDir . '/deadlines_20991231.json';
file_put_contents($deadlineFixture, json_encode([
    'source' => 'FIXTURE_OFFICIAL_DEADLINE_CACHE',
    'deadlines' => ['20991231TMG01' => '23:59', '20991231TMG02' => '23:59', '20991231TMG03' => '23:59'],
], JSON_THROW_ON_ERROR));
$contextCapture = $ledger->captureLive(array_replace(readinessLiveContext('2099-12-31', '20991231TMG01'), [
    'legacy_by_course' => [
        2 => [
            'sashi' => ['20991231TMG01' => ['star_level' => 2]],
            'makuri' => [],
        ],
        3 => ['20991231TMG01' => ['star_level' => 2]],
    ],
    'traditional_evaluation_by_course' => [
        4 => ['20991231TMG01' => [
            'policy_exists' => true,
            'evaluated' => true,
            'reason' => 'EXISTING_POLICY_EVALUATED',
        ]],
        5 => ['20991231TMG01' => [
            'policy_exists' => true,
            'evaluated' => false,
            'reason' => 'LANE5_ATTACK_RATE_MISSING',
        ]],
        6 => ['20991231TMG01' => [
            'policy_exists' => false,
            'evaluated' => false,
            'reason' => 'PRIMARY_POLICY_DISABLED',
        ]],
    ],
    'ml_decisions' => [
        '20991231TMG01' => [
            '3' => ['player_id' => '9999', 'targets' => [
                'top3' => ['decision_available' => true, 'selected' => false, 'threshold' => 0.42,
                    'model_version' => 'fixture_ml_v1', 'artifact_identifier' => 'fixture.joblib', 'artifact_sha256' => str_repeat('a', 64)],
            ]],
        ],
    ],
]));
readinessAssert($contextCapture['records'] === 18, 'live API context captures complete 6C x 3 target population');
$contextPath = $root . '/records/20991231/' . hash('sha256', 'READINESS_COMPLETION|20991231TMG01|TMG|3|top3|exhibition') . '.json';
$contextStored = json_decode((string)file_get_contents($contextPath), true, 512, JSON_THROW_ON_ERROR);
readinessAssert(isset($contextStored['measurement_implementation_identity'], $contextStored['measurement_manifest_ref'], $contextStored['measurement_source_freeze_ref']), 'Canonical row receives measurement implementation provenance');
$contextProvenance = new MeasurementImplementationProvenance(dirname(__DIR__), $root);
$contextVerification = $contextProvenance->verify(
    (string)$contextStored['measurement_implementation_identity'],
    (string)$contextStored['measurement_manifest_ref'],
    (string)$contextStored['measurement_source_freeze_ref'],
);
readinessAssert($contextVerification['valid'] === true, 'Canonical formal row reverses to valid frozen measurement sources');
readinessAssert($contextStored['traditional_selected'] === true, 'observed star level produces traditional true');
$contextFalsePath = $root . '/records/20991231/' . hash('sha256', 'READINESS_COMPLETION|20991231TMG01|TMG|3|first|exhibition') . '.json';
$contextFalse = json_decode((string)file_get_contents($contextFalsePath), true, 512, JSON_THROW_ON_ERROR);
readinessAssert($contextFalse['traditional_selected'] === false, 'observed lower star level safely produces traditional false');
$contextUnknownPath = $root . '/records/20991231/' . hash('sha256', 'READINESS_COMPLETION|20991231TMG01|TMG|4|top3|exhibition') . '.json';
$contextUnknown = json_decode((string)file_get_contents($contextUnknownPath), true, 512, JSON_THROW_ON_ERROR);
readinessAssert($contextUnknown['traditional_selected'] === false, 'evaluated legacy absence safely resolves false');
readinessAssert($contextUnknown['traditional_unknown_classification'] === 'RESOLVED_TRUE_FALSE', 'evaluated false classified resolved');
$contextInputUnknownPath = $root . '/records/20991231/' . hash('sha256', 'READINESS_COMPLETION|20991231TMG01|TMG|5|top3|exhibition') . '.json';
$contextInputUnknown = json_decode((string)file_get_contents($contextInputUnknownPath), true, 512, JSON_THROW_ON_ERROR);
readinessAssert($contextInputUnknown['traditional_selected'] === null, 'missing policy input remains unknown');
readinessAssert($contextInputUnknown['traditional_unknown_classification'] === 'INPUT_UNAVAILABLE', 'missing input classified');
$contextPolicyUnknownPath = $root . '/records/20991231/' . hash('sha256', 'READINESS_COMPLETION|20991231TMG01|TMG|6|top3|exhibition') . '.json';
$contextPolicyUnknown = json_decode((string)file_get_contents($contextPolicyUnknownPath), true, 512, JSON_THROW_ON_ERROR);
readinessAssert($contextPolicyUnknown['traditional_selected'] === null, 'disabled policy remains unknown');
readinessAssert($contextPolicyUnknown['traditional_unknown_classification'] === 'POLICY_UNDEFINED', 'disabled policy classified');
$context2TruePath = $root . '/records/20991231/' . hash('sha256', 'READINESS_COMPLETION|20991231TMG01|TMG|2|top2|exhibition') . '.json';
$context2True = json_decode((string)file_get_contents($context2TruePath), true, 512, JSON_THROW_ON_ERROR);
readinessAssert($context2True['traditional_selected'] === true, '2C explicit component safely proves true');
$context2UnknownPath = $root . '/records/20991231/' . hash('sha256', 'READINESS_COMPLETION|20991231TMG01|TMG|2|first|exhibition') . '.json';
$context2Unknown = json_decode((string)file_get_contents($context2UnknownPath), true, 512, JSON_THROW_ON_ERROR);
readinessAssert($context2Unknown['traditional_selected'] === null, '2C combined false is not invented');
readinessAssert($context2Unknown['traditional_unknown_classification'] === 'POLICY_UNDEFINED', '2C policy ambiguity classified');
$results[] = 'live_context_traditional_true_false_input_policy_unknown_and_2c_policy_limit';

$artifactUnavailable = $ledger->captureLive(array_replace(readinessLiveContext('2099-12-31', '20991231TMG02'), [
    'legacy_by_course' => [],
    'ml_decisions' => [],
    'traditional_policy_version' => 'fixture_traditional_v1',
    'ml_policy_version' => 'fixture_ml_v1',
]));
readinessAssert($artifactUnavailable['records'] === 18, 'artifact error retains full population rows');
$artifactPath = $root . '/records/20991231/' . hash('sha256', 'READINESS_COMPLETION|20991231TMG02|TMG|1|top3|exhibition') . '.json';
$artifactRow = json_decode((string)file_get_contents($artifactPath), true, 512, JSON_THROW_ON_ERROR);
readinessAssert($artifactRow['eligible'] === false && $artifactRow['missing_reason'] === 'ARTIFACT_ERROR', 'artifact error classified');
$failureAuditPath = $root . '/failures/20991231.jsonl';
readinessAssert(is_file($failureAuditPath), 'fail-loud audit file exists');
readinessAssert(str_contains((string)file_get_contents($failureAuditPath), 'ARTIFACT_ERROR'), 'fail-loud audit has error code');
$results[] = 'artifact_error_population_rows_and_fail_loud_audit';

$inputUnavailable = $ledger->captureLive(array_replace(readinessLiveContext('2099-12-31', '20991231TMG03'), [
    'legacy_by_course' => [],
    'ml_decisions' => [],
    'ml_failure_reason' => 'INFERENCE_INPUT_EMPTY',
    'traditional_policy_version' => 'fixture_traditional_v1',
    'ml_policy_version' => 'fixture_ml_v1',
]));
readinessAssert($inputUnavailable['records'] === 18, 'input failure retains full population rows');
$inputPath = $root . '/records/20991231/' . hash('sha256', 'READINESS_COMPLETION|20991231TMG03|TMG|1|top3|exhibition') . '.json';
$inputRow = json_decode((string)file_get_contents($inputPath), true, 512, JSON_THROW_ON_ERROR);
readinessAssert($inputRow['eligible'] === false && $inputRow['missing_reason'] === 'INFERENCE_INPUT_EMPTY', 'explicit input failure propagated to canonical row');
$results[] = 'inference_input_failure_population_rows_and_fail_loud_audit';

$ledger->recordCaptureFailure([
    'date' => '2099-12-31',
    'place' => 'TMG',
    'race_codes' => ['20991231TMG01'],
    'error_type' => 'FIXTURE',
    'error_code' => 'RACE_CAPTURE_FAILURE',
]);
$ledger->recordCaptureFailure([
    'date' => '2099-12-31',
    'place' => '',
    'race_codes' => [],
    'error_type' => 'FIXTURE',
    'error_code' => 'ENUMERATION_FAILURE',
]);
$failureAudit = (string)file_get_contents($failureAuditPath);
readinessAssert(str_contains($failureAudit, 'RACE_SPECIFIC_CAPTURE') && str_contains($failureAudit, 'DATE_WIDE_RACE_ENUMERATION'), 'failure audit distinguishes exact and pre-enumeration opportunity scope');
$results[] = 'capture_failure_opportunity_identity';

$afterCutoff = $ledger->writeRecords([readinessFixture([
    'course' => 6,
    'target' => 'top3',
    'captured_at' => '2099-01-01T10:16:00+09:00',
    'prediction_timestamp' => '2099-01-01T10:16:00+09:00',
])]);
readinessAssert($afterCutoff['records'] === 0, 'after-cutoff LIVE_STRICT rejected');
$results[] = 'after_cutoff_rejected';

// Offset-aware timestamps must compare the same instant, not their local clock
// text.  00:30 JST is 15:30Z on the previous day and precedes 15:45Z.
$offsetBefore = $ledger->writeRecords([readinessFixture([
    'course' => 6,
    'target' => 'top2',
    'captured_at' => '2099-01-01T00:30:00+09:00',
    'prediction_timestamp' => '2099-01-01T00:30:00+09:00',
    'cutoff_timestamp' => '2098-12-31T15:45:00Z',
])]);
readinessAssert($offsetBefore['records'] === 1, 'JST versus UTC same-instant comparison accepts before cutoff');
$offsetAfter = $ledger->writeRecords([readinessFixture([
    'course' => 6,
    'target' => 'first',
    'captured_at' => '2099-01-01T00:30:00+09:00',
    'prediction_timestamp' => '2099-01-01T00:30:00+09:00',
    'cutoff_timestamp' => '2098-12-31T15:15:00Z',
])]);
readinessAssert($offsetAfter['records'] === 0, 'JST versus UTC same-instant comparison rejects after cutoff');
$results[] = 'offset_aware_jst_utc_cutoff_comparison';

$naiveTimestamp = $ledger->writeRecords([readinessFixture([
    'course' => 6,
    'target' => 'top3',
    'captured_at' => '2099-01-01T10:00:00',
    'prediction_timestamp' => '2099-01-01T10:00:00',
])]);
readinessAssert($naiveTimestamp['records'] === 0, 'naive timestamp is rejected rather than guessed as JST');
$results[] = 'naive_timestamp_rejected';

$frozenReplay = $ledger->writeRecords([readinessFixture([
    'course' => 1,
    'target' => 'top2',
    'capture_mode' => 'REPLAY_FROM_FROZEN_DECISION',
    'captured_at' => '2099-01-01T11:00:00+09:00',
    'prediction_timestamp' => '2099-01-01T10:00:00+09:00',
    'replay_source_id' => 'fixture-source-id',
    'replay_original_captured_at' => '2099-01-01T10:00:00+09:00',
    'replay_source_hash' => str_repeat('b', 64),
])]);
readinessAssert($frozenReplay['records'] === 1, 'frozen replay accepts provenance');
$frozenPath = $root . '/records/20990101/' . hash('sha256', 'READINESS_COMPLETION|20990101TMG01|TMG|1|top2|exhibition') . '.json';
$frozen = json_decode((string)file_get_contents($frozenPath), true, 512, JSON_THROW_ON_ERROR);
readinessAssert($frozen['formal_candidate'] === false, 'replay is excluded from formal candidate');
$results[] = 'frozen_replay_provenance_and_exclusion';

$recomputedReplay = $ledger->writeRecords([readinessFixture([
    'course' => 2,
    'target' => 'top2',
    'capture_mode' => 'REPLAY_RECOMPUTED',
])]);
readinessAssert($recomputedReplay['records'] === 1, 'recomputed replay recorded as non-formal readiness evidence');
$results[] = 'recomputed_replay_nonformal';

// Formal evidence taint isolation: all fixtures use only temporary ledgers and
// a future-dated /tmp deadline cache. A taint never converts a race to
// INELIGIBLE and never deletes already append-only expected/canonical rows.
$taintDate = '2099-12-30';
$taintCodes = ['20991230TMG01', '20991230TMG02', '20991230TMG03'];
$taintOpportunity = new StrictForwardOpportunityLedger($opportunityRoot . '_taint');
$taintOpportunity->recordCandidateUniverse([
    'date' => $taintDate,
    'candidates' => array_map(static fn(string $code): array => readinessCandidate($code, '2099-12-30T23:59:00+09:00'), $taintCodes),
    'source' => 'fixture-official-cache', 'source_identity' => 'sha256:taint-base', 'source_generated_at' => '2099-12-30T08:00:00+09:00',
]);
$taintRoot = $root . '_taint';
$taintWindowPath = $taintRoot . '/validation_window_manifest.json';
if (!is_dir($taintRoot) && !mkdir($taintRoot, 0775, true) && !is_dir($taintRoot)) throw new RuntimeException('taint_fixture_root_unavailable');
readinessAssert(WindowActivationHold::createPrepared($taintWindowPath, 'fixture-window-taint')['accepted'] === true, 'taint fixture PREPARED Window created');
$taintDeclaration = new WindowDeclaredImplementationIdentity($taintWindowPath, new MeasurementImplementationProvenance(dirname(__DIR__), $taintRoot));
readinessAssert($taintDeclaration->declare()['accepted'] === true, 'taint fixture Window declared identity created');
$taintActivation = new WindowActivationHold($taintWindowPath, $taintDeclaration, $taintRoot);
readinessAssert($taintActivation->activate('fixture://taint-preflight')['accepted'] === true, 'taint fixture Window activated');
$taintLedger = new StrictForwardReadinessLedger($taintRoot, false, $taintOpportunity, $taintDeclaration, $taintActivation);
$taintCache = $deadlineDir . '/deadlines_20991230.json';
file_put_contents($taintCache, json_encode([
    'source' => 'FIXTURE_OFFICIAL_DEADLINE_CACHE',
    'deadlines' => array_fill_keys($taintCodes, '23:59'),
], JSON_THROW_ON_ERROR));

// A. Taint before Expected: no formal expected rows, no false INELIGIBLE.
$taintOpportunity->recordOfficialScheduleSnapshot([
    'date' => $taintDate, 'raw_source_identity' => 'sha256:taint-before-expected', 'source_generated_at' => '2099-12-30T08:10:00+09:00',
    'schedules' => [['race_code' => $taintCodes[0], 'cutoff_timestamp' => '2099-12-30T23:58:00+09:00', 'official_status' => 'ACTIVE']],
]);
$beforeExpected = $taintLedger->recordExpectedLive([
    'date' => $taintDate, 'place' => 'TMG',
    'races' => [['race_code' => $taintCodes[0], 'cutoff_timestamp' => '2099-12-30T23:59:00+09:00', 'deadline_source' => 'FIXTURE_OFFICIAL_CACHE']],
]);
$beforeExpectedStatus = $taintOpportunity->formalMeasurementStatus($taintDate, $taintCodes[0]);
readinessAssert($beforeExpected['expected'] === 0 && $beforeExpected['tainted'] === 1, 'taint before Expected writes no formal expected rows');
readinessAssert(($beforeExpectedStatus['status'] ?? '') === 'TAINTED_SEMANTIC_REVISION_UNVERIFIED', 'taint before Expected is unresolved, not ineligible');
$results[] = 'taint_before_expected_rejected_without_ineligible_conversion';

// B. Expected exists, then taint arrives before canonical capture: existing
// expected history remains, but canonical formal evidence is rejected.
$preCanonicalExpected = $taintLedger->recordExpectedLive([
    'date' => $taintDate, 'place' => 'TMG',
    'races' => [['race_code' => $taintCodes[1], 'cutoff_timestamp' => '2099-12-30T23:59:00+09:00', 'deadline_source' => 'FIXTURE_OFFICIAL_CACHE']],
]);
readinessAssert($preCanonicalExpected['expected'] === 18, 'clean race creates Expected before later taint');
$taintOpportunity->recordOfficialScheduleSnapshot([
    'date' => $taintDate, 'raw_source_identity' => 'sha256:taint-before-canonical', 'source_generated_at' => '2099-12-30T08:11:00+09:00',
    'schedules' => [['race_code' => $taintCodes[1], 'cutoff_timestamp' => '2099-12-30T23:58:00+09:00', 'official_status' => 'ACTIVE']],
]);
$preCanonicalCapture = $taintLedger->captureLive(readinessLiveContext($taintDate, $taintCodes[1]));
$preCanonicalExpectedFiles = glob($taintRoot . '/expected/20991230/*.json') ?: [];
$preCanonicalRecordFiles = glob($taintRoot . '/records/20991230/*.json') ?: [];
readinessAssert($preCanonicalCapture['records'] === 0 && count($preCanonicalExpectedFiles) === 18 && count($preCanonicalRecordFiles) === 0, 'taint before canonical preserves Expected but creates no formal canonical record');
$results[] = 'taint_before_canonical_rejects_formal_capture';

// C. Normal formal evidence already exists, then a late unverified revision
// arrives. Historical rows remain, but the latest admission status is false,
// so Gate C cannot accept or count this race.
$lateExpected = $taintLedger->recordExpectedLive([
    'date' => $taintDate, 'place' => 'TMG',
    'races' => [['race_code' => $taintCodes[2], 'cutoff_timestamp' => '2099-12-30T23:59:00+09:00', 'deadline_source' => 'FIXTURE_OFFICIAL_CACHE']],
]);
$lateCapture = $taintLedger->captureLive(readinessLiveContext($taintDate, $taintCodes[2]));
readinessAssert($lateExpected['expected'] === 18 && $lateCapture['records'] === 18, 'clean race initially creates formal Expected and canonical rows');
$lateRecordPath = $taintRoot . '/records/20991230/' . hash('sha256', 'READINESS_COMPLETION|' . $taintCodes[2] . '|TMG|1|top3|exhibition') . '.json';
readinessAssert(is_file($lateRecordPath), 'late-taint fixture has append-only canonical history');
$taintOpportunity->recordOfficialScheduleSnapshot([
    'date' => $taintDate, 'raw_source_identity' => 'sha256:taint-late', 'source_generated_at' => '2099-12-30T08:12:00+09:00',
    'schedules' => [['race_code' => $taintCodes[2], 'cutoff_timestamp' => '2099-12-30T23:58:00+09:00', 'official_status' => 'ACTIVE']],
]);
$lateStatus = $taintOpportunity->formalMeasurementStatus($taintDate, $taintCodes[2]);
$lateRepeatCapture = $taintLedger->captureLive(readinessLiveContext($taintDate, $taintCodes[2]));
readinessAssert(is_file($lateRecordPath) && ($lateStatus['accepted'] ?? true) === false, 'late taint preserves append-only canonical row but rejects acceptance');
readinessAssert(($lateStatus['status'] ?? '') === 'TAINTED_SEMANTIC_REVISION_UNVERIFIED' && $lateRepeatCapture['records'] === 0, 'late taint cannot become a new formal candidate or Gate C sample');
$finalizerSource = (string)file_get_contents(__DIR__ . '/finalize_window_continuability_verification.php');
readinessAssert(str_contains($finalizerSource, '$formalSchedule = $ledger->formalMeasurementStatus($date, $raceCode);')
    && str_contains($finalizerSource, '$isEligible = !empty($formalSchedule[\'accepted\'])')
    && str_contains($finalizerSource, 'BLOCKED_IMPLEMENTATION_PROVENANCE')
    && str_contains($finalizerSource, 'fwcvRowsHaveValidMeasurementProvenance'), 'Gate C acceptance excludes tainted or provenance-missing formal rows');
$results[] = 'late_taint_preserves_history_but_rejects_gate_c_acceptance';

// Same semantics and independently verified revisions remain admissible.
$cleanOpportunity = new StrictForwardOpportunityLedger($opportunityRoot . '_verified');
$cleanCode = '20991229TMG01';
$cleanOpportunity->recordCandidateUniverse([
    'date' => '2099-12-29', 'candidates' => [readinessCandidate($cleanCode, '2099-12-29T23:59:00+09:00')],
    'source' => 'fixture-official-cache', 'source_identity' => 'sha256:verified-base', 'source_generated_at' => '2099-12-29T08:00:00+09:00',
]);
$cleanOpportunity->recordOfficialScheduleSnapshot([
    'date' => '2099-12-29', 'raw_source_identity' => 'sha256:same-semantic', 'source_generated_at' => '2099-12-29T08:01:00+09:00',
    'schedules' => [['race_code' => $cleanCode, 'cutoff_timestamp' => '2099-12-29T23:59:00+09:00', 'official_status' => 'ACTIVE']],
]);
$sameSemantic = $cleanOpportunity->formalMeasurementStatus('2099-12-29', $cleanCode);
$cleanOpportunity->recordOfficialScheduleSnapshot([
    'date' => '2099-12-29', 'raw_source_identity' => 'sha256:verified-change', 'source_generated_at' => '2099-12-29T08:02:00+09:00',
    'official_revision_evidence' => [
        'evidence_source' => 'fixture-official-revision', 'evidence_identity' => 'sha256:verified-evidence',
        'official_revision_timestamp' => '2099-12-29T08:02:00+09:00', 'official_predecessor_revision_timestamp' => '2099-12-29T08:00:00+09:00',
        'predecessor_semantic_fingerprint' => 'sha256:' . hash('sha256', json_encode([
            'race_code' => $cleanCode, 'venue' => 'TMG', 'effective_cutoff_timestamp' => '2099-12-29T23:59:00+09:00', 'official_status' => 'ACTIVE',
        ], JSON_UNESCAPED_SLASHES)),
    ],
    'schedules' => [['race_code' => $cleanCode, 'cutoff_timestamp' => '2099-12-29T23:58:00+09:00', 'official_status' => 'ACTIVE']],
]);
$verifiedRevision = $cleanOpportunity->formalMeasurementStatus('2099-12-29', $cleanCode);
readinessAssert(($sameSemantic['accepted'] ?? false) === true, 'same semantic refresh remains formally admissible');
readinessAssert(($verifiedRevision['accepted'] ?? false) === true && ($verifiedRevision['cutoff_timestamp'] ?? '') === '2099-12-29T23:58:00+09:00', 'verified revision remains formally admissible');
$results[] = 'same_semantic_and_verified_revision_regression';

$outcomeAttempt = $ledger->writeRecords([readinessFixture([
    'course' => 1,
    'target' => 'top3',
    'target_outcome' => true,
])]);
readinessAssert($outcomeAttempt['records'] === 0, 'outcome field rejected');
$results[] = 'outcome_field_rejected';

$blockedRoot = $root . '/blocked-file';
file_put_contents($blockedRoot, 'not a directory');
$failOpen = (new StrictForwardReadinessLedger($blockedRoot, false))->writeRecords([readinessFixture([
    'course' => 2,
    'target' => 'top3',
])]);
readinessAssert($failOpen['records'] === 0 && $failOpen['conflicts'] === 0, 'I/O failure stays isolated');
$results[] = 'fail_open_io_isolation';

$apiSource = (string)file_get_contents(__DIR__ . '/../web/tamagawa_lane4_star_api.php');
$captureSource = (string)file_get_contents(__DIR__ . '/capture_all_race_forward.php');
$gateA1Source = (string)file_get_contents(__DIR__ . '/../web/logic/GateA1ProspectiveInventory.php');
foreach (['AMG', 'ASY', 'BWK', 'EDG', 'HWJ', 'KRY', 'MKN', 'TMG', 'TDA', 'OMR', 'SMS', 'SME'] as $venue) {
    readinessAssert(str_contains($apiSource, "'{$venue}'"), 'API ML venue integration: ' . $venue);
    readinessAssert(str_contains($gateA1Source, "'{$venue}'"), 'shared scheduler ML venue integration: ' . $venue);
}
readinessAssert(str_contains($captureSource, 'GateA1ProspectiveInventory::isStrictVenue'), 'scheduler uses shared Gate A1 venue source');
readinessAssert(str_contains($captureSource, 'recordExpectedLiveFromContext'), 'scheduler records expected universe');
readinessAssert(str_contains($captureSource, 'recordCaptureFailureFromContext'), 'scheduler has fail-loud capture audit');
$results[] = 'twelve_venue_and_scheduler_source_integration';

echo json_encode([
    'status' => 'PASS',
    'root' => $root,
    'tests' => $results,
    'outcome_contract' => StrictForwardReadinessLedger::outcomeLedgerContract(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
