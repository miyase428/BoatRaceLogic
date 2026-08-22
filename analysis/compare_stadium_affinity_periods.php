<?php

declare(strict_types=1);

/**
 * 現行Web予想 場別相性 2期間比較
 *
 * 2本のレース別CSVを使い、場ごとの相性・安定性・1号艇評価のズレを比較する。
 * DBにはアクセスしないため、長期間CSV生成中でも並行実行できる。
 *
 * 主な確認項目
 *   - 各期間の本命1着率
 *   - 2期間合算の本命1着率と順位
 *   - 本命1着率の期間差（安定性）
 *   - 実際の1コース1着率
 *   - Webが1号艇を本命にした割合
 *   - 1C評価差 = Web本命1C率 - 実1C1着率
 *       + : 1号艇を実績より多く本命にしている（過信寄り）
 *       - : 1号艇を実績より少なく本命にしている（過小評価寄り）
 *
 * Usage:
 *   php analysis/compare_stadium_affinity_periods.php \
 *     analysis/output/final_prediction_races_20260615_20260714.csv \
 *     analysis/output/final_prediction_races_20260715_20260814.csv
 */

if ($argc < 3) {
    echo PHP_EOL;
    echo "使用方法:" . PHP_EOL;
    echo "  php analysis/compare_stadium_affinity_periods.php <期間1_races_csv> <期間2_races_csv>" . PHP_EOL;
    echo PHP_EOL;
    exit(1);
}

$csv1 = $argv[1];
$csv2 = $argv[2];

foreach ([$csv1, $csv2] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "CSVが見つかりません: {$path}" . PHP_EOL);
        exit(1);
    }
}

function pct(int $count, int $total): float
{
    return $total > 0 ? ($count / $total) * 100.0 : 0.0;
}

function fmtPct(float $value): string
{
    return number_format($value, 2) . '%';
}

function fmtPt(float $value): string
{
    return sprintf('%+.2fpt', $value);
}

/**
 * 1本のレース別CSVを場別集計する。
 */
function aggregateCsv(string $csvPath): array
{
    $fp = fopen($csvPath, 'rb');
    if ($fp === false) {
        throw new RuntimeException("CSVを開けません: {$csvPath}");
    }

    $header = fgetcsv($fp);
    if ($header === false) {
        fclose($fp);
        throw new RuntimeException("CSVヘッダーを読み込めません: {$csvPath}");
    }

    $header[0] = preg_replace('/^\\xEF\\xBB\\xBF/', '', $header[0]);
    $map = [];
    foreach ($header as $i => $name) {
        $map[$name] = $i;
    }

    $required = [
        'race_code',
        'race_date',
        'stadium_name',
        'honmei_head',
        'actual_1st',
        'actual_2nd',
        'actual_3rd',
    ];

    foreach ($required as $column) {
        if (!array_key_exists($column, $map)) {
            fclose($fp);
            throw new RuntimeException("必要な列がありません: {$column} ({$csvPath})");
        }
    }

    $stadiums = [];
    $startDate = null;
    $endDate = null;
    $valid = 0;

    while (($row = fgetcsv($fp)) !== false) {
        if (count($row) < count($header)) {
            continue;
        }

        $raceCode = trim((string)$row[$map['race_code']]);
        $raceDate = trim((string)$row[$map['race_date']]);
        $stadium = trim((string)$row[$map['stadium_name']]);
        $honmei = (int)$row[$map['honmei_head']];
        $actual1 = (int)$row[$map['actual_1st']];
        $actual2 = (int)$row[$map['actual_2nd']];
        $actual3 = (int)$row[$map['actual_3rd']];

        if (
            $raceCode === '' ||
            $stadium === '' ||
            $honmei < 1 || $honmei > 6 ||
            $actual1 < 1 || $actual1 > 6 ||
            $actual2 < 1 || $actual2 > 6 ||
            $actual3 < 1 || $actual3 > 6
        ) {
            continue;
        }

        if (!isset($stadiums[$stadium])) {
            $stadiums[$stadium] = [
                'races' => 0,
                'lane1_win' => 0,
                'honmei_lane1' => 0,
                'honmei_first' => 0,
                'honmei_second' => 0,
                'honmei_third' => 0,
            ];
        }

        $s =& $stadiums[$stadium];
        $s['races']++;
        if ($actual1 === 1) $s['lane1_win']++;
        if ($honmei === 1) $s['honmei_lane1']++;
        if ($actual1 === $honmei) $s['honmei_first']++;
        if ($honmei === $actual1 || $honmei === $actual2) $s['honmei_second']++;
        if ($honmei === $actual1 || $honmei === $actual2 || $honmei === $actual3) $s['honmei_third']++;
        unset($s);

        $valid++;
        if ($raceDate !== '') {
            if ($startDate === null || $raceDate < $startDate) $startDate = $raceDate;
            if ($endDate === null || $raceDate > $endDate) $endDate = $raceDate;
        }
    }

    fclose($fp);

    $rates = [];
    foreach ($stadiums as $stadium => $s) {
        $races = (int)$s['races'];
        $lane1 = pct((int)$s['lane1_win'], $races);
        $honmeiLane1 = pct((int)$s['honmei_lane1'], $races);

        $rates[$stadium] = [
            'races' => $races,
            'lane1_win_rate' => $lane1,
            'honmei_lane1_rate' => $honmeiLane1,
            'lane1_bias' => $honmeiLane1 - $lane1,
            'honmei_first_rate' => pct((int)$s['honmei_first'], $races),
            'honmei_second_rate' => pct((int)$s['honmei_second'], $races),
            'honmei_third_rate' => pct((int)$s['honmei_third'], $races),
            // 合算用の実数も残す
            'counts' => $s,
        ];
    }

    return [
        'csv' => $csvPath,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'valid' => $valid,
        'stadiums' => $rates,
    ];
}

