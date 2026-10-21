<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/FormalMeasurementWindowContext.php';
require_once __DIR__ . '/../web/logic/StrictForwardOpportunityLedger.php';
require_once __DIR__ . '/../web/logic/StrictForwardAcceptedRace.php';

/**
 * Outcome-blind evaluator for the explicitly armed Operational LIVE window.
 * It reads only the scheduler candidate/eligibility and strict expected/capture
 * filesystem ledgers. It never reads PostgreSQL, race results, payouts, odds,
 * ROI, hit rate, lift, or any outcome join.
 */

const LIVE_EVAL_OUTPUT = __DIR__ . '/output/ml_forward_live_readiness';

/** @return list<array<string,mixed>> */
function liveJsonRows(string $pattern): array
{
    $rows = [];
    foreach (glob($pattern) ?: [] as $path) {
        $row = json_decode((string)file_get_contents($path), true);
        if (is_array($row)) $rows[] = $row;
    }
    return $rows;
}

/** @return list<array<string,mixed>> */
function liveJsonlRows(string $pattern): array
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

function liveAfter(string $value, string $armedAt): bool
{
    try { return $value !== '' && (new DateTimeImmutable($value)) > (new DateTimeImmutable($armedAt)); }
    catch (Throwable) { return false; }
}

function liveBefore(string $value, string $cutoff): bool
{
    try { return $value !== '' && (new DateTimeImmutable($value)) < (new DateTimeImmutable($cutoff)); }
    catch (Throwable) { return false; }
}

/** @param list<list<string>> $rows */
function liveCsv(string $path, array $header, array $rows): void
{
    $handle = fopen($path, 'wb');
    if (!is_resource($handle)) throw new RuntimeException('cannot_write_' . basename($path));
    fputcsv($handle, $header);
    foreach ($rows as $row) fputcsv($handle, $row);
    fclose($handle);
}

function liveText(string $path, string $text): void
{
    if (file_put_contents($path, $text) === false) throw new RuntimeException('cannot_write_' . basename($path));
}

$windowContext = FormalMeasurementWindowContext::production(dirname(__DIR__));
$liveEvalState = $windowContext->windowManifestPath();
$opportunityRoot = $windowContext->opportunityRoot();
$strictRoot = $windowContext->ledgerRoot();
if (!is_file($liveEvalState)) throw new RuntimeException('validation_window_not_armed');
$manifest = json_decode((string)file_get_contents($liveEvalState), true);
if (!is_array($manifest) || !is_string($manifest['armed_at'] ?? null)) throw new RuntimeException('invalid_validation_window_manifest');
if (!is_dir(LIVE_EVAL_OUTPUT) && !mkdir(LIVE_EVAL_OUTPUT, 0775, true) && !is_dir(LIVE_EVAL_OUTPUT)) throw new RuntimeException('cannot_create_output');

$armedAt = (string)$manifest['armed_at'];
$authorization = $windowContext->authorizeFormalWrite();
$implementation = is_array($authorization['implementation'] ?? null) ? $authorization['implementation'] : [];
$windowId = (string)($authorization['window_id'] ?? '');
$scheduleLedger = new StrictForwardOpportunityLedger($opportunityRoot);
// Amendment 2026-10-05: the accounting universe is an opportunity that still
// existed at ARM, therefore cutoff (not first-write candidate timestamp) is
// the boundary. Formal eligibility/expected/canonical evidence remains strict
// post-ARM evidence below.
$candidates = array_values(array_filter(liveJsonRows($opportunityRoot . '/candidates/*/*.json'), static fn(array $row): bool => liveAfter((string)($row['cutoff_timestamp'] ?? ''), $armedAt)));
$resolutions = array_values(array_filter(liveJsonRows($opportunityRoot . '/eligibility/*/*.json'), static fn(array $row): bool => liveAfter((string)($row['first_eligible_observed_at'] ?? $row['resolved_at'] ?? ''), $armedAt)));
$sourceFailures = array_values(array_filter(liveJsonlRows($opportunityRoot . '/source_failures/*.jsonl'), static fn(array $row): bool => liveAfter((string)($row['observed_at'] ?? ''), $armedAt)));
$expected = array_values(array_filter(liveJsonRows($strictRoot . '/expected/*/*.json'), static fn(array $row): bool => liveAfter((string)($row['expected_at'] ?? ''), $armedAt)));
$canonical = array_values(array_filter(liveJsonRows($strictRoot . '/records/*/*.json'), static fn(array $row): bool => liveAfter((string)($row['captured_at'] ?? ''), $armedAt)));
$failures = array_values(array_filter(liveJsonlRows($strictRoot . '/failures/*.jsonl'), static fn(array $row): bool => liveAfter((string)($row['attempted_at'] ?? $row['observed_at'] ?? ''), $armedAt)));

