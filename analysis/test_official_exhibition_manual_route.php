<?php

declare(strict_types=1);

require_once __DIR__ . '/../logic/OfficialExhibitionManualRoute.php';

function manualRouteAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$cases = 0;
$enabled = new OfficialAmagasakiOperationalPolicy(true, false, null, 'ok', false);
$route = OfficialExhibitionManualRoute::decide($enabled, '20261022AMG09');
manualRouteAssert($route['supported'] && $route['source'] === 'official_amagasaki', 'AMG manual route must be official without automation approval'); $cases++;
$disabled = new OfficialAmagasakiOperationalPolicy(false, false, null, 'ok', false);
manualRouteAssert(!OfficialExhibitionManualRoute::decide($disabled, '20261022AMG09')['supported'], 'disabled manual route must fail closed'); $cases++;
foreach (['20261022TKY01', '20261022TDA01', '20261020AMG01'] as $race) {
    $route = OfficialExhibitionManualRoute::decide($enabled, $race);
    manualRouteAssert(!$route['supported'] && $route['reason'] === 'OFFICIAL_EXHIBITION_UNSUPPORTED_VENUE', 'unsupported venue must not fall back'); $cases++;
}
echo "PASS {$cases} manual official route cases\n";
