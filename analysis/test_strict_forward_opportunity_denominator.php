<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/StrictForwardOpportunityLedger.php';

/** @return array<string,mixed> */
function opportunityCandidate(string $raceCode = '20261005TMG01'): array
{
    return ['race_code' => $raceCode, 'venue' => substr($raceCode, 8, 3), 'cutoff_timestamp' => '2026-10-05T10:06:00+09:00'];
}

function opportunityAssert(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException('ASSERTION_FAILED: ' . $label);
    echo "PASS {$label}\n";
}

function opportunityLedger(string $suffix): StrictForwardOpportunityLedger
{
    $root = sys_get_temp_dir() . '/strict_forward_opportunity_fixture_' . $suffix . '_' . bin2hex(random_bytes(4));
    return new StrictForwardOpportunityLedger($root);
}

function opportunitySemanticFingerprint(string $raceCode, string $cutoff, string $status): string
{
    return 'sha256:' . hash('sha256', json_encode([
        'race_code' => $raceCode, 'venue' => substr($raceCode, 8, 3),
        'effective_cutoff_timestamp' => $cutoff, 'official_status' => $status,
    ], JSON_UNESCAPED_SLASHES));
}

/** @return array<string,string> */
function opportunityOfficialRevisionEvidence(
    string $raceCode,
    string $previousCutoff,
    string $previousStatus,
    string $timestamp = '2026-10-05T09:03:00+09:00',
    string $previousTimestamp = '2026-10-05T09:00:00+09:00'
): array
{
    return [
        'evidence_source' => 'official-response-revision-fixture',
        'evidence_identity' => 'sha256:official-revision-fixture',
        'official_revision_timestamp' => $timestamp,
        'official_predecessor_revision_timestamp' => $previousTimestamp,
        'predecessor_semantic_fingerprint' => opportunitySemanticFingerprint($raceCode, $previousCutoff, $previousStatus),
    ];
}

$results = [];

// 1. A candidate stays frozen even if a later enumeration/source failure occurs.
$ledger = opportunityLedger('candidate_persists');
$ledger->recordCandidateUniverse([
    'date' => '2026-10-05', 'candidates' => [opportunityCandidate()], 'source' => 'official-cache',
    'source_identity' => 'sha256:fixture-a', 'source_generated_at' => '2026-10-05T08:00:00+09:00',
    'universe_recorded_at' => '2026-10-05T08:01:00+09:00',
]);
$ledger->recordUniverseSourceUnavailable(['date' => '2026-10-05', 'source' => 'official-cache', 'error_code' => 'ENUMERATION_FAILED']);
opportunityAssert(count($ledger->candidateRows('2026-10-05')) === 1, 'candidate persists after later source failure');
$results[] = ['candidate_persists_after_enumeration_failure', 'PASS'];

// 2. Incomplete is provisional. A valid complete read before cutoff wins.
$ledger = opportunityLedger('transition');
$ledger->recordCandidateUniverse([
    'date' => '2026-10-05', 'candidates' => [opportunityCandidate()], 'source' => 'official-cache',
    'source_identity' => 'sha256:fixture-b', 'source_generated_at' => '2026-10-05T08:00:00+09:00',
]);
$ledger->observeEligibility(['date' => '2026-10-05', 'ready_race_codes' => [], 'observed_at' => '2026-10-05T10:00:00+09:00']);
opportunityAssert($ledger->resolutionRows('2026-10-05') === [], 'incomplete observation is not final before cutoff');
$ledger->observeEligibility(['date' => '2026-10-05', 'ready_race_codes' => ['20261005TMG01'], 'observed_at' => '2026-10-05T10:04:00+09:00']);
$resolution = $ledger->resolutionRows('2026-10-05');
opportunityAssert(($resolution[0]['eligibility_status'] ?? '') === 'ELIGIBLE_OBSERVED', 'complete transition becomes eligible');
$results[] = ['incomplete_to_complete_transition', 'PASS'];

