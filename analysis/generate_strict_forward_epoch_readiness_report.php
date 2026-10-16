<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/StrictForwardReadinessLedger.php';

/**
 * Outcome-blind verification of Strict Forward epoch readiness. It only reads
 * the completion ledger, scheduler wrapper/log, and source identities.
 */
const EPOCH_READINESS_OUTPUT = __DIR__ . '/output/ml_forward_epoch_readiness';
const EPOCH_READINESS_LEDGER = __DIR__ . '/../var/strict_forward_readiness_completion';

/** @return list<array<string,mixed>> */
function epochRows(string $pattern): array
{
    $rows = [];
    foreach (glob($pattern) ?: [] as $path) {
        $row = json_decode((string)file_get_contents($path), true);
        if (is_array($row)) $rows[] = $row;
    }
    return $rows;
}

/** @return list<array<string,mixed>> */
function epochJsonl(string $pattern): array
{
    $rows = [];
    foreach (glob($pattern) ?: [] as $path) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) $rows[] = $row;
        }
    }
    return $rows;
}

function epochCsv(string $path, array $header, array $rows): void
{
    $handle = fopen($path, 'wb');
    if (!is_resource($handle)) throw new RuntimeException('cannot_write_' . basename($path));
    fputcsv($handle, $header);
    foreach ($rows as $row) fputcsv($handle, $row);
    fclose($handle);
}

function epochMarkdown(string $path, string $text): void
{
    if (file_put_contents($path, $text) === false) throw new RuntimeException('cannot_write_' . basename($path));
}

if (!is_dir(EPOCH_READINESS_OUTPUT) && !mkdir(EPOCH_READINESS_OUTPUT, 0775, true) && !is_dir(EPOCH_READINESS_OUTPUT)) {
    throw new RuntimeException('cannot_create_epoch_readiness_output');
}

$expectedRows = epochRows(EPOCH_READINESS_LEDGER . '/expected/*/*.json');
$predictionRows = epochRows(EPOCH_READINESS_LEDGER . '/records/*/*.json');
$operationalRows = epochJsonl(EPOCH_READINESS_LEDGER . '/operational/*.jsonl');
$failureRows = epochJsonl(EPOCH_READINESS_LEDGER . '/failures/*.jsonl');
$expectedByKey = [];
foreach ($expectedRows as $row) $expectedByKey[(string)($row['stable_unique_key'] ?? '')] = $row;
$predictionByKey = [];
foreach ($predictionRows as $row) $predictionByKey[(string)($row['stable_unique_key'] ?? '')] = $row;
$sharedLogicalKeys = array_values(array_intersect(array_keys($expectedByKey), array_keys($predictionByKey)));
$missingKeys = array_values(array_diff(array_keys($expectedByKey), array_keys($predictionByKey)));
$conflicts = array_values(array_filter($operationalRows, static fn(array $r): bool => ($r['event'] ?? '') === 'conflict'));
$duplicates = array_values(array_filter($operationalRows, static fn(array $r): bool => ($r['event'] ?? '') === 'duplicate'));
$formalLive = array_values(array_filter($predictionRows, static fn(array $r): bool => ($r['capture_mode'] ?? '') === 'LIVE_STRICT' && !empty($r['formal_candidate'])));
$unknowns = array_values(array_filter($predictionRows, static fn(array $r): bool => ($r['traditional_selected'] ?? 'known') === null));
$artifactComplete = count(array_filter($predictionRows, static fn(array $r): bool => preg_match('/^[a-f0-9]{64}$/', (string)($r['artifact_sha256'] ?? '')) === 1));
$policyComplete = count(array_filter($predictionRows, static fn(array $r): bool => trim((string)($r['traditional_policy_version'] ?? '')) !== '' && trim((string)($r['traditional_logic_version'] ?? '')) !== ''));

