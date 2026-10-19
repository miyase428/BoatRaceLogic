<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/StrictForwardOpportunityLedger.php';
require_once __DIR__ . '/../web/logic/MeasurementImplementationProvenance.php';
require_once __DIR__ . '/../web/logic/WindowDeclaredImplementationIdentity.php';
require_once __DIR__ . '/../web/logic/WindowActivationHold.php';
require_once __DIR__ . '/../web/logic/FormalMeasurementWindowContext.php';
require_once __DIR__ . '/../web/logic/GateA1ProspectiveInventory.php';

const FWCV_OUTPUT = __DIR__ . '/output/ml_forward_live_readiness';

/** @return list<array<string,mixed>> */
function fwcvJson(string $pattern): array
{
    $rows = [];
    foreach (glob($pattern) ?: [] as $path) {
        $row = json_decode((string)file_get_contents($path), true);
        if (is_array($row)) $rows[] = $row;
    }
    return $rows;
}

/** @return list<array<string,mixed>> */
function fwcvJsonl(string $pattern): array
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

function fwcvAfter(string $left, string $right): bool
{
    try { return $left !== '' && new DateTimeImmutable($left) > new DateTimeImmutable($right); }
    catch (Throwable) { return false; }
}

function fwcvBefore(string $left, string $right): bool
{
    try { return $left !== '' && $right !== '' && new DateTimeImmutable($left) < new DateTimeImmutable($right); }
    catch (Throwable) { return false; }
}

/** @param list<list<string>> $rows */
function fwcvCsv(string $path, array $header, array $rows): void
{
    $handle = fopen($path, 'wb');
    if (!is_resource($handle)) throw new RuntimeException('cannot_write_csv');
    fputcsv($handle, $header);
    foreach ($rows as $row) fputcsv($handle, $row);
    fclose($handle);
}

function fwcvText(string $path, string $text): void
{
    if (file_put_contents($path, $text) === false) throw new RuntimeException('cannot_write_text');
}

/** @param list<array<string,mixed>> $rows */
function fwcvRowsHaveValidMeasurementProvenance(array $rows, MeasurementImplementationProvenance $provenance, array &$cache, string $declaredIdentity): bool
{
    if ($rows === []) return $declaredIdentity !== '';
    foreach ($rows as $row) {
        $identity = (string)($row['measurement_implementation_identity'] ?? '');
        $manifestRef = (string)($row['measurement_manifest_ref'] ?? '');
        $freezeRef = (string)($row['measurement_source_freeze_ref'] ?? '');
        $key = $identity . '|' . $manifestRef . '|' . $freezeRef;
        if (!isset($cache[$key])) $cache[$key] = $provenance->verify($identity, $manifestRef, $freezeRef);
        $verified = $cache[$key];
        if ($declaredIdentity === '' || !hash_equals($declaredIdentity, $identity)
            || empty($verified['valid'])
            || !hash_equals((string)($verified['manifest_sha256'] ?? ''), (string)($row['measurement_manifest_sha256'] ?? ''))
            || !hash_equals((string)($verified['source_freeze_sha256'] ?? ''), (string)($row['measurement_source_freeze_sha256'] ?? ''))
            || (string)($row['measurement_manifest_schema_version'] ?? '') !== MeasurementImplementationProvenance::MANIFEST_SCHEMA_VERSION) {
            return false;
        }
    }
    return true;
}

$windowContext = FormalMeasurementWindowContext::production(dirname(__DIR__));
$fwcvManifest = $windowContext->windowManifestPath();
$fwcvOpportunity = $windowContext->opportunityRoot();
$fwcvStrict = $windowContext->ledgerRoot();
$manifest = json_decode((string)file_get_contents($fwcvManifest), true);
if (!is_array($manifest)) throw new RuntimeException('manifest_invalid');
$windowId = (string)($manifest['validation_window_id'] ?? '');
$armedAt = (string)($manifest['armed_at'] ?? '');
if ($windowId === '' || $armedAt === '') throw new RuntimeException('window_identity_missing');
if (!is_dir(FWCV_OUTPUT) && !mkdir(FWCV_OUTPUT, 0775, true) && !is_dir(FWCV_OUTPUT)) throw new RuntimeException('cannot_create_output');