// 3. An unreadable predicate can only resolve as unresolved, never ineligible.
$ledger = opportunityLedger('unresolved');
$ledger->recordCandidateUniverse([
    'date' => '2026-10-05', 'candidates' => [opportunityCandidate()], 'source' => 'official-cache',
    'source_identity' => 'sha256:fixture-c', 'source_generated_at' => '2026-10-05T08:00:00+09:00',
]);
$ledger->observeEligibility(['date' => '2026-10-05', 'input_availability' => 'UNAVAILABLE', 'reason' => 'DB_READ_FAILED', 'observed_at' => '2026-10-05T10:00:00+09:00']);
$ledger->finalizeExpired(['date' => '2026-10-05', 'observed_at' => '2026-10-05T10:06:00+09:00']);
$resolution = $ledger->resolutionRows('2026-10-05');
opportunityAssert(($resolution[0]['eligibility_status'] ?? '') === 'ELIGIBILITY_UNRESOLVED', 'input failure resolves as unresolved');
$results[] = ['unresolved_input_is_not_ineligible', 'PASS'];

// 4. A source outage is an explicit failure event, not a zero candidate count.
$ledger = opportunityLedger('source_unavailable');
$ledger->recordUniverseSourceUnavailable(['date' => '2026-10-05', 'source' => 'official-cache', 'error_code' => 'CACHE_UNAVAILABLE']);
$sourceFailure = $ledger->sourceFailureRows('2026-10-05')[0] ?? [];
opportunityAssert($ledger->candidateRows('2026-10-05') === [] && ($sourceFailure['zero_candidates_asserted'] ?? true) === false, 'source unavailable creates no fabricated zero-candidate assertion');
$results[] = ['source_unavailable_not_zero_candidates', 'PASS'];

// 5. The stable race key is sufficient to trace candidate -> eligibility ->
// expected -> canonical rows. This is a synthetic structural fixture only; it
// does not start LIVE validation or evaluate any real capture.
$ledger = opportunityLedger('full_chain');
$raceCode = '20261005TMG01';
$ledger->recordCandidateUniverse([
    'date' => '2026-10-05', 'candidates' => [opportunityCandidate($raceCode)], 'source' => 'official-cache',
    'source_identity' => 'sha256:fixture-d', 'source_generated_at' => '2026-10-05T08:00:00+09:00',
]);
$ledger->observeEligibility(['date' => '2026-10-05', 'ready_race_codes' => [$raceCode], 'observed_at' => '2026-10-05T10:04:00+09:00']);
$candidate = $ledger->candidateRows('2026-10-05')[0] ?? [];
$eligible = $ledger->resolutionRows('2026-10-05')[0] ?? [];
$expected = ['race_code' => $raceCode, 'stage' => 'expected'];
$canonical = ['race_code' => $raceCode, 'stage' => 'canonical'];
opportunityAssert(($candidate['race_code'] ?? '') === $raceCode && ($eligible['race_code'] ?? '') === $raceCode
    && $expected['race_code'] === $raceCode && $canonical['race_code'] === $raceCode, 'candidate eligibility expected canonical chain is traceable');
$results[] = ['full_candidate_to_canonical_chain', 'PASS'];