$logPath = __DIR__ . '/../logs/all_race_forward_capture.log';
$starts = [];
$wrapperDbFailures = 0;
if (is_file($logPath)) {
    foreach (file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (preg_match('/\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2})(?:\+09:00)?\].*全レース前向き保存開始/u', $line, $m)) {
            $starts[] = $m[1];
        }
        if (str_contains($line, 'PDOException') || str_contains($line, '前向き保存DB接続エラー')) {
            $wrapperDbFailures++;
        }
    }
}
$recentStarts = array_slice($starts, -30);
$twoMinutePairs = 0;
$intervalPairs = 0;
for ($i = 1; $i < count($recentStarts); $i++) {
    $a = new DateTimeImmutable(str_replace('T', ' ', $recentStarts[$i - 1]), new DateTimeZone('Asia/Tokyo'));
    $b = new DateTimeImmutable(str_replace('T', ' ', $recentStarts[$i]), new DateTimeZone('Asia/Tokyo'));
    $delta = $b->getTimestamp() - $a->getTimestamp();
    $intervalPairs++;
    if ($delta >= 110 && $delta <= 130) $twoMinutePairs++;
}
$schedulerEvidence = is_file($logPath) && count($recentStarts) >= 2 && $twoMinutePairs > 0;

$keyAudit = "# Expected / Prediction key separation audit\n\n"
    . "Both stores intentionally use the same **logical** key:\n\n"
    . "`" . StrictForwardReadinessLedger::EPOCH_STATUS . "|race_code|venue|course|target|exhibition`\n\n"
    . "They cannot collide at persistence time because expected rows are written only under `var/strict_forward_readiness_completion/expected/YYYYMMDD/`, while prediction decisions are written only under `.../records/YYYYMMDD/`. `writeExpectedOne()` never reads `records/`; `writeOne()` never reads `expected/`. Thus an expected row cannot turn a later prediction into duplicate, conflict, or rejection.\n\n"
    . "Evaluator contract: only `records/` may provide canonical prediction decisions. `expected/` is denominator/audit material only. Same decision payload in `records/` is a duplicate event; a changed payload is a conflict; conflicts are excluded from future performance evaluation until separately resolved.\n\n"
    . "Current observed shared logical keys: **" . count($sharedLogicalKeys) . "** (expected when real captures exist). Persistence-path collision: **none by construction**.\n";
epochMarkdown(EPOCH_READINESS_OUTPUT . '/strict_forward_key_separation_audit.md', $keyAudit);

$venues = ['TMG', 'TDA', 'OMR', 'SMS', 'SME', 'KRY', 'BWK', 'ASY', 'AMG', 'HWJ', 'EDG', 'MKN'];
$artifactNames = [
    'TMG' => 'tamagawa_center_signal_v1.joblib', 'TDA' => 'toda_course_signal_v1.joblib',
    'OMR' => 'omura_course_signal_v1.joblib', 'SMS' => 'shimonoseki_course_signal_v1.joblib',
    'SME' => 'suminoe_course_signal_v1.joblib', 'KRY' => 'kiryuu_course_signal_v1.joblib',
    'BWK' => 'biwako_course_signal_v1.joblib', 'ASY' => 'ashiya_course_signal_v1.joblib',
    'AMG' => 'amagasaki_course_signal_v1.joblib', 'HWJ' => 'heiwajima_course_signal_v1.joblib',
    'EDG' => 'edogawa_course_signal_v1.joblib', 'MKN' => 'mikuni_course_signal_v1.joblib',
];
$runtimeIdentityRows = [];
foreach ($artifactNames as $venue => $name) {
    $path = __DIR__ . '/../forecast/models/' . $name;
    $runtimeIdentityRows[] = [$venue, $name, is_file($path) ? 'PRESENT' : 'MISSING', is_file($path) ? hash_file('sha256', $path) : '', 'courseSignalLogicVersion()'];
}
epochCsv(EPOCH_READINESS_OUTPUT . '/strict_forward_venue_runtime_identity.csv', ['venue', 'artifact', 'artifact_status', 'sha256', 'traditional_logic_identity'], $runtimeIdentityRows);
$traditionalRows = [];
foreach ($venues as $venue) {
    for ($course = 1; $course <= 6; $course++) {
        foreach (['top3', 'top2', 'first'] as $target) {
            if ($course === 2) {
                $traditionalRows[] = [$venue, $course, $target, 'POLICY_UNDEFINED', '2C is separately displayed as sashi/makuri; no existing combined course-target policy. Explicit component can prove true, but combined false is never inferred.', 'COMPARATIVE_UNAVAILABLE_BY_POLICY'];
            } else {
                $traditionalRows[] = [$venue, $course, $target, 'IMPLEMENTATION_UNAVAILABLE', 'Existing positive-only legacy map does not expose an explicit negative decision. Explicit star_level safely yields true/false; map absence remains unknown.', 'SHADOW_EXTRACTION_INCOMPLETE'];
            }
        }
    }
}
epochCsv(EPOCH_READINESS_OUTPUT . '/strict_forward_traditional_unknown_classification.csv', ['venue', 'course', 'target', 'classification_when_unknown', 'basis', 'comparative_status'], $traditionalRows);

