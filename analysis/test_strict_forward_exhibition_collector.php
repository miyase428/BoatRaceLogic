<?php

declare(strict_types=1);

require_once __DIR__ . '/collect_strict_forward_exhibition.php';

/** @param list<array{0:string,1:bool}> $results */
function cc30bAssert(bool $condition, string $name, array &$results): void
{
    $results[] = [$name, $condition];
    if (!$condition) throw new RuntimeException('ASSERTION_FAILED:' . $name);
}

/** @return array<string,mixed> */
function cc30bTestCandidate(string $race = '20991228TDA09', string $cutoff = '2099-12-28T14:48:00+09:00', string $armed = '2099-12-28T08:00:00+09:00'): array
{
    return [
        'record_type' => 'SCHEDULER_CANDIDATE_UNIVERSE', 'stable_candidate_key' => 'scheduler-opportunity|' . $race,
        'race_code' => $race, 'venue' => substr($race, 8, 3), 'target_date' => '2099-12-28',
        'cutoff_timestamp' => $cutoff, 'armed_at' => $armed,
        'deadline_snapshot_identity' => 'sha256:' . str_repeat('a', 64),
        'prospective_keyset_identity' => 'sha256:' . str_repeat('b', 64),
        'prospective_filter_contract' => GateA1ProspectiveInventory::PROSPECTIVE_FILTER_CONTRACT_VERSION,
    ];
}

$results = [];
$tz = new DateTimeZone('Asia/Tokyo');
$cutoff = new DateTimeImmutable('2099-12-28T22:45:00+09:00', $tz);
$armed = '2099-12-28T08:00:00+09:00';

cc30bAssert(cc30bWindowGuard('ACTIVE', true, '')['kind'] === 'ACTIVE', '01_active_window_authorized', $results);
cc30bAssert(cc30bWindowGuard('PREPARED', false, '')['kind'] === 'NOOP', '02_prepared_window_noop', $results);
cc30bAssert(cc30bWindowGuard('CLOSED', false, '')['kind'] === 'NOOP', '03_closed_window_noop', $results);
cc30bAssert(cc30bWindowGuard('ABORTED', false, '')['kind'] === 'NOOP', '04_aborted_window_noop', $results);
cc30bAssert(cc30bWindowGuard('ACTIVE', false, 'DECLARED_CURRENT_IDENTITY_MISMATCH')['kind'] === 'GLOBAL_FATAL', '05_identity_mismatch_fatal', $results);
cc30bAssert(cc30bWindowGuard('ACTIVE', true, '', 2)['reason'] === 'OPERATIONAL_ACTIVE_WINDOW_CARDINALITY_INVALID', '06_multiple_active_fatal', $results);
cc30bAssert(cc30bEpochStateGuard(true, true, 'ACTIVE')['kind'] === 'ACTIVE', '07_epoch_active_allowed', $results);
cc30bAssert(cc30bEpochStateGuard(false, true, 'NOT_STARTED')['reason'] === 'OFFICIAL_EPOCH_NOT_STARTED', '08_epoch_not_started_noop', $results);
cc30bAssert(cc30bEpochStateGuard(true, true, 'SUSPENDED')['kind'] === 'GLOBAL_FATAL', '09_epoch_suspended_fatal', $results);
cc30bAssert(cc30bEpochStateGuard(true, true, 'ENDED')['kind'] === 'GLOBAL_FATAL', '10_epoch_ended_fatal', $results);
cc30bAssert(cc30bEpochStateGuard(true, false, 'ACTIVE')['kind'] === 'GLOBAL_FATAL', '11_epoch_invalid_fail_loud', $results);

$valid = cc30bValidateCandidate(cc30bTestCandidate(), $armed);
cc30bAssert($valid['valid'], '12_formal_candidate_only_valid', $results);
$bad = cc30bTestCandidate(); $bad['record_type'] = 'SCHEDULE_DERIVED';
cc30bAssert(!cc30bValidateCandidate($bad, $armed)['valid'], '13_no_schedule_rederive', $results);
$bad = cc30bTestCandidate(); $bad['target_date'] = '2099-12-29';
cc30bAssert(!cc30bValidateCandidate($bad, $armed)['valid'], '14_candidate_date_provenance_required', $results);
$bad = cc30bTestCandidate(); $bad['cutoff_timestamp'] = $armed;
cc30bAssert(!cc30bValidateCandidate($bad, $armed)['valid'], '15_prearmed_candidate_rejected', $results);
$bad = cc30bTestCandidate(); $bad['deadline_snapshot_identity'] = 'broken';
cc30bAssert(!cc30bValidateCandidate($bad, $armed)['valid'], '16_candidate_provenance_corruption_fatal', $results);

