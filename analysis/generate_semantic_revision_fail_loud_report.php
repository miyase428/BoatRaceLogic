<?php

declare(strict_types=1);

/**
 * Outcome-blind, read-only audit of semantic schedule revision fail-loud
 * behavior. It only reads existing ledgers/manifest and executes the isolated
 * temporary-directory fixture suite. It never captures predictions, mutates a
 * validation window, opens the database, or fetches an external source.
 */

require_once __DIR__ . '/../web/logic/StrictForwardOpportunityLedger.php';

const SRFL_OUTPUT = __DIR__ . '/output/ml_forward_live_readiness';
const SRFL_MANIFEST = __DIR__ . '/../var/strict_forward_live_readiness/validation_window_manifest.json';
const SRFL_ROOT = __DIR__ . '/../var/strict_forward_opportunity_denominator';

/** @return list<array<string,mixed>> */
function srflJsonl(string $pattern): array
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

/** @return list<array<string,mixed>> */
function srflJson(string $pattern): array
{
    $rows = [];
    foreach (glob($pattern) ?: [] as $path) {
        $row = json_decode((string)file_get_contents($path), true);
        if (is_array($row)) $rows[] = $row;
    }
    return $rows;
}

function srflAfter(string $left, string $right): bool
{
    try { return (new DateTimeImmutable($left)) > (new DateTimeImmutable($right)); }
    catch (Throwable) { return false; }
}

/** @param list<list<string>> $rows */
function srflCsv(string $path, array $header, array $rows): void
{
    $handle = fopen($path, 'wb');
    if (!is_resource($handle)) throw new RuntimeException('cannot_write_csv');
    fputcsv($handle, $header);
    foreach ($rows as $row) fputcsv($handle, $row);
    fclose($handle);
}

function srflWrite(string $path, string $content): void
{
    if (file_put_contents($path, $content) === false) throw new RuntimeException('cannot_write_report');
}

if (!is_file(SRFL_MANIFEST)) throw new RuntimeException('armed_manifest_missing');
$manifest = json_decode((string)file_get_contents(SRFL_MANIFEST), true);
if (!is_array($manifest)) throw new RuntimeException('armed_manifest_invalid');
$windowId = (string)($manifest['validation_window_id'] ?? '');
$armedAt = (string)($manifest['armed_at'] ?? '');
if ($windowId === '' || $armedAt === '') throw new RuntimeException('armed_window_identity_missing');
if (!is_dir(SRFL_OUTPUT) && !mkdir(SRFL_OUTPUT, 0775, true) && !is_dir(SRFL_OUTPUT)) throw new RuntimeException('cannot_create_output');

$candidates = array_values(array_filter(
    srflJson(SRFL_ROOT . '/candidates/*/*.json'),
    static fn(array $row): bool => srflAfter((string)($row['cutoff_timestamp'] ?? ''), $armedAt)
));
$revisions = srflJsonl(SRFL_ROOT . '/schedule_revisions/*/*.jsonl');
$sourceFailures = array_values(array_filter(
    srflJsonl(SRFL_ROOT . '/source_failures/*.jsonl'),
    static fn(array $row): bool => srflAfter((string)($row['observed_at'] ?? ''), $armedAt)
));
$typeCounts = [];
foreach ($revisions as $revision) {
    $type = (string)($revision['revision_type'] ?? 'UNCLASSIFIED');
    $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
}
$unverifiedCount = $typeCounts['SEMANTIC_REVISION_UNVERIFIED'] ?? 0;

$ledger = StrictForwardOpportunityLedger::production();
$integrityPass = 0;
$integrityBlocked = 0;
foreach ($candidates as $candidate) {
    $effective = $ledger->resolveEffectiveSchedule((string)($candidate['target_date'] ?? ''), (string)($candidate['race_code'] ?? ''));
    if (($effective['schedule_revision_integrity'] ?? 'PASS') === 'PASS') $integrityPass++;
    else $integrityBlocked++;
}
$a1 = count($candidates) === 84 ? 'PASS' : 'CONFLICT';
$a2 = $integrityBlocked === 0 && $unverifiedCount === 0 && $sourceFailures === [] ? 'PASS' : 'BLOCKED_FAIL_LOUD';
$continuable = $a1 === 'PASS' && $a2 === 'PASS' ? 'YES' : 'UNKNOWN';

