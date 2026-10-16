<?php

declare(strict_types=1);

/**
 * Outcome-blind operational-acceptance contract generator. It freezes the
 * criteria for a later live check; it neither starts capture nor reads result,
 * payout, ROI, hit-rate, lift, or outcome-join data.
 */

const OPERATIONAL_CONTRACT_OUTPUT = __DIR__ . '/output/ml_forward_operational_contract';

/** @param list<list<string>> $rows */
function operationalCsv(string $path, array $header, array $rows): void
{
    $handle = fopen($path, 'wb');
    if (!is_resource($handle)) throw new RuntimeException('cannot_write_' . basename($path));
    fputcsv($handle, $header);
    foreach ($rows as $row) fputcsv($handle, $row);
    fclose($handle);
}

function operationalMarkdown(string $path, string $text): void
{
    if (file_put_contents($path, $text) === false) throw new RuntimeException('cannot_write_' . basename($path));
}

/** @return list<array<string,string>> */
function operationalRows(string $path): array
{
    $handle = fopen($path, 'rb');
    if (!is_resource($handle)) throw new RuntimeException('cannot_read_' . basename($path));
    $header = fgetcsv($handle);
    if (!is_array($header)) throw new RuntimeException('missing_header_' . basename($path));
    $rows = [];
    while (($line = fgetcsv($handle)) !== false) {
        $row = [];
        foreach ($header as $i => $name) $row[(string)$name] = (string)($line[$i] ?? '');
        $rows[] = $row;
    }
    fclose($handle);
    return $rows;
}

if (!is_dir(OPERATIONAL_CONTRACT_OUTPUT) && !mkdir(OPERATIONAL_CONTRACT_OUTPUT, 0775, true) && !is_dir(OPERATIONAL_CONTRACT_OUTPUT)) {
    throw new RuntimeException('cannot_create_operational_contract_output');
}

$startHead = trim((string)shell_exec('git rev-parse HEAD'));
$crosswalkPath = __DIR__ . '/output/ml_forward_epoch_launch_criteria/production_use_slot_crosswalk.csv';
$hardGatePath = __DIR__ . '/output/ml_forward_epoch_launch_criteria/epoch_hard_gate_contract.csv';
$numericGatePath = __DIR__ . '/output/ml_forward_epoch_launch_criteria/epoch_numeric_quality_gate.csv';
$crosswalk = operationalRows($crosswalkPath);
$hardGates = operationalRows($hardGatePath);
$numericGates = operationalRows($numericGatePath);
$comparative = count(array_filter($crosswalk, static fn(array $row): bool => ($row['direct_comparison_capability'] ?? '') === 'COMPARATIVE_ML_VS_TRADITIONAL'));
$mlOnly = count(array_filter($crosswalk, static fn(array $row): bool => ($row['direct_comparison_capability'] ?? '') === 'ML_ONLY_POLICY_LIMITED'));
$unresolved = count($crosswalk) - $comparative - $mlOnly;

$capturePath = __DIR__ . '/capture_all_race_forward.php';
$deadlinePath = __DIR__ . '/../web/logic/OfficialDeadlineLogic.php';
$ledgerPath = __DIR__ . '/../web/logic/StrictForwardReadinessLedger.php';
$captureSource = (string)file_get_contents($capturePath);
$deadlineSource = (string)file_get_contents($deadlinePath);
$ledgerSource = (string)file_get_contents($ledgerPath);

$deadlineUniverseAvailable = str_contains($deadlineSource, "'deadlines' => \$flat")
    && str_contains($deadlineSource, "'places' => \$places")
    && str_contains($deadlineSource, "count(\$deadlines) !== 12");
$strictExpectedIsNotDenominator = str_contains($captureSource, 'recordExpectedLiveFromContext')
    && str_contains($captureSource, '$strictSignalTargets');
$exhibitionEligibilityIsEphemeral = str_contains($captureSource, 'exhibitionReadyRaceCodes($pdo, $raceCodes)')
    && !str_contains($captureSource, 'opportunity_denominator');

// The official deadline cache is an outcome-free and strict-ledger-independent
// schedule universe. However the current implementation does not separately
// persist the pre-cutoff exhibition-ready subset. It is therefore not safe to
// calculate an *eligible* denominator after a pre-enumeration DB/API failure.
$denominatorStatus = $deadlineUniverseAvailable && $strictExpectedIsNotDenominator && $exhibitionEligibilityIsEphemeral
    ? 'SCHEDULER_OPPORTUNITY_DENOMINATOR_UNRESOLVED'
    : 'SOURCE_AUDIT_CONFLICT';

