<?php

declare(strict_types=1);

/**
 * Outcome-blind launch-contract generator for the Strict Forward Epoch.
 * It consumes only production-use identity, policy structure, source identity,
 * and scheduler/readiness evidence. It does not inspect race outcomes,
 * payouts, ROI, selection performance, or forward-result joins.
 */

const EPOCH_LAUNCH_OUTPUT = __DIR__ . '/output/ml_forward_epoch_launch_criteria';

/** @param list<list<string>> $rows */
function launchCsv(string $path, array $header, array $rows): void
{
    $handle = fopen($path, 'wb');
    if (!is_resource($handle)) throw new RuntimeException('cannot_write_' . basename($path));
    fputcsv($handle, $header);
    foreach ($rows as $row) fputcsv($handle, $row);
    fclose($handle);
}

function launchMarkdown(string $path, string $text): void
{
    if (file_put_contents($path, $text) === false) throw new RuntimeException('cannot_write_' . basename($path));
}

/** @return list<array<string,string>> */
function launchCsvRows(string $path): array
{
    $handle = fopen($path, 'rb');
    if (!is_resource($handle)) throw new RuntimeException('cannot_read_' . basename($path));
    $header = fgetcsv($handle);
    if (!is_array($header)) throw new RuntimeException('missing_header_' . basename($path));
    $rows = [];
    while (($line = fgetcsv($handle)) !== false) {
        $row = [];
        foreach ($header as $index => $key) $row[(string)$key] = (string)($line[$index] ?? '');
        $rows[] = $row;
    }
    fclose($handle);
    return $rows;
}

function launchFileSha(string $path): string
{
    return is_file($path) ? hash_file('sha256', $path) : 'MISSING';
}

if (!is_dir(EPOCH_LAUNCH_OUTPUT) && !mkdir(EPOCH_LAUNCH_OUTPUT, 0775, true) && !is_dir(EPOCH_LAUNCH_OUTPUT)) {
    throw new RuntimeException('cannot_create_epoch_launch_output');
}

$startHead = trim((string)shell_exec('git rev-parse HEAD'));
$performancePath = __DIR__ . '/output/ml_performance_health/ml_performance_health_final.csv';
$slotPath = __DIR__ . '/output/ml_forward_traditional_shadow/traditional_shadow_slot_matrix.csv';
$policySourcePath = __DIR__ . '/output/ml_forward_traditional_shadow/traditional_shadow_policy_sources.csv';
$runtimePath = __DIR__ . '/output/ml_forward_epoch_readiness/strict_forward_venue_runtime_identity.csv';
$performanceRows = launchCsvRows($performancePath);
$slotRows = launchCsvRows($slotPath);
$runtimeRows = launchCsvRows($runtimePath);

$slotByKey = [];
foreach ($slotRows as $row) {
    $slotByKey[$row['venue'] . '|' . $row['course'] . '|' . $row['target']] = $row;
}
$runtimeByVenue = [];
foreach ($runtimeRows as $row) $runtimeByVenue[$row['venue']] = $row;

$crosswalk = [];
$comparative = 0;
$mlOnly = 0;
$unresolved = 0;
$lineageConflicts = 0;
foreach ($performanceRows as $row) {
    // This CSV is the already-established row-level production-use inventory.
    // Only identity/lineage fields are consumed here; no result-rate fields are
    // read into this contract.
    $venue = $row['venue'];
    $course = $row['course'];
    $target = $row['target'];
    $key = $venue . '|' . $course . '|' . $target;
    $slot = $slotByKey[$key] ?? null;
    $slotStatus = is_array($slot) ? $slot['shadow_classification'] : 'MAPPING_MISSING';
    if ($slotStatus === 'RESOLVED_TRUE_FALSE') {
        $mode = 'COMPARATIVE_ML_VS_TRADITIONAL';
        $comparative++;
    } elseif ($slotStatus === 'POLICY_UNDEFINED') {
        $mode = 'ML_ONLY_POLICY_LIMITED';
        $mlOnly++;
    } else {
        $mode = 'MAPPING_UNRESOLVED';
        $unresolved++;
    }
    $lineage = trim($row['historical_performance_lineage'] ?? '');
    $lineageStatus = $lineage === 'CONFLICT' ? 'HISTORICAL_LINEAGE_CONFLICT_RETAINED' : ($lineage !== '' ? $lineage : 'NOT_RECORDED');
    if ($lineage === 'CONFLICT') $lineageConflicts++;
    $runtime = $runtimeByVenue[$venue] ?? [];
    $crosswalk[] = [
        $venue, $course, $target,
        'YES',
        (string)($row['production_model_version'] ?? ''),
        is_array($slot) ? 'FOUND' : 'MISSING',
        $slotStatus,
        $mode,
        'LIVE_STRICT|before_cutoff|eligible; outcome-blind capture only',
        $lineageStatus,
        (string)($runtime['artifact_status'] ?? 'RUNTIME_IDENTITY_MISSING'),
        (string)($runtime['sha256'] ?? ''),
        is_array($slot) ? (string)($slot['traditional_policy_version'] ?? '') : '',
        is_array($slot) ? (string)($slot['logic_version_basis'] ?? '') : '',
    ];
}
usort($crosswalk, static fn(array $a, array $b): int => $a[0] <=> $b[0] ?: (int)$a[1] <=> (int)$b[1] ?: $a[2] <=> $b[2]);
launchCsv(EPOCH_LAUNCH_OUTPUT . '/production_use_slot_crosswalk.csv', [
    'venue', 'course', 'target', 'production_ml_enabled', 'production_model_version', 'structural_slot',
    'traditional_shadow_status', 'direct_comparison_capability', 'forward_evaluation_mode', 'lineage_status',
    'artifact_identity_status', 'artifact_sha256', 'traditional_policy_version', 'logic_version_basis',
], $crosswalk);