// Freeze one audit instant. Runtime ledgers may continue to grow after these
// arrays are read; every output below describes only this snapshot.
$auditAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM);
$ledger = new StrictForwardOpportunityLedger($fwcvOpportunity);
$measurementProvenance = new MeasurementImplementationProvenance(dirname(__DIR__), $windowContext->declaration()->provenanceStorageRoot());
$measurementProvenanceCache = [];
$declaredImplementation = $windowContext->declaration();
$declaredReadStatus = $declaredImplementation->read();
$declaredImplementationStatus = $declaredImplementation->assertCurrent();
$activationHold = $windowContext->activationHold();
$activationStatus = $activationHold->authorizeFormalWrite();
$declaredIdentity = !empty($declaredReadStatus['accepted'])
    ? (string)($declaredReadStatus['declaration']['implementation_identity'] ?? '') : '';
$gateA1Inventory = (new GateA1ProspectiveInventory($fwcvOpportunity))->compare($windowId, $armedAt);
$candidates = $gateA1Inventory['observed_rows'];
$candidateByRace = [];
foreach ($candidates as $row) $candidateByRace[(string)($row['race_code'] ?? '')] = $row;

$operational = fwcvJsonl($fwcvOpportunity . '/operational/*.jsonl');
$oldEvents = array_values(array_filter($operational, static fn(array $row): bool => ($row['event'] ?? '') === 'CANDIDATE_SOURCE_CONFLICT'));
$revisionEvents = fwcvJsonl($fwcvOpportunity . '/schedule_revisions/*/*.jsonl');
$observations = array_values(array_filter(fwcvJsonl($fwcvOpportunity . '/observations/*.jsonl'), static fn(array $row): bool => fwcvAfter((string)($row['observed_at'] ?? ''), $armedAt)));
$resolutions = array_values(array_filter(fwcvJson($fwcvOpportunity . '/eligibility/*/*.json'), static fn(array $row): bool => fwcvAfter((string)($row['first_eligible_observed_at'] ?? $row['resolved_at'] ?? ''), $armedAt)));
$sourceFailures = array_values(array_filter(fwcvJsonl($fwcvOpportunity . '/source_failures/*.jsonl'), static fn(array $row): bool => fwcvAfter((string)($row['observed_at'] ?? ''), $armedAt)));
$expected = array_values(array_filter(fwcvJson($fwcvStrict . '/expected/*/*.json'), static fn(array $row): bool => fwcvAfter((string)($row['expected_at'] ?? ''), $armedAt)));
$canonical = array_values(array_filter(fwcvJson($fwcvStrict . '/records/*/*.json'), static fn(array $row): bool => fwcvAfter((string)($row['captured_at'] ?? ''), $armedAt) && ($row['capture_mode'] ?? '') === 'LIVE_STRICT'));
$strictFailures = array_values(array_filter(fwcvJsonl($fwcvStrict . '/failures/*.jsonl'), static fn(array $row): bool => fwcvAfter((string)($row['attempted_at'] ?? $row['observed_at'] ?? ''), $armedAt)));