$resolutionByRace = [];
foreach ($resolutions as $row) $resolutionByRace[(string)($row['race_code'] ?? '')] = $row;
$expectedByRace = [];
foreach ($expected as $row) $expectedByRace[(string)($row['race_code'] ?? '')][] = $row;
$canonicalByRace = [];
foreach ($canonical as $row) $canonicalByRace[(string)($row['race_code'] ?? '')][] = $row;

$traceRows = [];
$validSourceCount = 0;
$eligible = [];
$unresolved = 0;
$captureComplete = 0;
$expectedCanonicalComplete = 0;
$artifactComplete = 0;
$policyComplete = 0;
$beforeCutoffComplete = 0;
$traceComplete = 0;
$acceptedComplete = 0;
foreach ($candidates as $candidate) {
    $raceCode = (string)($candidate['race_code'] ?? '');
    $sourceGenerated = (string)($candidate['source_generated_at'] ?? '');
    $recorded = (string)($candidate['universe_recorded_at'] ?? '');
    $targetDate = str_replace('-', '', (string)($candidate['target_date'] ?? ''));
    $sourceValid = $sourceGenerated !== '' && liveBefore($sourceGenerated, $recorded) && substr($raceCode, 0, 8) === $targetDate
        && str_starts_with((string)($candidate['source_identity'] ?? ''), 'sha256:');
    if ($sourceValid) $validSourceCount++;
    $resolution = $resolutionByRace[$raceCode] ?? [];
    $eligibility = (string)($resolution['eligibility_status'] ?? 'PENDING_ELIGIBILITY');
    if ($eligibility === 'ELIGIBLE_OBSERVED') $eligible[] = $raceCode;
    if ($eligibility === 'ELIGIBILITY_UNRESOLVED') $unresolved++;
    $expectedRows = $expectedByRace[$raceCode] ?? [];
    $canonicalRows = $canonicalByRace[$raceCode] ?? [];
    $expectedKeys = array_values(array_unique(array_map(static fn(array $row): string => (string)($row['stable_unique_key'] ?? ''), $expectedRows)));
    $canonicalKeys = array_values(array_unique(array_map(static fn(array $row): string => (string)($row['stable_unique_key'] ?? ''), $canonicalRows)));
    $expectedComplete = $eligibility !== 'ELIGIBLE_OBSERVED' ? null : (count($expectedKeys) === 18);
    $canonicalComplete = $eligibility !== 'ELIGIBLE_OBSERVED' ? null : ($expectedComplete && count(array_diff($expectedKeys, $canonicalKeys)) === 0);
    $formalSchedule = $scheduleLedger->formalMeasurementStatus((string)($candidate['target_date'] ?? ''), $raceCode);
    $acceptance = StrictForwardAcceptedRace::evaluate($expectedRows, $canonicalRows, [
        'formal_schedule_accepted' => !empty($formalSchedule['accepted']) && $eligibility === 'ELIGIBLE_OBSERVED',
        'window_id' => $windowId,
        'implementation' => $implementation,
        'scheduler_candidate_key' => 'scheduler-opportunity|' . $raceCode,
    ]);
    if ($eligibility === 'ELIGIBLE_OBSERVED') {
        if (!empty($acceptance['structural_complete'])) {
            $captureComplete++;
            $expectedCanonicalComplete++;
        }
        if (!empty($acceptance['accepted'])) {
            $artifactComplete++;
            $policyComplete++;
            $beforeCutoffComplete++;
            $traceComplete++;
            $acceptedComplete++;
        }
    }
    $traceRows[] = [$raceCode, (string)($candidate['venue'] ?? ''), (string)($candidate['scheduler_candidate_key'] ?? ''), $recorded, (string)($candidate['cutoff_timestamp'] ?? ''), $sourceValid ? 'PASS' : 'FAIL', $eligibility, (string)count($expectedKeys), (string)count($canonicalKeys), !empty($acceptance['structural_complete']) ? 'PASS' : 'FAIL', !empty($acceptance['accepted']) ? 'PASS' : 'FAIL', (string)($acceptance['reason'] ?? '')];
}

