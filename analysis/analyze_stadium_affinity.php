<?php

declare(strict_types=1);

/**
 * 現行Web予想 場別相性チェック
 *
 * レース別CSVだけを使い、競艇場ごとの現行予想成績を比較する。
 * DBにはアクセスしないため、期間CSV生成中でも並行して実行できる。
 *
 * 主な確認項目
 *   - 対象レース数
 *   - 実際の1コース1着率
 *   - 本命が1号艇だった割合
 *   - 本命1着率
 *   - 本命2連対率
 *   - 本命3連対率
 *   - 本命買い目的中率
 *   - 本命＋対抗の頭的中率
 *
 * Usage:
 *   php analysis/analyze_stadium_affinity.php \
 *     analysis/output/final_prediction_races_20260715_20260814.csv
 */

if ($argc < 2) {
    echo PHP_EOL;
    echo "使用方法:" . PHP_EOL;
    echo "  php analysis/analyze_stadium_affinity.php <races_csv>" . PHP_EOL;
    echo PHP_EOL;
    exit(1);
}

$csvPath = $argv[1];

if (!is_file($csvPath)) {
    fwrite(STDERR, "CSVが見つかりません: {$csvPath}" . PHP_EOL);
    exit(1);
}

/**
 * 3連単フォーメーションを展開せず、そのまま的中判定する。
 *
 * 例:
 *   1-256-23456
 */
function isTrifectaHit(
    string $formation,
    int $actual1,
    int $actual2,
    int $actual3
): bool {
    $formation = trim($formation);

    if ($formation === '') {
        return false;
    }

    $parts = explode('-', $formation);

    if (count($parts) !== 3) {
        return false;
    }

    $first = str_split(trim($parts[0]));
    $second = str_split(trim($parts[1]));
    $third = str_split(trim($parts[2]));

    return
        in_array((string)$actual1, $first, true)
        && in_array((string)$actual2, $second, true)
        && in_array((string)$actual3, $third, true);
}

function pct(int $count, int $total): float
{
    return $total > 0
        ? ($count / $total) * 100
        : 0.0;
}

function fmtPct(float $value): string
{
    return number_format($value, 2) . '%';
}

$fp = fopen($csvPath, 'rb');

if ($fp === false) {
    fwrite(STDERR, "CSVを開けません: {$csvPath}" . PHP_EOL);
    exit(1);
}

$header = fgetcsv($fp);

if ($header === false) {
    fclose($fp);
    fwrite(STDERR, "CSVヘッダーを読み込めません。" . PHP_EOL);
    exit(1);
}

$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

$headerMap = [];

foreach ($header as $index => $name) {
    $headerMap[$name] = $index;
}

$requiredColumns = [
    'race_code',
    'race_date',
    'stadium_name',
    'honmei_head',
    'taikou_head',
    'honmei_kai',
    'actual_1st',
    'actual_2nd',
    'actual_3rd',
];

foreach ($requiredColumns as $column) {
    if (!array_key_exists($column, $headerMap)) {
        fclose($fp);
        fwrite(STDERR, "必要な列がありません: {$column}" . PHP_EOL);
        exit(1);
    }
}

$stadiums = [];
$totalValid = 0;
$startDate = null;
$endDate = null;

while (($row = fgetcsv($fp)) !== false) {
    if (count($row) < count($header)) {
        continue;
    }

    $raceCode = trim($row[$headerMap['race_code']]);
    $raceDate = trim($row[$headerMap['race_date']]);
    $stadium = trim($row[$headerMap['stadium_name']]);

    $honmeiHead = (int)$row[$headerMap['honmei_head']];
    $taikouHead = (int)$row[$headerMap['taikou_head']];
    $honmeiKai = trim($row[$headerMap['honmei_kai']]);

    $actual1 = (int)$row[$headerMap['actual_1st']];
    $actual2 = (int)$row[$headerMap['actual_2nd']];
    $actual3 = (int)$row[$headerMap['actual_3rd']];

    if (
        $raceCode === '' ||
        $stadium === '' ||
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
            'honmei_second_or_better' => 0,
            'honmei_third_or_better' => 0,
            'honmei_bet_hit' => 0,
            'head_either_hit' => 0,
        ];
    }

    $s =& $stadiums[$stadium];
    $s['races']++;

    if ($actual1 === 1) {
        $s['lane1_win']++;
    }

    if ($honmeiHead === 1) {
        $s['honmei_lane1']++;
    }

    if ($actual1 === $honmeiHead) {
        $s['honmei_first']++;
    }

    if ($honmeiHead === $actual1 || $honmeiHead === $actual2) {
        $s['honmei_second_or_better']++;
    }

    if (
        $honmeiHead === $actual1 ||
        $honmeiHead === $actual2 ||
        $honmeiHead === $actual3
    ) {
        $s['honmei_third_or_better']++;
    }

    if (isTrifectaHit($honmeiKai, $actual1, $actual2, $actual3)) {
        $s['honmei_bet_hit']++;
    }

    if ($actual1 === $honmeiHead || $actual1 === $taikouHead) {
        $s['head_either_hit']++;
    }

    $totalValid++;

    if ($raceDate !== '') {
        if ($startDate === null || $raceDate < $startDate) {
            $startDate = $raceDate;
        }

        if ($endDate === null || $raceDate > $endDate) {
            $endDate = $raceDate;
        }
    }

    unset($s);
}

