<?php

declare(strict_types=1);

/**
 * Permanent, low-load exhibition collector for an operational Strict Forward
 * Window.  It intentionally has no formal-evidence writer: Candidates are
 * read from the ACTIVE Window and exhibition_live remains the only data it
 * can change through the existing production update path.
 */

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../logic/exhibition_data_quality.php';
require_once __DIR__ . '/../web/api/ApiClientProduction.php';
require_once __DIR__ . '/../web/logic/FormalMeasurementWindowContext.php';
require_once __DIR__ . '/../web/logic/GateA1ProspectiveInventory.php';
require_once __DIR__ . '/../web/logic/OfficialEpochLifecycleV1.php';
require_once __DIR__ . '/../web/logic/OfficialEpochWindowBindingRuntimeAuthorization.php';

const CC30B_COLLECTOR_SCHEMA = 'strict-forward-exhibition-collector/v2';
const CC30B_FETCH_OPEN_SECONDS = 15 * 60;
const CC30B_FETCH_CLOSE_SECONDS = 4 * 60;
const CC30B_LOCK_RELATIVE_PATH = 'var/strict_forward_live_readiness/runtime/strict_forward_exhibition_collector.lock';

/** @return array{dry_run:bool,now:DateTimeImmutable} */
function cc30bArguments(array $argv): array
{
    $dryRun = false;
    $nowText = null;
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--dry-run') {
            $dryRun = true;
        } elseif (str_starts_with($argument, '--now=')) {
            $nowText = substr($argument, strlen('--now='));
        } else {
            throw new InvalidArgumentException('unknown_argument:' . $argument);
        }
    }
    if ($nowText !== null && !$dryRun) throw new InvalidArgumentException('now_override_requires_dry_run');
    $timezone = new DateTimeZone('Asia/Tokyo');
    $now = $nowText === null ? new DateTimeImmutable('now', $timezone) : new DateTimeImmutable($nowText, $timezone);
    return ['dry_run' => $dryRun, 'now' => $now->setTimezone($timezone)];
}

/** @return array{kind:string,reason:string} */
function cc30bEpochStateGuard(bool $recordPresent, bool $projectionValid, string $state): array
{
    if (!$recordPresent || $state === 'NOT_STARTED') return ['kind' => 'NOOP', 'reason' => 'OFFICIAL_EPOCH_NOT_STARTED'];
    if (!$projectionValid) return ['kind' => 'GLOBAL_FATAL', 'reason' => 'OFFICIAL_EPOCH_STATE_INVALID'];
    if (in_array($state, ['SUSPENDED', 'ENDED'], true)) return ['kind' => 'GLOBAL_FATAL', 'reason' => 'OFFICIAL_EPOCH_' . $state];
    if ($state !== 'ACTIVE') return ['kind' => 'GLOBAL_FATAL', 'reason' => 'OFFICIAL_EPOCH_STATE_INVALID'];
    return ['kind' => 'ACTIVE', 'reason' => ''];
}

/** @return array{kind:string,reason:string,epoch_id:string} */
function cc30bEpochGuard(string $repoRoot): array
{
    try {
        $lifecycle = new OfficialEpochLifecycleV1($repoRoot);
        $start = $lifecycle->startStore()->existing();
        if ($start === []) return ['kind' => 'NOOP', 'reason' => 'OFFICIAL_EPOCH_NOT_STARTED', 'epoch_id' => ''];
        $epochId = (string)($start['epoch_id'] ?? '');
        $projection = $lifecycle->incidentStore()->project($epochId);
        $guard = cc30bEpochStateGuard(true, !empty($projection['valid']), (string)($projection['state'] ?? ''));
        return ['kind' => $guard['kind'], 'reason' => $guard['reason'], 'epoch_id' => $epochId];
    } catch (Throwable $error) {
        return ['kind' => 'GLOBAL_FATAL', 'reason' => 'OFFICIAL_EPOCH_CONTEXT_UNAVAILABLE:' . get_class($error), 'epoch_id' => ''];
    }
}

