<?php

declare(strict_types=1);

require_once __DIR__ . '/../logic/OfficialAmagasakiNightlyBackfill.php';

function nightlyAssert(bool $value, string $message): void
{
    if (!$value) throw new RuntimeException($message);
}

$cases = 0;
$at23 = new OfficialAmagasakiNightlyBackfillContext(true, static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-21T23:15:00+09:00'));
nightlyAssert($at23->permits('20261021AMG01'), 'same-day AMG is permitted during JST 23:00'); $cases++;
nightlyAssert(!$at23->permits('20261020AMG01'), 'historical AMG is never backfilled by the current-night route'); $cases++;
nightlyAssert(!$at23->permits('20261021TAM01'), 'other venues never enter the AMG backfill route'); $cases++;
$at22 = new OfficialAmagasakiNightlyBackfillContext(true, static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-21T22:59:59+09:00'));
nightlyAssert(!$at22->permits('20261021AMG01'), 'pre-23:00 invocation is rejected'); $cases++;
$at00 = new OfficialAmagasakiNightlyBackfillContext(true, static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-22T00:00:00+09:00'));
nightlyAssert(!$at00->permits('20261021AMG01'), 'post-midnight invocation is rejected'); $cases++;
$notRequested = new OfficialAmagasakiNightlyBackfillContext(false, static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-21T23:15:00+09:00'));
nightlyAssert(!$notRequested->permits('20261021AMG01'), 'generic nightly invocation cannot request fallback ownership'); $cases++;

echo "PASS {$cases} isolated nightly backfill context cases\n";