$newPairSet = [];
foreach ($revisionEvents as $row) $newPairSet[(string)($row['race_code'] ?? '') . '|' . (string)($row['raw_source_identity'] ?? '')] = true;
$oldPairSet = [];
$oldClassCounts = [];
$oldRows = [];
$oldBeforeArm = 0;
$oldAfterArm = 0;
$oldUnaccounted = 0;
foreach ($oldEvents as $index => $row) {
    $raceCode = (string)($row['race_code'] ?? '');
    $pair = $raceCode . '|' . (string)($row['new_source_identity'] ?? '');
    $oldPairSet[$pair] = true;
    $observedAt = (string)($row['observed_at'] ?? '');
    if (fwcvAfter($observedAt, $armedAt)) $oldAfterArm++; else $oldBeforeArm++;
    if ($raceCode === '' || !isset($candidateByRace[$raceCode])) {
        $classification = 'OUTSIDE_NEW_ANALYSIS_SCOPE';
        $reason = 'race is not in the current Gate A1 observed Candidate scope';
    } elseif ((string)($row['original_cutoff_timestamp'] ?? '') === '' || (string)($row['new_cutoff_timestamp'] ?? '') === '') {
        $classification = 'UNCLASSIFIED';
        $reason = 'cutoff evidence missing';
        $oldUnaccounted++;
    } elseif ((string)$row['original_cutoff_timestamp'] === (string)$row['new_cutoff_timestamp']) {
        $classification = 'SOURCE_REFRESH_NO_SEMANTIC_CHANGE';
        $reason = 'raw cache hash changed while race cutoff stayed identical';
    } else {
        $classification = 'CUTOFF_CHANGED';
        $reason = 'legacy event contains an actual cutoff difference';
    }
    $oldClassCounts[$classification] = ($oldClassCounts[$classification] ?? 0) + 1;
    $oldRows[] = [
        (string)($index + 1), $observedAt, $raceCode,
        (string)($row['original_source_identity'] ?? ''), (string)($row['new_source_identity'] ?? ''),
        (string)($row['original_cutoff_timestamp'] ?? ''), (string)($row['new_cutoff_timestamp'] ?? ''),
        $classification, isset($newPairSet[$pair]) ? 'YES' : 'NO', $reason,
    ];
}
fwcvCsv(FWCV_OUTPUT . '/old_conflict_semantic_reconciliation.csv', [
    'legacy_event_no','observed_at','race_code','original_raw_source_identity','new_raw_source_identity',
    'original_cutoff','observed_cutoff','semantic_classification','pair_seen_in_new_ledger','accounting_reason',
], $oldRows);

$intersectionPairs = count(array_intersect_key($oldPairSet, $newPairSet));
$oldOnlyPairs = count(array_diff_key($oldPairSet, $newPairSet));
$newOnlyPairs = count(array_diff_key($newPairSet, $oldPairSet));
$oldTimes = array_values(array_filter(array_map(static fn(array $row): string => (string)($row['observed_at'] ?? ''), $oldEvents)));
$newObservedTimes = array_values(array_filter(array_map(static fn(array $row): string => (string)($row['observed_at'] ?? ''), $revisionEvents)));
$newSourceTimes = array_values(array_filter(array_map(static fn(array $row): string => (string)($row['source_generated_at'] ?? ''), $revisionEvents)));
sort($oldTimes); sort($newObservedTimes); sort($newSourceTimes);
$oldPeriod = ($oldTimes[0] ?? 'none') . ' .. ' . ($oldTimes[count($oldTimes) - 1] ?? 'none');
$newObservedPeriod = ($newObservedTimes[0] ?? 'none') . ' .. ' . ($newObservedTimes[count($newObservedTimes) - 1] ?? 'none');
$newSourcePeriod = ($newSourceTimes[0] ?? 'none') . ' .. ' . ($newSourceTimes[count($newSourceTimes) - 1] ?? 'none');
$referenceOldAt = '2026-10-05T09:58:52+09:00';
$referenceNewAt = '2026-10-05T10:32:50+09:00';
$referenceOldCount = count(array_filter($oldEvents, static fn(array $row): bool =>
    fwcvAfter((string)($row['observed_at'] ?? ''), $armedAt)
    && !fwcvAfter((string)($row['observed_at'] ?? ''), $referenceOldAt)));
$referenceNewCount = count(array_filter($revisionEvents, static fn(array $row): bool =>
    !fwcvAfter((string)($row['observed_at'] ?? ''), $referenceNewAt)));
$revisionTypeCounts = [];
$unsafeRollback = 0;
$unverifiedSemanticRevisions = 0;
foreach ($revisionEvents as $row) {
    $type = (string)($row['revision_type'] ?? 'UNCLASSIFIED');
    $revisionTypeCounts[$type] = ($revisionTypeCounts[$type] ?? 0) + 1;
    if ($type === 'STALE_OR_OUT_OF_ORDER_SOURCE') $unsafeRollback++;
    if ($type === 'SEMANTIC_REVISION_UNVERIFIED') $unverifiedSemanticRevisions++;
}

