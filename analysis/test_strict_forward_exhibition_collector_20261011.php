<?php

declare(strict_types=1);

require_once __DIR__ . '/collect_strict_forward_exhibition_20261011.php';

function cc23TestAssert(bool $condition, string $name, array &$results): void
{
    $results[] = [$name, $condition];
    if (!$condition) throw new RuntimeException('ASSERTION_FAILED:' . $name);
}

/** @return array<string,mixed> */
function cc23TestCandidate(string $race = '20261011TDA09', string $cutoff = '2026-10-11T14:48:00+09:00', string $armed = '2026-10-11T13:21:05+09:00'): array
{
    return [
        'record_type' => 'SCHEDULER_CANDIDATE_UNIVERSE',
        'stable_candidate_key' => 'scheduler-opportunity|' . $race,
        'race_code' => $race, 'venue' => substr($race, 8, 3), 'target_date' => CC23_TARGET_DATE,
        'cutoff_timestamp' => $cutoff, 'armed_at' => $armed,
        'deadline_snapshot_identity' => 'sha256:' . str_repeat('a', 64),
        'prospective_keyset_identity' => 'sha256:' . str_repeat('b', 64),
        'prospective_filter_contract' => GateA1ProspectiveInventory::PROSPECTIVE_FILTER_CONTRACT_VERSION,
    ];
}

$results = [];
$tz = new DateTimeZone('Asia/Tokyo');
$armed = '2026-10-11T13:21:05+09:00';

cc23TestAssert(!cc23DateAllowed(new DateTimeImmutable('2026-10-10T12:00:00+09:00')), 'previous_date_noop', $results);
cc23TestAssert(cc23DateAllowed(new DateTimeImmutable('2026-10-11T12:00:00+09:00')), 'exact_target_date_allowed', $results);
cc23TestAssert(!cc23DateAllowed(new DateTimeImmutable('2026-10-12T12:00:00+09:00')), 'following_date_noop', $results);

cc23TestAssert(cc23WindowGuard('PREPARED', false, 'WINDOW_NOT_ACTIVE')['kind'] === 'NOOP', 'prepared_window_noop', $results);
cc23TestAssert(cc23WindowGuard('ABORTED', false, 'WINDOW_ABORTED')['kind'] === 'NOOP', 'aborted_window_noop', $results);
cc23TestAssert(cc23WindowGuard('ACTIVE', true, '')['kind'] === 'ACTIVE', 'active_window_authorized', $results);
cc23TestAssert(cc23WindowGuard('ACTIVE', false, 'DECLARED_CURRENT_IDENTITY_MISMATCH')['kind'] === 'GLOBAL_FATAL', 'identity_mismatch_global_fail_loud', $results);

$valid = cc23ValidateCandidate(cc23TestCandidate(), CC23_TARGET_DATE, $armed);
cc23TestAssert($valid['valid'], 'active_candidate_readable', $results);
$preArmed = cc23TestCandidate('20261011TDA08', '2026-10-11T13:21:05+09:00', $armed);
$preArmedCheck = cc23ValidateCandidate($preArmed, CC23_TARGET_DATE, $armed);
cc23TestAssert($preArmedCheck['valid'] && !$preArmedCheck['prospective'] && $preArmedCheck['reason'] === 'PRE_ARMED_RACE', 'prearmed_candidate_skipped', $results);
$corrupt = cc23TestCandidate(); $corrupt['deadline_snapshot_identity'] = 'broken';
cc23TestAssert(!cc23ValidateCandidate($corrupt, CC23_TARGET_DATE, $armed)['valid'], 'candidate_corruption_global_fail_loud', $results);

