<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/StrictForwardReadinessLedger.php';

/**
 * Outcome-blind completion reporting for Strict Forward capture readiness.
 * It reads only the completion ledger, expected-universe files, and capture
 * failure audit. It never opens results, payouts, or performance artifacts.
 */
const COMPLETION_OUTPUT = __DIR__ . '/output/ml_forward_readiness_completion';
const COMPLETION_LEDGER = __DIR__ . '/../var/strict_forward_readiness_completion';

/** @return list<array<string,mixed>> */
function completionJsonRows(string $pattern): array
{
    $rows = [];
    foreach (glob($pattern) ?: [] as $path) {
        $row = json_decode((string)file_get_contents($path), true);
        if (is_array($row)) $rows[] = $row;
    }
    return $rows;
}

/** @return list<array<string,mixed>> */
function completionJsonlRows(string $pattern): array
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

function completionCsv(string $path, array $header, array $rows): void
{
    $handle = fopen($path, 'wb');
    if (!is_resource($handle)) throw new RuntimeException('cannot_write_' . basename($path));
    fputcsv($handle, $header);
    foreach ($rows as $row) fputcsv($handle, $row);
    fclose($handle);
}

function completionMarkdown(string $path, string $contents): void
{
    if (file_put_contents($path, $contents) === false) throw new RuntimeException('cannot_write_' . basename($path));
}

if (!is_dir(COMPLETION_OUTPUT) && !mkdir(COMPLETION_OUTPUT, 0775, true) && !is_dir(COMPLETION_OUTPUT)) {
    throw new RuntimeException('cannot_create_completion_output');
}

$records = completionJsonRows(COMPLETION_LEDGER . '/records/*/*.json');
$expected = completionJsonRows(COMPLETION_LEDGER . '/expected/*/*.json');
$operational = completionJsonlRows(COMPLETION_LEDGER . '/operational/*.jsonl');
$failures = completionJsonlRows(COMPLETION_LEDGER . '/failures/*.jsonl');
$actualByKey = [];
foreach ($records as $record) $actualByKey[(string)($record['stable_unique_key'] ?? '')] = $record;
$expectedByKey = [];
foreach ($expected as $row) $expectedByKey[(string)($row['stable_unique_key'] ?? '')] = $row;
$missed = array_values(array_diff(array_keys($expectedByKey), array_keys($actualByKey)));
$liveRecords = array_values(array_filter($records, static fn(array $r): bool => ($r['capture_mode'] ?? '') === 'LIVE_STRICT' && !empty($r['formal_candidate'])));
$traditionalUnknown = count(array_filter($records, static fn(array $r): bool => ($r['traditional_selected'] ?? 'sentinel') === null));
$artifactComplete = count(array_filter($records, static fn(array $r): bool => trim((string)($r['artifact_sha256'] ?? '')) !== ''));
$policyComplete = count(array_filter($records, static fn(array $r): bool => trim((string)($r['traditional_policy_version'] ?? '')) !== '' && trim((string)($r['traditional_logic_version'] ?? '')) !== ''));
$duplicates = count(array_filter($operational, static fn(array $r): bool => ($r['event'] ?? '') === 'duplicate'));
$conflicts = count(array_filter($operational, static fn(array $r): bool => ($r['event'] ?? '') === 'conflict'));
$venues = [
    'TMG' => ['tamagawa_center_signal_v1.joblib', '.venv-models/bin/python'],
    'TDA' => ['toda_course_signal_v1.joblib', '.venv-models/bin/python'],
    'OMR' => ['omura_course_signal_v1.joblib', '.venv-models/bin/python'],
    'SMS' => ['shimonoseki_course_signal_v1.joblib', '.venv-models/bin/python'],
    'SME' => ['suminoe_course_signal_v1.joblib', '.venv-models/bin/python'],
    'KRY' => ['kiryuu_course_signal_v1.joblib', '.venv-models/bin/python'],
    'BWK' => ['biwako_course_signal_v1.joblib', '/usr/bin/python3'],
    'ASY' => ['ashiya_course_signal_v1.joblib', '/usr/bin/python3'],
    'AMG' => ['amagasaki_course_signal_v1.joblib', '/usr/bin/python3'],
    'HWJ' => ['heiwajima_course_signal_v1.joblib', '/usr/bin/python3'],
    'EDG' => ['edogawa_course_signal_v1.joblib', '/usr/bin/python3'],
    'MKN' => ['mikuni_course_signal_v1.joblib', '/usr/bin/python3'],
];
$integrationRows = [];
$matrixRows = [];
foreach ($venues as $venue => [$artifact, $python]) {
    $integrationRows[] = [
        $venue,
        'web/tamagawa_lane4_star_api.php -> TamagawaCenterSignalLogic -> forecast/tamagawa_center_signal_live_v1.py',
        'forecast/models/' . $artifact . ' via ' . $python,
        'Pre-ML legacy lane maps in tamagawa_lane4_star_api.php (2C uses maximum observed star across existing sashi/makuri maps)',
        'YES: additive StrictForwardReadinessLedger captureLiveFromContext()',
        'analysis/run_all_race_forward_capture_cron.sh -> capture_all_race_forward.php --db-only (2-minute existing cron)',
        'CONNECTED_BY_CODE / LIVE_OBSERVATION_PENDING',
        'Expected universe records 6C x 3 targets before API subprocess; scheduler does not require browser access.',
    ];
    $matrixRows[] = [
        $venue,
        'true/false only if the existing pre-ML legacy map has an explicit star_level for the race/course',
        'target level <= observed star_level => true; target level > observed star_level => false',
        'map absence => NULL, TRADITIONAL_DECISION_UNAVAILABLE (never false)',
        'course_signal_rules.json SHA256 plus courseSignalLogicVersion()',
        'PARTIAL / OFFICIAL_COMPARATIVE_BLOCKER',
    ];
}
completionCsv(COMPLETION_OUTPUT . '/strict_forward_venue_integration.csv', ['venue', 'runtime_path', 'ml_source', 'traditional_source', 'ledger_hook', 'scheduler_path', 'status', 'notes'], $integrationRows);
completionCsv(COMPLETION_OUTPUT . '/strict_forward_traditional_matrix_status.csv', ['venue', 'traditional_true_false_basis', 'false_safety_basis', 'unknown_handling', 'version_identity', 'status'], $matrixRows);

