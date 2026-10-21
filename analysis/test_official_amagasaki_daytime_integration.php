<?php

declare(strict_types=1);

require_once __DIR__ . '/../logic/OfficialAmagasakiDaytimeIntegration.php';

final class IntegrationAttemptRepository implements OfficialAmagasakiAttemptRepository
{
    /** @var array<string,array<string,mixed>> */ public array $states = [];
    public int $claims = 0;
    public int $completions = 0;
    public function load(string $raceCode): ?array { return $this->states[$raceCode] ?? null; }
    public function claim(string $raceCode, int $expectedVersion, array $state): bool
    {
        $current = $this->states[$raceCode] ?? OfficialAmagasakiAttemptState::initial($raceCode);
        if ((int)$current['version'] !== $expectedVersion || $current['outcome'] === 'in_progress') return false;
        $this->states[$raceCode] = $state;
        $this->claims++;
        return true;
    }
    public function complete(array $before, string $claimToken, array $after): bool
    {
        $race = (string)$before['race_code'];
        $current = $this->states[$race] ?? null;
        if (!is_array($current) || (int)$current['version'] !== (int)$before['version'] || ($current['claim_token'] ?? null) !== $claimToken) return false;
        $this->states[$race] = $after;
        $this->completions++;
        return true;
    }
}
final class IntegrationFetcher implements OfficialAmagasakiPayloadFetcher
{
    /** @var list<array<string,mixed>> */ public array $responses = [];
    public int $calls = 0;
    public function fetch(string $raceCode): array { $this->calls++; return array_shift($this->responses) ?? ['outcome' => 'parse_error']; }
}
final class IntegrationPersistence implements OfficialAmagasakiClaimedPersistence
{
    public int $calls = 0;
    public bool $commit = true;
    public function __construct(private readonly IntegrationAttemptRepository $attempts) {}
    public function persistAndComplete(array $payload, string $raceCode, array $raceEntries, array $claimedState, string $claimToken, DateTimeImmutable $finishedAt): array
    {
        $this->calls++;
        if (!$this->commit) return ['committed' => false, 'status' => 'rolled_back', 'comparison' => 'none'];
        $after = OfficialAmagasakiAttemptState::complete($claimedState, $claimToken, 'saved', $finishedAt, null, false);
        return $this->attempts->complete($claimedState, $claimToken, $after)
            ? ['committed' => true, 'status' => 'saved', 'comparison' => 'none']
            : ['committed' => false, 'status' => 'rolled_back', 'comparison' => 'none'];
    }
}
function integrationAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function integrationEntries(): array { return array_map(static fn(int $lane): array => ['lane_number' => $lane, 'player_id' => (string)(7100 + $lane)], range(1, 6)); }
function integrationPayload(string $fetchedAt = '2026-10-21T10:00:01.000Z'): array {
    $boats = [];
    foreach (range(1, 6) as $lane) $boats[] = ['lane' => $lane, 'entry_course' => $lane, 'player_id' => (string)(7100 + $lane), 'exhibition_time' => '6.8' . $lane, 'start_timing' => $lane === 1 ? 'F.01' : ($lane === 2 ? 'L.02' : '.0' . $lane), 'lap_time' => '37.' . (10 + $lane), 'around_time' => '11.' . (20 + $lane), 'straight_time' => null];
    return ['schema' => 'official-exhibition/v1', 'status' => 'published', 'source' => 'official_amagasaki', 'source_url' => 'https://example.invalid/amg', 'race_date' => '20261021', 'race_no' => 1, 'race_code' => '20261021AMG01', 'fetched_at' => $fetchedAt, 'page_fetched_at' => ['direct_info' => '2026-10-21T09:59:58.000Z', 'start_display' => '2026-10-21T09:59:59.000Z', 'original_data' => '2026-10-21T10:00:00.000Z'], 'boats' => $boats];
}
function integrationCandidate(?array $state = null, string $race = '20261021AMG01', string $existing = 'none'): array {
    return ['race_code' => $race, 'deadline' => '2026-10-21T10:10:00+00:00', 'prediction_cutoff' => '2026-10-21T10:08:00.000Z', 'existing_status' => $existing, 'race_entries' => integrationEntries(), 'attempt_state' => $state];
}
function integrationRunner(bool $approved, IntegrationAttemptRepository $attempts, IntegrationFetcher $fetcher, IntegrationPersistence $persistence, DateTimeImmutable $finished): OfficialAmagasakiDaytimeIntegration {
    $flow = new OfficialAmagasakiDaytimeFlow(new OfficialExhibitionSourceRouter($approved), new OfficialAmagasakiExhibitionValidator(), 120, 120, false, 90);
    return new OfficialAmagasakiDaytimeIntegration($flow, $attempts, $fetcher, $persistence, static fn(): DateTimeImmutable => $finished);
}
function atRemaining(int $seconds): DateTimeImmutable { return (new DateTimeImmutable('2026-10-21T10:10:00+00:00'))->modify('-' . $seconds . ' seconds'); }

