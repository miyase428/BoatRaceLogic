<?php

declare(strict_types=1);

require_once __DIR__ . '/../logic/OfficialAmagasakiExhibitionStorage.php';
require_once __DIR__ . '/../logic/OfficialAmagasakiDeadlineCache.php';
require_once __DIR__ . '/../logic/OfficialTamagawaExhibitionStorage.php';
require_once __DIR__ . '/../logic/OfficialTamagawaDeadlineCache.php';
require_once __DIR__ . '/../logic/OfficialSuminoeExhibitionStorage.php';
require_once __DIR__ . '/../logic/OfficialSuminoeDeadlineCache.php';
require_once __DIR__ . '/../logic/OfficialExhibitionManualRoute.php';
require_once __DIR__ . '/../logic/NodeOfficialAmagasakiPayloadFetcher.php';
require_once __DIR__ . '/../logic/NodeOfficialTamagawaPayloadFetcher.php';
require_once __DIR__ . '/../logic/NodeOfficialSuminoePayloadFetcher.php';
require_once __DIR__ . '/../logic/OfficialAmagasakiManualExhibitionUpdate.php';
require_once __DIR__ . '/../logic/OfficialTamagawaManualExhibitionUpdate.php';
require_once __DIR__ . '/../logic/OfficialSuminoeManualExhibitionUpdate.php';

function sameDayWindowAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** @return array{allowed:bool,reason:string,after_deadline:bool} */
function sameDayWindowDecision(string $class, string $raceCode, string $now, string $completed): array
{
    return $class::manualFetchTimingDecision(
        $raceCode,
        new DateTimeImmutable($now),
        new DateTimeImmutable('2026-10-23 14:33:00', new DateTimeZone('Asia/Tokyo')),
        new DateTimeImmutable($completed),
    );
}

$venues = [
    ['class' => OfficialAmagasakiManualExhibitionUpdate::class, 'code' => '20261023AMG09'],
    ['class' => OfficialTamagawaManualExhibitionUpdate::class, 'code' => '20261023TMG09'],
    ['class' => OfficialSuminoeManualExhibitionUpdate::class, 'code' => '20261023SME09'],
];
$cases = 0;

$strictForwardEligibilitySource = (string)file_get_contents(__DIR__ . '/../web/logic/StrictForwardExhibitionEligibilityState.php');
sameDayWindowAssert(str_contains($strictForwardEligibilitySource, 'created_date < CAST(:cutoff AS timestamptz)'), 'Strict Forward keeps the shared created_date-before-cutoff predicate'); $cases++;

foreach ($venues as $venue) {
    $class = $venue['class'];
    $raceCode = $venue['code'];
    $label = substr($raceCode, 8, 3);

    $decision = sameDayWindowDecision($class, $raceCode, '2026-10-23 14:30:00 Asia/Tokyo', '2026-10-23T05:30:01.000Z');
    sameDayWindowAssert($decision['allowed'] && !$decision['after_deadline'], "$label pre-deadline fetch is allowed and classified pre-deadline"); $cases++;

    $decision = sameDayWindowDecision($class, $raceCode, '2026-10-23 14:33:01 Asia/Tokyo', '2026-10-23T05:33:01.000Z');
    sameDayWindowAssert($decision['allowed'] && $decision['after_deadline'], "$label immediate post-deadline fetch is allowed and classified post-deadline"); $cases++;

    $decision = sameDayWindowDecision($class, $raceCode, '2026-10-23 18:00:00 Asia/Tokyo', '2026-10-23T09:00:01.000Z');
    sameDayWindowAssert($decision['allowed'] && $decision['after_deadline'], "$label post-race same-day fetch is allowed"); $cases++;

    $decision = sameDayWindowDecision($class, $raceCode, '2026-10-23T14:59:59.000Z', '2026-10-23T14:59:59.000Z');
    sameDayWindowAssert($decision['allowed'] && $decision['after_deadline'], "$label UTC final second maps to permitted JST 23:59:59"); $cases++;

    $decision = sameDayWindowDecision($class, $raceCode, '2026-10-23T15:00:00.000Z', '2026-10-23T15:00:00.000Z');
    sameDayWindowAssert(!$decision['allowed'] && $decision['reason'] === 'OFFICIAL_MANUAL_NONCURRENT_DATE', "$label rejects at JST midnight"); $cases++;

    $preflight = $class::manualFetchTimingDecision($raceCode, new DateTimeImmutable('2026-10-23T14:59:59.000Z'), null, null);
    $completion = $class::manualFetchTimingDecision($raceCode, new DateTimeImmutable('2026-10-23T15:00:00.000Z'), new DateTimeImmutable('2026-10-23 14:33:00', new DateTimeZone('Asia/Tokyo')), new DateTimeImmutable('2026-10-23T15:00:00.000Z'));
    sameDayWindowAssert($preflight['allowed'] && !$completion['allowed'], "$label rejects a fetch that completes after the JST date changes"); $cases++;

    $future = preg_replace('/^20261023/', '20261024', $raceCode);
    $decision = $class::manualFetchTimingDecision($future, new DateTimeImmutable('2026-10-23 12:00:00', new DateTimeZone('Asia/Tokyo')), null, null);
    sameDayWindowAssert(!$decision['allowed'] && $decision['reason'] === 'OFFICIAL_MANUAL_NONCURRENT_DATE', "$label future date is rejected before official access"); $cases++;

    $past = preg_replace('/^20261023/', '20261022', $raceCode);
    $decision = $class::manualFetchTimingDecision($past, new DateTimeImmutable('2026-10-23 12:00:00', new DateTimeZone('Asia/Tokyo')), null, null);
    sameDayWindowAssert(!$decision['allowed'] && $decision['reason'] === 'OFFICIAL_MANUAL_NONCURRENT_DATE', "$label past date is rejected before official access"); $cases++;

    $invalid = substr($raceCode, 0, 8) . substr($raceCode, 8, 3) . '13';
    $decision = $class::manualFetchTimingDecision($invalid, new DateTimeImmutable('2026-10-23 12:00:00', new DateTimeZone('Asia/Tokyo')), null, null);
    sameDayWindowAssert(!$decision['allowed'] && $decision['reason'] === 'OFFICIAL_MANUAL_NONCURRENT_DATE', "$label invalid race code is rejected"); $cases++;
}

echo "PASS {$cases} three-venue same-day manual window cases\n";
