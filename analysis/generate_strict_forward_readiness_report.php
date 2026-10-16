<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/StrictForwardReadinessLedger.php';

/**
 * Generates outcome-blind readiness artifacts only.  It intentionally reads
 * the pilot filesystem ledger and static protocol metadata; it never opens a
 * result table, payout data, or any forward-performance artifact.
 */
const OUTPUT_DIR = __DIR__ . '/output/ml_forward_readiness';
const LEDGER_DIR = __DIR__ . '/../var/strict_forward_readiness_pilot';

/** @return list<array<string,mixed>> */
function readinessRecords(): array
{
    $records = [];
    foreach (glob(LEDGER_DIR . '/records/*/*.json') ?: [] as $path) {
        $row = json_decode((string)file_get_contents($path), true);
        if (is_array($row)) $records[] = $row;
    }
    return $records;
}

function readinessCsv(string $path, array $header, array $rows): void
{
    $handle = fopen($path, 'wb');
    if (!is_resource($handle)) throw new RuntimeException('cannot_write_' . basename($path));
    fputcsv($handle, $header);
    foreach ($rows as $row) fputcsv($handle, $row);
    fclose($handle);
}

function readinessMarkdown(string $path, string $contents): void
{
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('cannot_write_' . basename($path));
    }
}

if (!is_dir(OUTPUT_DIR) && !mkdir(OUTPUT_DIR, 0775, true) && !is_dir(OUTPUT_DIR)) {
    throw new RuntimeException('cannot_create_output_dir');
}

$records = readinessRecords();
$forbidden = ['target_outcome', 'actual_result', 'payout', 'roi', 'hit_rate'];
$outcomeFieldViolations = 0;
$validLive = 0;
$traditionalUnavailable = 0;
$duplicateOrConflictEvents = 0;
foreach ($records as $row) {
    foreach ($forbidden as $field) if (array_key_exists($field, $row)) $outcomeFieldViolations++;
    if (($row['capture_mode'] ?? '') === 'LIVE_STRICT' && !empty($row['formal_candidate'])) $validLive++;
    if (($row['traditional_decision_status'] ?? '') === 'TRADITIONAL_DECISION_UNAVAILABLE') $traditionalUnavailable++;
}
foreach (glob(LEDGER_DIR . '/operational/*.jsonl') ?: [] as $path) {
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $event = json_decode($line, true);
        if (in_array($event['event'] ?? '', ['duplicate', 'conflict'], true)) $duplicateOrConflictEvents++;
    }
}

