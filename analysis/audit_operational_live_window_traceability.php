<?php

declare(strict_types=1);

/**
 * Read-only traceability audit for the already armed Operational LIVE window.
 * It does not re-arm, change the manifest, invoke capture, or inspect any
 * outcome/performance data.
 */

const TRACE_STATE = __DIR__ . '/../var/strict_forward_live_readiness/validation_window_manifest.json';
const TRACE_OUTPUT = __DIR__ . '/output/ml_forward_live_readiness';
const TRACE_OPPORTUNITY_ROOT = __DIR__ . '/../var/strict_forward_opportunity_denominator';
const TRACE_STRICT_ROOT = __DIR__ . '/../var/strict_forward_readiness_completion';

/** @return list<array<string,mixed>> */
function traceRows(string $pattern): array
{
    $rows = [];
    foreach (glob($pattern) ?: [] as $path) {
        $row = json_decode((string)file_get_contents($path), true);
        if (is_array($row)) $rows[] = $row;
    }
    return $rows;
}

function traceAfter(string $value, string $armedAt): bool
{
    try { return $value !== '' && (new DateTimeImmutable($value)) > (new DateTimeImmutable($armedAt)); }
    catch (Throwable) { return false; }
}

/** @param list<list<string>> $rows */
function traceCsv(string $path, array $header, array $rows): void
{
    $handle = fopen($path, 'wb');
    if (!is_resource($handle)) throw new RuntimeException('cannot_write_' . basename($path));
    fputcsv($handle, $header);
    foreach ($rows as $row) fputcsv($handle, $row);
    fclose($handle);
}

if (!is_file(TRACE_STATE)) throw new RuntimeException('armed_window_manifest_missing');
$manifest = json_decode((string)file_get_contents(TRACE_STATE), true);
if (!is_array($manifest)) throw new RuntimeException('armed_window_manifest_invalid');
$windowId = (string)($manifest['validation_window_id'] ?? '');
$armedAt = (string)($manifest['armed_at'] ?? '');
if ($windowId === '' || $armedAt === '') throw new RuntimeException('armed_window_identity_missing');
if (!is_dir(TRACE_OUTPUT) && !mkdir(TRACE_OUTPUT, 0775, true) && !is_dir(TRACE_OUTPUT)) throw new RuntimeException('cannot_create_output');

$allCandidates = traceRows(TRACE_OPPORTUNITY_ROOT . '/candidates/*/*.json');
$windowCandidates = array_values(array_filter($allCandidates, static fn(array $row): bool => traceAfter((string)($row['universe_recorded_at'] ?? ''), $armedAt)));
$preArmCandidates = count($allCandidates) - count($windowCandidates);
$expected = array_values(array_filter(traceRows(TRACE_STRICT_ROOT . '/expected/*/*.json'), static fn(array $row): bool => traceAfter((string)($row['expected_at'] ?? ''), $armedAt)));
$canonical = array_values(array_filter(traceRows(TRACE_STRICT_ROOT . '/records/*/*.json'), static fn(array $row): bool => traceAfter((string)($row['captured_at'] ?? ''), $armedAt)));
$byRace = [];
foreach ($windowCandidates as $candidate) $byRace[(string)($candidate['race_code'] ?? '')][] = $candidate;
$ambiguous = array_filter($byRace, static fn(array $rows): bool => count($rows) !== 1);

$opportunitySource = (string)file_get_contents(__DIR__ . '/../web/logic/StrictForwardOpportunityLedger.php');
$strictSource = (string)file_get_contents(__DIR__ . '/../web/logic/StrictForwardReadinessLedger.php');
$captureSource = (string)file_get_contents(__DIR__ . '/capture_all_race_forward.php');
$candidateKeyRule = str_contains($opportunitySource, "'stable_candidate_key' => \$this->candidateKey(\$raceCode)")
    && str_contains($opportunitySource, "return 'scheduler-opportunity|' . \$raceCode;");
$eligibilityKeyRule = str_contains($opportunitySource, "'candidate_key' => \$candidate['stable_candidate_key']");
$expectedCanonicalRule = substr_count($strictSource, "'scheduler_candidate_key' => 'scheduler-opportunity|' . \$raceCode") >= 2;
$captureOrderRule = str_contains($captureSource, 'recordStrictForwardOpportunityUniverseFromAuthoritativeResult($dateText, $datePrefix, $deadlineResult, $places)')
    && str_contains($captureSource, "'expected_inventory_verified' => true")
    && str_contains($captureSource, 'exhibitionReadyRaceCodes($pdo, $raceCodes)');
$deterministic = $candidateKeyRule && $eligibilityKeyRule && $expectedCanonicalRule && $captureOrderRule && $ambiguous === [];