try {
    $p1 = aggregateCsv($csv1);
    $p2 = aggregateCsv($csv2);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

$allNames = array_values(array_unique(array_merge(
    array_keys($p1['stadiums']),
    array_keys($p2['stadiums'])
)));
sort($allNames);

$rows = [];

foreach ($allNames as $stadium) {
    $a = $p1['stadiums'][$stadium] ?? null;
    $b = $p2['stadiums'][$stadium] ?? null;

    if ($a === null || $b === null) {
        continue;
    }

    $c1 = $a['counts'];
    $c2 = $b['counts'];
    $combinedRaces = (int)$a['races'] + (int)$b['races'];
    $combinedHonmeiFirst = (int)$c1['honmei_first'] + (int)$c2['honmei_first'];
    $combinedHonmeiSecond = (int)$c1['honmei_second'] + (int)$c2['honmei_second'];
    $combinedHonmeiThird = (int)$c1['honmei_third'] + (int)$c2['honmei_third'];
    $combinedLane1 = (int)$c1['lane1_win'] + (int)$c2['lane1_win'];
    $combinedHonmeiLane1 = (int)$c1['honmei_lane1'] + (int)$c2['honmei_lane1'];

    $combinedLane1Rate = pct($combinedLane1, $combinedRaces);
    $combinedHonmeiLane1Rate = pct($combinedHonmeiLane1, $combinedRaces);

    $rows[] = [
        'stadium' => $stadium,
        'races' => $combinedRaces,
        'p1_races' => $a['races'],
        'p2_races' => $b['races'],
        'p1_first' => $a['honmei_first_rate'],
        'p2_first' => $b['honmei_first_rate'],
        'stability_gap' => abs($a['honmei_first_rate'] - $b['honmei_first_rate']),
        'combined_first' => pct($combinedHonmeiFirst, $combinedRaces),
        'combined_second' => pct($combinedHonmeiSecond, $combinedRaces),
        'combined_third' => pct($combinedHonmeiThird, $combinedRaces),
        'p1_bias' => $a['lane1_bias'],
        'p2_bias' => $b['lane1_bias'],
        'combined_lane1' => $combinedLane1Rate,
        'combined_honmei_lane1' => $combinedHonmeiLane1Rate,
        'combined_bias' => $combinedHonmeiLane1Rate - $combinedLane1Rate,
    ];
}

usort($rows, static function (array $a, array $b): int {
    $cmp = $b['combined_first'] <=> $a['combined_first'];
    if ($cmp !== 0) return $cmp;
    return $b['races'] <=> $a['races'];
});

foreach ($rows as $i => &$row) {
    $row['rank'] = $i + 1;
}
unset($row);

$overallRaces = 0;
$overallFirst = 0;
foreach ($rows as $r) {
    $overallRaces += $r['races'];
    $overallFirst += (int)round($r['combined_first'] / 100.0 * $r['races']);
}
$overallFirstRate = pct($overallFirst, $overallRaces);

echo PHP_EOL;
echo str_repeat('=', 130) . PHP_EOL;
echo "現行Web予想 場別相性 2期間比較" . PHP_EOL;
echo str_repeat('=', 130) . PHP_EOL;
echo "期間1 : {$p1['start_date']} ～ {$p1['end_date']} / {$p1['valid']}R" . PHP_EOL;
echo "期間2 : {$p2['start_date']} ～ {$p2['end_date']} / {$p2['valid']}R" . PHP_EOL;
echo "並び順: 2期間合算 本命1着率" . PHP_EOL;
echo PHP_EOL;

echo sprintf(
    "%-4s %-12s %6s %9s %9s %9s %9s %9s %9s %9s %9s %9s\n",
    '順位', '場', 'R数', 'P1本命', 'P2本命', '期間差', '合算1着', '合算2連', '合算3連', '1C実績', '本命1C', '1C評価差'
);
echo str_repeat('-', 130) . PHP_EOL;

foreach ($rows as $r) {
    echo sprintf(
        "%-4d %-12s %6d %9s %9s %8.2fpt %9s %9s %9s %9s %9s %+8.2fpt\n",
        $r['rank'],
        $r['stadium'],
        $r['races'],
        fmtPct($r['p1_first']),
        fmtPct($r['p2_first']),
        $r['stability_gap'],
        fmtPct($r['combined_first']),
        fmtPct($r['combined_second']),
        fmtPct($r['combined_third']),
        fmtPct($r['combined_lane1']),
        fmtPct($r['combined_honmei_lane1']),
        $r['combined_bias']
    );
}

echo str_repeat('-', 130) . PHP_EOL;
echo "全体合算 本命1着率 : " . fmtPct($overallFirstRate) . PHP_EOL;
echo PHP_EOL;

// 安定度ランキング
$stable = $rows;
usort($stable, static function (array $a, array $b): int {
    $cmp = $a['stability_gap'] <=> $b['stability_gap'];
    if ($cmp !== 0) return $cmp;
    return $b['combined_first'] <=> $a['combined_first'];
});

echo "【本命1着率の安定度 TOP10（期間差が小さい順）】" . PHP_EOL;
foreach (array_slice($stable, 0, 10) as $i => $r) {
    echo sprintf(
        "%2d. %-10s 期間差 %5.2fpt / 合算 %s / 24場順位 %d位\n",
        $i + 1,
        $r['stadium'],
        $r['stability_gap'],
        fmtPct($r['combined_first']),
        $r['rank']
    );
}

echo PHP_EOL;

// 1号艇の過信/過小評価ランキング
$over = $rows;
usort($over, static fn(array $a, array $b): int => $b['combined_bias'] <=> $a['combined_bias']);

echo "【1号艇を実績より多く本命にしている場 TOP8】" . PHP_EOL;
foreach (array_slice($over, 0, 8) as $i => $r) {
    echo sprintf(
        "%2d. %-10s 1C評価差 %s（実1C %s / 本命1C %s）\n",
        $i + 1,
        $r['stadium'],
        fmtPt($r['combined_bias']),
        fmtPct($r['combined_lane1']),
        fmtPct($r['combined_honmei_lane1'])
    );
}

echo PHP_EOL;

$under = $rows;
usort($under, static fn(array $a, array $b): int => $a['combined_bias'] <=> $b['combined_bias']);

echo "【1号艇を実績より少なく本命にしている場 TOP8】" . PHP_EOL;
foreach (array_slice($under, 0, 8) as $i => $r) {
    echo sprintf(
        "%2d. %-10s 1C評価差 %s（実1C %s / 本命1C %s）\n",
        $i + 1,
        $r['stadium'],
        fmtPt($r['combined_bias']),
        fmtPct($r['combined_lane1']),
        fmtPct($r['combined_honmei_lane1'])
    );
}

echo PHP_EOL;
echo "※ 1C評価差 = Web本命1C率 - 実際の1コース1着率。" . PHP_EOL;
echo "※ プラスが大きいほど1号艇を過信寄り、マイナスが大きいほど過小評価寄り。" . PHP_EOL;
echo "※ 期間差は本命1着率の2期間差（絶対値）。小さいほど安定。" . PHP_EOL;
echo PHP_EOL;
