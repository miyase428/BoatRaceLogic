<?php

declare(strict_types=1);

require_once __DIR__ . '/../logic/OfficialOmuraExhibitionStorage.php';
require_once __DIR__ . '/../logic/OfficialOmuraDeadlineCache.php';
require_once __DIR__ . '/../logic/OfficialExhibitionManualRoute.php';
require_once __DIR__ . '/../logic/NodeOfficialOmuraPayloadFetcher.php';
require_once __DIR__ . '/../logic/OfficialOmuraManualExhibitionUpdate.php';

function sameDayAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function timingDecision(string $now, string $completed): array
{
    return OfficialOmuraManualExhibitionUpdate::manualFetchTimingDecision(
        '20261023OMR09',
        new DateTimeImmutable($now),
        new DateTimeImmutable('2026-10-23 14:33:00', new DateTimeZone('Asia/Tokyo')),
        new DateTimeImmutable($completed),
    );
}

$cases = 0;

$decision = timingDecision('2026-10-23 14:30:00 Asia/Tokyo', '2026-10-23T05:30:01.000Z');
sameDayAssert($decision['allowed'] && !$decision['after_deadline'], 'pre-deadline manual fetch remains allowed and marked pre-deadline'); $cases++;

$decision = timingDecision('2026-10-23 14:33:01 Asia/Tokyo', '2026-10-23T05:33:01.000Z');
sameDayAssert($decision['allowed'] && $decision['after_deadline'], 'immediately post-deadline manual fetch remains allowed and marked post-deadline'); $cases++;

$decision = timingDecision('2026-10-23 18:00:00 Asia/Tokyo', '2026-10-23T09:00:01.000Z');
sameDayAssert($decision['allowed'] && $decision['after_deadline'], 'post-race manual fetch remains allowed on the same JST date'); $cases++;

$decision = timingDecision('2026-10-23 23:59:59 Asia/Tokyo', '2026-10-23T14:59:59.000Z');
sameDayAssert($decision['allowed'] && $decision['after_deadline'], '23:59:59 JST remains in the same-day window'); $cases++;

$decision = timingDecision('2026-10-23T14:59:59.000Z', '2026-10-23T14:59:59.000Z');
sameDayAssert($decision['allowed'] && $decision['after_deadline'], 'UTC 14:59:59 maps to the final second of the JST date'); $cases++;

$decision = timingDecision('2026-10-23T15:00:00.000Z', '2026-10-23T15:00:00.000Z');
sameDayAssert(!$decision['allowed'] && $decision['reason'] === 'OFFICIAL_MANUAL_NONCURRENT_DATE', 'UTC 15:00:00 maps to the following JST date and is rejected'); $cases++;

$decision = OfficialOmuraManualExhibitionUpdate::manualFetchTimingDecision(
    '20261024OMR01',
    new DateTimeImmutable('2026-10-23 12:00:00', new DateTimeZone('Asia/Tokyo')),
    null,
    null,
);
sameDayAssert(!$decision['allowed'] && $decision['reason'] === 'OFFICIAL_MANUAL_NONCURRENT_DATE', 'future-date manual fetch is rejected before any official access'); $cases++;

$decision = OfficialOmuraManualExhibitionUpdate::manualFetchTimingDecision(
    '20261022OMR12',
    new DateTimeImmutable('2026-10-23 00:00:00', new DateTimeZone('Asia/Tokyo')),
    null,
    null,
);
sameDayAssert(!$decision['allowed'] && $decision['reason'] === 'OFFICIAL_MANUAL_NONCURRENT_DATE', 'previous-date manual fetch is rejected'); $cases++;

$decision = OfficialOmuraManualExhibitionUpdate::manualFetchTimingDecision(
    '20261023OMR13',
    new DateTimeImmutable('2026-10-23 12:00:00', new DateTimeZone('Asia/Tokyo')),
    null,
    null,
);
sameDayAssert(!$decision['allowed'] && $decision['reason'] === 'OFFICIAL_MANUAL_NONCURRENT_DATE', 'invalid race code is rejected'); $cases++;

echo "PASS {$cases} Omura same-day manual window cases\n";