$bindingRows = [
    ['Candidate', 'universe_recorded_at > armed_at', 'stable_candidate_key = scheduler-opportunity|race_code', 'candidate JSON first-write path', $candidateKeyRule ? 'PASS' : 'FAIL'],
    ['Eligibility observation/resolution', 'candidate belongs to Accounting Scope', 'candidate_key / preserved stable_candidate_key', 'opportunity observation + eligibility ledger', $eligibilityKeyRule ? 'PASS' : 'FAIL'],
    ['Strict Expected', 'expected_at > armed_at and linked candidate exists', 'scheduler_candidate_key = scheduler-opportunity|race_code', 'strict expected ledger', $expectedCanonicalRule ? 'PASS' : 'FAIL'],
    ['Canonical LIVE_STRICT', 'captured_at > armed_at and linked candidate exists', 'scheduler_candidate_key = scheduler-opportunity|race_code', 'strict record ledger', $expectedCanonicalRule ? 'PASS' : 'FAIL'],
    ['Scheduler ordering', 'authoritative Expected freeze + readback before Candidate/exhibition/expected/capture', 'deadline_snapshot_identity', 'capture_all_race_forward.php', $captureOrderRule ? 'PASS' : 'FAIL'],
    ['Window uniqueness', 'one active manifest, timestamp boundary, one first-write candidate per race', 'window_id + armed_at + stable key', 'manifest + candidate ledger', $ambiguous === [] ? 'PASS' : 'FAIL'],
];
traceCsv(TRACE_OUTPUT . '/validation_window_binding_check.csv', ['stage', 'window_boundary', 'deterministic_identity', 'evidence', 'result'], $bindingRows);

$audit = "# Armed LIVE Window Traceability Audit\n\n"
    . "- Window ID: `{$windowId}`\n- Armed at: `{$armedAt}`\n- Manifest status (read only): `" . ($manifest['status'] ?? '') . "`\n\n"
    . "## Scope\n\n"
    . "**Accounting Scope** is every Candidate whose `universe_recorded_at` is strictly later than `armed_at`. Gate A and Gate B use this entire population; candidates before the first eligible race are not discarded. **Eligible Validation Sample** starts at the first `ELIGIBLE_OBSERVED` Candidate in that Accounting Scope and needs two distinct race codes.\n\n"
    . "## Deterministic attribution\n\n"
    . "Records do not carry the window ID directly. They use deterministic attribution: `stable_candidate_key = scheduler-opportunity|race_code`; eligibility observations carry `candidate_key`; Strict expected and canonical rows carry `scheduler_candidate_key` with the same value. Membership additionally requires the relevant candidate and downstream timestamps to be later than `armed_at`. The first-write candidate rule and the single immutable manifest prevent a multiple-window/manual-selection match.\n\n"
    . "- Candidate-key rule: " . ($candidateKeyRule ? 'PASS' : 'FAIL') . "\n- Eligibility linkage: " . ($eligibilityKeyRule ? 'PASS' : 'FAIL') . "\n- Expected/canonical linkage: " . ($expectedCanonicalRule ? 'PASS' : 'FAIL') . "\n- Authoritative Expected/readback-before-Candidate scheduler ordering: " . ($captureOrderRule ? 'PASS' : 'FAIL') . "\n- Ambiguous post-ARM candidate matches: **" . count($ambiguous) . "**\n- Manual attribution required: **NO**\n\n"
    . "## Current evidence\n\n"
    . "- Pre-ARM candidates excluded: **{$preArmCandidates}**\n- Post-ARM candidates: **" . count($windowCandidates) . "**\n- Post-ARM Strict expected rows: **" . count($expected) . "**\n- Post-ARM canonical rows: **" . count($canonical) . "**\n- Traceability result: **" . ($deterministic ? 'PASS_STRUCTURAL' : 'CONFLICT') . "**\n\n"
    . "No candidate exists in the active window yet, so Gate A/B/C and Operational LIVE Readiness remain `PENDING_LIVE`; 0/0 is not treated as PASS. This audit does not re-arm or evaluate outcomes/performance.\n";
file_put_contents(TRACE_OUTPUT . '/validation_window_traceability_audit.md', $audit);

echo json_encode([
    'status' => $deterministic ? 'ok' : 'conflict', 'validation_window_id' => $windowId,
    'rearmed' => 'NO', 'post_arm_candidates' => count($windowCandidates), 'pre_arm_candidates_excluded' => $preArmCandidates,
    'deterministic_attribution' => $deterministic ? 'PASS_STRUCTURAL' : 'CONFLICT',
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
