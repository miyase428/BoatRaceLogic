<?php

declare(strict_types=1);

/**
 * Lightweight, deadline-driven late observer for already-frozen Candidates.
 *
 * It does not discover races, call an external service, start the collector,
 * or create Expected/Canonical evidence.  Its sole write is a first-write
 * eligibility resolution from the same cutoff-safe DB predicate used by the
 * collector.  A scoped finalizer only finalizes races first observed by this
 * producer, so deploying it cannot repair a historical scheduler gap.
 */

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../web/logic/OfficialEpochWindowBindingRuntimeAuthorization.php';
require_once __DIR__ . '/../web/logic/StrictForwardOpportunityLedger.php';
require_once __DIR__ . '/../web/logic/StrictForwardExhibitionEligibilityState.php';

const CC30C_LATE_ELIGIBILITY_SCHEMA = 'strict-forward-late-eligibility/v1';
const CC30C_LATE_ELIGIBILITY_REASON = 'LATE_DEADLINE_DB_ONLY_ELIGIBILITY_READ';
const CC30C_LATE_ELIGIBILITY_LOCK = '/tmp/boatrace_strict_forward_late_eligibility.lock';

/** @return array{dry_run:bool,now:DateTimeImmutable} */
function cc30cLateEligibilityArguments(array $argv): array
{
    $dryRun = false; $nowText = null;
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--dry-run') $dryRun = true;
        elseif (str_starts_with($argument, '--now=')) $nowText = substr($argument, 6);
        else throw new InvalidArgumentException('unknown_argument:' . $argument);
    }
    if ($nowText !== null && !$dryRun) throw new InvalidArgumentException('now_override_requires_dry_run');
    $tz = new DateTimeZone('Asia/Tokyo');
    $now = $nowText === null ? new DateTimeImmutable('now', $tz) : new DateTimeImmutable($nowText, $tz);
    return ['dry_run' => $dryRun, 'now' => $now->setTimezone($tz)];
}

/** @return list<string> */
function cc30cLateEligibilityPriorScope(string $opportunityRoot, string $date): array
{
    $path = rtrim($opportunityRoot, DIRECTORY_SEPARATOR) . '/observations/' . str_replace('-', '', $date) . '.jsonl';
    if (!is_file($path)) return [];
    $scope = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $row = json_decode($line, true);
        if (is_array($row) && ($row['reason'] ?? '') === CC30C_LATE_ELIGIBILITY_REASON
            && is_string($row['race_code'] ?? null)) $scope[(string)$row['race_code']] = true;
    }
    $races = array_keys($scope); sort($races, SORT_STRING);
    return $races;
}

/** @return array{active:list<array<string,mixed>>,expired_scope:list<string>} */
function cc30cLateEligibilityScope(StrictForwardOpportunityLedger $ledger, string $date, DateTimeImmutable $now): array
{
    $active = [];
    foreach ($ledger->candidateRows($date) as $candidate) {
        if (is_file(rtrim($ledger->rootPath(), DIRECTORY_SEPARATOR) . '/eligibility/' . str_replace('-', '', $date) . '/' . hash('sha256', (string)$candidate['stable_candidate_key']) . '.json')) continue;
        try {
            if ($now < new DateTimeImmutable((string)$candidate['cutoff_timestamp'])) $active[] = $candidate;
        } catch (Throwable) {
            // The ledger's candidate schema guarantees this in production;
            // invalid fixture input must not produce a write.
        }
    }
    return ['active' => $active, 'expired_scope' => cc30cLateEligibilityPriorScope($ledger->rootPath(), $date)];
}

/**
 * @param callable(string,string,string):array{complete:bool,complete_boats:int,recorded_at:string} $predicate
 * @return array<string,mixed>
 */
function cc30cLateEligibilityRun(
    StrictForwardOpportunityLedger $ledger,
    string $date,
    DateTimeImmutable $now,
    callable $predicate,
    bool $dryRun,
): array {
    $scope = cc30cLateEligibilityScope($ledger, $date, $now);
    $activeCodes = []; $ready = []; $incomplete = [];
    foreach ($scope['active'] as $candidate) {
        $race = (string)$candidate['race_code']; $activeCodes[] = $race;
        $state = $predicate($race, (string)$candidate['venue'], (string)$candidate['cutoff_timestamp']);
        if (!empty($state['complete'])) $ready[] = $race; else $incomplete[] = $race;
    }
    if (!$dryRun && $activeCodes !== []) {
        $ledger->observeEligibility([
            'date' => $date, 'observed_at' => $now->format(DATE_ATOM), 'race_codes' => $activeCodes,
            'ready_race_codes' => $ready, 'input_availability' => 'AVAILABLE',
            'reason' => CC30C_LATE_ELIGIBILITY_REASON, 'finalize_expired' => false,
        ]);
    }
    // Only a race with an earlier event from this producer enters this scoped
    // finalizer. Existing historical observations can never be promoted.
    if (!$dryRun && $scope['expired_scope'] !== []) {
        $ledger->finalizeExpired(['date' => $date, 'observed_at' => $now->format(DATE_ATOM), 'race_codes' => $scope['expired_scope']]);
    }
    return [
        'schema_version' => CC30C_LATE_ELIGIBILITY_SCHEMA,
        'date' => $date, 'observed_at' => $now->format(DATE_ATOM), 'active_candidate_count' => count($activeCodes),
        'active_race_codes' => $activeCodes, 'eligible_before_cutoff' => $ready,
        'incomplete_before_cutoff' => $incomplete, 'expired_previously_late_observed' => $scope['expired_scope'],
        'dry_run' => $dryRun, 'external_access' => false, 'candidate_creation' => false,
        'collector_started' => false,
    ];
}

function cc30cLateEligibilityMain(array $argv): int
{
    $arguments = cc30cLateEligibilityArguments($argv);
    $runtime = (new OfficialEpochWindowBindingRuntimeAuthorization(dirname(__DIR__)))->authorizeOperationalPointerWindow();
    if (($runtime['kind'] ?? '') !== 'ACTIVE' || !isset($runtime['context'])) {
        echo json_encode(['schema_version' => CC30C_LATE_ELIGIBILITY_SCHEMA, 'status' => 'NOOP', 'reason' => $runtime['reason'] ?? 'WINDOW_NOT_ACTIVE']) . PHP_EOL;
        return 0;
    }
    $date = $arguments['now']->format('Y-m-d');
    $ledger = StrictForwardOpportunityLedger::production($runtime['context']);
    $pdo = getPDO();
    $report = cc30cLateEligibilityRun(
        $ledger,
        $date,
        $arguments['now'],
        static fn(string $race, string $venue, string $cutoff): array => StrictForwardExhibitionEligibilityState::forRace($pdo, $race, $venue, $cutoff),
        $arguments['dry_run'],
    );
    $report['status'] = $arguments['dry_run'] ? 'DRY_RUN' : 'OK';
    echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    return 0;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $lock = fopen(CC30C_LATE_ELIGIBILITY_LOCK, 'c');
    if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);
    try { exit(cc30cLateEligibilityMain($argv)); }
    finally { flock($lock, LOCK_UN); fclose($lock); }
}