// The fixture suite writes only randomly-named directories under /tmp.
$fixtureOutput = shell_exec(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/test_strict_forward_opportunity_denominator.php'));
$fixtureJson = null;
foreach (array_reverse(preg_split('/\R/', trim((string)$fixtureOutput)) ?: []) as $line) {
    $decoded = json_decode($line, true);
    if (is_array($decoded) && ($decoded['status'] ?? '') === 'ok') { $fixtureJson = $decoded; break; }
}
if (!is_array($fixtureJson)) throw new RuntimeException('fixture_suite_did_not_return_success_json');
$fixtureRows = [];
foreach ((array)($fixtureJson['fixtures'] ?? []) as $fixture) {
    if (!is_array($fixture) || count($fixture) < 2) continue;
    $fixtureRows[] = [(string)$fixture[0], (string)$fixture[1], 'isolated /tmp fixture; no production ledger write'];
}
srflCsv(SRFL_OUTPUT . '/semantic_revision_fail_loud_fixture_results.csv', ['fixture','result','execution_scope'], $fixtureRows);

$contract = "# Semantic Revision Fail-Loud Contract\n\n"
    . "## Provenance\n\n"
    . "- `source_generated_at` is the local official-cutoff cache generated timestamp. `OfficialDeadlineLogic` assigns PHP `date('c')` to cache `fetched_at`; the capture instrumentation copies that value to `source_generated_at`.\n"
    . "- It is **not** an official source revision timestamp and does not prove official revision chronology.\n"
    . "- `source_generated_at`, `observed_at`, then `source_sequence` provide **deterministic local observation ordering** only.\n\n"
    . "## Resolver rule\n\n"
    . "1. The first Candidate remains immutable.\n"
    . "2. A same-semantic observation is `SOURCE_REFRESH_NO_SEMANTIC_CHANGE`; raw-cache hash or local retrieval-time changes alone do not block A2.\n"
    . "3. A changed semantic fingerprint without independent official chronology evidence is `SEMANTIC_REVISION_UNVERIFIED`. It is retained in the append-only ledger, is not adopted into Current Effective Schedule, and makes measurement/audit `BLOCKED_SEMANTIC_REVISION_UNVERIFIED`.\n"
    . "4. A verified revision requires `evidence_source`, `evidence_identity`, the preceding semantic fingerprint, and a demonstrable official sequence: increasing official revision timestamps or increasing numeric official revisions. Only then may the resolver classify it as `CUTOFF_CHANGED`, `OFFICIAL_CANCELLED`, or `OFFICIAL_EXCLUDED`.\n"
    . "5. `STALE_OR_OUT_OF_ORDER_SOURCE` remains a local replay protection and cannot overwrite effective state.\n\n"
    . "No current producer supplies verified official chronology evidence; therefore this is intentionally fail-loud for a future semantic change, while production prediction remains fail-open.\n";
srflWrite(SRFL_OUTPUT . '/semantic_revision_fail_loud_contract.md', $contract);

$acceptance = "# Gate C Comparative Acceptance Contract\n\n"
    . "Gate C is not executed by this hardening. A future runtime evaluation may proceed only when all of the following hold:\n\n"
    . "- At least **2 distinct eligible race_codes** have a cutoff-before formal capture.\n"
    . "- Each actual eligible decision within the fixed 40-use Official Traditional Comparison Scope has the ML and Traditional decision attached to the same eligible race.\n"
    . "- Both decisions use the same cutoff contract and input universe.\n"
    . "- `comparison_decision_missing = 0`, `policy_identity_missing = 0`, and `unclassified comparison use = 0` for the decisions that actually occurred.\n"
    . "- No `SEMANTIC_REVISION_UNVERIFIED`, source-unavailable, or other unresolved schedule-integrity condition affects the evaluated races.\n\n"
    . "The 40 uses define policy scope. Gate C does **not** require every one of the 40 uses to occur within two races; it requires complete capture for whichever in-scope decisions actually occur.\n";
srflWrite(SRFL_OUTPUT . '/gate_c_comparative_acceptance_contract.md', $acceptance);

$typeLines = [];
ksort($typeCounts);
foreach ($typeCounts as $type => $count) $typeLines[] = "- {$type}: **{$count}**";
$summary = "# Semantic Revision Fail-Loud Hardening\n\n"
    . "- validation_window_id: `{$windowId}`\n- armed_at: `{$armedAt}`\n"
    . "- Candidate scope: **" . count($candidates) . "** (expected amended scope: 84)\n"
    . "- semantic ledger event count: **" . count($revisions) . "**\n"
    . "- `SEMANTIC_REVISION_UNVERIFIED`: **{$unverifiedCount}**\n"
    . "- effective integrity PASS / BLOCKED: **{$integrityPass} / {$integrityBlocked}**\n"
    . "- post-ARM source-unavailable records: **" . count($sourceFailures) . "**\n\n"
    . "## Revision types\n\n" . implode("\n", $typeLines) . "\n\n"
    . "## Gate state after hardening\n\n"
    . "- A1 Candidate accounting: **{$a1}**\n"
    . "- A2 Schedule revision integrity: **{$a2}**\n"
    . "- CURRENT_WINDOW_CONTINUABLE: **{$continuable}**\n"
    . "- Operational Live Readiness: **PENDING_LIVE** (preserved; Gate C was not executed)\n"
    . "- Global Comparative Readiness: **POLICY_LIMITED**\n"
    . "- Official Traditional Comparison Scope: **40 uses / SCOPE_DEFINED**\n"
    . "- Scoped Comparative Runtime Readiness: **RUNTIME_VERIFICATION_REQUIRED**\n"
    . "- Official Epoch Recommendation: **NO**\n\n"
    . "The current Window was not re-armed or invalidated. No outcome/result/payout/ROI/performance data was inspected or joined. No prediction, model, feature, threshold, coverage, database, cron, commit, or push change occurred.\n";
srflWrite(SRFL_OUTPUT . '/semantic_revision_fail_loud_summary.md', $summary);

$missing = "# Semantic Revision Fail-Loud Missing Items\n\n"
    . "- A verified official revision pathway has a strict contract but no current producer: the official cutoff cache exposes local fetch/cache timestamps, not an official revision timestamp or version.\n"
    . "- If a future semantic change arrives without independent official chronology evidence, A2 intentionally blocks fail-loud while Current Effective Schedule retains the last safe state.\n"
    . "- Gate C runtime evidence remains uncollected by design; this task did not create a Window, re-ARM, capture predictions, or execute comparison runtime.\n"
    . "- Official Epoch remains unstarted and not recommended.\n";
srflWrite(SRFL_OUTPUT . '/semantic_revision_fail_loud_missing_items.md', $missing);

echo json_encode([
    'status' => 'ok', 'window_id' => $windowId, 'a1' => $a1, 'a2' => $a2,
    'unverified_semantic_revisions' => $unverifiedCount,
    'current_window_continuable' => $continuable,
    'fixture_count' => count($fixtureRows), 'official_epoch_recommendation' => 'NO',
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
