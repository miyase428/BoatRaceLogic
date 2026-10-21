<?php

declare(strict_types=1);

/**
 * One-day, low-load exhibition collector for the 2026-10-11 Strict Forward
 * measurement Window. It is intentionally not a formal-evidence writer:
 * it can only invoke the existing exhibition update endpoint and inspect its
 * ordinary exhibition_live result. The scheduler remains the sole writer of
 * Eligibility, Expected, Canonical, snapshots, and pairs.
 */

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../logic/exhibition_data_quality.php';
require_once __DIR__ . '/../web/api/ApiClientProduction.php';
require_once __DIR__ . '/../web/logic/FormalMeasurementWindowContext.php';
require_once __DIR__ . '/../web/logic/GateA1ProspectiveInventory.php';

const CC23_TARGET_DATE = '2026-10-11';
const CC23_FETCH_OPEN_SECONDS = 15 * 60;
const CC23_FETCH_CLOSE_SECONDS = 4 * 60;
const CC23_COLLECTOR_SCHEMA = 'strict-forward-exhibition-collector/v1';

/** @return array{dry_run:bool,now:DateTimeImmutable} */
function cc23Arguments(array $argv): array
{
    $dryRun = false;
    $nowText = null;
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--dry-run') $dryRun = true;
        elseif (str_starts_with($argument, '--now=')) $nowText = substr($argument, strlen('--now='));
        else throw new InvalidArgumentException('unknown_argument:' . $argument);
    }
    if ($nowText !== null && !$dryRun) throw new InvalidArgumentException('now_override_requires_dry_run');
    $tz = new DateTimeZone('Asia/Tokyo');
    $now = $nowText === null ? new DateTimeImmutable('now', $tz) : new DateTimeImmutable($nowText, $tz);
    return ['dry_run' => $dryRun, 'now' => $now->setTimezone($tz)];
}

function cc23DateAllowed(DateTimeImmutable $now): bool
{
    return $now->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d') === CC23_TARGET_DATE;
}

/** @return array{kind:string,reason:string} */
function cc23WindowGuard(string $effectiveState, bool $authorizationAccepted, string $authorizationReason): array
{
    if ($effectiveState !== 'ACTIVE') return ['kind' => 'NOOP', 'reason' => 'WINDOW_NOT_ACTIVE'];
    if (!$authorizationAccepted) return ['kind' => 'GLOBAL_FATAL', 'reason' => $authorizationReason === '' ? 'MEASUREMENT_IDENTITY_OR_ACTIVATION_INVALID' : $authorizationReason];
    return ['kind' => 'ACTIVE', 'reason' => ''];
}

/** @return list<string> */
function cc23SupportedVenues(): array
{
    $map = require __DIR__ . '/../config/place_map_reverse.php';
    $venues = array_values(array_filter(array_map(static fn(mixed $key): string => strtoupper(trim((string)$key)), array_keys($map)), static fn(string $venue): bool => preg_match('/^[A-Z0-9]{3}$/', $venue) === 1));
    sort($venues, SORT_STRING);
    return array_values(array_unique($venues));
}

