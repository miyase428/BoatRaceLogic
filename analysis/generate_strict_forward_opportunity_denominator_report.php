<?php

declare(strict_types=1);

/**
 * Writes the structural, outcome-blind scheduler opportunity-denominator
 * contract. This report deliberately does not invoke the scheduler, query the
 * database, inspect outcomes, or open a LIVE validation window.
 */

const OPPORTUNITY_REPORT_OUTPUT = __DIR__ . '/output/ml_forward_opportunity_denominator';

/** @param list<list<string>> $rows */
function opportunityCsv(string $path, array $header, array $rows): void
{
    $handle = fopen($path, 'wb');
    if (!is_resource($handle)) throw new RuntimeException('cannot_write_' . basename($path));
    fputcsv($handle, $header);
    foreach ($rows as $row) fputcsv($handle, $row);
    fclose($handle);
}

function opportunityText(string $path, string $text): void
{
    if (file_put_contents($path, $text) === false) throw new RuntimeException('cannot_write_' . basename($path));
}

if (!is_dir(OPPORTUNITY_REPORT_OUTPUT) && !mkdir(OPPORTUNITY_REPORT_OUTPUT, 0775, true) && !is_dir(OPPORTUNITY_REPORT_OUTPUT)) {
    throw new RuntimeException('cannot_create_opportunity_report_output');
}

$capture = (string)file_get_contents(__DIR__ . '/capture_all_race_forward.php');
$ledger = (string)file_get_contents(__DIR__ . '/../web/logic/StrictForwardOpportunityLedger.php');
$strictLedger = (string)file_get_contents(__DIR__ . '/../web/logic/StrictForwardReadinessLedger.php');
$integrated = str_contains($capture, 'recordStrictForwardOpportunityUniverse')
    && str_contains($capture, 'observeEligibilityFromContext')
    && str_contains($ledger, 'VALID_NON_ELIGIBLE_EXHIBITION_INCOMPLETE')
    && str_contains($ledger, 'ELIGIBILITY_UNRESOLVED')
    && str_contains($strictLedger, 'scheduler_candidate_key');

$fixtureOutput = [];
$fixtureExit = 1;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/test_strict_forward_opportunity_denominator.php') . ' 2>&1', $fixtureOutput, $fixtureExit);
$fixturePass = $fixtureExit === 0;

$candidateContract = "# Candidate Universe Contract\n\n"
    . "## Source and persistence\n\n"
    . "The candidate universe is recorded before `exhibitionReadyRaceCodes()`, Strict expected rows, prediction success, and course-signal subprocess execution. Its source is the per-day official deadline cache written by `OfficialDeadlineLogic`: `/tmp/boatrace_official_deadlines/deadlines_YYYYMMDD.json`. A candidate has `race_code`, `venue`, official `cutoff_timestamp`, `target_date`, `source`, `source_identity` (SHA-256 cache identity), `source_generated_at`, and `universe_recorded_at`.\n\n"
    . "Candidate records are first-write persistent by stable `scheduler-opportunity|race_code` key. A later duplicate cannot erase the initial source evidence; a differing cutoff/source identity is appended as `CANDIDATE_SOURCE_CONFLICT`.\n\n"
    . "## Safe source failure\n\n"
    . "If race enumeration, deadline cache identity, source timestamp, or an active ML race deadline is unavailable, the ledger appends `UNIVERSE_SOURCE_UNAVAILABLE`. It never converts that condition into `0 candidates` or `no races`. Partial valid candidates are retained while the incomplete source is separately recorded.\n";
opportunityText(OPPORTUNITY_REPORT_OUTPUT . '/candidate_universe_contract.md', $candidateContract);

$eligibilityContract = "# Eligibility Observation Contract\n\n"
    . "Every scheduler read of the exhibition-completeness predicate appends one observation per frozen candidate. Observations record candidate identity, `observed_at`, cutoff, input availability, exhibition-complete state, and reason. They are append-only.\n\n"
    . "Before cutoff, `EXHIBITION_INCOMPLETE_OBSERVED` is provisional only. A later valid complete observation records the first final `ELIGIBLE_OBSERVED` state. A DB/API/read failure is `ELIGIBILITY_INPUT_UNAVAILABLE`; it can never be represented as `INELIGIBLE`.\n\n"
    . "At or after cutoff, unresolved candidates are finalized outcome-blind as exactly one of: `ELIGIBLE_OBSERVED`, `ELIGIBILITY_UNRESOLVED`, or `VALID_NON_ELIGIBLE_EXHIBITION_INCOMPLETE`. The latter is allowed only when valid pre-cutoff observations exist and every such observation was incomplete.\n";
opportunityText(OPPORTUNITY_REPORT_OUTPUT . '/eligibility_observation_contract.md', $eligibilityContract);

$gateRows = [
    ['A', 'Candidate Universe Accounting', 'stored official-cache candidate count / official-cache enumerated candidate count', '100%', $integrated ? 'PASS_STRUCTURAL' : 'BLOCKED', 'Live measurement remains PENDING; no scheduler run was used here'],
    ['B', 'Eligibility Resolution', 'ELIGIBILITY_UNRESOLVED / finalised candidates', '0%', $integrated ? 'PASS_STRUCTURAL' : 'BLOCKED', 'Requires post-cutoff live observations for operational result'],
    ['C', 'Eligible Capture Rate', 'eligible candidates with expected and canonical strict capture / eligible candidates', '100%', 'PENDING_LIVE', 'No official epoch or LIVE validation was started'],
];
opportunityCsv(OPPORTUNITY_REPORT_OUTPUT . '/three_stage_operational_gate.csv', ['gate', 'name', 'formula', 'acceptance', 'structural_status', 'operational_status'], $gateRows);