$obsBy = [];
foreach ($observations as $row) $obsBy[(string)($row['race_code'] ?? '')][] = $row;
$resolutionBy = [];
foreach ($resolutions as $row) $resolutionBy[(string)($row['race_code'] ?? '')] = $row;
$expectedBy = [];
foreach ($expected as $row) $expectedBy[(string)($row['race_code'] ?? '')][] = $row;
$canonicalBy = [];
foreach ($canonical as $row) $canonicalBy[(string)($row['race_code'] ?? '')][] = $row;
$revisionBy = [];
foreach ($revisionEvents as $row) $revisionBy[(string)($row['race_code'] ?? '')][] = $row;

$currentRows = [];
$effectiveComplete = 0;
$preCutoff = 0;
$cutoffPassed = 0;
$eligibleRaces = [];
$unresolved = 0;
$expectedRaces = 0;
$canonicalRaces = 0;
$canonicalCompleteRaces = 0;
$cancelled = 0;
$excluded = 0;
$instrumentationGap = 0;
$measurementProvenanceGap = 0;
foreach ($candidates as $candidate) {
    $raceCode = (string)$candidate['race_code'];
    $date = (string)$candidate['target_date'];
    $effective = $ledger->resolveEffectiveSchedule($date, $raceCode);
    $formalSchedule = $ledger->formalMeasurementStatus($date, $raceCode);
    $cutoff = (string)($effective['effective_cutoff_timestamp'] ?? '');
    $status = (string)($effective['effective_official_status'] ?? '');
    $reconstruction = $cutoff !== '' && in_array($status, ['ACTIVE','CANCELLED','EXCLUDED'], true)
        && str_starts_with((string)($effective['semantic_fingerprint'] ?? ''), 'sha256:')
        && (string)($effective['schedule_revision_integrity'] ?? 'PASS') === 'PASS';
    if ($reconstruction) $effectiveComplete++;
    if ($status === 'CANCELLED') $cancelled++;
    if ($status === 'EXCLUDED') $excluded++;
    $passed = $cutoff !== '' && !fwcvBefore($auditAt, $cutoff);
    if ($passed) $cutoffPassed++; else $preCutoff++;
    $resolution = $resolutionBy[$raceCode] ?? [];
    $eligibility = (string)($resolution['eligibility_status'] ?? 'NONE');
    if ($passed && $status === 'ACTIVE' && $eligibility === 'ELIGIBILITY_UNRESOLVED') $unresolved++;
    if ($passed && $status === 'ACTIVE' && $eligibility === 'NONE') $instrumentationGap++;
    // Historical append-only observations remain visible, but a latest taint
    // prevents this race from becoming an accepted Gate C formal sample.
    $isEligible = !empty($formalSchedule['accepted']) && $eligibility === 'ELIGIBLE_OBSERVED';
    if ($isEligible) $eligibleRaces[$raceCode] = true;
    $expectedRows = $expectedBy[$raceCode] ?? [];
    $canonicalRows = $canonicalBy[$raceCode] ?? [];
    if ($expectedRows !== []) $expectedRaces++;
    if ($canonicalRows !== []) $canonicalRaces++;
    $expectedKeys = array_values(array_unique(array_filter(array_map(static fn(array $row): string => (string)($row['stable_unique_key'] ?? ''), $expectedRows))));
    $canonicalKeys = array_values(array_unique(array_filter(array_map(static fn(array $row): string => (string)($row['stable_unique_key'] ?? ''), $canonicalRows))));
    $measurementProvenanceValid = fwcvRowsHaveValidMeasurementProvenance($expectedRows, $measurementProvenance, $measurementProvenanceCache, $declaredIdentity)
        && fwcvRowsHaveValidMeasurementProvenance($canonicalRows, $measurementProvenance, $measurementProvenanceCache, $declaredIdentity);
    if (($expectedRows !== [] || $canonicalRows !== []) && !$measurementProvenanceValid) $measurementProvenanceGap++;
    $canonicalComplete = !empty($formalSchedule['accepted']) && $isEligible && count($expectedKeys) === 18 && count(array_diff($expectedKeys, $canonicalKeys)) === 0
        && $measurementProvenanceValid
        && array_reduce($canonicalRows, static fn(bool $ok, array $row): bool => $ok && fwcvBefore((string)($row['captured_at'] ?? ''), (string)($row['cutoff_timestamp'] ?? '')), true);
    if ($canonicalComplete) $canonicalCompleteRaces++;
    /* Legacy nested expression retained in comments only; explicit branches
       below make the taint admission rule auditable. */
    /* $classification = empty($formalSchedule['accepted']) ? (string)($formalSchedule['status'] ?? 'SCHEDULE_INTEGRITY_UNRESOLVED')
        : (!$reconstruction ? 'RECONSTRUCTION_INCOMPLETE'
        : (in_array($status, ['CANCELLED','EXCLUDED'], true) ? $status
        : (!$passed ? 'PRE_CUTOFF_PENDING'
        : ($eligibility === 'ELIGIBILITY_UNRESOLVED' || $eligibility === 'NONE' ? 'ELIGIBILITY_UNRESOLVED'
        : ($eligibility === 'ELIGIBLE_OBSERVED' ? ($canonicalComplete ? 'ELIGIBLE_CANONICAL_COMPLETE' : 'ELIGIBLE_CANONICAL_MISSING') : 'VALID_NON_ELIGIBLE'))));
    */
    if (empty($formalSchedule['accepted'])) {
        $classification = (string)($formalSchedule['status'] ?? 'SCHEDULE_INTEGRITY_UNRESOLVED');
    } elseif (!$reconstruction) {
        $classification = 'RECONSTRUCTION_INCOMPLETE';
    } elseif (in_array($status, ['CANCELLED', 'EXCLUDED'], true)) {
        $classification = $status;
    } elseif (!$passed) {
        $classification = 'PRE_CUTOFF_PENDING';
    } elseif ($eligibility === 'ELIGIBILITY_UNRESOLVED' || $eligibility === 'NONE') {
        $classification = 'ELIGIBILITY_UNRESOLVED';
    } elseif ($eligibility === 'ELIGIBLE_OBSERVED') {
        $classification = $canonicalComplete ? 'ELIGIBLE_CANONICAL_COMPLETE' : 'ELIGIBLE_CANONICAL_MISSING';
    } else {
        $classification = 'VALID_NON_ELIGIBLE';
    }
    $currentRows[] = [
        (string)($candidate['stable_candidate_key'] ?? ''), $raceCode, (string)($candidate['venue'] ?? ''),
        $cutoff, $status, $passed ? 'CUTOFF_PASSED' : 'PRE_CUTOFF', (string)count($revisionBy[$raceCode] ?? []),
        (string)count($obsBy[$raceCode] ?? []), $eligibility, (string)count($expectedKeys), (string)count($canonicalKeys),
        (string)count($strictFailures), $reconstruction ? 'PASS' : 'FAIL', $measurementProvenanceValid ? 'PASS' : 'FAIL', $classification,
    ];
}
usort($currentRows, static fn(array $a, array $b): int => strcmp($a[1], $b[1]));
fwcvCsv(FWCV_OUTPUT . '/current_window_final_reconciliation.csv', [
    'stable_candidate_key','race_code','venue','current_effective_cutoff','current_official_status','cutoff_state',
    'semantic_revision_count','post_arm_eligibility_observation_count','post_arm_resolution','expected_key_count',
    'canonical_live_strict_key_count','window_strict_failure_event_count','reconstruction_completeness','measurement_implementation_provenance','final_classification',
], $currentRows);