/** @return array{kind:string,reason:string} */
function cc30bWindowGuard(string $effectiveState, bool $authorizationAccepted, string $authorizationReason, int $activeWindowCount = 1): array
{
    if ($activeWindowCount !== 1) return ['kind' => 'GLOBAL_FATAL', 'reason' => 'OPERATIONAL_ACTIVE_WINDOW_CARDINALITY_INVALID'];
    if ($effectiveState !== 'ACTIVE') return ['kind' => 'NOOP', 'reason' => 'SAFE_NOOP_NO_ACTIVE_WINDOW'];
    if (!$authorizationAccepted) return ['kind' => 'GLOBAL_FATAL', 'reason' => $authorizationReason === '' ? 'MEASUREMENT_IDENTITY_OR_ACTIVATION_INVALID' : $authorizationReason];
    return ['kind' => 'ACTIVE', 'reason' => ''];
}

/** @return array{kind:string,reason:string,context:?FormalMeasurementWindowContext,authorization:array<string,mixed>,window_id:string} */
function cc30bActiveWindow(string $repoRoot): array
{
    $runtime = (new OfficialEpochWindowBindingRuntimeAuthorization($repoRoot))->authorizeOperationalPointerWindow();
    return ['kind' => (string)($runtime['kind'] ?? 'GLOBAL_FATAL'), 'reason' => (string)($runtime['reason'] ?? 'OFFICIAL_EPOCH_WINDOW_BINDING_INVALID'), 'context' => $runtime['context'] ?? null, 'authorization' => $runtime['authorization'] ?? [], 'window_id' => (string)($runtime['window_id'] ?? '')];
}

/** @return list<string> */
function cc30bSupportedVenues(): array
{
    $required = ['TMG', 'TDA', 'OMR', 'SMS', 'SME', 'KRY', 'BWK', 'ASY', 'AMG', 'HWJ', 'EDG', 'MKN'];
    $map = require __DIR__ . '/../config/place_map_reverse.php';
    $available = array_fill_keys(array_map(static fn(mixed $value): string => strtoupper(trim((string)$value)), array_keys($map)), true);
    foreach ($required as $venue) {
        if (!isset($available[$venue])) throw new RuntimeException('STRICT_FORWARD_VENUE_MAPPING_MISSING:' . $venue);
    }
    return $required;
}

/** @param array<string,mixed> $event */
function cc30bLog(array $event): void
{
    $event = array_merge([
        'schema_version' => CC30B_COLLECTOR_SCHEMA,
        'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM),
        'epoch_id' => '', 'window_id' => '', 'race_code' => '', 'venue' => '', 'cutoff' => '',
        'attempt_started_at' => '', 'attempt_finished_at' => '', 'remaining_seconds_at_start' => null,
        'status' => '', 'complete_boats' => 0, 'skip_reason' => '', 'error' => '',
    ], $event);
    echo json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

/** @return array{valid:bool,reason:string,row:array<string,mixed>} */
function cc30bValidateCandidate(array $row, string $armedAt): array
{
    $race = strtoupper(trim((string)($row['race_code'] ?? '')));
    $venue = strtoupper(trim((string)($row['venue'] ?? '')));
    $targetDate = trim((string)($row['target_date'] ?? ''));
    $cutoff = trim((string)($row['cutoff_timestamp'] ?? ''));
    $raceDate = strlen($race) >= 8 ? substr($race, 0, 8) : '';
    if (($row['record_type'] ?? '') !== 'SCHEDULER_CANDIDATE_UNIVERSE'
        || !preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $race)
        || !preg_match('/^[A-Z0-9]{3}$/', $venue)
        || $venue !== substr($race, 8, 3)
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)
        || str_replace('-', '', $targetDate) !== $raceDate
        || ($row['stable_candidate_key'] ?? '') !== 'scheduler-opportunity|' . $race
        || ($row['prospective_filter_contract'] ?? '') !== GateA1ProspectiveInventory::PROSPECTIVE_FILTER_CONTRACT_VERSION
        || ($row['armed_at'] ?? '') !== $armedAt
        || !preg_match('/^sha256:[a-f0-9]{64}$/', (string)($row['deadline_snapshot_identity'] ?? ''))
        || !preg_match('/^sha256:[a-f0-9]{64}$/', (string)($row['prospective_keyset_identity'] ?? ''))) {
        return ['valid' => false, 'reason' => 'CANDIDATE_SCHEMA_OR_PROVENANCE_INVALID', 'row' => []];
    }
    try {
        if (!((new DateTimeImmutable($cutoff)) > (new DateTimeImmutable($armedAt)))) {
            return ['valid' => false, 'reason' => 'CANDIDATE_NOT_PROSPECTIVE', 'row' => []];
        }
    } catch (Throwable) {
        return ['valid' => false, 'reason' => 'CANDIDATE_CUTOFF_INVALID', 'row' => []];
    }
    $row['race_code'] = $race;
    $row['venue'] = $venue;
    $row['target_date'] = $targetDate;
    $row['cutoff_timestamp'] = $cutoff;
    return ['valid' => true, 'reason' => '', 'row' => $row];
}