$cases = 0;
$attempts = new IntegrationAttemptRepository(); $fetcher = new IntegrationFetcher(); $persistence = new IntegrationPersistence($attempts);
$runner = integrationRunner(false, $attempts, $fetcher, $persistence, atRemaining(599));
$result = $runner->run([integrationCandidate()], atRemaining(600), false);
integrationAssert($result[0]['reason'] === 'OFFICIAL_AUTOMATION_NOT_APPROVED' && $fetcher->calls === 0 && $attempts->claims === 0, 'unapproved mode never claims or fetches'); $cases++;

$attempts = new IntegrationAttemptRepository(); $fetcher = new IntegrationFetcher(); $persistence = new IntegrationPersistence($attempts);
$runner = integrationRunner(true, $attempts, $fetcher, $persistence, atRemaining(599));
$result = $runner->run([integrationCandidate()], atRemaining(600), true);
integrationAssert($result[0]['action'] === 'claim_first' && $fetcher->calls === 0 && $attempts->claims === 0 && $persistence->calls === 0, 'dry run only plans'); $cases++;

$attempts = new IntegrationAttemptRepository(); $fetcher = new IntegrationFetcher(); $fetcher->responses[] = ['outcome' => 'published', 'payload' => integrationPayload()]; $persistence = new IntegrationPersistence($attempts);
$runner = integrationRunner(true, $attempts, $fetcher, $persistence, atRemaining(599));
$result = $runner->run([integrationCandidate()], atRemaining(600), false);
integrationAssert($result[0]['action'] === 'saved' && $fetcher->calls === 1 && $persistence->calls === 1 && $attempts->states['20261021AMG01']['outcome'] === 'saved', 'six-row persistence and attempt completion share the claimed success path'); $cases++;

$attempts = new IntegrationAttemptRepository(); $fetcher = new IntegrationFetcher(); $fetcher->responses[] = ['outcome' => 'not_yet_published']; $persistence = new IntegrationPersistence($attempts);
$runner = integrationRunner(true, $attempts, $fetcher, $persistence, atRemaining(599));
$first = $runner->run([integrationCandidate()], atRemaining(600), false);
integrationAssert($first[0]['action'] === 'no_save' && $attempts->states['20261021AMG01']['outcome'] === 'not_yet_published' && $attempts->states['20261021AMG01']['attempt_count'] === 1, 'unpublished first attempt persists retryable state only'); $cases++;
$fetcher->responses[] = ['outcome' => 'published', 'payload' => integrationPayload('2026-10-21T10:05:01.000Z')];
$runner = integrationRunner(true, $attempts, $fetcher, $persistence, atRemaining(299));
$second = $runner->run([integrationCandidate()], atRemaining(300), false);
integrationAssert($second[0]['action'] === 'saved' && $attempts->states['20261021AMG01']['attempt_count'] === 2, 'only the retry window allows the second attempt'); $cases++;