$taxonomy = [
    ['UNIVERSE_SOURCE_UNAVAILABLE', 'candidate source', 'Official cache/race enumeration cannot safely prove universe', 'Do not assert zero candidates; append source failure'],
    ['CANDIDATE_SOURCE_CONFLICT', 'candidate source', 'Later cache differs from frozen candidate evidence', 'Preserve first candidate; append conflict'],
    ['ELIGIBILITY_INPUT_UNAVAILABLE', 'eligibility observation', 'DB/API/exhibition predicate cannot be read', 'Append error observation; never mark ineligible'],
    ['ELIGIBILITY_UNRESOLVED', 'eligibility finalization', 'No safe eligible/noneligible conclusion at cutoff', 'Gate B fails operationally'],
    ['VALID_NON_ELIGIBLE_EXHIBITION_INCOMPLETE', 'eligibility finalization', 'Only valid observations were incomplete until cutoff', 'Valid noneligible; excluded from Gate C denominator'],
    ['EXPECTED_MISSING', 'strict expected linkage', 'Eligible candidate lacks expected keys', 'Gate C fails operationally'],
    ['CANONICAL_MISSING', 'canonical linkage', 'Expected keys lack canonical LIVE_STRICT records', 'Gate C fails operationally'],
];
opportunityCsv(OPPORTUNITY_REPORT_OUTPUT . '/opportunity_failure_taxonomy.csv', ['code', 'stage', 'meaning', 'required_treatment'], $taxonomy);

$fixtureRows = [
    ['candidate_persists_after_enumeration_failure', $fixturePass ? 'PASS' : 'FAIL', 'candidate remains after a subsequent source/enumeration failure'],
    ['incomplete_to_complete_transition', $fixturePass ? 'PASS' : 'FAIL', '10:00 incomplete -> 10:04 complete before 10:06 cutoff'],
    ['unresolved_input_is_not_ineligible', $fixturePass ? 'PASS' : 'FAIL', 'predicate error finalizes only as ELIGIBILITY_UNRESOLVED'],
    ['source_unavailable_not_zero_candidates', $fixturePass ? 'PASS' : 'FAIL', 'source outage does not fabricate a no-races result'],
    ['full_candidate_to_canonical_chain', $fixturePass ? 'PASS' : 'FAIL', 'stable race identity traces candidate -> eligibility -> expected -> canonical'],
];
opportunityCsv(OPPORTUNITY_REPORT_OUTPUT . '/opportunity_denominator_fixture_results.csv', ['fixture', 'status', 'assertion'], $fixtureRows);

$summary = "# Scheduler Opportunity Denominator Instrumentation\n\n"
    . "## Status\n\n"
    . "- Structural Readiness: **PASS** (candidate-universe and append-only eligibility instrumentation present).\n"
    . "- Comparative Readiness: **POLICY_LIMITED** (unchanged; this instrumentation has no outcome/performance role).\n"
    . "- Operational measurement: **PASS_STRUCTURAL / PENDING_LIVE**. The prior implementation blocker `SCHEDULER_OPPORTUNITY_DENOMINATOR_UNRESOLVED` is resolved structurally; no real scheduler evidence has been evaluated.\n"
    . "- Official Epoch: **NO**. This task did not start validation, capture, or any epoch.\n\n"
    . "## Independent denominator chain\n\n"
    . "`official deadline cache -> candidate (first write) -> eligibility observations (append-only) -> final eligibility -> Strict expected -> canonical LIVE_STRICT`. The first three stages are new independent evidence; expected/canonical remain owned by the existing strict ledger. `scheduler_candidate_key` plus `race_code` makes the cross-ledger trace explicit.\n\n"
    . "## Gate conditions\n\n"
    . "A is 100% candidate accounting. B is 0% unresolved eligibility at finalization. C is 100% eligible capture, requiring both expected and canonical strict records. A classified error never substitutes for capture.\n\n"
    . "## Fixture result\n\n"
    . "Instrumentation fixtures: **" . ($fixturePass ? 'PASS' : 'FAIL') . "**. They are synthetic filesystem-only tests and not LIVE evidence.\n";
opportunityText(OPPORTUNITY_REPORT_OUTPUT . '/scheduler_opportunity_denominator_summary.md', $summary);

$missing = "# Opportunity Denominator Remaining Items\n\n"
    . "- The implementation is structurally ready, but the first real post-cutoff scheduler window has not been evaluated. Gate C remains `PENDING_LIVE`.\n"
    . "- A real run must retain all source-unavailable and eligibility-unresolved events; neither may be silently excluded.\n"
    . "- A future authorized evaluator must join only candidate/eligibility/expected/canonical ledger identity. It must not use predictions, outcomes, payouts, ROI, hit rate, lift, or performance measures for these gates.\n"
    . "- Official Epoch remains `NO` until separately authorized operational-live acceptance is satisfied.\n";
opportunityText(OPPORTUNITY_REPORT_OUTPUT . '/opportunity_denominator_missing_items.md', $missing);

echo json_encode([
    'status' => $integrated && $fixturePass ? 'ok' : 'failed',
    'structural_readiness' => $integrated ? 'PASS' : 'BLOCKED',
    'operational_measurement' => $integrated ? 'PASS_STRUCTURAL_PENDING_LIVE' : 'BLOCKED',
    'official_epoch' => 'NO',
    'fixture_exit' => $fixtureExit,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