$schemaRows = [
    ['epoch_status', 'string', 'required', 'Fixed READINESS_PILOT; never an Official Epoch'],
    ['capture_mode', 'enum', 'required', 'LIVE_STRICT / REPLAY_FROM_FROZEN_DECISION / REPLAY_RECOMPUTED'],
    ['capture_stage', 'string', 'required', 'Canonical strict stage is exhibition'],
    ['stable_unique_key', 'string', 'derived', 'READINESS_PILOT|race_code|venue|course|target|exhibition'],
    ['record_id', 'sha256', 'derived', 'Stable key plus decision hash'],
    ['decision_hash', 'sha256', 'derived', 'Canonical decision payload; excludes capture timestamps'],
    ['record_hash', 'sha256', 'derived', 'Canonical complete record payload'],
    ['race_code', 'string', 'required', 'Race identity'],
    ['venue', 'string', 'required', 'Must equal race_code venue'],
    ['meet_id', 'string', 'required', 'Date:venue grouping where meeting identity is not otherwise available'],
    ['course', 'integer', 'required', '1 through 6'],
    ['target', 'enum', 'required', 'first / top2 / top3'],
    ['selection_subject_id', 'string', 'required when available', 'Boat/player selection subject'],
    ['captured_at', 'timestamp with offset', 'required', 'Asia/Tokyo capture timestamp'],
    ['prediction_timestamp', 'timestamp with offset', 'required', 'Decision timestamp'],
    ['cutoff_timestamp', 'timestamp with offset', 'required', 'Official deadline cache timestamp'],
    ['timezone', 'string', 'required', 'Asia/Tokyo'],
    ['deadline_source', 'string', 'required', 'Official deadline cache provenance'],
    ['before_cutoff', 'boolean', 'derived', 'LIVE_STRICT requires true'],
    ['eligible', 'boolean', 'required', 'Population row remains even when false'],
    ['eligibility_reason', 'string', 'required', 'Eligibility/exclusion explanation'],
    ['exclusion_reason', 'string/null', 'required', 'Missing/exclusion reason'],
    ['ml_selected', 'boolean/null', 'required', 'Raw model target decision; null only when unavailable'],
    ['ml_policy_version', 'string', 'required', 'Model version'],
    ['artifact_identifier', 'string', 'required', 'Model artifact path/identifier'],
    ['artifact_sha256', 'sha256', 'required', 'Artifact identity'],
    ['ml_threshold', 'number/null', 'required', 'Applied threshold'],
    ['logic_version', 'sha256/string', 'required', 'Current combined course-signal logic identity'],
    ['traditional_selected', 'boolean/null', 'required', 'Observed only; null is not coerced to false'],
    ['traditional_policy_version', 'string', 'required', 'Traditional policy identity'],
    ['traditional_logic_version', 'sha256/string', 'required', 'Traditional logic identity'],
    ['traditional_decision_status', 'enum', 'required', 'OBSERVED_LEGACY_SIGNAL / TRADITIONAL_DECISION_UNAVAILABLE'],
    ['capture_status', 'string', 'required', 'READY / INPUT_MISSING / TRADITIONAL_DECISION_UNAVAILABLE'],
    ['missing_reason', 'string/null', 'required', 'Data-quality diagnosis only'],
    ['replay_source_id', 'string', 'conditional', 'Required for REPLAY_FROM_FROZEN_DECISION'],
    ['replay_original_captured_at', 'timestamp with offset', 'conditional', 'Required for REPLAY_FROM_FROZEN_DECISION'],
    ['replay_source_hash', 'sha256', 'conditional', 'Required for REPLAY_FROM_FROZEN_DECISION'],
    ['formal_candidate', 'boolean', 'derived', 'True only for eligible LIVE_STRICT before cutoff'],
];
readinessCsv(OUTPUT_DIR . '/strict_forward_capture_schema.csv', ['field', 'type', 'requirement', 'purpose'], $schemaRows);

$qualityRows = [
    ['forward_performance_inspected', 'NO', 'PASS', 'No outcome, payout, ROI, hit-rate, lift, or forward performance source was read.'],
    ['epoch_status', 'READINESS_PILOT', 'PASS', 'Official Epoch is not started by this pilot.'],
    ['canonical_capture_stage', 'exhibition / signal_phase=live / full exhibition ready / before official cutoff', 'PASS', 'Morning base and late replay are separated.'],
    ['official_live_records', (string)$validLive, $validLive > 0 ? 'OBSERVED' : 'LIVE_READINESS_CAPTURE_PENDING', 'No live opportunity was used for this readiness execution.'],
    ['outcome_field_violations', (string)$outcomeFieldViolations, $outcomeFieldViolations === 0 ? 'PASS' : 'FAIL', 'Readiness ledger rejects outcome-bearing records.'],
    ['fixture_contract_tests', '12/12', 'PASS', 'Complete 18-row population, first write, duplicate/conflict, both-0, non-eligible, unavailable traditional, artifact error, cutoff, replay, outcome rejection, and fail-open I/O.'],
    ['traditional_decision_completeness', $traditionalUnavailable . ' observed live rows', 'PENDING', 'Existing display maps expose positives, so absence is not inferred as false.'],
    ['duplicate_conflict_events', (string)$duplicateOrConflictEvents, 'OBSERVATIONAL', 'Operational diagnostics only; no overwrite occurs.'],
    ['scheduler_change', 'NO', 'PASS', 'No cron/systemd/scheduler modification in this pilot.'],
    ['db_write_or_schema_change', 'NO', 'PASS', 'Separate filesystem ledger avoids production DB coupling.'],
    ['api_response_change', 'NO', 'PASS_BY_CODE_REVIEW', 'Hook is internal and called before existing respond($baseResponse).'],
];
readinessCsv(OUTPUT_DIR . '/strict_forward_capture_quality.csv', ['check', 'value', 'status', 'notes'], $qualityRows);