$denominator = "# Scheduler Opportunity Denominator\n\n"
    . "## Independent outcome-free schedule universe\n\n"
    . "The candidate source is the official deadline cache: `/tmp/boatrace_official_deadlines/deadlines_YYYYMMDD.json`, written by `OfficialDeadlineLogic`. It records date, place, official source URL/fetch time, and 12 deadline times for every successfully loaded venue. It is independent of `StrictForwardReadinessLedger` expected rows.\n\n"
    . "## Eligible-opportunity definition\n\n"
    . "A race is eligible only when all are true before its official cutoff: (1) the official deadline cache identifies its scheduled race code; (2) the venue has one or more production ML uses in the frozen 123-use crosswalk; (3) the exhibition-completeness predicate used by `exhibitionReadyRaceCodes()` is true; and (4) the strict capture window remains open. Outcomes, payouts, odds, and results are not inputs.\n\n"
    . "## Denominator independence finding\n\n"
    . "`recordExpectedLiveFromContext()` is deliberately **not** the denominator. Current capture code derives `readyAfter` through `exhibitionReadyRaceCodes()` and then writes strict expected rows. The official deadline cache is independent for schedule/deadline identity, but the exhibition-ready subset is not separately persisted outside the strict ledger. A DB/API failure before enumeration can therefore leave the historical eligible subset unprovable.\n\n"
    . "`{$denominatorStatus}`. Operational acceptance must not be evaluated, and Scheduler Opportunity Capture Rate must not be declared 100%, until an additive outcome-free pre-cutoff opportunity manifest exists outside the strict expected ledger. This document does not implement that manifest.\n";
operationalMarkdown(OPERATIONAL_CONTRACT_OUTPUT . '/scheduler_opportunity_denominator.md', $denominator);

$gateRows = [
    ['independent_schedule_deadline_universe', $deadlineUniverseAvailable ? 'PASS_STRUCTURAL' : 'FAIL', 'OfficialDeadlineLogic cache; not Strict expected rows', 'Required source for schedule/race-code/deadline identity'],
    ['independent_eligible_opportunity_denominator', 'PENDING_STOP', 'Official schedule cache + independently persisted pre-cutoff exhibition-ready subset', $denominatorStatus],
    ['race_level_capture_numerator', 'FROZEN_CONTRACT', 'For each eligible race, every frozen production ML use at that venue must have a canonical LIVE_STRICT row with before_cutoff=true and eligible=true.', '123-use crosswalk defines required per-venue uses'],
    ['scheduler_opportunity_capture_rate', 'FROZEN_CONTRACT', 'captured eligible race opportunities / independent eligible race opportunities', 'Acceptance=100%; no arbitrary tolerance'],
    ['expected_to_canonical_completeness', 'FROZEN_CONTRACT', 'canonical strict keys / strict expected keys', 'Acceptance=100% after completed capture window'],
    ['unclassified_missing_rate', 'FROZEN_CONTRACT', 'unclassified missing / independent eligible opportunities', 'Acceptance=0%; POLICY_UNDEFINED excluded'],
    ['identity_and_conflict_integrity', 'FROZEN_CONTRACT', 'artifact identity=100%; policy identity=100%; unresolved conflict=0', 'Required for every canonical record'],
    ['failure_reason_classification', 'FROZEN_CONTRACT', 'classified failure events / all failure events', 'Acceptance=100%; classification never substitutes for canonical capture'],
    ['live_window_continuity', 'FROZEN_CONTRACT', 'distinct eligible race_code count', 'Minimum=2; failed/missing/skip events do not reset window'],
];
operationalCsv(OPERATIONAL_CONTRACT_OUTPUT . '/scheduler_opportunity_capture_gate.csv', ['gate', 'status', 'definition', 'acceptance_or_reason'], $gateRows);

$window = "# Operational LIVE validation window\n\n"
    . "- The window starts at the first independently established eligible LIVE race opportunity after this contract is frozen.\n"
    . "- It ends only after **at least two distinct `race_code` values** have been observed. This is an operational-continuity minimum, not a performance sample size.\n"
    . "- A day with one eligible race keeps that race in the window; the window extends to the next eligible race.\n"
    . "- A failure, missing record, scheduler skip, DB/API failure, or classified failure does **not** reset or discard the window. It is evidence against Operational PASS if the eligible race lacks canonical capture.\n"
    . "- A canonical accepted race requires, for every frozen production ML use of its venue: `capture_mode=LIVE_STRICT`, `before_cutoff=true`, `eligible=true`, no replay mode, no late canonical replacement, complete artifact/policy identity, and no unresolved conflict.\n"
    . "- Polling near two minutes is secondary operational evidence only. Jitter alone is not failure; a missed eligible race is captured by the race-level 100% rate.\n\n"
    . "The present status is `PENDING_STOP` because the independent eligible-denominator manifest is not yet implemented. No LIVE window is opened by this document.\n";