/** @param array<string,mixed> $event */
function cc23Log(array $event): void
{
    $event = array_merge([
        'schema_version' => CC23_COLLECTOR_SCHEMA,
        'date' => CC23_TARGET_DATE,
        'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM),
        'race_code' => '', 'venue' => '', 'cutoff' => '', 'attempt_started_at' => '', 'attempt_finished_at' => '',
        'remaining_seconds_at_start' => null, 'status' => '', 'complete_boats' => 0, 'skip_reason' => '', 'error' => '',
    ], $event);
    echo json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

/** @return array{kind:string,reason:string,context:?FormalMeasurementWindowContext,authorization:array<string,mixed>} */
function cc23ActiveWindow(): array
{
    try {
        $context = FormalMeasurementWindowContext::production(dirname(__DIR__));
        $resolved = $context->resolve();
        if (!$resolved['accepted']) return ['kind' => 'GLOBAL_FATAL', 'reason' => (string)$resolved['reason'], 'context' => null, 'authorization' => []];
        $state = $context->lifecycle()->effectiveState((string)$resolved['window_id'], $resolved['manifest']);
        $effectiveState = (string)($state['effective_state'] ?? '');
        if ($effectiveState !== 'ACTIVE') return ['kind' => 'NOOP', 'reason' => 'WINDOW_NOT_ACTIVE', 'context' => $context, 'authorization' => []];
        $authorization = $context->authorizeMeasurementWrite();
        $guard = cc23WindowGuard($effectiveState, !empty($authorization['accepted']), (string)($authorization['reason'] ?? ''));
        return ['kind' => $guard['kind'], 'reason' => $guard['reason'], 'context' => $context, 'authorization' => $authorization];
    } catch (Throwable $error) {
        return ['kind' => 'GLOBAL_FATAL', 'reason' => 'FORMAL_WINDOW_CONTEXT_UNAVAILABLE:' . get_class($error), 'context' => null, 'authorization' => []];
    }
}

/** @return array{valid:bool,reason:string,prospective:bool,row:array<string,mixed>} */
function cc23ValidateCandidate(array $row, string $date, string $armedAt): array
{
    $race = strtoupper(trim((string)($row['race_code'] ?? '')));
    $venue = strtoupper(trim((string)($row['venue'] ?? '')));
    $cutoff = trim((string)($row['cutoff_timestamp'] ?? ''));
    if (($row['record_type'] ?? '') !== 'SCHEDULER_CANDIDATE_UNIVERSE'
        || ($row['target_date'] ?? '') !== $date
        || !preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $race)
        || !preg_match('/^[A-Z0-9]{3}$/', $venue)
        || $venue !== substr($race, 8, 3)
        || ($row['stable_candidate_key'] ?? '') !== 'scheduler-opportunity|' . $race
        || ($row['prospective_filter_contract'] ?? '') !== GateA1ProspectiveInventory::PROSPECTIVE_FILTER_CONTRACT_VERSION
        || ($row['armed_at'] ?? '') !== $armedAt
        || !preg_match('/^sha256:[a-f0-9]{64}$/', (string)($row['deadline_snapshot_identity'] ?? ''))
        || !preg_match('/^sha256:[a-f0-9]{64}$/', (string)($row['prospective_keyset_identity'] ?? ''))) {
        return ['valid' => false, 'reason' => 'CANDIDATE_SCHEMA_OR_PROVENANCE_INVALID', 'prospective' => false, 'row' => []];
    }
    try {
        $prospective = (new DateTimeImmutable($cutoff)) > (new DateTimeImmutable($armedAt));
    } catch (Throwable) {
        return ['valid' => false, 'reason' => 'CANDIDATE_CUTOFF_INVALID', 'prospective' => false, 'row' => []];
    }
    $row['race_code'] = $race;
    $row['venue'] = $venue;
    $row['cutoff_timestamp'] = $cutoff;
    return ['valid' => true, 'reason' => $prospective ? '' : 'PRE_ARMED_RACE', 'prospective' => $prospective, 'row' => $row];
}

/** @return array{kind:string,reason:string,candidates:list<array<string,mixed>>,skipped:list<array<string,mixed>>} */
function cc23ProspectiveCandidates(FormalMeasurementWindowContext $context, string $date, string $armedAt): array
{
    $directory = $context->opportunityRoot() . '/candidates/' . str_replace('-', '', $date);
    if (!is_dir($directory)) return ['kind' => 'NOOP', 'reason' => 'CANDIDATE_NOT_READY', 'candidates' => [], 'skipped' => []];
    $paths = glob($directory . '/*.json') ?: [];
    if ($paths === []) return ['kind' => 'NOOP', 'reason' => 'CANDIDATE_NOT_READY', 'candidates' => [], 'skipped' => []];
    $candidates = []; $skipped = [];
    foreach ($paths as $path) {
        try { $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable) { return ['kind' => 'GLOBAL_FATAL', 'reason' => 'CANDIDATE_EVIDENCE_CORRUPT', 'candidates' => [], 'skipped' => []]; }
        if (!is_array($row)) return ['kind' => 'GLOBAL_FATAL', 'reason' => 'CANDIDATE_EVIDENCE_CORRUPT', 'candidates' => [], 'skipped' => []];
        $validated = cc23ValidateCandidate($row, $date, $armedAt);
        if (!$validated['valid']) return ['kind' => 'GLOBAL_FATAL', 'reason' => $validated['reason'], 'candidates' => [], 'skipped' => []];
        $race = (string)$validated['row']['race_code'];
        if (isset($candidates[$race]) || isset($skipped[$race])) return ['kind' => 'GLOBAL_FATAL', 'reason' => 'CANDIDATE_DUPLICATE_RACE_CODE', 'candidates' => [], 'skipped' => []];
        if (!$validated['prospective']) { $skipped[$race] = $validated['row']; continue; }
        $candidates[$race] = $validated['row'];
    }
    ksort($candidates, SORT_STRING);
    ksort($skipped, SORT_STRING);
    return ['kind' => 'READY', 'reason' => '', 'candidates' => array_values($candidates), 'skipped' => array_values($skipped)];
}