/** @return array{kind:string,reason:string,candidates:list<array<string,mixed>>} */
function cc30bCandidates(FormalMeasurementWindowContext $context, string $armedAt): array
{
    $directory = $context->opportunityRoot() . '/candidates';
    if (!is_dir($directory)) return ['kind' => 'NOOP', 'reason' => 'CANDIDATE_NOT_READY', 'candidates' => []];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    $candidates = [];
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'json') continue;
        try {
            $row = json_decode((string)file_get_contents($file->getPathname()), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ['kind' => 'GLOBAL_FATAL', 'reason' => 'CANDIDATE_EVIDENCE_CORRUPT', 'candidates' => []];
        }
        if (!is_array($row)) return ['kind' => 'GLOBAL_FATAL', 'reason' => 'CANDIDATE_EVIDENCE_CORRUPT', 'candidates' => []];
        $validated = cc30bValidateCandidate($row, $armedAt);
        if (!$validated['valid']) return ['kind' => 'GLOBAL_FATAL', 'reason' => $validated['reason'], 'candidates' => []];
        $race = (string)$validated['row']['race_code'];
        if (isset($candidates[$race])) return ['kind' => 'GLOBAL_FATAL', 'reason' => 'CANDIDATE_DUPLICATE_RACE_CODE', 'candidates' => []];
        $candidates[$race] = $validated['row'];
    }
    if ($candidates === []) return ['kind' => 'NOOP', 'reason' => 'CANDIDATE_NOT_READY', 'candidates' => []];
    ksort($candidates, SORT_STRING);
    return ['kind' => 'READY', 'reason' => '', 'candidates' => array_values($candidates)];
}

/** @return array{complete:bool,complete_boats:int,recorded_at:string} */
function cc30bExhibitionState(PDO $pdo, string $raceCode, string $venue, string $cutoff): array
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
function cc30bFetchDecision(DateTimeImmutable $now, DateTimeImmutable $cutoff, bool $complete, bool $supported): array
{
    if (!$supported) return ['status' => 'UNSUPPORTED_VENUE', 'skip_reason' => 'UNSUPPORTED_VENUE'];
    if ($complete) return ['status' => 'ALREADY_SATISFIED', 'skip_reason' => ''];
    $seconds = $cutoff->getTimestamp() - $now->getTimestamp();
    if ($seconds > CC30B_FETCH_OPEN_SECONDS) return ['status' => 'TOO_EARLY', 'skip_reason' => 'BEFORE_FETCH_WINDOW'];
    if ($seconds < CC30B_FETCH_CLOSE_SECONDS) return ['status' => 'TOO_LATE', 'skip_reason' => 'AFTER_FETCH_WINDOW'];
    return ['status' => 'FETCH_ELIGIBLE', 'skip_reason' => ''];
}

/** @return array{status:string,skip_reason:string} */
function cc30bPostFetchDecision(bool $existingPathReportedSuccess, bool $completeAfter): array
{
    if ($completeAfter) return ['status' => 'FETCHED_COMPLETE', 'skip_reason' => ''];
    if ($existingPathReportedSuccess) return ['status' => 'RETRY_PENDING', 'skip_reason' => 'NOT_YET_AVAILABLE'];
    return ['status' => 'FETCH_FAILED', 'skip_reason' => 'EXISTING_FETCH_PATH_REPORTED_FAILURE'];
}

/** @return array{ok:bool,message:string} */
function cc30bFetchViaExistingPath(ApiClientProduction $client, string $raceCode): array
{
    [$message] = $client->updateExhibition($raceCode);
    $message = (string)$message;
    return ['ok' => !str_starts_with($message, '展示情報の更新に失敗しました:'), 'message' => $message];
}