operationalMarkdown(OPERATIONAL_CONTRACT_OUTPUT . '/operational_validation_window.md', $window);

$reporting = "# Forward reporting contract\n\n"
    . "## Stable populations\n\n"
    . "- `COMPARATIVE_ML_VS_TRADITIONAL`: **{$comparative}** production uses. Only these may enter a direct Traditional-versus-ML comparison denominator in a later, separately authorized performance stage.\n"
    . "- `ML_ONLY_POLICY_LIMITED`: **{$mlOnly}** production uses. These may appear in a 123-use ML-only operational aggregate, but never as evidence of Traditional superiority/inferiority.\n"
    . "- Unresolved mapping: **{$unresolved}**.\n\n"
    . "`POLICY_UNDEFINED` is neither missing, implementation failure, Traditional false, nor Traditional loss. It is excluded from comparative and missing-rate denominators.\n\n"
    . "`TMG 2C top3` retains `HISTORICAL_LINEAGE_CONFLICT_RETAINED`. Prospective strict-forward evidence does not retrospectively resolve the historical conflict.\n\n"
    . "This operational contract authorizes no outcome/performance report.\n";
operationalMarkdown(OPERATIONAL_CONTRACT_OUTPUT . '/forward_reporting_contract.md', $reporting);

$acceptance = "# Strict Forward Operational Live Acceptance Contract\n\n"
    . "## Frozen acceptance conditions\n\n"
    . "Operational Live Readiness may be `PASS` only when the following are simultaneously true for the unreset validation window:\n\n"
    . "1. At least two distinct independently eligible `race_code` values are observed.\n"
    . "2. Scheduler Opportunity Capture Rate is exactly **100%** using the independent eligible denominator, not strict expected rows.\n"
    . "3. Strict expected-to-canonical completeness is **100%**.\n"
    . "4. Unclassified missing is **0**.\n"
    . "5. Artifact identity and policy identity are each **100%** on canonical records.\n"
    . "6. Unresolved conflicts are **0**.\n"
    . "7. Failure-reason classification is **100%** for observed failures, but never substitutes for canonical capture.\n"
    . "8. Every canonical record proves `captured_at < cutoff_timestamp`; no `REPLAY_RECOMPUTED` or late replacement becomes canonical.\n\n"
    . "A classified DB/API/scheduler failure is still a failed race-level capture whenever an eligible race lacks its canonical record. It yields `FAIL` or `PENDING`, not PASS.\n\n"
    . "## Launch linkage\n\n"
    . "Official Epoch remains prohibited until Structural Readiness is PASS, Operational Live Readiness is PASS, required hard gates are PASS/N/A, numeric gates pass, comparative limitations are documented, and CG approves launch. Codex must not launch it alone.\n";
operationalMarkdown(OPERATIONAL_CONTRACT_OUTPUT . '/operational_live_acceptance_contract.md', $acceptance);

$missing = "# Operational contract remaining items\n\n"
    . "- `{$denominatorStatus}`: implement and verify an additive, outcome-free, pre-cutoff opportunity manifest separate from strict expected rows before starting the live validation window.\n"
    . "- Live validation window has not started; minimum two distinct eligible race codes remain unobserved.\n"
    . "- No optional tolerance is permitted for race-level capture: acceptance is 100%.\n"
    . "- No performance/outcome work is authorized.\n\n"
    . "Current readiness: Structural=`PASS`; Operational Live=`PENDING`; Comparative=`POLICY_LIMITED`; Launch Contract=`PENDING`; Official Epoch Recommendation=`NO`.\n";
operationalMarkdown(OPERATIONAL_CONTRACT_OUTPUT . '/operational_contract_missing_items.md', $missing);

$endHead = trim((string)shell_exec('git rev-parse HEAD'));
echo json_encode([
    'status' => 'ok', 'start_head' => $startHead, 'end_head' => $endHead,
    'independent_schedule_source' => $deadlineUniverseAvailable ? 'OFFICIAL_DEADLINE_CACHE' : 'UNVERIFIED',
    'denominator_status' => $denominatorStatus, 'comparative_uses' => $comparative, 'ml_only_uses' => $mlOnly,
    'structural_readiness' => 'PASS', 'operational_live_readiness' => 'PENDING',
    'comparative_readiness' => 'POLICY_LIMITED', 'official_epoch_recommendation' => 'NO',
    'forward_performance_inspected' => 'NO', 'outcome_join_performed' => 'NO', 'result_payout_roi_inspected' => 'NO',
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