$candidateCount = count($candidates);
$eligible = array_values(array_unique($eligible));
$eligibleCount = count($eligible);
$distinctEligible = $eligibleCount;
$gateA = $candidateCount === 0 ? 'PENDING_LIVE' : (($validSourceCount === $candidateCount && $sourceFailures === []) ? 'PASS' : 'FAIL');
$terminalCount = count(array_filter($candidates, static function (array $candidate) use ($resolutionByRace): bool {
    $row = $resolutionByRace[(string)($candidate['race_code'] ?? '')] ?? [];
    return in_array((string)($row['eligibility_status'] ?? ''), ['ELIGIBLE_OBSERVED', 'ELIGIBILITY_UNRESOLVED', 'VALID_NON_ELIGIBLE_EXHIBITION_INCOMPLETE'], true);
}));
$gateB = $candidateCount === 0 || $terminalCount < $candidateCount ? 'PENDING_LIVE' : ($unresolved === 0 ? 'PASS' : 'FAIL');
$gateC = $eligibleCount === 0 ? 'PENDING_LIVE' : ($acceptedComplete === $eligibleCount ? 'PASS' : 'FAIL');
if ($distinctEligible >= 2) $windowStatus = ($gateA === 'PASS' && $gateB === 'PASS' && $gateC === 'PASS') ? 'COMPLETE_PASS' : 'COMPLETE_FAIL';
elseif ($candidateCount === 0) $windowStatus = 'ARMED';
else $windowStatus = 'IN_PROGRESS';
$manifest['status'] = $windowStatus;
$manifest['last_evaluated_at'] = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM);
$manifest['first_eligible_race_code'] = $eligible[0] ?? null;
$manifest['observed_distinct_eligible_race_codes'] = $distinctEligible;
file_put_contents($liveEvalState, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
file_put_contents(LIVE_EVAL_OUTPUT . '/validation_window_manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

liveCsv(LIVE_EVAL_OUTPUT . '/validation_race_trace.csv', ['race_code', 'venue', 'scheduler_candidate_key', 'candidate_recorded_at', 'cutoff_timestamp', 'cache_source', 'eligibility', 'expected_key_count', 'canonical_key_count', 'structural_completeness', 'accepted', 'acceptance_reason'], $traceRows);
liveCsv(LIVE_EVAL_OUTPUT . '/three_stage_gate_result.csv', ['gate', 'result', 'numerator', 'denominator', 'acceptance', 'note'], [
    ['A Candidate Universe Accounting', $gateA, (string)$validSourceCount, (string)$candidateCount, '100%', 'source failures=' . count($sourceFailures)],
    ['B Eligibility Resolution', $gateB, (string)$unresolved, (string)$terminalCount, 'ELIGIBILITY_UNRESOLVED=0%', 'PENDING until all candidate eligibility is terminal'],
    ['C Eligible Capture Rate', $gateC, (string)$captureComplete, (string)$eligibleCount, '100%', 'eligible expected/canonical completeness is separately required'],
]);
liveCsv(LIVE_EVAL_OUTPUT . '/live_strict_quality_result.csv', ['metric', 'numerator', 'denominator', 'result'], [
    ['expected_to_canonical_completeness', (string)$expectedCanonicalComplete, (string)$eligibleCount, $gateC === 'PASS' ? 'PASS' : 'PENDING_OR_FAIL'],
    ['artifact_identity_completeness', (string)$artifactComplete, (string)$eligibleCount, $gateC === 'PASS' ? 'PASS' : 'PENDING_OR_FAIL'],
    ['policy_identity_completeness', (string)$policyComplete, (string)$eligibleCount, $gateC === 'PASS' ? 'PASS' : 'PENDING_OR_FAIL'],
    ['captured_at_before_cutoff', (string)$beforeCutoffComplete, (string)$eligibleCount, $gateC === 'PASS' ? 'PASS' : 'PENDING_OR_FAIL'],
    ['race_trace_completeness', (string)$traceComplete, (string)$eligibleCount, $gateC === 'PASS' ? 'PASS' : 'PENDING_OR_FAIL'],
    ['unresolved_conflict', '0', '0', 'PENDING_LIVE_NO_ELIGIBLE_CAPTURE' ],
    ['failure_classification', (string)count($failures), (string)count($failures), $failures === [] ? 'PENDING_LIVE_NO_FAILURE_OBSERVED' : 'REQUIRES_CLASSIFICATION_REVIEW'],
]);
liveCsv(LIVE_EVAL_OUTPUT . '/official_cache_validation.csv', ['race_code', 'target_date', 'source_generated_at', 'source_identity', 'universe_recorded_at', 'freshness_and_identity'], array_map(static fn(array $candidate): array => [
    (string)($candidate['race_code'] ?? ''), (string)($candidate['target_date'] ?? ''), (string)($candidate['source_generated_at'] ?? ''), (string)($candidate['source_identity'] ?? ''), (string)($candidate['universe_recorded_at'] ?? ''),
    (liveBefore((string)($candidate['source_generated_at'] ?? ''), (string)($candidate['universe_recorded_at'] ?? '')) && str_starts_with((string)($candidate['source_identity'] ?? ''), 'sha256:')) ? 'PASS' : 'FAIL',
], $candidates));
$failureRows = array_map(static fn(array $row): array => [(string)($row['event'] ?? ''), (string)($row['error_type'] ?? ''), (string)($row['error_code'] ?? ''), (string)($row['race_code'] ?? ''), (string)($row['observed_at'] ?? $row['attempted_at'] ?? '')], array_merge($sourceFailures, $failures));
liveCsv(LIVE_EVAL_OUTPUT . '/live_validation_failures.csv', ['event', 'error_type', 'error_code', 'race_code', 'observed_at'], $failureRows);

$summary = "# Operational LIVE Readiness Validation\n\n"
    . "- Validation window: `{$manifest['validation_window_id']}`\n- Armed at: `{$armedAt}`\n- Window status: **{$windowStatus}**\n- First eligible race: `" . ($eligible[0] ?? 'none') . "`\n- Distinct eligible race codes: **{$distinctEligible} / 2**\n\n"
    . "## Outcome-blind scope\n\nNo race-result, payout, ROI, hit-rate, lift, performance comparison, or outcome join was read. Official Epoch remains **NO**.\n\n"
    . "## Gates\n\n- Gate A: **{$gateA}**\n- Gate B: **{$gateB}**\n- Gate C: **{$gateC}**\n\n"
    . "Operational Measurement Readiness is **PASS_STRUCTURAL**. Operational LIVE Readiness is **" . ($windowStatus === 'COMPLETE_PASS' ? 'PASS' : ($windowStatus === 'COMPLETE_FAIL' ? 'FAIL' : 'PENDING_LIVE')) . "**. Comparative Readiness remains **POLICY_LIMITED** (40 direct-comparative uses; 83 ML-only policy-limited uses). Historical `TMG 2C top3` lineage conflict remains retained.\n";
liveText(LIVE_EVAL_OUTPUT . '/operational_live_validation_summary.md', $summary);
$missing = "# LIVE Readiness Remaining Items\n\n"
    . "- Window is `{$windowStatus}`; it requires two distinct post-arm `ELIGIBLE_OBSERVED` race codes before completion.\n"
    . "- Accounting Scope includes Candidate opportunities with `cutoff_timestamp > armed_at`; formal eligibility, expected rows, and captures still require their own post-arm timestamp and cannot be promoted retroactively.\n"
    . "- LONG_TERM_INCIDENT_POLICY_REQUIRES_CG_DECISION = YES.\n"
    . "- Official Epoch remains unstarted regardless of this evaluator result.\n";
liveText(LIVE_EVAL_OUTPUT . '/live_readiness_missing_items.md', $missing);
echo json_encode(['status' => 'ok', 'validation_window_id' => $manifest['validation_window_id'], 'window_status' => $windowStatus, 'candidate_count' => $candidateCount, 'eligible_count' => $eligibleCount, 'gate_a' => $gateA, 'gate_b' => $gateB, 'gate_c' => $gateC, 'official_epoch_started' => 'NO'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