$qualityRows = [
    ['epoch_status', StrictForwardReadinessLedger::EPOCH_STATUS, 'PASS', 'Pre-official completion status only.'],
    ['forward_performance_inspected', 'NO', 'PASS', 'No result, payout, ROI, hit rate, lift, or performance source was read.'],
    ['outcome_join_performed', 'NO', 'PASS', 'No outcome ledger join was performed.'],
    ['live_opportunity_count', (string)count(array_unique(array_map(static fn(array $r): string => (string)($r['race_code'] ?? ''), $expected))), count($expected) > 0 ? 'OBSERVED' : 'LIVE_READINESS_CAPTURE_PENDING', 'Only expected pre-cutoff live opportunities are counted.'],
    ['expected_keys', (string)count($expectedByKey), count($expectedByKey) > 0 ? 'OBSERVED' : 'OPERATIONAL_SAMPLE_INSUFFICIENT', '18 expected keys per captured live race.'],
    ['actual_captured_keys', (string)count($actualByKey), count($actualByKey) > 0 ? 'OBSERVED' : 'LIVE_READINESS_CAPTURE_PENDING', 'Decision records only; no outcomes.'],
    ['missed_capture_keys', (string)count($missed), count($expectedByKey) > 0 ? 'OBSERVED' : 'PENDING', 'Expected key absent from canonical first-write record.'],
    ['formal_live_records', (string)count($liveRecords), count($liveRecords) > 0 ? 'OBSERVED' : 'LIVE_READINESS_CAPTURE_PENDING', 'LIVE_STRICT + before_cutoff + eligible only.'],
    ['traditional_unknown_rows', (string)$traditionalUnknown, $traditionalUnknown === 0 && count($records) > 0 ? 'OBSERVED' : 'COMPARATIVE_BLOCKER', 'Absence of legacy positive map is not false.'],
    ['artifact_sha_complete_rows', (string)$artifactComplete, count($records) > 0 ? 'OBSERVED' : 'PENDING', 'Per-record model artifact identity.'],
    ['policy_version_complete_rows', (string)$policyComplete, count($records) > 0 ? 'OBSERVED' : 'PENDING', 'Traditional policy and logic identities.'],
    ['duplicate_events', (string)$duplicates, 'OBSERVATIONAL', 'Same key + same decision payload; evaluator uses canonical first record only.'],
    ['conflict_events', (string)$conflicts, $conflicts === 0 ? 'OBSERVATIONAL' : 'COMPARATIVE_BLOCKER', 'Same key + different payload; never auto-switch or evaluate.'],
    ['failure_audit_events', (string)count($failures), 'OBSERVATIONAL', 'Filesystem audit plus PHP error_log fail-loud path.'],
    ['data_quality_threshold', 'NOT_SET', 'DATA_QUALITY_THRESHOLD_REQUIRES_CG_DECISION', 'No completion percentage threshold was invented.'],
];
completionCsv(COMPLETION_OUTPUT . '/strict_forward_live_capture_quality.csv', ['check', 'value', 'status', 'notes'], $qualityRows);