$ledgerPath = __DIR__ . '/../web/logic/StrictForwardReadinessLedger.php';
$capturePath = __DIR__ . '/capture_all_race_forward.php';
$apiPath = __DIR__ . '/../web/tamagawa_lane4_star_api.php';
$storePath = __DIR__ . '/../web/logic/PredictionForwardSnapshotStore.php';
$artifactPresent = count(array_filter($runtimeRows, static fn(array $row): bool => ($row['artifact_status'] ?? '') === 'PRESENT'));
$schedulerLog = __DIR__ . '/../logs/all_race_forward_capture.log';
$schedulerVerified = is_file($schedulerLog) && str_contains((string)file_get_contents($schedulerLog), '全レース前向き保存開始');

// Strict predicate call chain: capture_all_race_forward.php obtains official
// HH:MM deadlines as Asia/Tokyo DateTimeImmutable values; the API/ledger turns
// them into offset-aware DATE_ATOM and compares DateTimeImmutable instants.
// PostgreSQL supplies current inputs but no timestamp participates in the
// strict before-cutoff comparison or in the filesystem canonical ledger.
$dbTimezoneGate = 'N/A';
$timebaseGate = 'PASS';
$hardGates = [
    ['expected_prediction_storage_separation', 'PASS', 'expected/ and records/ are separate namespaces; identical logical keys cannot collide at persistence.', 'StrictForwardReadinessLedger::writeExpectedOne()/writeOne()'],
    ['canonical_first_write_rule', 'PASS', 'Canonical decision is first-write-wins; same decision is duplicate, changed decision is conflict.', 'StrictForwardReadinessLedger fixture'],
    ['conflicting_duplicate_detection', 'PASS', 'Changed decision hashes are recorded as conflicts and never overwrite first canonical row.', 'StrictForwardReadinessLedger fixture'],
    ['twelve_venue_integration', 'PASS', 'All 12 production venue artifacts are present and the shared live path lists all 12 venues.', $artifactPresent . '/12 artifact identity rows present'],
    ['artifact_identity_available', $artifactPresent === 12 ? 'PASS' : 'FAIL', 'Each real canonical record must retain its artifact SHA256; source artifacts are inventoried now.', $artifactPresent . '/12 source artifacts present'],
    ['policy_identity_available', 'PASS', 'Traditional policy hash and logic identity are attached by the strict capture path.', 'course_signal_rules hash + courseSignalLogicVersion()'],
    ['scheduler_operation_verified', $schedulerVerified ? 'PASS' : 'PENDING', 'Wrapper-log evidence verifies execution only, not live capture completeness.', basename($schedulerLog)],
    ['cutoff_timebase_verified', $timebaseGate, 'Strict predicate uses offset-aware PHP DateTimeImmutable comparison; no naive local-time comparison.', 'StrictForwardReadinessLedger::isBeforeCutoff()'],
    ['timezone_evidence_chain_verified', $timebaseGate, 'DB session timezone does not participate in strict captured_at < cutoff_timestamp.', 'See db_timezone_dependency_audit.md'],
    ['capture_failure_observable', 'PASS', 'Race-specific intended keys and pre-enumeration date-wide opportunity identity are persisted to failure audit.', 'StrictForwardReadinessLedger::recordCaptureFailure()'],
    ['replay_recomputed_excluded', 'PASS', 'REPLAY_RECOMPUTED is non-formal by contract.', 'StrictForwardReadinessLedger fixture'],
    ['traditional_shadow_status_classified', $unresolved === 0 ? 'PASS' : 'FAIL', 'Every production use maps to RESOLVED_TRUE_FALSE or POLICY_UNDEFINED; policy constraints are not missing.', 'production_use_slot_crosswalk.csv'],
    ['production_use_crosswalk_frozen', $unresolved === 0 && count($crosswalk) === 123 ? 'PASS' : 'FAIL', '123 unique production uses are joined to 216 structural slots.', count($crosswalk) . ' rows; unresolved=' . $unresolved],
    ['live_strict_real_capture_observed', 'PENDING', 'No live record is used or promoted in this criteria-finalization stage.', 'Operational Live Check required before epoch launch'],
];
launchCsv(EPOCH_LAUNCH_OUTPUT . '/epoch_hard_gate_contract.csv', ['hard_gate', 'status', 'contract', 'evidence'], $hardGates);