cc30bAssert(cc30bFetchDecision(new DateTimeImmutable('2099-12-28T22:29:59+09:00', $tz), $cutoff, false, true, true)['status'] === 'TOO_EARLY', '17_before_window_too_early', $results);
cc30bAssert(cc30bFetchDecision(new DateTimeImmutable('2099-12-28T22:30:00+09:00', $tz), $cutoff, false, true, true)['status'] === 'FETCH_ELIGIBLE', '18_start_boundary_fetch_eligible', $results);
cc30bAssert(cc30bFetchDecision(new DateTimeImmutable('2099-12-28T22:36:00+09:00', $tz), $cutoff, false, true, true)['status'] === 'FETCH_ELIGIBLE', '19_inside_window_fetch_eligible', $results);
cc30bAssert(cc30bFetchDecision(new DateTimeImmutable('2099-12-28T22:41:00+09:00', $tz), $cutoff, false, true, true)['status'] === 'FETCH_ELIGIBLE', '20_end_boundary_fetch_eligible', $results);
cc30bAssert(cc30bFetchDecision(new DateTimeImmutable('2099-12-28T22:41:01+09:00', $tz), $cutoff, false, true, true)['status'] === 'TOO_LATE', '21_after_window_too_late', $results);
cc30bAssert(cc30bFetchDecision(new DateTimeImmutable('2099-12-28T22:36:00+09:00', $tz), $cutoff, true, true)['status'] === 'ALREADY_SATISFIED', '22_complete_exhibition_skip', $results);
cc30bAssert(cc30bFetchDecision(new DateTimeImmutable('2099-12-28T22:36:00+09:00', $tz), $cutoff, false, false)['status'] === 'UNSUPPORTED_VENUE', '23_unsupported_race_local', $results);
cc30bAssert(cc30bFetchDecision(new DateTimeImmutable('2099-12-28T13:36:00Z'), $cutoff, false, true, true)['status'] === 'FETCH_ELIGIBLE', '24_offset_aware_jst_instant', $results);
cc30bAssert(cc30bPostFetchDecision(true, true)['status'] === 'FETCHED_COMPLETE', '25_existing_path_complete', $results);
cc30bAssert(cc30bPostFetchDecision(true, false)['status'] === 'RETRY_PENDING', '26_same_race_retry_possible', $results);
cc30bAssert(cc30bPostFetchDecision(false, false)['status'] === 'FETCH_FAILED', '27_scraper_failure_race_local', $results);

$supported = cc30bSupportedVenues();
cc30bAssert($supported === ['TMG', 'TDA', 'OMR', 'SMS', 'SME', 'KRY', 'BWK', 'ASY', 'AMG', 'HWJ', 'EDG', 'MKN'], '28_all_twelve_venues_mapped', $results);
$chain = [];
foreach ([['EDG', false], ['TDA', true], ['SMS', true]] as [$venue, $available]) $chain[] = cc30bFetchDecision(new DateTimeImmutable('2099-12-28T22:36:00+09:00', $tz), $cutoff, false, $available, true)['status'];
cc30bAssert($chain === ['UNSUPPORTED_VENUE', 'FETCH_ELIGIBLE', 'FETCH_ELIGIBLE'], '29_race_local_failure_does_not_stop_following', $results);

$source = (string)file_get_contents(__DIR__ . '/collect_strict_forward_exhibition.php');
cc30bAssert(!str_contains($source, 'StrictForwardReadinessLedger::captureLive'), '30_no_formal_evidence_write', $results);
cc30bAssert(!str_contains($source, 'StrictForwardInputSnapshotStore'), '31_no_snapshot_write', $results);
cc30bAssert(!str_contains($source, 'StrictForwardComparativePairStore'), '32_no_pair_write', $results);
cc30bAssert(!str_contains($source, '2026-10-11'), '33_no_date_hardcoding', $results);
cc30bAssert(!str_contains($source, 'CC23_TARGET_DATE'), '34_no_legacy_day_guard', $results);
cc30bAssert(str_contains($source, 'CC30B_LOCK_RELATIVE_PATH'), '35_overlap_lock_present', $results);
cc30bAssert(str_contains($source, 'OfficialEpochLifecycleV1'), '36_epoch_guard_present', $results);
cc30bAssert(str_contains($source, 'OfficialEpochWindowBindingRuntimeAuthorization'), '37_pointer_binding_runtime_authorization', $results);
cc30bAssert(!str_contains($source, 'capture_all_race_forward.php'), '38_production_prediction_routing_unchanged', $results);
cc30bAssert(!str_contains($source, 'OfficialEpochIncidentLifecycleStore->'), '39_epoch_lifecycle_not_written', $results);
cc30bAssert(cc30bArguments(['collector', '--dry-run', '--now=2099-12-28T22:36:00+09:00'])['dry_run'], '40_dry_run_argument', $results);
try { cc30bArguments(['collector', '--now=2099-12-28T22:36:00+09:00']); $nowOverrideBlocked = false; } catch (Throwable) { $nowOverrideBlocked = true; }
cc30bAssert($nowOverrideBlocked, '41_now_override_dry_run_only', $results);
cc30bAssert(cc30bFetchDecision(new DateTimeImmutable('2099-12-28T22:36:00+09:00', $tz), $cutoff, false, true)['status'] === 'AUTOMATIC_FETCH_DETACHED', '42_nationwide_daytime_fetch_is_manual_only', $results);
cc30bAssert(cc30bFetchViaExistingPath(new ApiClientProduction(), '20991228TDA09')['message'] === 'AUTOMATIC_EXHIBITION_FETCH_DETACHED', '43_fetch_path_backstop_never_calls_external_source', $results);

$pass = count(array_filter($results, static fn(array $row): bool => $row[1]));
echo json_encode(['total' => count($results), 'pass' => $pass, 'fail' => count($results) - $pass, 'tests' => $results], JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($pass === count($results) ? 0 : 1);