/** @return array{complete:bool,complete_boats:int,recorded_at:string} */
function cc23ExhibitionState(PDO $pdo, string $raceCode, string $venue, string $cutoff): array
{
    $straight = exhibitionRequiresStraight($venue) ? 'AND straight_time IS NOT NULL AND straight_time > 0' : '';
    $sql = <<<SQL
SELECT
  COUNT(DISTINCT entry_course) FILTER (
    WHERE entry_course BETWEEN 1 AND 6
      AND player_id IS NOT NULL
      AND exhibition_time IS NOT NULL AND exhibition_time > 0
      AND start_timing IS NOT NULL
      AND lap_time IS NOT NULL AND lap_time > 0
      AND around_time IS NOT NULL AND around_time > 0
      {$straight}
      AND created_date < CAST(:cutoff AS timestamptz)
  ) AS complete_boats,
  MAX(created_date) AS recorded_at
FROM boat_race.exhibition_live
WHERE race_code = :race_code
SQL;
    $statement = $pdo->prepare($sql);
    $statement->execute([':race_code' => $raceCode, ':cutoff' => $cutoff]);
    $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    $boats = (int)($row['complete_boats'] ?? 0);
    return ['complete' => $boats === 6, 'complete_boats' => $boats, 'recorded_at' => (string)($row['recorded_at'] ?? '')];
}

/** @return array{status:string,skip_reason:string} */
function cc23FetchDecision(DateTimeImmutable $now, DateTimeImmutable $cutoff, bool $complete, bool $supported): array
{
    if (!$supported) return ['status' => 'UNSUPPORTED_VENUE', 'skip_reason' => 'UNSUPPORTED_VENUE'];
    if ($complete) return ['status' => 'ALREADY_COMPLETE', 'skip_reason' => ''];
    $seconds = $cutoff->getTimestamp() - $now->getTimestamp();
    if ($seconds > CC23_FETCH_OPEN_SECONDS || $seconds < CC23_FETCH_CLOSE_SECONDS) return ['status' => 'OUTSIDE_FETCH_WINDOW', 'skip_reason' => 'OUTSIDE_FETCH_WINDOW'];
    return ['status' => 'FETCH_TARGET', 'skip_reason' => ''];
}

/** @return array{status:string,skip_reason:string} */
function cc23PostFetchDecision(bool $existingPathReportedSuccess, bool $completeAfter): array
{
    if ($completeAfter) return ['status' => 'FETCHED_COMPLETE', 'skip_reason' => ''];
    if ($existingPathReportedSuccess) return ['status' => 'RETRY_PENDING', 'skip_reason' => 'NOT_YET_AVAILABLE'];
    return ['status' => 'FETCH_FAILED', 'skip_reason' => 'EXISTING_FETCH_PATH_REPORTED_FAILURE'];
}

/** @return array{ok:bool,message:string} */
function cc23FetchViaExistingPath(ApiClientProduction $client, string $raceCode): array
{
    [$message] = $client->updateExhibition($raceCode);
    $message = (string)$message;
    return ['ok' => !str_starts_with($message, '展示情報の更新に失敗しました:'), 'message' => $message];
}