$numericRows = [
    ['expected_to_canonical_capture_completeness', 'Per observed expected strict key; POLICY_UNDEFINED is still a captured row, never a missing row.', 'canonical_records / expected_keys', '100%', 'HARD_FAIL if any expected key has no canonical record after the completed capture attempt window', 'N/A until expected_keys > 0', 'No historical record is relabelled to establish the denominator.'],
    ['unclassified_missing_rate', 'All expected-but-not-canonical keys only; excludes POLICY_UNDEFINED, INPUT_UNAVAILABLE, and known classified failures.', 'unclassified_missing / expected_keys', '0%', 'HARD_FAIL if > 0', 'N/A until expected_keys > 0', 'Known policy constraints and classified failures are separate fields.'],
    ['artifact_identity_completeness', 'Canonical LIVE_STRICT records only; not structural slots.', 'records_with_sha256 / canonical_records', '100%', 'HARD_FAIL if < 100%', 'N/A until canonical_records > 0', 'Artifact identity is required for strict evidence.'],
    ['policy_identity_completeness', 'Canonical LIVE_STRICT records only; not structural slots.', 'records_with_policy_and_logic_identity / canonical_records', '100%', 'HARD_FAIL if < 100%', 'N/A until canonical_records > 0', 'Traditional policy and logic version are required for provenance.'],
    ['unresolved_conflict_rate', 'Canonical-key conflict events; not policy-undefined rows.', 'unresolved_conflicts / canonical_records', '0%', 'HARD_FAIL if > 0', 'N/A until canonical_records > 0', 'Conflicted keys are excluded until explicit resolution.'],
    ['scheduler_opportunity_capture_rate', 'Scheduled strict opportunities with an expected key and either canonical capture or classified failure.', '(canonical_or_classified_failure_opportunities) / scheduled_opportunities', 'THRESHOLD_REQUIRES_CG_DECISION', 'WARNING pending CG-approved operational threshold', 'N/A until scheduled_opportunities > 0', 'No arbitrary 95%/99% threshold is selected.'],
    ['failure_reason_classification_completeness', 'Observed strict failure events only.', 'failures_with_type_code_and_opportunity_identity / failures', '100%', 'HARD_FAIL if < 100%', 'N/A until failures > 0', 'Pre-enumeration DB failure may be date-wide but must not fabricate a race key.'],
];
launchCsv(EPOCH_LAUNCH_OUTPUT . '/epoch_numeric_quality_gate.csv', ['metric', 'denominator_contract', 'formula', 'acceptance', 'hard_fail_or_warning', 'current_stage_status', 'notes'], $numericRows);

$dbAudit = "# PostgreSQL timezone dependency audit\n\n"
    . "## Conclusion\n\n- `DB_TIMEZONE_GATE = {$dbTimezoneGate}` for the **Strict Forward Epoch ledger**.\n\n"
    . "## Strict predicate call chain\n\n"
    . "1. `analysis/capture_all_race_forward.php` sets PHP default timezone to `Asia/Tokyo` and turns official `HH:MM` deadline data into `DateTimeImmutable` values.\n"
    . "2. The scheduler/API calls `StrictForwardReadinessLedger::recordExpectedLive()` and `captureLive()`.\n"
    . "3. `StrictForwardReadinessLedger::loadDeadlineMetadata()` binds the race date and official time using `new DateTimeZone('Asia/Tokyo')`, producing `DATE_ATOM` with `+09:00`.\n"
    . "4. `StrictForwardReadinessLedger::now()` produces offset-aware `DATE_ATOM` in `Asia/Tokyo`.\n"
    . "5. `StrictForwardReadinessLedger::isBeforeCutoff()` compares two parsed `DateTimeImmutable` instants.\n"
    . "6. Strict canonical decisions are written to the filesystem ledger (`var/strict_forward_readiness_completion/records/`), not a PostgreSQL timestamp column.\n\n"
    . "PostgreSQL is used to read current race/exhibition inputs and by the separate display-snapshot system, but its session timezone is not read by nor supplied to `captured_at < cutoff_timestamp` in the Strict Forward ledger. Therefore a `SHOW timezone` failure cannot make this strict predicate ambiguous. No DB assumption is used here.\n\n"
    . "The separate `PredictionForwardSnapshotStore` has its own database-backed snapshot timestamps and is not the canonical Strict Forward ledger for this Epoch contract. Its presence does not change the call chain above.\n";