$failureRows = [];
foreach ($failures as $row) {
    $failureRows[] = [
        (string)($row['timestamp'] ?? ''), (string)($row['venue'] ?? ''), (string)($row['event'] ?? ''),
        (string)($row['error_type'] ?? ''), (string)($row['error_code'] ?? ''),
        (string)($row['capture_stage'] ?? ''), json_encode($row['race_codes'] ?? $row['intended_keys'] ?? $row['intended_key'] ?? null, JSON_UNESCAPED_SLASHES),
    ];
}
completionCsv(COMPLETION_OUTPUT . '/strict_forward_capture_failures.csv', ['timestamp', 'venue', 'event', 'error_type', 'error_code', 'capture_stage', 'intended_keys_or_races'], $failureRows);

$missing = "# Strict Forward Capture Completion: unresolved items\n\n"
    . "- `LIVE_READINESS_CAPTURE_PENDING`: no real post-exhibition, pre-cutoff live record was created during this completion run. No historical race was replayed or relabelled as live.\n"
    . "- `OPERATIONAL_SAMPLE_INSUFFICIENT`: no numeric sample size was invented; continuity needs multiple future live opportunities, outcome-blind.\n"
    . "- `TRADITIONAL_DECISION_UNAVAILABLE`: existing legacy maps safely yield false only when an explicit star_level exists. Map absence remains NULL; therefore the 12-venue Traditional Shadow Decision Matrix is structurally connected but not complete enough for an official comparative Epoch.\n"
    . "- `DATA_QUALITY_THRESHOLD_REQUIRES_CG_DECISION`: expected/actual and missingness are now measurable, but acceptance percentages must be fixed before an Official Epoch and independently of results.\n"
    . "- Scheduler registration itself was not changed or reinstalled. The existing 2-minute capture wrapper now invokes the strict capture path for every eligible ML venue without browser/API-view dependence. Confirm actual crontab ownership/registration separately before declaring operational continuity.\n"
    . "- Official Strict Forward Epoch remains unstarted. Existing 2026-09-28 through 2026-10-03 records are not promoted.\n";
completionMarkdown(COMPLETION_OUTPUT . '/strict_forward_completion_missing_items.md', $missing);

$recommendation = (count($liveRecords) > 0 && $traditionalUnknown === 0 && count($missed) === 0 && $conflicts === 0)
    ? 'NOT_EVALUATED_BY_POLICY' : 'NO';
$summary = "# Strict Forward Capture Readiness Completion\n\n"
    . "- **Epoch status:** `" . StrictForwardReadinessLedger::EPOCH_STATUS . "` (Official Epoch not started)\n"
    . "- **forward_performance_inspected:** `NO`\n"
    . "- **outcome_join_performed:** `NO`\n"
    . "- **result/payout/ROI references:** `NO`\n\n"
    . "## Integration\n\n"
    . "All 12 production ML venues are connected through the shared Course Signal API path. The existing daytime 2-minute DB-only capture runner now registers expected keys and calls the same live API even when the ordinary display snapshot already exists. It does not scrape external exhibition sources and does not depend on user page views.\n\n"
    . "## Canonicalization\n\n"
    . "`" . StrictForwardReadinessLedger::EPOCH_STATUS . "|race_code|venue|course|target|exhibition` is the logical key. The first normal record is canonical. Same decision payload is a duplicate audit event; changed payload is a conflict, never overwritten or automatically selected. A future evaluator must exclude unresolved conflicts and use only the canonical first record.\n\n"
    . "## Current outcome-blind observations\n\n"
    . "- Live opportunities: **" . count(array_unique(array_map(static fn(array $r): string => (string)($r['race_code'] ?? ''), $expected))) . "**\n"
    . "- Expected keys: **" . count($expectedByKey) . "**; actual canonical keys: **" . count($actualByKey) . "**; missing: **" . count($missed) . "**\n"
    . "- Formal LIVE_STRICT records: **" . count($liveRecords) . "**\n"
    . "- Traditional unknown rows: **" . $traditionalUnknown . "**\n"
    . "- Conflicts: **" . $conflicts . "**; audited failures: **" . count($failures) . "**\n\n"
    . "## Completion assessment\n\n"
    . "- Structural readiness: **PARTIAL** — ledger, expected universe, 12-venue integration, duplicate canonicalization, and independent failure audit are implemented.\n"
    . "- Operational live readiness: **PENDING** — no real live opportunity was used in this run.\n"
    . "- Comparative readiness: **BLOCKED** — the traditional decision matrix is intentionally partial; unknown map absence is not treated as false.\n"
    . "- Official Epoch recommendation: **" . $recommendation . "**. No numeric quality threshold was chosen.\n";
completionMarkdown(COMPLETION_OUTPUT . '/strict_forward_completion_summary.md', $summary);

echo json_encode([
    'status' => 'ok',
    'forward_performance_inspected' => 'NO',
    'outcome_join_performed' => 'NO',
    'expected_keys' => count($expectedByKey),
    'actual_keys' => count($actualByKey),
    'missed_keys' => count($missed),
    'output_dir' => COMPLETION_OUTPUT,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