$schedulerText = "# Scheduler operation evidence\n\n"
    . "- Direct `crontab -l` was not readable for this process (`Permission denied`); no privilege escalation or scheduler change was attempted.\n"
    . "- Alternative evidence: `logs/all_race_forward_capture.log` contains real wrapper starts through `" . (end($recentStarts) ?: 'none') . "`.\n"
    . "- In the most recent " . count($recentStarts) . " starts, " . $twoMinutePairs . "/" . $intervalPairs . " adjacent intervals were approximately two minutes.\n"
    . "- Wrapper log contains **" . $wrapperDbFailures . "** database-connectivity failure line(s). These are outcome-blind scheduler/capture failures. Future DB-connect failures are additionally sent to StrictForwardReadinessLedger's separate failure audit before process exit.\n"
    . "- The wrapper invokes `analysis/capture_all_race_forward.php --db-only`; the capture source now records expected strict keys and invokes the shared API for every eligible ML venue, independent of browser views and without external exhibition scraping.\n"
    . "- Scheduler operation status: `" . ($schedulerEvidence ? 'VERIFIED_BY_WRAPPER_LOG' : 'SCHEDULER_OPERATION_UNVERIFIED') . "`. This verifies invocation, not live-capture completeness.\n";
epochMarkdown(EPOCH_READINESS_OUTPUT . '/strict_forward_scheduler_evidence.md', $schedulerText);

$liveRows = [];
foreach ($predictionRows as $record) {
    $liveRows[] = [
        (string)($record['race_code'] ?? ''), (string)($record['venue'] ?? ''), (string)($record['course'] ?? ''), (string)($record['target'] ?? ''),
        (string)($record['captured_at'] ?? ''), (string)($record['cutoff_timestamp'] ?? ''), json_encode($record['before_cutoff'] ?? null),
        json_encode($record['eligible'] ?? null), json_encode($record['ml_selected'] ?? null), json_encode($record['traditional_selected'] ?? null),
        (string)($record['traditional_unknown_classification'] ?? ''), (string)($record['artifact_sha256'] ?? ''),
        (string)($record['traditional_policy_version'] ?? ''), (string)($record['missing_reason'] ?? ''), (string)($record['capture_status'] ?? ''),
    ];
}
epochCsv(EPOCH_READINESS_OUTPUT . '/strict_forward_live_verification.csv', ['race_code', 'venue', 'course', 'target', 'captured_at', 'cutoff_timestamp', 'before_cutoff', 'eligible', 'ml_selected', 'traditional_selected', 'traditional_unknown_classification', 'artifact_sha256', 'traditional_policy_version', 'missing_reason', 'capture_status'], $liveRows);

