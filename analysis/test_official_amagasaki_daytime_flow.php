<?php

declare(strict_types=1);

require_once __DIR__ . '/../logic/OfficialAmagasakiDaytimeFlow.php';

function daytimeCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function daytimeEntries(): array { return array_map(static fn(int $lane): array => ['lane_number' => $lane, 'player_id' => (string)(6100 + $lane)], range(1, 6)); }
function daytimePayload(string $status = 'published'): array {
    if ($status === 'not_yet_published') return ['status' => $status];
    $boats = [];
    foreach (range(1, 6) as $lane) $boats[] = ['lane' => $lane, 'entry_course' => $lane, 'player_id' => (string)(6100 + $lane), 'exhibition_time' => '6.8' . $lane, 'start_timing' => $lane === 1 ? 'F.01' : ($lane === 2 ? 'L.02' : '.0' . $lane), 'lap_time' => '37.' . (10 + $lane), 'around_time' => '11.' . (20 + $lane), 'straight_time' => null];
    return ['schema' => 'official-exhibition/v1', 'status' => 'published', 'source' => 'official_amagasaki', 'source_url' => 'https://example.invalid/amg', 'race_date' => '20261021', 'race_no' => 1, 'race_code' => '20261021AMG01', 'fetched_at' => '2026-10-21T10:00:03.000Z', 'page_fetched_at' => ['direct_info' => '2026-10-21T10:00:00.000Z', 'start_display' => '2026-10-21T10:00:01.000Z', 'original_data' => '2026-10-21T10:00:02.000Z'], 'boats' => $boats];
}
function daytimeCandidate(string $race = '20261021AMG01', string $state = 'none', bool $attempted = false, string $deadline = '2026-10-21T10:10:00+00:00'): array { return ['race_code' => $race, 'deadline' => $deadline, 'existing_status' => $state, 'official_attempted' => $attempted]; }

$now = new DateTimeImmutable('2026-10-21T10:00:00+00:00');
$enabled = new OfficialAmagasakiDaytimeFlow(new OfficialExhibitionSourceRouter(true), new OfficialAmagasakiExhibitionValidator(), 120);
$disabled = new OfficialAmagasakiDaytimeFlow(new OfficialExhibitionSourceRouter(false), new OfficialAmagasakiExhibitionValidator(), 120);
$cases = 0;
daytimeCheck($enabled->plan(daytimeCandidate(), $now)['action'] === 'fetch_once', 'AMG candidate is fetchable only before cutoff'); $cases++;
daytimeCheck($enabled->plan(daytimeCandidate('20261021TMG01'), $now)['action'] === 'not_owned', 'other venues retain legacy path'); $cases++;
daytimeCheck($enabled->plan(daytimeCandidate('20261020AMG01'), $now)['action'] === 'not_owned', 'pre-transition AMG retains legacy path'); $cases++;
daytimeCheck($disabled->plan(daytimeCandidate(), $now)['action'] === 'blocked', 'automation remains disabled by default'); $cases++;
daytimeCheck($enabled->plan(daytimeCandidate('20261021AMG01', 'already_official'), $now)['reason'] === 'OFFICIAL_SIX_BOATS_ALREADY_AUDITED', 'audited six boats are not fetched twice'); $cases++;
foreach (['partial', 'source_unknown', 'mismatch'] as $state) { daytimeCheck($enabled->plan(daytimeCandidate('20261021AMG01', $state), $now)['action'] === 'review', "{$state} requires review"); $cases++; }
daytimeCheck($enabled->plan(daytimeCandidate('20261021AMG01', 'none', true), $now)['reason'] === 'OFFICIAL_ATTEMPT_ALREADY_RECORDED', 'attempt record prevents unbounded retries'); $cases++;
daytimeCheck($enabled->plan(daytimeCandidate('20261021AMG01', 'none', false, 'bad'), $now)['reason'] === 'OFFICIAL_DEADLINE_INVALID', 'invalid deadline fails closed'); $cases++;
daytimeCheck($enabled->plan(daytimeCandidate('20261021AMG01', 'none', false, '2026-10-21T10:02:00+00:00'), $now)['reason'] === 'CUTOFF_SAFETY_WINDOW_CLOSED', 'cutoff boundary is rejected'); $cases++;
daytimeCheck($enabled->evaluatePayload(daytimePayload('not_yet_published'), '20261021AMG01', daytimeEntries(), '2026-10-21T10:10:00.000Z')['action'] === 'no_save', 'not-published response is never saved'); $cases++;
foreach (['http_403', 'http_429', 'http_5xx', 'captcha', 'parse_error'] as $failure) {
    daytimeCheck($enabled->evaluatePayload(['status' => $failure], '20261021AMG01', daytimeEntries(), '2026-10-21T10:10:00.000Z')['reason'] === 'OFFICIAL_FETCH_OR_PARSE_FAILED', "{$failure} never falls back or saves"); $cases++;
}
daytimeCheck($enabled->evaluatePayload(daytimePayload(), '20261021AMG01', daytimeEntries(), '2026-10-21T10:10:00.000Z')['action'] === 'save', 'complete official payload before cutoff is saveable'); $cases++;
daytimeCheck($enabled->evaluatePayload(daytimePayload(), '20261021AMG01', daytimeEntries(), '2026-10-21T10:00:02.000Z')['reason'] === 'OFFICIAL_FETCH_COMPLETED_AFTER_CUTOFF', 'post-cutoff payload cannot be saved as daytime evidence'); $cases++;
$bad = daytimePayload(); $bad['boats'][0]['player_id'] = '9999'; daytimeCheck($enabled->evaluatePayload($bad, '20261021AMG01', daytimeEntries(), '2026-10-21T10:10:00.000Z')['action'] === 'no_save', 'entry mismatch is rejected'); $cases++;
daytimeCheck(!$enabled->nightlyOverwriteAllowed('20261021AMG01', true), 'nightly legacy path must not overwrite audited official data'); $cases++;
daytimeCheck($enabled->nightlyOverwriteAllowed('20261021AMG01', false), 'nightly policy stays unchanged absent official audit'); $cases++;
daytimeCheck($enabled->nightlyOverwriteAllowed('20261021TMG01', true), 'other venues remain unchanged'); $cases++;
daytimeCheck(!$enabled->strictForwardAvailable(false, '2026-10-21T10:00:00.000Z', '2026-10-21T10:10:00.000Z'), 'unknown source is not strict-forward evidence'); $cases++;
daytimeCheck($enabled->strictForwardAvailable(true, '2026-10-21T10:00:00.000Z', '2026-10-21T10:10:00.000Z'), 'audited pre-cutoff data is eligible'); $cases++;
daytimeCheck(!$enabled->strictForwardAvailable(true, '2026-10-21T10:10:01.000Z', '2026-10-21T10:10:00.000Z'), 'post-cutoff data is rejected'); $cases++;

echo "PASS {$cases} isolated daytime-flow cases\n";