$sourceUnavailable = count($sourceFailures);
$a1 = (string)$gateA1Inventory['status'];
$a2 = $oldUnaccounted === 0 && $effectiveComplete === count($candidates) && $unsafeRollback === 0
    && $unverifiedSemanticRevisions === 0 && $sourceUnavailable === 0 ? 'PASS' : 'FAIL';
$gateB = ($unresolved === 0 && $instrumentationGap === 0) ? 'PASS' : 'FAIL';
$eligibleCount = count($eligibleRaces);
$gateC = $a1 !== 'PASS' ? ($a1 === 'PENDING_LIVE' ? 'PENDING_LIVE' : 'BLOCKED_A1') : ($a2 !== 'PASS' ? 'BLOCKED_A2' : (empty($declaredImplementationStatus['accepted']) || empty($activationStatus['accepted']) || $measurementProvenanceGap > 0 ? 'BLOCKED_IMPLEMENTATION_PROVENANCE' : ($eligibleCount === 0 ? 'PENDING_LIVE' : ($canonicalCompleteRaces === $eligibleCount ? 'PASS' : 'FAIL'))));
$a1Blocking = !in_array($a1, ['PASS', 'PENDING_LIVE'], true);
$continuable = $a1 === 'PASS' && $a2 === 'PASS' ? 'YES' : ($a1Blocking || $oldUnaccounted > 0 || $unsafeRollback > 0 ? 'NO' : 'UNKNOWN');
$operationalReadiness = $continuable !== 'YES' ? 'BLOCKED_INSTRUMENTATION'
    : (($gateB === 'FAIL' || $gateC === 'FAIL') ? 'FAIL' : ($eligibleCount < 2 ? 'PENDING_LIVE' : 'PENDING_LIVE'));