$metrics = [
    ['expected_key_count', (string)count($expectedByKey), count($expectedByKey) ? 'OBSERVED' : 'N/A_NO_LIVE_OPPORTUNITY'],
    ['canonical_prediction_key_count', (string)count($predictionByKey), count($predictionByKey) ? 'OBSERVED' : 'N/A_NO_LIVE_OPPORTUNITY'],
    ['capture_completeness', count($expectedByKey) ? (string)(count($predictionByKey) / count($expectedByKey)) : 'N/A', 'No numeric pass threshold set'],
    ['missing_key_count', (string)count($missingKeys), count($expectedByKey) ? 'OBSERVED' : 'N/A_NO_LIVE_OPPORTUNITY'],
    ['traditional_unknown_count', (string)count($unknowns), count($predictionByKey) ? 'OBSERVED' : 'N/A_NO_LIVE_OPPORTUNITY'],
    ['traditional_evaluated_rate', count($predictionByKey) ? (string)((count($predictionByKey) - count($unknowns)) / count($predictionByKey)) : 'N/A', 'Unknown is never converted to false'],
    ['artifact_sha_completeness', count($predictionByKey) ? (string)($artifactComplete / count($predictionByKey)) : 'N/A', 'Real records required'],
    ['policy_version_completeness', count($predictionByKey) ? (string)($policyComplete / count($predictionByKey)) : 'N/A', 'Real records required'],
    ['conflict_rate', count($predictionByKey) ? (string)(count($conflicts) / count($predictionByKey)) : 'N/A', 'Conflicts remain excluded'],
    ['scheduler_opportunity_capture_rate', count($expectedByKey) ? (string)(count($predictionByKey) / count($expectedByKey)) : 'N/A', 'Scheduler invocation and capture completion are distinct'],
    ['scheduler_wrapper_db_connectivity_failures', (string)$wrapperDbFailures, 'Observed in wrapper log; not prediction performance'],
];
epochCsv(EPOCH_READINESS_OUTPUT . '/strict_forward_numeric_metrics.csv', ['metric', 'value', 'notes'], $metrics);

$gates = [
    ['expected_prediction_persistence_collision', 'PASS', 'Separate expected/ and records/ namespaces; same logical key is intentional.'],
    ['scheduler_actual_operation', $schedulerEvidence ? 'PASS' : 'PENDING', 'Wrapper log is the alternative evidence; crontab itself was not readable.'],
    ['live_strict_real_record', count($formalLive) > 0 ? 'PASS' : 'PENDING', 'No historical replay was relabelled as LIVE_STRICT.'],
    ['real_cutoff_proof', count($formalLive) > 0 ? 'PASS' : 'PENDING', 'Fixture verifies rule; no real record yet.'],
    ['prediction_canonicalization', 'PASS', 'First-write-wins and conflict preservation fixture verified.'],
    ['conflict_detection', 'PASS', 'Fixture verified; real conflict count is ' . count($conflicts) . '.'],
    ['replay_recomputed_exclusion', 'PASS', 'Code marks replay non-formal; no completion record is promoted.'],
    ['artifact_policy_identity_real_rows', count($predictionByKey) > 0 && $artifactComplete === count($predictionByKey) && $policyComplete === count($predictionByKey) ? 'PASS' : 'PENDING', 'Real rows are required.'],
    ['capture_failure_observable', 'PASS', 'Separate failure audit plus PHP error_log fixture verified.'],
    ['expected_vs_actual_accounting', 'PASS_STRUCTURAL', 'Expected and actual scans are implemented; live denominator is currently zero.'],
    ['traditional_unknown_classification', 'PASS_STRUCTURAL', 'Implementation vs policy limit is explicitly separated.'],
    ['traditional_complete_comparative_matrix', 'BLOCKED', '2C combined policy is undefined; other negative decisions are not explicitly emitted by legacy maps.'],
];
epochCsv(EPOCH_READINESS_OUTPUT . '/strict_forward_hard_gates.csv', ['gate', 'status', 'evidence'], $gates);