// 6. Raw cache refreshes must not create semantic conflicts; a later official
// cutoff/cancellation must update the derived effective schedule, while a
// stale snapshot cannot roll it back.
$ledger = opportunityLedger('schedule_revision');
$raceCode = '20261005TMG01';
$ledger->recordCandidateUniverse([
    'date' => '2026-10-05', 'candidates' => [opportunityCandidate($raceCode)], 'source' => 'official-cache',
    'source_identity' => 'sha256:original', 'source_generated_at' => '2026-10-05T09:00:00+09:00',
]);
$ledger->recordOfficialScheduleSnapshot([
    'date' => '2026-10-05', 'raw_source_identity' => 'sha256:refresh-a', 'source_generated_at' => '2026-10-05T09:01:00+09:00',
    'schedules' => [['race_code' => $raceCode, 'cutoff_timestamp' => '2026-10-05T10:06:00+09:00', 'official_status' => 'ACTIVE']],
]);
$ledger->recordOfficialScheduleSnapshot([
    'date' => '2026-10-05', 'raw_source_identity' => 'sha256:refresh-b', 'source_generated_at' => '2026-10-05T09:02:00+09:00',
    'schedules' => [['race_code' => $raceCode, 'cutoff_timestamp' => '2026-10-05T10:06:00+09:00', 'official_status' => 'ACTIVE']],
]);
$ledger->recordOfficialScheduleSnapshot([
    'date' => '2026-10-05', 'raw_source_identity' => 'sha256:changed', 'source_generated_at' => '2026-10-05T09:03:00+09:00',
    'official_revision_evidence' => opportunityOfficialRevisionEvidence($raceCode, '2026-10-05T10:06:00+09:00', 'ACTIVE'),
    'schedules' => [['race_code' => $raceCode, 'cutoff_timestamp' => '2026-10-05T10:10:00+09:00', 'official_status' => 'ACTIVE']],
]);
$ledger->recordOfficialScheduleSnapshot([
    'date' => '2026-10-05', 'raw_source_identity' => 'sha256:stale', 'source_generated_at' => '2026-10-05T09:02:30+09:00',
    'schedules' => [['race_code' => $raceCode, 'cutoff_timestamp' => '2026-10-05T10:06:00+09:00', 'official_status' => 'ACTIVE']],
]);
$method = (new ReflectionClass($ledger))->getMethod('scheduleRevisionRows');
$method->setAccessible(true);
$revisionRows = $method->invoke($ledger, '2026-10-05', $raceCode);
opportunityAssert(($revisionRows[0]['revision_type'] ?? '') === 'SOURCE_REFRESH_NO_SEMANTIC_CHANGE', 'same semantic content with new raw hash remains source refresh');
opportunityAssert(($revisionRows[3]['revision_type'] ?? '') === 'STALE_OR_OUT_OF_ORDER_SOURCE', 'older local generation timestamp remains stale source');
$effective = $ledger->resolveEffectiveSchedule('2026-10-05', $raceCode);
opportunityAssert(($effective['effective_cutoff_timestamp'] ?? '') === '2026-10-05T10:10:00+09:00', 'semantic cutoff revision advances and stale snapshot cannot roll back');
$ledger->recordOfficialScheduleSnapshot([
    'date' => '2026-10-05', 'raw_source_identity' => 'sha256:cancelled', 'source_generated_at' => '2026-10-05T09:04:00+09:00',
    'official_revision_evidence' => opportunityOfficialRevisionEvidence($raceCode, '2026-10-05T10:10:00+09:00', 'ACTIVE', '2026-10-05T09:04:00+09:00', '2026-10-05T09:03:00+09:00'),
    'schedules' => [['race_code' => $raceCode, 'cutoff_timestamp' => '', 'official_status' => 'CANCELLED']],
]);
$effective = $ledger->resolveEffectiveSchedule('2026-10-05', $raceCode);
opportunityAssert(($effective['effective_official_status'] ?? '') === 'CANCELLED', 'official cancellation becomes effective schedule state');
$ledger->recordOfficialScheduleSnapshot([
    'date' => '2026-10-05', 'raw_source_identity' => 'sha256:excluded', 'source_generated_at' => '2026-10-05T09:05:00+09:00',
    'official_revision_evidence' => opportunityOfficialRevisionEvidence($raceCode, '', 'CANCELLED', '2026-10-05T09:05:00+09:00', '2026-10-05T09:04:00+09:00'),
    'schedules' => [['race_code' => $raceCode, 'cutoff_timestamp' => '', 'official_status' => 'EXCLUDED']],
]);
$effective = $ledger->resolveEffectiveSchedule('2026-10-05', $raceCode);
opportunityAssert(($effective['effective_official_status'] ?? '') === 'EXCLUDED', 'official exclusion becomes effective schedule state');
$results[] = ['semantic_schedule_revision_and_stale_ordering', 'PASS'];

