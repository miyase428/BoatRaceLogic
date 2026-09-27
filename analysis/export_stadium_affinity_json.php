<?php

declare(strict_types=1);

/**
 * 直近2か月の場別予想相性を画面表示用JSONに出力する。
 *
 * Usage:
 *   php analysis/export_stadium_affinity_json.php RACES_CSV
 */

if ($argc !== 2 || !is_file($argv[1])) {
    fwrite(STDERR, "Usage: php {$argv[0]} RACES_CSV\n");
    exit(1);
}

$csvPath = $argv[1];
$configPath = __DIR__ . '/../config/stadium_affinity.json';

function affinityAtomicWrite(string $path, string $contents): void
{
    $tmpPath = $path . '.tmp.' . getmypid();
    if (file_put_contents($tmpPath, $contents) === false || !rename($tmpPath, $path)) {
        @unlink($tmpPath);
        throw new RuntimeException("JSONを書き込めません: {$path}");
    }
}

function affinityRate(int $numerator, int $denominator): float
{
    return $denominator > 0 ? 100.0 * $numerator / $denominator : 0.0;
}

function affinityMark(float $difference): string
{
    if ($difference >= 3.0) return '◎';
    if ($difference >= -1.0) return '○';
    if ($difference >= -5.0) return '△';
    return '×';
}

function stabilityMark(float $gap): string
{
    if ($gap <= 2.0) return '◎';
    if ($gap <= 5.0) return '○';
    if ($gap <= 8.0) return '△';
    return '×';
}

$fp = fopen($csvPath, 'rb');
if ($fp === false) {
    throw new RuntimeException("CSVを開けません: {$csvPath}");
}

$header = fgetcsv($fp);
if (!is_array($header)) {
    throw new RuntimeException("CSVヘッダーを読み込めません: {$csvPath}");
}
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
$columns = array_flip($header);
foreach (['race_date', 'stadium_name', 'honmei_head', 'actual_1st', 'actual_2nd', 'actual_3rd'] as $required) {
    if (!array_key_exists($required, $columns)) {
        throw new RuntimeException("必要な列がありません: {$required}");
    }
}

$rows = [];
$endDate = null;
while (($row = fgetcsv($fp)) !== false) {
    if (count($row) !== count($header)) continue;
    $raceDate = trim((string)$row[$columns['race_date']]);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raceDate) !== 1) continue;
    $endDate = $endDate === null || $raceDate > $endDate ? $raceDate : $endDate;
    $rows[] = $row;
}
fclose($fp);

if ($endDate === null) {
    throw new RuntimeException('有効なレース日がありません。');
}

$end = new DateTimeImmutable($endDate);
$start = $end->modify('-59 days');
$period2Start = $end->modify('-29 days');
$startDate = $start->format('Y-m-d');
$period2StartDate = $period2Start->format('Y-m-d');

$previous = is_file($configPath) ? json_decode((string)file_get_contents($configPath), true) : null;
$nameToCode = [];
foreach (($previous['stadiums'] ?? []) as $code => $stadium) {
    if (is_array($stadium) && trim((string)($stadium['name'] ?? '')) !== '') {
        $nameToCode[(string)$stadium['name']] = (string)$code;
    }
}
if ($nameToCode === []) {
    throw new RuntimeException('場コード対応を読み込めません。');
}

$totals = [];
foreach ($rows as $row) {
    $raceDate = trim((string)$row[$columns['race_date']]);
    if ($raceDate < $startDate || $raceDate > $endDate) continue;
    $name = trim((string)$row[$columns['stadium_name']]);
    $code = $nameToCode[$name] ?? null;
    $honmei = (int)$row[$columns['honmei_head']];
    $actual1 = (int)$row[$columns['actual_1st']];
    $actual2 = (int)$row[$columns['actual_2nd']];
    $actual3 = (int)$row[$columns['actual_3rd']];
    if ($code === null || $honmei < 1 || $honmei > 6 || $actual1 < 1 || $actual1 > 6 || $actual2 < 1 || $actual2 > 6 || $actual3 < 1 || $actual3 > 6) {
        continue;
    }
    if (!isset($totals[$code])) {
        $totals[$code] = ['races' => 0, 'first' => 0, 'p1_races' => 0, 'p1_first' => 0, 'p2_races' => 0, 'p2_first' => 0];
    }
    $isFirst = $honmei === $actual1;
    $totals[$code]['races']++;
    $totals[$code]['first'] += $isFirst ? 1 : 0;
    $period = $raceDate < $period2StartDate ? 'p1' : 'p2';
    $totals[$code]["{$period}_races"]++;
    $totals[$code]["{$period}_first"] += $isFirst ? 1 : 0;
}

$overallRaces = array_sum(array_column($totals, 'races'));
$overallFirst = array_sum(array_column($totals, 'first'));
if ($overallRaces === 0) {
    throw new RuntimeException('直近2か月に有効なレースがありません。');
}
$overallRate = affinityRate($overallFirst, $overallRaces);

$stadiums = [];
foreach ($totals as $code => $total) {
    if ($total['p1_races'] === 0 || $total['p2_races'] === 0) continue;
    $p1Rate = affinityRate($total['p1_first'], $total['p1_races']);
    $p2Rate = affinityRate($total['p2_first'], $total['p2_races']);
    $rate = affinityRate($total['first'], $total['races']);
    $stadiums[$code] = [
        'name' => array_search($code, $nameToCode, true),
        'races' => $total['races'],
        'honmei_first_rate' => round($rate, 2),
        'period1_rate' => round($p1Rate, 2),
        'period2_rate' => round($p2Rate, 2),
        'stability_gap' => round(abs($p1Rate - $p2Rate), 2),
        'affinity' => affinityMark($rate - $overallRate),
        'stability' => stabilityMark(abs($p1Rate - $p2Rate)),
    ];
}

uasort($stadiums, static fn(array $a, array $b): int => $b['honmei_first_rate'] <=> $a['honmei_first_rate']);
foreach ($stadiums as $rank => &$stadium) {
    $stadium['rank'] = array_search($rank, array_keys($stadiums), true) + 1;
}
unset($stadium);
ksort($stadiums, SORT_STRING);

$output = [
    'meta' => [
        'label' => '直近2か月・毎朝更新',
        'start_date' => $startDate,
        'end_date' => $endDate,
        'generated_at' => date(DATE_ATOM),
        'total_races' => $overallRaces,
        'overall_honmei_first_rate' => round($overallRate, 2),
        'affinity_rule' => '本命1着率を全体平均と比較（◎:+3pt以上 / ○:-1pt以上 / △:-5pt以上 / ×:それ未満）',
        'stability_rule' => '2期間の本命1着率差（◎:2pt以内 / ○:5pt以内 / △:8pt以内 / ×:8pt超）',
    ],
    'stadiums' => $stadiums,
];

$json = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if (!is_string($json)) {
    throw new RuntimeException('JSON生成に失敗しました。');
}
affinityAtomicWrite($configPath, $json . PHP_EOL);
echo "場別予想相性を更新しました: {$startDate} ～ {$endDate} / {$overallRaces}R\n";