fclose($fp);

if ($totalValid === 0) {
    fwrite(STDERR, "有効なレースがありません。" . PHP_EOL);
    exit(1);
}

$results = [];

foreach ($stadiums as $stadium => $s) {
    $races = $s['races'];

    $results[] = [
        'stadium' => $stadium,
        'races' => $races,
        'lane1_win_rate' => pct($s['lane1_win'], $races),
        'honmei_lane1_rate' => pct($s['honmei_lane1'], $races),
        'honmei_first_rate' => pct($s['honmei_first'], $races),
        'honmei_second_rate' => pct($s['honmei_second_or_better'], $races),
        'honmei_third_rate' => pct($s['honmei_third_or_better'], $races),
        'honmei_bet_hit_rate' => pct($s['honmei_bet_hit'], $races),
        'head_either_rate' => pct($s['head_either_hit'], $races),
    ];
}

// 現行Web本命の1着率が高い順。
usort(
    $results,
    static function (array $a, array $b): int {
        $cmp = $b['honmei_first_rate'] <=> $a['honmei_first_rate'];

        if ($cmp !== 0) {
            return $cmp;
        }

        return $b['races'] <=> $a['races'];
    }
);

$overall = [
    'races' => 0,
    'lane1_win' => 0,
    'honmei_lane1' => 0,
    'honmei_first' => 0,
    'honmei_second_or_better' => 0,
    'honmei_third_or_better' => 0,
    'honmei_bet_hit' => 0,
    'head_either_hit' => 0,
];

foreach ($stadiums as $s) {
    foreach ($overall as $key => $value) {
        $overall[$key] += $s[$key];
    }
}

$overallRaces = $overall['races'];
$overallHonmeiFirstRate = pct($overall['honmei_first'], $overallRaces);

echo PHP_EOL;
echo "==============================================================================================" . PHP_EOL;
echo "現行Web予想 場別相性チェック" . PHP_EOL;
echo "==============================================================================================" . PHP_EOL;
echo "CSV      : {$csvPath}" . PHP_EOL;
echo "期間     : " . ($startDate ?? '-') . " ～ " . ($endDate ?? '-') . PHP_EOL;
echo "有効R    : {$totalValid}" . PHP_EOL;
echo "場数     : " . count($results) . PHP_EOL;
echo "並び順   : 本命1着率の高い順" . PHP_EOL;
echo PHP_EOL;

echo sprintf(
    "%-4s %-12s %6s %9s %9s %9s %9s %9s %9s %9s\n",
    '順位',
    '場',
    'R数',
    '1C1着',
    '本命1C',
    '本命1着',
    '平均との差',
    '本命2連',
    '本命3連',
    '買目Hit'
);

echo str_repeat('-', 106) . PHP_EOL;

foreach ($results as $index => $r) {
    $diff = $r['honmei_first_rate'] - $overallHonmeiFirstRate;

    echo sprintf(
        "%-4d %-12s %6d %9s %9s %9s %+8.2fpt %9s %9s %9s\n",
        $index + 1,
        $r['stadium'],
        $r['races'],
        fmtPct($r['lane1_win_rate']),
        fmtPct($r['honmei_lane1_rate']),
        fmtPct($r['honmei_first_rate']),
        $diff,
        fmtPct($r['honmei_second_rate']),
        fmtPct($r['honmei_third_rate']),
        fmtPct($r['honmei_bet_hit_rate'])
    );
}

echo str_repeat('-', 106) . PHP_EOL;

echo sprintf(
    "%-4s %-12s %6d %9s %9s %9s %9s %9s %9s\n",
    '',
    '全体',
    $overallRaces,
    fmtPct(pct($overall['lane1_win'], $overallRaces)),
    fmtPct(pct($overall['honmei_lane1'], $overallRaces)),
    fmtPct($overallHonmeiFirstRate),
    fmtPct(pct($overall['honmei_second_or_better'], $overallRaces)),
    fmtPct(pct($overall['honmei_third_or_better'], $overallRaces)),
    fmtPct(pct($overall['honmei_bet_hit'], $overallRaces))
);

echo PHP_EOL;
echo "補助指標:" . PHP_EOL;
echo "  本命＋対抗のどちらかが1着 : "
    . fmtPct(pct($overall['head_either_hit'], $overallRaces))
    . PHP_EOL;
echo PHP_EOL;
echo "※ 回収率はこの第1版では未集計。CSV生成中のDB負荷を避けるため、まず予想精度だけを見る。" . PHP_EOL;
echo "※ 1C1着 = 実際の1コース1着率、本命1C = Webが1号艇を本命にした割合。" . PHP_EOL;
echo PHP_EOL;
