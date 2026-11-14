<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/StrictForwardMaterializationDiagnostic.php';

function materializationAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('ASSERTION_FAILED: ' . $message);
}

/** @return array<string,string> */
function materializationImplementation(): array
{
    return [
        'implementation_identity' => 'sha256:' . str_repeat('a', 64),
        'manifest_schema_version' => 'measurement-implementation-manifest/v1',
        'manifest_sha256' => 'sha256:' . str_repeat('b', 64),
        'manifest_ref' => 'implementation_manifests/' . str_repeat('a', 64) . '.json',
        'source_freeze_sha256' => 'sha256:' . str_repeat('c', 64),
        'source_freeze_ref' => 'implementation_sources/' . str_repeat('a', 64) . '.json',
    ];
}

/** @return array<string,mixed> */
function materializationRow(int $course, string $target, array $implementation): array
{
    $race = '20990101OMR01';
    return [
        'window_id' => 'fixture-window',
        'stable_unique_key' => 'READINESS_COMPLETION|' . $race . '|OMR|' . $course . '|' . $target . '|exhibition',
        'scheduler_candidate_key' => 'scheduler-opportunity|' . $race,
        'capture_mode' => 'LIVE_STRICT',
        'before_cutoff' => true,
        'expected_at' => '2099-01-01T10:00:00+09:00',
        'captured_at' => '2099-01-01T10:01:00+09:00',
        'cutoff_timestamp' => '2099-01-01T10:15:00+09:00',
        'capture_status' => 'READY',
        'eligibility_reason' => 'EXHIBITION_READY_AND_MODEL_DECISION_AVAILABLE',
        'measurement_implementation_identity' => $implementation['implementation_identity'],
        'measurement_manifest_schema_version' => $implementation['manifest_schema_version'],
        'measurement_manifest_sha256' => $implementation['manifest_sha256'],
        'measurement_manifest_ref' => $implementation['manifest_ref'],
        'measurement_source_freeze_sha256' => $implementation['source_freeze_sha256'],
        'measurement_source_freeze_ref' => $implementation['source_freeze_ref'],
    ];
}

$implementation = materializationImplementation();
$rows = [];
foreach (range(1, 6) as $course) foreach (['top3', 'top2', 'first'] as $target) {
    $rows[] = materializationRow($course, $target, $implementation);
}
$context = [
    'formal_schedule_accepted' => true,
    'window_id' => 'fixture-window',
    'implementation' => $implementation,
    'scheduler_candidate_key' => 'scheduler-opportunity|20990101OMR01',
];

$checks = [];
$empty = StrictForwardMaterializationDiagnostic::diagnose([], [], $context);
materializationAssert($empty['state'] === 'IN_PROGRESS', 'eligible before expected is in progress');
$checks[] = 'eligible_before_expected';

$partial = StrictForwardMaterializationDiagnostic::diagnose(array_slice($rows, 0, 8), array_slice($rows, 0, 3), $context);
materializationAssert($partial['state'] === 'IN_PROGRESS' && $partial['expected_count'] === 8 && $partial['canonical_count'] === 3, 'valid partial matrix');
$checks[] = 'partial_expected_canonical';

$complete = StrictForwardMaterializationDiagnostic::diagnose($rows, $rows, $context);
materializationAssert($complete['state'] === 'COMPLETE', 'complete matrix');
$checks[] = 'complete_matrix';

$duplicate = StrictForwardMaterializationDiagnostic::diagnose([$rows[0], $rows[0]], [], $context);
materializationAssert($duplicate['state'] === 'INVALID', 'duplicate rejected');
$checks[] = 'duplicate_rejected';

$foreign = [$rows[0]];
$foreign[0]['stable_unique_key'] = 'READINESS_COMPLETION|20990101OMR02|OMR|1|top3|exhibition';
materializationAssert(StrictForwardMaterializationDiagnostic::diagnose($foreign, [], $context)['state'] === 'INVALID', 'foreign key rejected');
$checks[] = 'foreign_key_rejected';

$provenance = [$rows[0]];
$provenance[0]['measurement_implementation_identity'] = 'sha256:' . str_repeat('0', 64);
materializationAssert(StrictForwardMaterializationDiagnostic::diagnose($provenance, [], $context)['state'] === 'INVALID', 'provenance mismatch rejected');
$checks[] = 'provenance_rejected';

$invalid = [$rows[0]];
$invalid[0]['capture_status'] = 'ARTIFACT_ERROR';
$invalid[0]['eligibility_reason'] = 'ARTIFACT_ERROR';
materializationAssert(StrictForwardMaterializationDiagnostic::diagnose([$rows[0]], $invalid, $context)['state'] === 'INVALID', 'invalid decision rejected');
$checks[] = 'invalid_decision_rejected';

$scheduleRejected = $context;
$scheduleRejected['formal_schedule_accepted'] = false;
materializationAssert(StrictForwardMaterializationDiagnostic::diagnose([], [], $scheduleRejected)['state'] === 'INVALID', 'schedule rejection retained');
$checks[] = 'schedule_rejection';

materializationAssert(StrictForwardMaterializationDiagnostic::displayAsInProgress($partial, false, false, true), 'healthy partial displayed in progress');
materializationAssert(!StrictForwardMaterializationDiagnostic::displayAsInProgress($partial, true, false, true), 'explicit failure is not in progress');
materializationAssert(!StrictForwardMaterializationDiagnostic::displayAsInProgress($partial, false, true, true), 'stale partial is not in progress');
materializationAssert(!StrictForwardMaterializationDiagnostic::displayAsInProgress($partial, false, false, false), 'unauthorized partial is not in progress');
$checks[] = 'display_requires_no_failure_no_stale_and_authorization';

echo json_encode(['status' => 'PASS', 'case_count' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