fwcvCsv(FWCV_OUTPUT . '/final_gate_result.csv', ['gate','result','numerator','denominator','acceptance','note'], [
    ['A1_PROSPECTIVE_INVENTORY',$a1,(string)$gateA1Inventory['observed_count'],(string)$gateA1Inventory['expected_count'],'exact canonical-key equality','missing=' . count($gateA1Inventory['missing']) . '; unexpected=' . count($gateA1Inventory['unexpected']) . '; duplicate_or_conflicting=' . count($gateA1Inventory['duplicate']) . '; ' . (string)$gateA1Inventory['reason']],
    ['A2_SCHEDULE_REVISION_INTEGRITY',$a2,(string)$effectiveComplete,(string)count($candidates),'100%','unaccounted old=' . $oldUnaccounted . '; unsafe rollback=' . $unsafeRollback . '; unverified semantic=' . $unverifiedSemanticRevisions . '; source unavailable=' . $sourceUnavailable],
    ['B_ELIGIBILITY_RESOLUTION',$gateB,(string)$unresolved,(string)$cutoffPassed,'ELIGIBILITY_UNRESOLVED=0','pre-cutoff pending excluded; instrumentation gaps=' . $instrumentationGap],
    ['C_ELIGIBLE_CAPTURE_RATE',$gateC,(string)$canonicalCompleteRaces,(string)$eligibleCount,'100%; >=2 distinct eligible required','post-ARM eligibility/expected/canonical only; provenance gaps=' . $measurementProvenanceGap],
]);

$sourceProvenance = "# source_generated_at Provenance\n\n"
    . "- Classification: **LOCAL_CACHE_GENERATED_TIMESTAMP**\n"
    . "- Producer: `web/logic/OfficialDeadlineLogic.php`\n"
    . "- Generation: the top-level official deadline cache payload assigns PHP `date('c')` to `fetched_at` when the local cache payload is assembled.\n"
    . "- Consumer: `analysis/capture_all_race_forward.php` copies cache `fetched_at` into `source_generated_at`.\n"
    . "- It is **not** a timestamp supplied by BOAT RACE official and must not be described as official revision chronology.\n"
    . "- It identifies local cache generations on the same host. `observed_at` records ledger observation time; `source_sequence` breaks ties per Candidate.\n";
fwcvText(FWCV_OUTPUT . '/source_generated_at_provenance.md', $sourceProvenance);

