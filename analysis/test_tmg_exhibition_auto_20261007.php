<?php

declare(strict_types=1);

require_once __DIR__ . '/fetch_tamagawa_exhibition_20261007.php';

function tmgAutoAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('ASSERTION_FAILED: ' . $message);
}

/** @return array{action:string,reason:string} */
function tmgDecision(int $seconds, bool $complete, int $attempts): array
{
    return tmgAutoAction($seconds, $complete, $attempts);
}

tmgAutoAssert(tmgDecision(7 * 60, false, 0) === ['action' => 'FETCH', 'reason' => 'FIRST_FETCH_WINDOW'], '8-minute first fetch window');
tmgAutoAssert(tmgDecision(4 * 60, false, 1) === ['action' => 'FETCH', 'reason' => 'RETRY_FETCH_WINDOW'], '5-minute retry window');
tmgAutoAssert(tmgDecision(4 * 60, false, 0)['action'] === 'SKIP', 'retry window cannot become late first fetch');
tmgAutoAssert(tmgDecision(7 * 60, true, 0)['reason'] === 'ALREADY_CAPTURED', 'complete exhibition skips');
tmgAutoAssert(tmgDecision(7 * 60, false, 2)['reason'] === 'RETRY_LIMIT_REACHED', 'two attempts is hard limit');
tmgAutoAssert(tmgDecision(2 * 60, false, 1)['reason'] === 'SAFE_CUTOFF_REACHED', 'two-minute safe cutoff');
tmgAutoAssert(tmgDecision(9 * 60, false, 0)['reason'] === 'OUTSIDE_FETCH_WINDOW', 'outside first window skips');

$today = tmgAutoArguments(['fixture.php', '--dry-run', '--now=2026-10-07T12:00:00+09:00']);
tmgAutoAssert($today['now']->format('Y-m-d') === TMG_AUTO_TARGET_DATE && $today['dry_run'], 'target-day dry run accepted');
$otherDay = tmgAutoArguments(['fixture.php', '--dry-run', '--now=2026-10-08T12:00:00+09:00']);
tmgAutoAssert($otherDay['now']->format('Y-m-d') !== TMG_AUTO_TARGET_DATE, 'other-day date guard fixture');
tmgAutoAssert(tmgAutoIsTargetRaceCode('20261007TMG01'), 'TMG race is in fixed venue scope');
tmgAutoAssert(!tmgAutoIsTargetRaceCode('20261007OMR01'), 'other venue is out of fixed venue scope');

echo json_encode([
    'status' => 'PASS',
    'tests' => ['date_guard', 'venue_fixed_tmg', 'already_captured_skip', 'first_window', 'retry_window', 'retry_limit', 'safe_cutoff'],
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
