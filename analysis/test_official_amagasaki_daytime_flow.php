<?php

declare(strict_types=1);

require_once __DIR__ . '/../logic/OfficialAmagasakiDaytimeFlow.php';

final class FakeAttemptClaimStore implements OfficialAmagasakiAttemptClaimStore
{
    /** @var array<string,array<string,mixed>> */ public array $states = [];
    public function claim(string $raceCode, int $expectedVersion, array $state): bool
    {
        $current = $this->states[$raceCode] ?? OfficialAmagasakiAttemptState::initial($raceCode);
        if ((int)$current['version'] !== $expectedVersion || $current['outcome'] === 'in_progress') return false;
        $this->states[$raceCode] = $state;
        return true;
    }
}

function daytimeCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function daytimeEntries(): array { return array_map(static fn(int $lane): array => ['lane_number' => $lane, 'player_id' => (string)(6100 + $lane)], range(1, 6)); }
function daytimePayload(string $status = 'published'): array {
    if ($status !== 'published') return ['status' => $status];
    $boats = [];
    foreach (range(1, 6) as $lane) $boats[] = ['lane' => $lane, 'entry_course' => $lane, 'player_id' => (string)(6100 + $lane), 'exhibition_time' => '6.8' . $lane, 'start_timing' => $lane === 1 ? 'F.01' : ($lane === 2 ? 'L.02' : ($lane === 3 ? '0.00' : '.0' . $lane)), 'lap_time' => '37.' . (10 + $lane), 'around_time' => '11.' . (20 + $lane), 'straight_time' => null];
    return ['schema' => 'official-exhibition/v1', 'status' => 'published', 'source' => 'official_amagasaki', 'source_url' => 'https://example.invalid/amg', 'race_date' => '20261021', 'race_no' => 1, 'race_code' => '20261021AMG01', 'fetched_at' => '2026-10-21T10:00:03.000Z', 'page_fetched_at' => ['direct_info' => '2026-10-21T10:00:00.000Z', 'start_display' => '2026-10-21T10:00:01.000Z', 'original_data' => '2026-10-21T10:00:02.000Z'], 'boats' => $boats];
}
function daytimeCandidate(?array $state = null, string $race = '20261021AMG01', string $existing = 'none', string $deadline = '2026-10-21T10:10:00+00:00'): array { return ['race_code' => $race, 'deadline' => $deadline, 'existing_status' => $existing, 'attempt_state' => $state]; }
function timeForSeconds(int $seconds): DateTimeImmutable { return (new DateTimeImmutable('2026-10-21T10:10:00+00:00'))->modify('-' . $seconds . ' seconds'); }

$flow = new OfficialAmagasakiDaytimeFlow(new OfficialExhibitionSourceRouter(true), new OfficialAmagasakiExhibitionValidator(), 120, 120, false, 90);
$disabled = new OfficialAmagasakiDaytimeFlow(new OfficialExhibitionSourceRouter(false), new OfficialAmagasakiExhibitionValidator(), 120, 120, false, 90);
$cases = 0;

daytimeCheck($flow->plan(daytimeCandidate(), timeForSeconds(600))['action'] === 'claim_first', '10 minutes is first-window start'); $cases++;
daytimeCheck($flow->plan(daytimeCandidate(), timeForSeconds(540))['action'] === 'claim_first', '9 minutes is first-window'); $cases++;
daytimeCheck($flow->plan(daytimeCandidate(), timeForSeconds(480))['reason'] === 'FIRST_FETCH_WINDOW_MISSED', '8 minute boundary does not fetch'); $cases++;
daytimeCheck($flow->plan(daytimeCandidate(), timeForSeconds(660))['reason'] === 'BEFORE_FIRST_FETCH_WINDOW', '11 minutes does not fetch'); $cases++;