/** @param array<string,int> $counts */
function cc30bSummary(string $status, DateTimeImmutable $started, DateTimeImmutable $finished, string $epochId, string $windowId, int $candidateCount, array $counts, string $reason = '', string $error = ''): void
{
    cc30bLog([
        'timestamp' => $finished->format(DATE_ATOM), 'status' => $status, 'skip_reason' => $reason, 'error' => $error,
        'epoch_id' => $epochId, 'window_id' => $windowId, 'started_at' => $started->format(DATE_ATOM), 'finished_at' => $finished->format(DATE_ATOM),
        'candidate_count' => $candidateCount, 'too_early' => $counts['TOO_EARLY'] ?? 0, 'fetch_eligible' => $counts['FETCH_ELIGIBLE'] ?? 0,
        'already_satisfied' => $counts['ALREADY_SATISFIED'] ?? 0, 'fetch_attempted' => $counts['FETCH_ATTEMPTED'] ?? 0,
        'fetch_success' => $counts['FETCHED_COMPLETE'] ?? 0, 'fetch_failed' => $counts['FETCH_FAILED'] ?? 0,
        'too_late' => $counts['TOO_LATE'] ?? 0, 'retry_pending' => $counts['RETRY_PENDING'] ?? 0,
    ]);
}

/** @return resource|false */
function cc30bAcquireLock(string $repoRoot)
{
    $path = rtrim($repoRoot, DIRECTORY_SEPARATOR) . '/' . CC30B_LOCK_RELATIVE_PATH;
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) return false;
    $lock = @fopen($path, 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        return false;
    }
    return $lock;
}

