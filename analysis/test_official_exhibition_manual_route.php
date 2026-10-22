<?php

declare(strict_types=1);

require_once __DIR__ . '/../logic/OfficialExhibitionManualRoute.php';

$cases = [
    ['20261021AMG01', 'official_amagasaki'],
    ['20261020AMG01', 'legacy'],
    ['20261021TKY01', 'legacy'],
    ['invalid', 'legacy'],
];

foreach ($cases as [$raceCode, $expected]) {
    $actual = OfficialExhibitionManualRoute::decide($raceCode)['source'];
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL {$raceCode}: expected {$expected}, got {$actual}\n");
        exit(1);
    }
}

echo "PASS 4 manual exhibition route cases\n";