$attempts = new IntegrationAttemptRepository(); $fetcher = new IntegrationFetcher(); $fetcher->responses[] = ['outcome' => 'http_restricted']; $persistence = new IntegrationPersistence($attempts);
$runner = integrationRunner(true, $attempts, $fetcher, $persistence, atRemaining(599));
$result = $runner->run([integrationCandidate()], atRemaining(600), false);
integrationAssert($result[0]['action'] === 'no_save' && $attempts->states['20261021AMG01']['manual_review_required'] && $persistence->calls === 0, 'restricted official response never saves or falls back'); $cases++;

$attempts = new IntegrationAttemptRepository(); $fetcher = new IntegrationFetcher(); $fetcher->responses[] = ['outcome' => 'published', 'payload' => integrationPayload('2026-10-21T10:08:00.001Z')]; $persistence = new IntegrationPersistence($attempts);
$runner = integrationRunner(true, $attempts, $fetcher, $persistence, new DateTimeImmutable('2026-10-21T10:08:00.001Z'));
$result = $runner->run([integrationCandidate()], atRemaining(600), false);
integrationAssert($result[0]['reason'] === 'PAYLOAD_OR_TIME_REJECTED' && $persistence->calls === 0, 'post-cutoff completion never reaches persistence'); $cases++;

$attempts = new IntegrationAttemptRepository(); $fetcher = new IntegrationFetcher(); $fetcher->responses[] = ['outcome' => 'published', 'payload' => integrationPayload('2026-10-21T09:59:59.000Z')]; $persistence = new IntegrationPersistence($attempts);
$runner = integrationRunner(true, $attempts, $fetcher, $persistence, atRemaining(599));
$result = $runner->run([integrationCandidate()], atRemaining(600), false);
integrationAssert($result[0]['reason'] === 'PAYLOAD_OR_TIME_REJECTED' && $persistence->calls === 0, 'backdated payload completion never reaches persistence'); $cases++;

$attempts = new IntegrationAttemptRepository(); $fetcher = new IntegrationFetcher(); $persistence = new IntegrationPersistence($attempts); $persistence->commit = false;
$fetcher->responses[] = ['outcome' => 'published', 'payload' => integrationPayload()];
$runner = integrationRunner(true, $attempts, $fetcher, $persistence, atRemaining(599));
$result = $runner->run([integrationCandidate()], atRemaining(600), false);
integrationAssert($result[0]['action'] === 'review' && $attempts->states['20261021AMG01']['outcome'] === 'in_progress', 'atomic persistence failure cannot claim a successful completion'); $cases++;

$attempts = new IntegrationAttemptRepository(); $claimed = OfficialAmagasakiAttemptState::claim(OfficialAmagasakiAttemptState::initial('20261021AMG01'), atRemaining(600), 'other-worker', 90); $attempts->states['20261021AMG01'] = $claimed;
$fetcher = new IntegrationFetcher(); $persistence = new IntegrationPersistence($attempts); $runner = integrationRunner(true, $attempts, $fetcher, $persistence, atRemaining(599));
$result = $runner->run([integrationCandidate()], atRemaining(590), false);
integrationAssert($result[0]['reason'] === 'ATTEMPT_ALREADY_CLAIMED' && $fetcher->calls === 0, 'concurrent claim blocks duplicate fetch'); $cases++;

$attempts = new IntegrationAttemptRepository(); $fetcher = new IntegrationFetcher(); $persistence = new IntegrationPersistence($attempts); $runner = integrationRunner(true, $attempts, $fetcher, $persistence, atRemaining(599));
integrationAssert($runner->run([integrationCandidate(null, '20261021TMG01')], atRemaining(600), false)[0]['action'] === 'not_owned' && $fetcher->calls === 0, 'other venues retain legacy route'); $cases++;
integrationAssert($runner->run([integrationCandidate(null, '20261021AMG01', 'source_unknown')], atRemaining(600), false)[0]['action'] === 'review' && $fetcher->calls === 0, 'source-unknown rows are protected'); $cases++;

echo "PASS {$cases} isolated daytime integration cases\n";