function cc30bMain(array $argv, ?string $repoOverride = null): int
{
    try {
        ['dry_run' => $dryRun, 'now' => $now] = cc30bArguments($argv);
    } catch (Throwable $error) {
        cc30bLog(['status' => 'GLOBAL_FATAL', 'skip_reason' => 'INVALID_ARGUMENT', 'error' => $error->getMessage()]);
        return 2;
    }
    $repoRoot = $repoOverride ?? dirname(__DIR__);
    $started = $now;
    $counts = [];
    $lock = cc30bAcquireLock($repoRoot);
    if ($lock === false) {
        cc30bSummary('ALREADY_RUNNING', $started, $now, '', '', 0, $counts, 'ALREADY_RUNNING');
        return 0;
    }
    try {
        $epoch = cc30bEpochGuard($repoRoot);
        if ($epoch['kind'] !== 'ACTIVE') {
            $status = $epoch['kind'] === 'NOOP' ? 'SAFE_NOOP_EPOCH_NOT_ACTIVE' : 'GLOBAL_FATAL';
            cc30bSummary($status, $started, $now, $epoch['epoch_id'], '', 0, $counts, $epoch['reason'], $epoch['kind'] === 'GLOBAL_FATAL' ? $epoch['reason'] : '');
            return $epoch['kind'] === 'GLOBAL_FATAL' ? 1 : 0;
        }
        $window = cc30bActiveWindow($repoRoot);
        if ($window['kind'] !== 'ACTIVE') {
            $status = $window['kind'] === 'NOOP' ? 'SAFE_NOOP_NO_ACTIVE_WINDOW' : 'GLOBAL_FATAL';
            cc30bSummary($status, $started, $now, $epoch['epoch_id'], $window['window_id'], 0, $counts, $window['reason'], $window['kind'] === 'GLOBAL_FATAL' ? $window['reason'] : '');
            return $window['kind'] === 'GLOBAL_FATAL' ? 1 : 0;
        }
        $context = $window['context'];
        $armedAt = (string)($window['authorization']['manifest']['armed_at'] ?? '');
        $candidateResult = cc30bCandidates($context, $armedAt);
        if ($candidateResult['kind'] !== 'READY') {
            $status = $candidateResult['kind'] === 'NOOP' ? 'SAFE_NOOP_NO_ACTIVE_WINDOW' : 'GLOBAL_FATAL';
            cc30bSummary($status, $started, $now, $epoch['epoch_id'], $window['window_id'], 0, $counts, $candidateResult['reason'], $candidateResult['kind'] === 'GLOBAL_FATAL' ? $candidateResult['reason'] : '');
            return $candidateResult['kind'] === 'GLOBAL_FATAL' ? 1 : 0;
        }
        $candidates = $candidateResult['candidates'];
        $supported = array_fill_keys(cc30bSupportedVenues(), true);
        if ($dryRun) {
            foreach ($candidates as $candidate) {
                $cutoff = new DateTimeImmutable((string)$candidate['cutoff_timestamp']);
                $decision = cc30bFetchDecision($now, $cutoff, false, isset($supported[(string)$candidate['venue']]));
                $counts[$decision['status']] = ($counts[$decision['status']] ?? 0) + 1;
                cc30bLog(['timestamp' => $now->format(DATE_ATOM), 'epoch_id' => $epoch['epoch_id'], 'window_id' => $window['window_id'], 'race_code' => $candidate['race_code'], 'venue' => $candidate['venue'], 'cutoff' => $candidate['cutoff_timestamp'], 'remaining_seconds_at_start' => $cutoff->getTimestamp() - $now->getTimestamp(), 'status' => $decision['status'], 'skip_reason' => $decision['skip_reason']]);
            }
            cc30bSummary('SUCCESS', $started, $now, $epoch['epoch_id'], $window['window_id'], count($candidates), $counts);
            return 0;
        }
        try {
            $pdo = getPDO();
        } catch (Throwable $error) {
            cc30bSummary('GLOBAL_FATAL', $started, new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')), $epoch['epoch_id'], $window['window_id'], count($candidates), $counts, 'DB_UNAVAILABLE', get_class($error));
            return 1;
        }
        $client = new ApiClientProduction();
        foreach ($candidates as $candidate) {
            $race = (string)$candidate['race_code'];
            $venue = (string)$candidate['venue'];
            $cutoffText = (string)$candidate['cutoff_timestamp'];
            $attemptAt = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
            try {
                $cutoff = new DateTimeImmutable($cutoffText);
                $before = cc30bExhibitionState($pdo, $race, $venue, $cutoffText);
                $decision = cc30bFetchDecision($attemptAt, $cutoff, $before['complete'], isset($supported[$venue]));
                $counts[$decision['status']] = ($counts[$decision['status']] ?? 0) + 1;
                $event = ['epoch_id' => $epoch['epoch_id'], 'window_id' => $window['window_id'], 'race_code' => $race, 'venue' => $venue, 'cutoff' => $cutoffText, 'attempt_started_at' => $attemptAt->format(DATE_ATOM), 'remaining_seconds_at_start' => $cutoff->getTimestamp() - $attemptAt->getTimestamp(), 'complete_boats' => $before['complete_boats'], 'status' => $decision['status'], 'skip_reason' => $decision['skip_reason']];
                if ($decision['status'] !== 'FETCH_ELIGIBLE') {
                    cc30bLog($event);
                    continue;
                }
                $counts['FETCH_ATTEMPTED'] = ($counts['FETCH_ATTEMPTED'] ?? 0) + 1;
                $fetch = cc30bFetchViaExistingPath($client, $race);
                $after = cc30bExhibitionState($pdo, $race, $venue, $cutoffText);
                $event['attempt_finished_at'] = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM);
                $event['complete_boats'] = $after['complete_boats'];
                $postFetch = cc30bPostFetchDecision($fetch['ok'], $after['complete']);
                $event['status'] = $postFetch['status'];
                $event['skip_reason'] = $postFetch['skip_reason'];
                $counts[$event['status']] = ($counts[$event['status']] ?? 0) + 1;
                if ($event['status'] === 'FETCH_FAILED') $event['error'] = $event['skip_reason'];
                cc30bLog($event);
            } catch (Throwable $error) {
                $counts['FETCH_FAILED'] = ($counts['FETCH_FAILED'] ?? 0) + 1;
                cc30bLog(['epoch_id' => $epoch['epoch_id'], 'window_id' => $window['window_id'], 'race_code' => $race, 'venue' => $venue, 'cutoff' => $cutoffText, 'attempt_started_at' => $attemptAt->format(DATE_ATOM), 'attempt_finished_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM), 'status' => 'FETCH_FAILED', 'skip_reason' => 'RACE_LOCAL_FAILURE', 'error' => get_class($error)]);
            }
        }
        $finished = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
        $status = ($counts['FETCH_FAILED'] ?? 0) > 0 ? 'PARTIAL_RACE_FAILURE' : 'SUCCESS';
        cc30bSummary($status, $started, $finished, $epoch['epoch_id'], $window['window_id'], count($candidates), $counts);
        return 0;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) exit(cc30bMain($argv));