$claimStore = new FakeAttemptClaimStore();
$firstPlan = $flow->plan(daytimeCandidate(), timeForSeconds(600));
$first = $flow->claim($firstPlan, timeForSeconds(600), $claimStore, 'claim-1');
daytimeCheck($first['claimed'] && $first['state']['attempt_count'] === 1 && $first['state']['outcome'] === 'in_progress', 'first attempt claims atomically'); $cases++;
daytimeCheck(!$flow->claim($firstPlan, timeForSeconds(600), $claimStore, 'claim-2')['claimed'], 'same race rejects a concurrent claim'); $cases++;
daytimeCheck($flow->plan(daytimeCandidate($first['state']), timeForSeconds(590))['reason'] === 'ATTEMPT_ALREADY_CLAIMED', 'retry cannot start while first claim is live'); $cases++;
$unpublished = $flow->completeClaim($first['state'], 'claim-1', 'not_yet_published', timeForSeconds(599));
daytimeCheck($unpublished['outcome'] === 'not_yet_published' && !$unpublished['terminal'] && $unpublished['attempt_count'] === 1, 'unpublished first result is retryable'); $cases++;
daytimeCheck($flow->plan(daytimeCandidate($unpublished), timeForSeconds(305))['reason'] === 'BEFORE_RETRY_FETCH_WINDOW', 'first attempt is not repeated before retry window'); $cases++;
daytimeCheck($flow->plan(daytimeCandidate($unpublished), timeForSeconds(300))['action'] === 'claim_retry', '5 minute boundary is retry window start'); $cases++;
daytimeCheck($flow->plan(daytimeCandidate($unpublished), timeForSeconds(180))['action'] === 'claim_retry', '3 minutes is retry window'); $cases++;
daytimeCheck($flow->plan(daytimeCandidate($unpublished), timeForSeconds(120))['reason'] === 'CUTOFF_SAFETY_WINDOW_CLOSED', '2 minute boundary stops all attempts'); $cases++;
daytimeCheck($flow->plan(daytimeCandidate($unpublished), timeForSeconds(-1))['reason'] === 'CUTOFF_SAFETY_WINDOW_CLOSED', 'post-cutoff stops all attempts'); $cases++;

$retryStore = new FakeAttemptClaimStore(); $retryStore->states['20261021AMG01'] = $unpublished;
$retry = $flow->claim($flow->plan(daytimeCandidate($unpublished), timeForSeconds(300)), timeForSeconds(300), $retryStore, 'claim-3');
$unpublishedAgain = $flow->completeClaim($retry['state'], 'claim-3', 'not_yet_published', timeForSeconds(299));
daytimeCheck($unpublishedAgain['terminal'] && $unpublishedAgain['attempt_count'] === 2, 'second unpublished attempt is terminal'); $cases++;
daytimeCheck($flow->plan(daytimeCandidate($unpublishedAgain), timeForSeconds(250))['reason'] === 'RETRY_LIMIT_REACHED', 'two attempts prevent further requests'); $cases++;

$badRetry = $unpublished; $badRetry['retry_not_before'] = 'bad';
daytimeCheck($flow->plan(daytimeCandidate($badRetry), timeForSeconds(300))['reason'] === 'ATTEMPT_STATE_TIMESTAMP_INVALID', 'invalid retry timestamp fails closed'); $cases++;
$badState = ['race_code' => '20261021AMG01', 'source' => 'official_amagasaki', 'outcome' => 'unrecognised', 'attempt_count' => 1, 'terminal' => false, 'manual_review_required' => false];
daytimeCheck($flow->plan(daytimeCandidate($badState), timeForSeconds(600))['action'] === 'blocked', 'invalid attempt state fails closed'); $cases++;
$impossibleState = OfficialAmagasakiAttemptState::initial('20261021AMG01'); $impossibleState['attempt_count'] = 1;
daytimeCheck($flow->plan(daytimeCandidate($impossibleState), timeForSeconds(600))['reason'] === 'ATTEMPT_STATE_TRANSITION_INVALID', 'impossible state transition fails closed'); $cases++;
$stale = OfficialAmagasakiAttemptState::initial('20261021AMG01'); $stale = OfficialAmagasakiAttemptState::claim($stale, timeForSeconds(600), 'stale', 30);
daytimeCheck($flow->plan(daytimeCandidate($stale), timeForSeconds(500))['reason'] === 'STALE_ATTEMPT_CLAIM_REQUIRES_REVIEW', 'expired claim is never auto-retried'); $cases++;