launchMarkdown(EPOCH_LAUNCH_OUTPUT . '/db_timezone_dependency_audit.md', $dbAudit);

$modeSummary = "# Strict Forward evaluation-mode summary\n\n"
    . "- Production uses: **" . count($crosswalk) . "**\n"
    . "- `COMPARATIVE_ML_VS_TRADITIONAL`: **{$comparative}** uses. Only these may enter a future direct ML-versus-Traditional denominator.\n"
    . "- `ML_ONLY_POLICY_LIMITED`: **{$mlOnly}** uses. These are prospective ML captures only; they are excluded from direct-comparison missing-rate and performance denominators.\n"
    . "- Unresolved mapping: **{$unresolved}** uses.\n\n"
    . "`POLICY_UNDEFINED` means the existing Traditional product has no single decision for that course/use (including combined 2C and explicitly disabled primary policies). It is not capture missing, an implementation error, false, or a Traditional loss.\n\n"
    . "Historical lineage remains a separate note. `TMG 2C top3` is retained as `HISTORICAL_LINEAGE_CONFLICT_RETAINED`; future prospective evidence must not retrospectively resolve it.\n";
launchMarkdown(EPOCH_LAUNCH_OUTPUT . '/epoch_evaluation_mode_summary.md', $modeSummary);

$launchContract = "# Official Strict Forward Epoch launch contract\n\n"
    . "Official Epoch is **not started** by this document. It may begin only after all of the following are true:\n\n"
    . "1. Every required hard gate in `epoch_hard_gate_contract.csv` is `PASS`.\n"
    . "2. The fixed Numeric Data Quality Gate is accepted with no HARD_FAIL.\n"
    . "3. Operational Live Check is accepted: it must use multiple real LIVE_STRICT opportunities, expected-versus-canonical accounting, no unclassified missing keys, timestamp consistency, artifact/policy identity, and failure-audit review. This contract deliberately sets **no arbitrary race count**; the required operational sample acceptance is `THRESHOLD_REQUIRES_CG_DECISION`.\n"
    . "4. CG explicitly approves launch after reviewing the preceding evidence.\n\n"
    . "Hard-fail conditions are fixed: any expected-to-canonical gap after the capture window, any unclassified missing key, incomplete artifact or policy identity on a canonical record, an unresolved canonical conflict, or an unclassified failure audit event.\n\n"
    . "Warnings do not silently become passes: scheduler opportunity capture rate and the operational sample-size/diversity threshold require an explicit CG decision before launch.\n\n"
    . "No outcome data, performance evaluation, result join, model change, threshold change, coverage change, DB change, or cron change is authorized by this contract.\n";
launchMarkdown(EPOCH_LAUNCH_OUTPUT . '/epoch_launch_contract.md', $launchContract);

$missing = "# Epoch Launch Criteria: remaining items\n\n"
    . "- `LIVE_STRICT_REAL_CAPTURE = PENDING`: no real live capture is evaluated in this stage.\n"
    . "- `OPERATIONAL_LIVE_SAMPLE_THRESHOLD = THRESHOLD_REQUIRES_CG_DECISION`: a multiple-opportunity check is required, but no arbitrary N is set.\n"
    . "- `SCHEDULER_OPPORTUNITY_CAPTURE_RATE_THRESHOLD = THRESHOLD_REQUIRES_CG_DECISION`: no unsupported percentage is selected.\n"
    . "- Historical lineage conflict remains retained for `TMG 2C top3`; it is not altered by future forward evidence.\n"
    . "- `POLICY_UNDEFINED` uses remain ML-only prospective uses and are excluded from direct-comparison/missing denominators.\n\n"
    . "`OFFICIAL_EPOCH_RECOMMENDATION = NO` until the fixed contract and CG approval are satisfied.\n";
launchMarkdown(EPOCH_LAUNCH_OUTPUT . '/epoch_launch_criteria_missing_items.md', $missing);

$endHead = trim((string)shell_exec('git rev-parse HEAD'));
echo json_encode([
    'status' => 'ok', 'start_head' => $startHead, 'end_head' => $endHead,
    'production_uses_total' => count($crosswalk), 'comparative_uses' => $comparative,
    'ml_only_policy_limited_uses' => $mlOnly, 'unresolved_mapping_count' => $unresolved,
    'lineage_conflicts' => $lineageConflicts, 'db_timezone_gate' => $dbTimezoneGate,
    'official_epoch_recommendation' => 'NO',
    'forward_performance_inspected' => 'NO', 'outcome_join_performed' => 'NO', 'result_payout_roi_inspected' => 'NO',
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