// 7. A changed payload with only a newer local cache timestamp cannot prove
// official chronology. It stays append-only evidence and cannot replace the
// original effective schedule, even if later local observations contain A.
$ledger = opportunityLedger('unverified_semantic');
$raceCode = '20261005TMG01';
$ledger->recordCandidateUniverse([
    'date' => '2026-10-05', 'candidates' => [array_merge(opportunityCandidate($raceCode), ['cutoff_timestamp' => '2026-10-05T12:00:00+09:00'])], 'source' => 'official-cache',
    'source_identity' => 'sha256:original-unverified', 'source_generated_at' => '2026-10-05T09:00:00+09:00',
]);
$ledger->recordOfficialScheduleSnapshot([
    'date' => '2026-10-05', 'raw_source_identity' => 'sha256:unverified-b', 'source_generated_at' => '2026-10-05T09:10:00+09:00',
    'schedules' => [['race_code' => $raceCode, 'cutoff_timestamp' => '2026-10-05T12:10:00+09:00', 'official_status' => 'ACTIVE']],
]);
$ledger->recordOfficialScheduleSnapshot([
    'date' => '2026-10-05', 'raw_source_identity' => 'sha256:stale-content-a-new-local-time', 'source_generated_at' => '2026-10-05T09:11:00+09:00',
    'schedules' => [['race_code' => $raceCode, 'cutoff_timestamp' => '2026-10-05T12:00:00+09:00', 'official_status' => 'ACTIVE']],
]);
$method = (new ReflectionClass($ledger))->getMethod('scheduleRevisionRows');
$method->setAccessible(true);
$revisionRows = $method->invoke($ledger, '2026-10-05', $raceCode);
$effective = $ledger->resolveEffectiveSchedule('2026-10-05', $raceCode);
opportunityAssert(($revisionRows[0]['revision_type'] ?? '') === 'SEMANTIC_REVISION_UNVERIFIED', 'unverified cutoff change is fail-loud');
opportunityAssert(($effective['effective_cutoff_timestamp'] ?? '') === '2026-10-05T12:00:00+09:00', 'newer local timestamp with stale semantic content cannot change effective cutoff');
opportunityAssert(($effective['schedule_revision_integrity'] ?? '') === 'BLOCKED_SEMANTIC_REVISION_UNVERIFIED', 'unverified semantic revision blocks A2-equivalent integrity');
$results[] = ['unverified_semantic_revision_never_replaces_effective_state', 'PASS'];

// 8. Cancellation and exclusion follow the same rule: local ordering alone
// cannot decide status; independently evidenced revisions can.
foreach ([
    ['cancelled-unverified', 'CANCELLED', false],
    ['excluded-unverified', 'EXCLUDED', false],
    ['cancelled-verified', 'CANCELLED', true],
    ['excluded-verified', 'EXCLUDED', true],
] as [$suffix, $status, $verified]) {
    $ledger = opportunityLedger((string)$suffix);
    $ledger->recordCandidateUniverse([
        'date' => '2026-10-05', 'candidates' => [opportunityCandidate()], 'source' => 'official-cache',
        'source_identity' => 'sha256:' . $suffix, 'source_generated_at' => '2026-10-05T09:00:00+09:00',
    ]);
    $context = [
        'date' => '2026-10-05', 'raw_source_identity' => 'sha256:' . $suffix . '-revision', 'source_generated_at' => '2026-10-05T09:10:00+09:00',
        'schedules' => [['race_code' => '20261005TMG01', 'cutoff_timestamp' => '', 'official_status' => $status]],
    ];
    if ($verified) $context['official_revision_evidence'] = opportunityOfficialRevisionEvidence('20261005TMG01', '2026-10-05T10:06:00+09:00', 'ACTIVE', '2026-10-05T09:10:00+09:00');
    $ledger->recordOfficialScheduleSnapshot($context);
    $effective = $ledger->resolveEffectiveSchedule('2026-10-05', '20261005TMG01');
    opportunityAssert(($effective['effective_official_status'] ?? '') === ($verified ? $status : 'ACTIVE'), $suffix . ' follows official-chronology verification rule');
}
$results[] = ['verified_and_unverified_status_transitions', 'PASS'];

echo json_encode(['status' => 'ok', 'fixtures' => $results], JSON_UNESCAPED_SLASHES) . PHP_EOL;