function cc23Main(array $argv): int
{
    try { ['dry_run' => $dryRun, 'now' => $now] = cc23Arguments($argv); }
    catch (Throwable $error) { cc23Log(['status' => 'FETCH_FAILED', 'skip_reason' => 'INVALID_ARGUMENT', 'error' => $error->getMessage()]); return 2; }
    if (!cc23DateAllowed($now)) {
        cc23Log(['status' => 'NOOP', 'skip_reason' => 'DATE_GUARD', 'error' => '', 'timestamp' => $now->format(DATE_ATOM)]);
        return 0;
    }
    $window = cc23ActiveWindow();
    if ($window['kind'] !== 'ACTIVE') {
        cc23Log(['status' => $window['kind'] === 'NOOP' ? 'NOOP' : 'GLOBAL_FAIL_LOUD', 'skip_reason' => $window['reason'], 'error' => $window['kind'] === 'GLOBAL_FATAL' ? $window['reason'] : '', 'timestamp' => $now->format(DATE_ATOM)]);
        return $window['kind'] === 'GLOBAL_FATAL' ? 1 : 0;
    }
    $context = $window['context'];
    $armedAt = (string)($window['authorization']['manifest']['armed_at'] ?? '');
    $candidateResult = cc23ProspectiveCandidates($context, CC23_TARGET_DATE, $armedAt);
    if ($candidateResult['kind'] !== 'READY') {
        cc23Log(['status' => $candidateResult['kind'] === 'NOOP' ? 'NOOP' : 'GLOBAL_FAIL_LOUD', 'skip_reason' => $candidateResult['reason'], 'error' => $candidateResult['kind'] === 'GLOBAL_FATAL' ? $candidateResult['reason'] : '', 'timestamp' => $now->format(DATE_ATOM)]);
        return $candidateResult['kind'] === 'GLOBAL_FATAL' ? 1 : 0;
    }
    foreach ($candidateResult['skipped'] as $candidate) {
        cc23Log(['timestamp' => $now->format(DATE_ATOM), 'race_code' => $candidate['race_code'], 'venue' => $candidate['venue'], 'cutoff' => $candidate['cutoff_timestamp'], 'status' => 'OUTSIDE_FETCH_WINDOW', 'skip_reason' => 'PRE_ARMED_RACE']);
    }
    if ($dryRun) {
        foreach ($candidateResult['candidates'] as $candidate) {
            $cutoff = new DateTimeImmutable((string)$candidate['cutoff_timestamp']);
            $decision = cc23FetchDecision($now, $cutoff, false, in_array((string)$candidate['venue'], cc23SupportedVenues(), true));
            cc23Log(['timestamp' => $now->format(DATE_ATOM), 'race_code' => $candidate['race_code'], 'venue' => $candidate['venue'], 'cutoff' => $candidate['cutoff_timestamp'], 'remaining_seconds_at_start' => $cutoff->getTimestamp() - $now->getTimestamp(), 'status' => $decision['status'], 'skip_reason' => $decision['skip_reason']]);
        }
        return 0;
    }
    try { $pdo = getPDO(); }
    catch (Throwable $error) { cc23Log(['status' => 'GLOBAL_FAIL_LOUD', 'skip_reason' => 'DB_UNAVAILABLE', 'error' => get_class($error)]); return 1; }
    $supported = array_fill_keys(cc23SupportedVenues(), true);
    $client = new ApiClientProduction();
    foreach ($candidateResult['candidates'] as $candidate) {
        $race = (string)$candidate['race_code']; $venue = (string)$candidate['venue']; $cutoffText = (string)$candidate['cutoff_timestamp'];
        $started = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
        try {
            $cutoff = new DateTimeImmutable($cutoffText);
            $before = cc23ExhibitionState($pdo, $race, $venue, $cutoffText);
            $decision = cc23FetchDecision($started, $cutoff, $before['complete'], isset($supported[$venue]));
            $event = ['race_code' => $race, 'venue' => $venue, 'cutoff' => $cutoffText, 'attempt_started_at' => $started->format(DATE_ATOM), 'remaining_seconds_at_start' => $cutoff->getTimestamp() - $started->getTimestamp(), 'complete_boats' => $before['complete_boats'], 'status' => $decision['status'], 'skip_reason' => $decision['skip_reason']];
            if ($decision['status'] !== 'FETCH_TARGET') { cc23Log($event); continue; }
            $fetch = cc23FetchViaExistingPath($client, $race);
            $after = cc23ExhibitionState($pdo, $race, $venue, $cutoffText);
            $event['attempt_finished_at'] = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM);
            $event['complete_boats'] = $after['complete_boats'];
            $postFetch = cc23PostFetchDecision($fetch['ok'], $after['complete']);
            $event['status'] = $postFetch['status'];
            $event['skip_reason'] = $postFetch['skip_reason'];
            if ($event['status'] === 'FETCH_FAILED') $event['error'] = $event['skip_reason'];
            cc23Log($event);
        } catch (Throwable $error) {
            cc23Log(['race_code' => $race, 'venue' => $venue, 'cutoff' => $cutoffText, 'attempt_started_at' => $started->format(DATE_ATOM), 'attempt_finished_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM), 'status' => 'FETCH_FAILED', 'skip_reason' => 'RACE_LOCAL_FAILURE', 'error' => get_class($error)]);
        }
    }
    return 0;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) exit(cc23Main($argv));