$cutoff = new DateTimeImmutable('2026-10-11T14:48:00+09:00', $tz);
cc23TestAssert(cc23FetchDecision(new DateTimeImmutable('2026-10-11T14:33:00+09:00', $tz), $cutoff, false, true)['status'] === 'FETCH_TARGET', 'fifteen_minutes_before_fetch_target', $results);
cc23TestAssert(cc23FetchDecision(new DateTimeImmutable('2026-10-11T14:44:00+09:00', $tz), $cutoff, false, true)['status'] === 'FETCH_TARGET', 'four_minutes_before_fetch_target', $results);
cc23TestAssert(cc23FetchDecision(new DateTimeImmutable('2026-10-11T14:44:01+09:00', $tz), $cutoff, false, true)['status'] === 'OUTSIDE_FETCH_WINDOW', 'under_four_minutes_blocked', $results);
cc23TestAssert(cc23FetchDecision(new DateTimeImmutable('2026-10-11T14:32:59+09:00', $tz), $cutoff, false, true)['status'] === 'OUTSIDE_FETCH_WINDOW', 'over_fifteen_minutes_blocked', $results);
cc23TestAssert(cc23FetchDecision(new DateTimeImmutable('2026-10-11T14:35:00+09:00', $tz), $cutoff, true, true)['status'] === 'ALREADY_COMPLETE', 'complete_six_boats_skipped', $results);
cc23TestAssert(cc23FetchDecision(new DateTimeImmutable('2026-10-11T14:35:00+09:00', $tz), $cutoff, false, false)['status'] === 'UNSUPPORTED_VENUE', 'unsupported_venue_race_local_skip', $results);
cc23TestAssert(cc23PostFetchDecision(true, false)['status'] === 'RETRY_PENDING', 'not_yet_available_retries_next_run', $results);
cc23TestAssert(cc23PostFetchDecision(true, true)['status'] === 'FETCHED_COMPLETE', 'existing_path_success_with_six_boats_is_complete', $results);
cc23TestAssert(cc23PostFetchDecision(false, false)['status'] === 'FETCH_FAILED', 'playwright_race_local_failure_logged', $results);

$supported = cc23SupportedVenues();
cc23TestAssert(in_array('EDG', $supported, true) && in_array('TDA', $supported, true) && in_array('TMG', $supported, true), 'existing_generic_path_supported_venues', $results);
$simulated = [];
foreach ([['EDG', false], ['TDA', true], ['SME', true]] as [$venue, $isSupported]) {
    $simulated[] = cc23FetchDecision(new DateTimeImmutable('2026-10-11T14:35:00+09:00', $tz), $cutoff, false, $isSupported)['status'];
}
cc23TestAssert($simulated === ['UNSUPPORTED_VENUE', 'FETCH_TARGET', 'FETCH_TARGET'], 'edg_or_race_local_failure_does_not_stop_following_races', $results);
cc23TestAssert(!str_contains((string)file_get_contents(__DIR__ . '/collect_strict_forward_exhibition_20261011.php'), 'StrictForwardReadinessLedger::captureLive'), 'collector_has_no_formal_evidence_writer', $results);
cc23TestAssert(!str_contains((string)file_get_contents(__DIR__ . '/collect_strict_forward_exhibition_20261011.php'), 'StrictForwardInputSnapshotStore'), 'collector_has_no_snapshot_writer', $results);
cc23TestAssert(!str_contains((string)file_get_contents(__DIR__ . '/collect_strict_forward_exhibition_20261011.php'), 'StrictForwardComparativePairStore'), 'collector_has_no_pair_writer', $results);

ob_start();
$exit = cc23Main(['collector', '--dry-run', '--now=2026-10-10T12:00:00+09:00']);
$dry = (string)ob_get_clean();
$dryRow = json_decode(trim($dry), true);
cc23TestAssert($exit === 0 && is_array($dryRow) && ($dryRow['skip_reason'] ?? '') === 'DATE_GUARD', 'dry_run_current_date_zero_targets_no_fetch', $results);

$pass = count(array_filter($results, static fn(array $row): bool => $row[1]));
echo json_encode(['total' => count($results), 'pass' => $pass, 'fail' => count($results) - $pass, 'tests' => $results], JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($pass === count($results) ? 0 : 1);