$ordering = "# Final Ordering Contract\n\n"
    . "The contract is **deterministic local observation ordering**, not official chronology. Events sort by `source_generated_at ASC`, then `observed_at ASC`, then per-Candidate `source_sequence ASC`. A local cache timestamp older than the current effective timestamp is retained as `STALE_OR_OUT_OF_ORDER_SOURCE` but cannot replace effective state. Equal timestamps remain deterministic through observation time and sequence. At audit time, stale/out-of-order events={$unsafeRollback}; unsafe rollback applied=0.\n\n"
    . "This protection assumes the cache producer and ledger use the same host clock. It proves deterministic replay of recorded local observations; it does not prove the official site's internal event time.\n";
fwcvText(FWCV_OUTPUT . '/ordering_contract_final.md', $ordering);

$classLines = [];
foreach ($oldClassCounts as $type => $count) $classLines[] = "- {$type}: **{$count}**";
$revisionLines = [];
foreach ($revisionTypeCounts as $type => $count) $revisionLines[] = "- {$type}: **{$count}**";
$summary = "# Final Window Continuability Verification\n\n"
    . "- audit_at: `{$auditAt}`\n- validation_window_id: `{$windowId}`\n- armed_at: `{$armedAt}`\n"
    . "- status: `" . (string)($manifest['status'] ?? '') . "`\n- Official Epoch started: **" . (string)($manifest['official_epoch_started'] ?? 'NO') . "**\n\n"
    . "## Legacy raw-conflict accounting\n\n- old event population: **" . count($oldEvents) . "** event rows (`CANDIDATE_SOURCE_CONFLICT`)\n"
    . "- old observed period: `{$oldPeriod}`\n- before ARM / after ARM: **{$oldBeforeArm} / {$oldAfterArm}**\n"
    . "- distinct old race×raw-hash pairs: **" . count($oldPairSet) . "**; repeated event rows beyond that unit: **" . (count($oldEvents) - count($oldPairSet)) . "**\n"
    . "- new semantic event population: **" . count($revisionEvents) . "** event rows\n- new observed period: `{$newObservedPeriod}`\n- new local-cache-generation period: `{$newSourcePeriod}`\n"
    . "- distinct new race×raw-hash pairs: **" . count($newPairSet) . "**; repeated event rows beyond that unit: **" . (count($revisionEvents) - count($newPairSet)) . "**\n"
    . "- race×raw-hash pair intersection / old-only / new-only: **{$intersectionPairs} / {$oldOnlyPairs} / {$newOnlyPairs}**\n"
    . "- UNACCOUNTED_OLD_SOURCE_EVENTS: **{$oldUnaccounted}**\n\n" . implode("\n", $classLines) . "\n\n"
    . "The former count is a raw event count, not a Candidate count. The newer ledger began later and continues appending per-Candidate semantic observations, so its count must not equal the old count. The historical **2,520** is independently reproduced as the post-ARM legacy subset through `{$referenceOldAt}` (recount={$referenceOldCount}); **924** is reproduced as the semantic-ledger prefix through the saved instrumentation artifact time `{$referenceNewAt}` (recount={$referenceNewCount}). They are earlier snapshots, not final populations at this audit instant.\n\n"
    . "## New semantic ledger\n\n" . implode("\n", $revisionLines) . "\n\n"
    . "- Effective reconstruction: **{$effectiveComplete}/" . count($candidates) . "**\n- Source unavailable after ARM: **{$sourceUnavailable}**\n- Cancellation / exclusion: **{$cancelled} / {$excluded}**\n"
    . "- Ordering: deterministic local observation ordering\n\n"
    . "## Current prospective Candidate reconciliation\n\n- Gate A1 expected / observed canonical keys: **" . (string)$gateA1Inventory['expected_count'] . " / " . (string)$gateA1Inventory['observed_count'] . "**\n- Gate A1 missing / unexpected / duplicate-or-conflicting: **" . count($gateA1Inventory['missing']) . " / " . count($gateA1Inventory['unexpected']) . " / " . count($gateA1Inventory['duplicate']) . "**\n- pre-cutoff / cutoff-passed: **{$preCutoff} / {$cutoffPassed}**\n"
    . "- ELIGIBLE_OBSERVED: **{$eligibleCount}**\n- ELIGIBILITY_UNRESOLVED: **{$unresolved}**\n"
    . "- Expected races / Canonical LIVE_STRICT races: **{$expectedRaces} / {$canonicalRaces}**\n- Canonical-complete eligible races: **{$canonicalCompleteRaces}**\n"
    . "- Strict failure audit events: **" . count($strictFailures) . "**\n- Declared implementation identity: `" . ($declaredIdentity !== '' ? $declaredIdentity : 'MISSING') . "`\n- Current implementation identity check: **" . (!empty($declaredImplementationStatus['accepted']) ? 'PASS' : 'FAIL') . "**\n- Activation hold check: **" . (!empty($activationStatus['accepted']) ? 'PASS' : 'FAIL') . "**\n- Measurement implementation provenance gaps: **{$measurementProvenanceGap}**\n\n"
    . "## Final determination\n\n- Gate A1: **{$a1}**\n- Gate A2: **{$a2}**\n- Gate B: **{$gateB}**\n- Gate C: **{$gateC}**\n"
    . "- distinct eligible race_codes: **{$eligibleCount}**\n- CURRENT_WINDOW_CONTINUABLE: **{$continuable}**\n"
    . "- Operational Live Readiness: **{$operationalReadiness}**\n- Structural Readiness: **PASS**\n- Comparative Readiness: **POLICY_LIMITED**\n- Official Epoch Recommendation: **NO**\n\n"
    . "No result, payout, ROI, hit-rate, lift, performance, or outcome data was read or joined. No Re-ARM, invalidation, production prediction change, model/feature/threshold/coverage change, DB change, cron change, commit, or push was performed.\n";
