<?php

declare(strict_types=1);

/**
 * Outcome-blind boundary audit. It leaves the armed manifest untouched and
 * creates only an amendment record when a pre-ARM candidate still had a
 * post-ARM official cutoff.
 */

const BOUNDARY_MANIFEST = __DIR__ . '/../var/strict_forward_live_readiness/validation_window_manifest.json';
const BOUNDARY_OUTPUT = __DIR__ . '/output/ml_forward_live_readiness';
const BOUNDARY_CANDIDATES = __DIR__ . '/../var/strict_forward_opportunity_denominator/candidates';

function boundaryBefore(string $left, string $right): bool
{
    try { return (new DateTimeImmutable($left)) < (new DateTimeImmutable($right)); }
    catch (Throwable) { return false; }
}
function boundaryAfter(string $left, string $right): bool
{
    try { return (new DateTimeImmutable($left)) > (new DateTimeImmutable($right)); }
    catch (Throwable) { return false; }
}
/** @param list<list<string>> $rows */
function boundaryCsv(string $path, array $header, array $rows): void
{
    $handle = fopen($path, 'wb');
    if (!is_resource($handle)) throw new RuntimeException('cannot_write_boundary_csv');
    fputcsv($handle, $header);
    foreach ($rows as $row) fputcsv($handle, $row);
    fclose($handle);
}

if (!is_file(BOUNDARY_MANIFEST)) throw new RuntimeException('armed_manifest_missing');
$manifest = json_decode((string)file_get_contents(BOUNDARY_MANIFEST), true);
if (!is_array($manifest)) throw new RuntimeException('armed_manifest_invalid');
$armedAt = (string)($manifest['armed_at'] ?? '');
$windowId = (string)($manifest['validation_window_id'] ?? '');
if ($armedAt === '' || $windowId === '') throw new RuntimeException('armed_identity_missing');
if (!is_dir(BOUNDARY_OUTPUT) && !mkdir(BOUNDARY_OUTPUT, 0775, true) && !is_dir(BOUNDARY_OUTPUT)) throw new RuntimeException('cannot_create_boundary_output');

$preRows = [];
$postCutoff = $preOrEqual = $invalid = 0;
$seen = [];
foreach (glob(BOUNDARY_CANDIDATES . '/*/*.json') ?: [] as $path) {
    $candidate = json_decode((string)file_get_contents($path), true);
    if (!is_array($candidate)) continue;
    $recorded = (string)($candidate['universe_recorded_at'] ?? '');
    if (!boundaryBefore($recorded, $armedAt)) continue;
    $cutoff = (string)($candidate['cutoff_timestamp'] ?? '');
    $cutoffAfter = false;
    if ($cutoff === '') {
        $invalid++;
    } else {
        try {
            $cutoffAfter = (new DateTimeImmutable($cutoff)) > (new DateTimeImmutable($armedAt));
            if ($cutoffAfter) $postCutoff++;
            else $preOrEqual++;
        } catch (Throwable) {
            $invalid++;
        }
    }
    $raceCode = (string)($candidate['race_code'] ?? '');
    $seen[$raceCode] = ($seen[$raceCode] ?? 0) + 1;
    $preRows[] = [
        (string)($candidate['stable_candidate_key'] ?? 'scheduler-opportunity|' . $raceCode), $raceCode, $recorded,
        $cutoff, $cutoff === '' ? 'INVALID' : ($cutoffAfter ? 'YES' : 'NO'), (string)($candidate['venue'] ?? ''),
    ];
}
usort($preRows, static fn(array $a, array $b): int => strcmp($a[1], $b[1]));
$duplicates = array_filter($seen, static fn(int $count): bool => $count > 1);
boundaryCsv(BOUNDARY_OUTPUT . '/pre_arm_candidate_boundary_check.csv', ['scheduler_candidate_key', 'race_code', 'candidate_recorded_at', 'cutoff_timestamp', 'cutoff_after_armed_at', 'venue'], $preRows);

$case = $postCutoff > 0 ? 'CASE_B_BOUNDARY_GAP' : 'CASE_A_NO_OPPORTUNITY_GAP';
$audit = "# Pre-ARM Candidate Boundary Audit\n\n"
    . "- Window ID: `{$windowId}`\n- Armed at: `{$armedAt}`\n- Manifest was read only; re-arm: **NO**\n- Case: **{$case}**\n\n"
    . "## Counts\n\n"
    . "- PRE_ARM_CANDIDATE_COUNT: **" . count($preRows) . "**\n- PRE_ARM_WITH_POST_ARM_CUTOFF_COUNT: **{$postCutoff}**\n- PRE_ARM_WITH_PRE_OR_EQUAL_ARM_CUTOFF_COUNT: **{$preOrEqual}**\n- INVALID_OR_MISSING_CUTOFF_COUNT: **{$invalid}**\n- Duplicate race_code count: **" . count($duplicates) . "**\n\n"
    . ($postCutoff > 0
        ? "A boundary gap exists in the original `universe_recorded_at > armed_at` Accounting Scope: the listed Candidate opportunities still existed after ARM because their official cutoff was later. Candidate provenance may be included by cutoff boundary, but only post-ARM eligibility/expected/canonical/failure observations may become Formal Validation Evidence.\n"
        : "No pre-ARM Candidate had a post-ARM cutoff. The original Accounting Scope has no observed opportunity gap and remains unchanged.\n");
file_put_contents(BOUNDARY_OUTPUT . '/pre_arm_candidate_boundary_audit.md', $audit);

if ($postCutoff > 0) {
    $amendedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM);
    $amendment = "# Validation Scope Amendment\n\n"
        . "- amendment_timestamp: `{$amendedAt}`\n- validation_window_id: `{$windowId}` (unchanged)\n- armed_at: `{$armedAt}` (unchanged)\n- reason: **{$postCutoff}** pre-ARM Candidate records had `cutoff_timestamp > armed_at`; the race opportunity still existed after ARM.\n\n"
        . "## Old Accounting Scope\n\n`candidate.universe_recorded_at > armed_at`\n\n"
        . "## New Accounting Scope\n\n`candidate.cutoff_timestamp > armed_at`\n\n"
        . "Gate A and Gate B use this complete opportunity universe. Gate C is unchanged: only races with post-ARM `ELIGIBLE_OBSERVED` Formal Validation Evidence enter its denominator, and the two-distinct-race requirement remains.\n\n"
        . "## Evidence boundary\n\nCandidate provenance from before ARM is permitted only to establish the post-ARM opportunity universe. Formal Validation Evidence must itself be timestamped after ARM: eligibility observation/resolution, Strict expected, canonical LIVE_STRICT, and failure audit. ARM-before evidence is not promoted. A candidate whose post-ARM eligibility cannot be safely established remains `ELIGIBILITY_UNRESOLVED`, never `INELIGIBLE`.\n\n"
        . "No result, payout, ROI, hit-rate, lift, performance data, or outcome join was inspected.\n";
    file_put_contents(BOUNDARY_OUTPUT . '/validation_scope_amendment.md', $amendment);
}

echo json_encode([
    'status' => 'ok', 'case' => $case, 'validation_window_id' => $windowId, 'armed_at' => $armedAt,
    'pre_arm_candidate_count' => count($preRows), 'post_arm_cutoff_count' => $postCutoff,
    'pre_or_equal_cutoff_count' => $preOrEqual, 'invalid_cutoff_count' => $invalid,
    'duplicate_race_code_count' => count($duplicates), 'rearmed' => 'NO',
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