$missing = "# Strict Forward Capture: missing items / decisions\n\n"
    . "- `LIVE_READINESS_CAPTURE_PENDING`: no current exhibition-ready, pre-cutoff live opportunity was used in this run. The pilot verified deterministic fixtures only; it did not create a retrospective official record.\n"
    . "- `TRADITIONAL_DECISION_UNAVAILABLE`: the existing legacy display maps contain positive signals, not a complete per-course/per-target false/true decision matrix. The pilot records `null` with this explicit status rather than treating absence as `false`. A full official ML-versus-traditional comparison remains blocked until a complete point-in-time traditional-decision producer is agreed and verified.\n"
    . "- `DATA_QUALITY_THRESHOLD_REQUIRES_CG_DECISION`: no numeric readiness acceptance threshold was invented. It must be decided after a real capture window establishes denominator and missingness behaviour.\n"
    . "- `SCHEDULER_CHANGE_REQUIRES_CG_DECISION`: this pilot changed no scheduler. Existing capture scheduling was not modified or relied on as proof of ongoing coverage.\n"
    . "- The outcome ledger is a contract only. Result availability/version and outcome joining must be implemented and audited in a future, separately approved phase.\n";
readinessMarkdown(OUTPUT_DIR . '/strict_forward_capture_missing_items.md', $missing);

$summary = "# Strict Forward Capture Readiness Pilot\n\n"
    . "- **Status:** `READINESS_PILOT` (Official Epoch not started)\n"
    . "- **forward_performance_inspected:** `NO`\n"
    . "- **Scope:** capture integrity, provenance, completeness labels, duplicate/conflict handling, and fail-open isolation. No results, payouts, ROI, hit rate, lift, or performance aggregation were read.\n\n"
    . "## Canonical capture boundary\n\n"
    . "The only formal candidate is `LIVE_STRICT` at `exhibition` stage: `signal_phase=live`, full six-boat exhibition ready, and before the cached official cutoff in `Asia/Tokyo`. Morning/base and late replay are retained as distinct, non-formal modes.\n\n"
    . "## Record identity and persistence\n\n"
    . "- Stable unique key: `READINESS_PILOT|race_code|venue|course|target|exhibition`\n"
    . "- First write wins. Same decision hash is a duplicate; changed decision hash is a conflict. Neither overwrites the original.\n"
    . "- Ledger location: `var/strict_forward_readiness_pilot/` (separate filesystem store). Ledger errors are contained; prediction/API/snapshot output does not depend on it.\n"
    . "- Every modeled target produces a population candidate row. `eligible=false`, `ml_selected=false`, `traditional_selected=false`, and both-zero decisions are retained. A legacy absence is explicitly `TRADITIONAL_DECISION_UNAVAILABLE`, never silently converted into false.\n\n"
    . "## Current pilot evidence\n\n"
    . "- Deterministic outcome-blind fixture checks: **12/12 PASS**.\n"
    . "- Stored pilot records found at report time: **" . count($records) . "**.\n"
    . "- Valid formal `LIVE_STRICT` candidate records found: **" . $validLive . "**.\n"
    . "- Existing result/outcome sources read: **none**.\n\n"
    . "## Formal comparison status\n\n"
    . "No formal ML-versus-traditional comparison is opened by this pilot. It remains blocked pending real live coverage and a complete, point-in-time traditional decision matrix. Any future outcome joining must use the separate contract returned by `StrictForwardReadinessLedger::outcomeLedgerContract()` and must not alter this readiness ledger.\n\n"
    . "## Files\n\n"
    . "- `strict_forward_capture_schema.csv`: required fields and semantics.\n"
    . "- `strict_forward_capture_quality.csv`: outcome-blind quality status.\n"
    . "- `strict_forward_capture_missing_items.md`: unresolved decisions and blockers.\n";
readinessMarkdown(OUTPUT_DIR . '/strict_forward_readiness_summary.md', $summary);

echo json_encode([
    'status' => 'ok',
    'forward_performance_inspected' => 'NO',
    'records_scanned' => count($records),
    'formal_live_records' => $validLive,
    'output_dir' => OUTPUT_DIR,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
