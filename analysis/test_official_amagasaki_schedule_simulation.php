<?php

declare(strict_types=1);

require_once __DIR__ . '/../logic/OfficialAmagasakiDaytimeFlow.php';

function scheduleAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function scheduleCandidate(): array { return ['race_code' => '20261021AMG01', 'deadline' => '2026-10-21T10:10:00+00:00', 'existing_status' => 'none']; }
function scheduleFlow(): OfficialAmagasakiDaytimeFlow { return new OfficialAmagasakiDaytimeFlow(new OfficialExhibitionSourceRouter(true), new OfficialAmagasakiExhibitionValidator(), 120, 120); }
function scheduleNow(int $remaining): DateTimeImmutable { return (new DateTimeImmutable('2026-10-21T10:10:00+00:00'))->modify('-' . $remaining . ' seconds'); }
/** @param list<int> $remainingSeconds */
function scheduleInitialHit(array $remainingSeconds): bool {
    $flow = scheduleFlow();
    foreach ($remainingSeconds as $remaining) if ($flow->plan(scheduleCandidate(), scheduleNow($remaining))['action'] === 'claim_first') return true;
    return false;
}

$cases = 0;
scheduleAssert(scheduleInitialHit([599, 479]), 'two-minute cadence starts inside the initial window when its first run is timely'); $cases++;
scheduleAssert(!scheduleInitialHit([479, 359]), 'a 121-second start delay loses the 120-second initial window'); $cases++;
scheduleAssert(scheduleInitialHit([599, 539, 479]), 'one-minute cadence retains a second initial-window opportunity after a short delay'); $cases++;
scheduleAssert(!scheduleInitialHit([]), 'a lock that suppresses every start loses the window rather than falling back late'); $cases++;
$flow = scheduleFlow();
scheduleAssert($flow->plan(scheduleCandidate(), scheduleNow(300))['reason'] === 'FIRST_FETCH_WINDOW_MISSED', 'a missed first attempt never becomes a retry-window first fetch'); $cases++;
$changed = scheduleCandidate(); $changed['deadline'] = '2026-10-21T10:12:00+00:00';
scheduleAssert($flow->plan($changed, scheduleNow(600))['reason'] === 'BEFORE_FIRST_FETCH_WINDOW', 'each planning pass uses the latest supplied deadline'); $cases++;

// Exhaustive phase check: a perfectly punctual 120-second timer enters the
// initial window for every whole-second deadline phase, but has no redundancy.
for ($phase = 0; $phase < 120; $phase++) {
    $remaining = 600 - $phase;
    scheduleAssert(scheduleInitialHit([$remaining]), "punctual two-minute phase {$phase} enters once");
    $cases++;
}

echo "PASS {$cases} isolated schedule simulation cases\n";