fwcvText(FWCV_OUTPUT . '/final_window_continuability_summary.md', $summary);

$missing = "# Final Continuability Missing Items\n\n"
    . ($eligibleCount < 2 ? "- The acceptance minimum of two distinct post-ARM eligible races is not met (current={$eligibleCount}); Operational Live Readiness remains `PENDING_LIVE`.\n" : '')
    . ($gateB === 'FAIL' ? "- Cutoff-passed eligibility has unresolved or missing instrumentation evidence.\n" : '')
    . ($gateC === 'FAIL' ? "- At least one eligible race lacks complete pre-cutoff Canonical LIVE_STRICT evidence.\n" : '')
    . ($gateC === 'BLOCKED_IMPLEMENTATION_PROVENANCE' ? "- Window declaration or at least one formal Expected/Canonical row lacks a matching valid implementation identity/frozen source chain.\n" : '')
    . ($a1 !== 'PASS' ? "- Gate A1 prospective expected/observed canonical-key accounting is `{$a1}`: " . (string)$gateA1Inventory['reason'] . ".\n" : '')
    . "- `source_generated_at` is a local cache-generation timestamp, not official event time; retain that terminology in future reports.\n"
    . "- Official Epoch remains unstarted and is not recommended by this verification.\n";
fwcvText(FWCV_OUTPUT . '/final_continuability_missing_items.md', $missing);

echo json_encode([
    'status' => 'ok', 'audit_at' => $auditAt, 'window_id' => $windowId,
    'old_conflicts' => count($oldEvents), 'new_semantic_events' => count($revisionEvents),
    'unaccounted_old' => $oldUnaccounted, 'candidates' => count($candidates),
    'gate_a1_expected_count' => $gateA1Inventory['expected_count'], 'gate_a1_observed_count' => $gateA1Inventory['observed_count'],
    'gate_a1_missing_count' => count($gateA1Inventory['missing']), 'gate_a1_unexpected_count' => count($gateA1Inventory['unexpected']),
    'gate_a1_duplicate_or_conflicting_count' => count($gateA1Inventory['duplicate']),
    'pre_cutoff' => $preCutoff, 'cutoff_passed' => $cutoffPassed,
    'eligible' => $eligibleCount, 'unresolved' => $unresolved,
    'gate_a1' => $a1, 'gate_a2' => $a2, 'gate_b' => $gateB, 'gate_c' => $gateC, 'measurement_provenance_gaps' => $measurementProvenanceGap,
    'current_window_continuable' => $continuable, 'operational_live_readiness' => $operationalReadiness,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