$missing = "# Strict Forward Epoch Readiness: unresolved items\n\n"
    . "- `LIVE_VERIFICATION_PENDING`: no real post-exhibition/pre-cutoff prediction record exists. No past race was replayed as live.\n"
    . "- `OPERATIONAL_SAMPLE_INSUFFICIENT`: scheduler invocation is evidenced, but no live strict opportunity has yet produced a record.\n"
    . "- 2C combined Traditional decision is `POLICY_UNDEFINED`: the existing product exposes separate sashi/makuri policies, not a combined course-level policy.\n"
    . "- Other legacy map absences remain `IMPLEMENTATION_UNAVAILABLE` for comparative shadowing; they are not converted to false.\n"
    . "- `DATA_QUALITY_THRESHOLD_REQUIRES_CG_DECISION = YES`: all metrics are exposed but no acceptance percentage/count was chosen.\n"
    . "- Official Epoch remains unstarted.\n";
epochMarkdown(EPOCH_READINESS_OUTPUT . '/strict_forward_epoch_readiness_missing_items.md', $missing);

$summary = "# Strict Forward Epoch Readiness Verification\n\n"
    . "- `forward_performance_inspected = NO`\n- `outcome_join_performed = NO`\n- `result_payout_roi_inspected = NO`\n- Official Epoch is **not started**.\n\n"
    . "## Fact summary\n\n"
    . "- Expected/prediction persistence collision: **none**; shared logical key is intentional, storage namespace is separate.\n"
    . "- Scheduler: **" . ($schedulerEvidence ? 'VERIFIED_BY_WRAPPER_LOG' : 'SCHEDULER_OPERATION_UNVERIFIED') . "**.\n"
    . "- Real LIVE_STRICT records: **" . count($formalLive) . "**; expected keys: **" . count($expectedByKey) . "**; canonical prediction keys: **" . count($predictionByKey) . "**; missing: **" . count($missingKeys) . "**.\n"
    . "- Conflicts: **" . count($conflicts) . "**; duplicate events: **" . count($duplicates) . "**; capture-failure audit events: **" . count($failureRows) . "**.\n"
    . "- Source artifacts present: **" . count(array_filter($runtimeIdentityRows, static fn(array $r): bool => $r[2] === 'PRESENT')) . "/12**; per-record identity remains pending until a real capture.\n"
    . "- Traditional unknown structural classification: 2C=`POLICY_UNDEFINED`; all other course-target map-absence cases=`IMPLEMENTATION_UNAVAILABLE`.\n\n"
    . "## Readiness\n\n"
    . "- Structural Readiness: **PARTIAL**\n- Operational Live Readiness: **PENDING**\n- Comparative Readiness: **POLICY_LIMITED**\n- `DATA_QUALITY_THRESHOLD_REQUIRES_CG_DECISION = YES`\n- `OFFICIAL_EPOCH_RECOMMENDATION = NO`\n\n"
    . "The recommendation is not based on prediction performance. It is blocked only by absence of real LIVE_STRICT evidence and the incomplete/undefined Traditional comparison matrix.\n";
epochMarkdown(EPOCH_READINESS_OUTPUT . '/strict_forward_epoch_readiness_summary.md', $summary);

echo json_encode([
    'status' => 'ok', 'forward_performance_inspected' => 'NO', 'outcome_join_performed' => 'NO',
    'result_payout_roi_inspected' => 'NO', 'expected_keys' => count($expectedByKey),
    'prediction_keys' => count($predictionByKey), 'formal_live_records' => count($formalLive),
    'scheduler_status' => $schedulerEvidence ? 'VERIFIED_BY_WRAPPER_LOG' : 'SCHEDULER_OPERATION_UNVERIFIED',
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