$saved = $flow->completeClaim($first['state'], 'claim-1', 'saved', timeForSeconds(599));
daytimeCheck($saved['terminal'] && !$saved['manual_review_required'], 'saved outcome is terminal'); $cases++;
daytimeCheck($flow->plan(daytimeCandidate($saved), timeForSeconds(300))['reason'] === 'RETRY_LIMIT_REACHED', 'saved result is not re-fetched'); $cases++;
foreach (['http_restricted', 'server_error', 'parse_error', 'validation_error', 'source_unknown'] as $outcome) {
    $state = $flow->completeClaim($first['state'], 'claim-1', $outcome, timeForSeconds(599));
    daytimeCheck($state['terminal'] && $state['manual_review_required'], "{$outcome} requires manual review"); $cases++;
}
daytimeCheck($disabled->plan(daytimeCandidate(), timeForSeconds(600))['reason'] === 'OFFICIAL_AUTOMATION_NOT_APPROVED', 'unapproved automation never fetches'); $cases++;
foreach (['http_403', 'http_429', 'http_5xx', 'captcha', 'parse_error'] as $failure) {
    daytimeCheck($flow->evaluatePayload(daytimePayload($failure), '20261021AMG01', daytimeEntries(), '2026-10-21T10:10:00.000Z')['action'] === 'no_save', "{$failure} has no fallback or save"); $cases++;
}

daytimeCheck($flow->evaluatePayload(daytimePayload('not_yet_published'), '20261021AMG01', daytimeEntries(), '2026-10-21T10:10:00.000Z')['action'] === 'no_save', 'unpublished payload is not saved'); $cases++;
daytimeCheck($flow->evaluatePayload(daytimePayload(), '20261021AMG01', daytimeEntries(), '2026-10-21T10:10:00.000Z')['action'] === 'save', 'pre-cutoff F/L/0.00 payload is saveable'); $cases++;
$badPayload = daytimePayload(); $badPayload['boats'][0]['entry_course'] = 2;
daytimeCheck($flow->evaluatePayload($badPayload, '20261021AMG01', daytimeEntries(), '2026-10-21T10:10:00.000Z')['action'] === 'no_save', 'entry change mismatch is not saved'); $cases++;
$incompletePayload = daytimePayload(); array_pop($incompletePayload['boats']);
daytimeCheck($flow->evaluatePayload($incompletePayload, '20261021AMG01', daytimeEntries(), '2026-10-21T10:10:00.000Z')['action'] === 'no_save', 'fewer than six boats is not saved'); $cases++;
daytimeCheck($flow->evaluatePayload(daytimePayload(), '20261021AMG01', daytimeEntries(), '2026-10-21T10:00:02.000Z')['reason'] === 'OFFICIAL_FETCH_COMPLETED_AFTER_CUTOFF', 'completion beyond cutoff is no-save'); $cases++;
daytimeCheck(!$flow->strictForwardAvailable(false, '2026-10-21T10:00:00.000Z', '2026-10-21T10:10:00.000Z'), 'unknown source is never strict-forward evidence'); $cases++;
daytimeCheck($flow->strictForwardAvailable(true, '2026-10-21T10:00:00.000Z', '2026-10-21T10:10:00.000Z'), 'audited fetched completion before cutoff is eligible'); $cases++;
daytimeCheck(!$flow->strictForwardAvailable(true, '2026-10-21T10:10:01.000Z', '2026-10-21T10:10:00.000Z'), 'post-cutoff payload is not strict-forward evidence'); $cases++;
foreach (['20261021TMG01', '20261021TDA01', '20261021KRY01', '20261020AMG01'] as $legacyRace) {
    daytimeCheck($flow->plan(daytimeCandidate(null, $legacyRace), timeForSeconds(600))['action'] === 'not_owned', "{$legacyRace} keeps legacy route"); $cases++;
}
foreach (['partial', 'source_unknown', 'mismatch'] as $existing) {
    daytimeCheck($flow->plan(daytimeCandidate(null, '20261021AMG01', $existing), timeForSeconds(600))['action'] === 'review', "{$existing} existing data requires audit review"); $cases++;
}
daytimeCheck(!$flow->nightlyOverwriteAllowed('20261021AMG01', true), 'audited official data is protected from future nightly overwrite'); $cases++;
daytimeCheck($flow->nightlyOverwriteAllowed('20261021TMG01', true), 'other venues are unchanged at night'); $cases++;

echo "PASS {$cases} isolated daytime-flow cases\n";
