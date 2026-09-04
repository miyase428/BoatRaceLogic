<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/EffectiveRaceOutcomeFilter.php';

$raceCode = strtoupper(trim((string)($argv[1] ?? '')));
if (!preg_match('/^\d{8}[A-Z0-9]{3}\d{2}$/', $raceCode)) {
    fwrite(STDERR, "Usage: php {$argv[0]} YYYYMMDDXXXRR\n");
    exit(1);
}

$filter = new EffectiveRaceOutcomeFilter();
$active = $filter->detectActiveBoats($raceCode);
$excluded = array_values(array_diff(range(1, 6), $active));
$n = count($active);
$trifecta = $n >= 3 ? $n * ($n - 1) * ($n - 2) : 0;
$exacta = $n >= 2 ? $n * ($n - 1) : 0;

echo str_repeat('=', 72) . "\n";
echo "実質出走艇 判定診断\n";
echo "race_code : {$raceCode}\n";
echo str_repeat('=', 72) . "\n";
echo "有効艇     : " . implode(',', $active) . "\n";
echo "除外艇     : " . ($excluded ? implode(',', $excluded) : 'なし') . "\n";
echo "有効艇数   : {$n}艇\n";
echo "3連単件数  : {$trifecta}通り\n";
echo "2連単件数  : {$exacta}通り\n";
echo str_repeat('=', 72) . "\n";
