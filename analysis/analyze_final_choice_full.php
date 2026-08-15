<?php

/**
 * STEP 3-6 完全版
 *
 * 統合1位 → 一次1位 仮変更シミュレーション
 *
 * 対象：
 *   一次1位と二次1位が不一致
 *
 * 条件：
 *   一次 2～5 × 二次 0～2
 *   一次 5以上 × 二次 0～2
 *
 * 比較：
 *   現在の統合1位
 *   vs
 *   仮に一次1位を採用した場合
 *
 * 注意：
 *   actual_rank <= 0 は実着順なしとして除外
 */

if ($argc < 2) {
    echo "Usage: php analyze_final_choice_full.php <CSVファイル>\n";
    exit(1);
}

$csvFile = $argv[1];

if (!file_exists($csvFile)) {
    echo "CSVファイルが見つかりません: {$csvFile}\n";
    exit(1);
}

echo "========================================\n";
echo "STEP 3-6 統合1位 → 一次1位 完全比較\n";
echo "========================================\n";
echo "CSV : {$csvFile}\n\n";

$fp = fopen($csvFile, 'r');

if ($fp === false) {
    echo "CSVを開けませんでした。\n";
    exit(1);
}

$header = fgetcsv($fp);

if ($header === false) {
    echo "CSVが空です。\n";
    exit(1);
}

$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

$headerMap = [];

foreach ($header as $index => $name) {
    $headerMap[trim($name)] = $index;
}

$required = [
    'race_code',
    'lane_number',
    'first_total_score',
    'first_rank',
    'second_score',
    'second_rank',
    'final_rank',
    'actual_rank',
];

foreach ($required as $column) {

    if (!isset($headerMap[$column])) {
        echo "必要な列がありません: {$column}\n";
        exit(1);
    }
}

$races = [];

while (($row = fgetcsv($fp)) !== false) {

    if (count($row) !== count($header)) {
        continue;
    }

    $raceCode = trim($row[$headerMap['race_code']]);

    if ($raceCode === '') {
        continue;
    }

    $races[$raceCode][] = [

        'lane_number' =>
            (int)$row[$headerMap['lane_number']],

        'first_total_score' =>
            (float)$row[$headerMap['first_total_score']],

        'first_rank' =>
            (int)$row[$headerMap['first_rank']],

        'second_score' =>
            (float)$row[$headerMap['second_score']],

        'second_rank' =>
            (int)$row[$headerMap['second_rank']],

        'final_rank' =>
            (int)$row[$headerMap['final_rank']],

        'actual_rank' =>
            (int)$row[$headerMap['actual_rank']],
    ];
}

fclose($fp);

echo "読み込みレース数 : " . count($races) . "\n";
echo "読み込み艇数     : " . array_sum(array_map('count', $races)) . "\n";


$conditions = [

    '一次 2～5 × 二次 0～2' =>
        [
            'first_min' => 2,
            'first_max' => 5,
            'second_min' => 0,
            'second_max' => 2,
        ],

    '一次 5以上 × 二次 0～2' =>
        [
            'first_min' => 5,
            'first_max' => PHP_FLOAT_MAX,
            'second_min' => 0,
            'second_max' => 2,
        ],
];


foreach ($conditions as $conditionName => $condition) {

    echo "\n========================================\n";
    echo "{$conditionName}\n";
    echo "========================================\n";

    $targetRaces = 0;
    $comparableRaces = 0;

    $skip = [
        'first1_missing' => 0,
        'first2_missing' => 0,
        'second1_missing' => 0,
        'second2_missing' => 0,
        'final1_missing' => 0,
        'same_top' => 0,
        'actual_missing_final' => 0,
        'actual_missing_primary' => 0,
    ];

    $beforeStats = createStats();
    $afterStats = createStats();

    $direct = [
        'primary_better' => 0,
        'final_better' => 0,
        'tie' => 0,
    ];

    $transition = [
        'first_to_first' => 0,
        'first_to_nonfirst' => 0,
        'nonfirst_to_first' => 0,
        'third_to_third' => 0,
        'third_to_nonthird' => 0,
        'nonthird_to_third' => 0,
    ];

    foreach ($races as $raceCode => $boats) {

        $first1 = findRankOne($boats, 'first_rank');
        $first2 = findRankTwo($boats, 'first_rank');

        $second1 = findRankOne($boats, 'second_rank');
        $second2 = findRankTwo($boats, 'second_rank');

        $final1 = findRankOne($boats, 'final_rank');

        if ($first1 === null) {
            $skip['first1_missing']++;
            continue;
        }

        if ($first2 === null) {
            $skip['first2_missing']++;
            continue;
        }

        if ($second1 === null) {
            $skip['second1_missing']++;
            continue;
        }

        if ($second2 === null) {
            $skip['second2_missing']++;
            continue;
        }

        if ($final1 === null) {
            $skip['final1_missing']++;
            continue;
        }

        /*
         * 一次1位・二次1位が一致しているレースは対象外
         */
        if (
            $first1['lane_number']
            ===
            $second1['lane_number']
        ) {
            $skip['same_top']++;
            continue;
        }

        $firstGap =
            $first1['first_total_score']
            -
            $first2['first_total_score'];

        $secondGap =
            $second1['second_score']
            -
            $second2['second_score'];

        if (
            $firstGap < $condition['first_min'] ||
            $firstGap >= $condition['first_max'] ||
            $secondGap < $condition['second_min'] ||
            $secondGap >= $condition['second_max']
        ) {
            continue;
        }

        $targetRaces++;

        /*
         * 比較対象：
         *
         * 現在の統合1位
         * 仮変更後の一次1位
         */

        $currentBoat = $final1;
        $primaryBoat = $first1;

        /*
         * 実着順がない場合は直接比較できない
         */
        if ($currentBoat['actual_rank'] <= 0) {
            $skip['actual_missing_final']++;
            continue;
        }

        if ($primaryBoat['actual_rank'] <= 0) {
            $skip['actual_missing_primary']++;
            continue;
        }

        $comparableRaces++;

        addActual(
            $beforeStats,
            $currentBoat['actual_rank']
        );

        addActual(
            $afterStats,
            $primaryBoat['actual_rank']
        );

        /*
         * 直接比較
         */
        if (
            $primaryBoat['actual_rank']
            <
            $currentBoat['actual_rank']
        ) {

            $direct['primary_better']++;

        } elseif (
            $primaryBoat['actual_rank']
            >
            $currentBoat['actual_rank']
        ) {

            $direct['final_better']++;

        } else {

            $direct['tie']++;
        }

        /*
         * 1着の遷移
         */
        $beforeFirst =
            ($currentBoat['actual_rank'] === 1);

        $afterFirst =
            ($primaryBoat['actual_rank'] === 1);

        if ($beforeFirst && $afterFirst) {
            $transition['first_to_first']++;
        }

        if ($beforeFirst && !$afterFirst) {
            $transition['first_to_nonfirst']++;
        }

        if (!$beforeFirst && $afterFirst) {
            $transition['nonfirst_to_first']++;
        }

        /*
         * 3連対の遷移
         */
        $beforeThird =
            ($currentBoat['actual_rank'] <= 3);

        $afterThird =
            ($primaryBoat['actual_rank'] <= 3);

        if ($beforeThird && $afterThird) {
            $transition['third_to_third']++;
        }

        if ($beforeThird && !$afterThird) {
            $transition['third_to_nonthird']++;
        }

        if (!$beforeThird && $afterThird) {
            $transition['nonthird_to_third']++;
        }
    }


    echo "対象レース       : {$targetRaces}\n";
    echo "直接比較可能     : {$comparableRaces}\n\n";


    echo "----------------------------------------\n";
    echo "現在の統合1位\n";
    echo "----------------------------------------\n";

    printStats($beforeStats);


    echo "----------------------------------------\n";
    echo "一次1位へ変更\n";
    echo "----------------------------------------\n";

    printStats($afterStats);


    echo "\n----------------------------------------\n";
    echo "実着順直接比較\n";
    echo "----------------------------------------\n";

    $directTotal =
        $direct['primary_better']
        +
        $direct['final_better']
        +
        $direct['tie'];

    printf(
        "一次1位へ変更した方が上位 : %4d (%.2f%%)\n",
        $direct['primary_better'],
        rate($direct['primary_better'], $directTotal)
    );

    printf(
        "現在の統合1位の方が上位   : %4d (%.2f%%)\n",
        $direct['final_better'],
        rate($direct['final_better'], $directTotal)
    );

    printf(
        "同着                       : %4d (%.2f%%)\n",
        $direct['tie'],
        rate($direct['tie'], $directTotal)
    );


    echo "\n----------------------------------------\n";
    echo "1着遷移\n";
    echo "----------------------------------------\n";

    printf(
        "統合1位 1着 → 一次1位 1着     : %d\n",
        $transition['first_to_first']
    );

    printf(
        "統合1位 1着 → 一次1位 2着以下 : %d\n",
        $transition['first_to_nonfirst']
    );

    printf(
        "統合1位 2着以下 → 一次1位 1着 : %d\n",
        $transition['nonfirst_to_first']
    );


    echo "\n----------------------------------------\n";
    echo "3連対遷移\n";
    echo "----------------------------------------\n";

    printf(
        "統合1位 3連対 → 一次1位 3連対外 : %d\n",
        $transition['third_to_nonthird']
    );

    printf(
        "統合1位 3連対外 → 一次1位 3連対 : %d\n",
        $transition['nonthird_to_third']
    );


    echo "\n----------------------------------------\n";
    echo "改善幅\n";
    echo "----------------------------------------\n";

    $before = calcRates($beforeStats);
    $after = calcRates($afterStats);

    printf(
        "1着率       : %+.2fポイント\n",
        $after['first'] - $before['first']
    );

    printf(
        "2連対率     : %+.2fポイント\n",
        $after['second'] - $before['second']
    );

    printf(
        "3連対率     : %+.2fポイント\n",
        $after['third'] - $before['third']
    );

    printf(
        "平均着順    : %+.3f\n",
        $before['average'] - $after['average']
    );


    echo "\n----------------------------------------\n";
    echo "除外状況\n";
    echo "----------------------------------------\n";

    printf(
        "一次1位なし       : %d\n",
        $skip['first1_missing']
    );

    printf(
        "一次2位なし       : %d\n",
        $skip['first2_missing']
    );

    printf(
        "二次1位なし       : %d\n",
        $skip['second1_missing']
    );

    printf(
        "二次2位なし       : %d\n",
        $skip['second2_missing']
    );

    printf(
        "統合1位なし       : %d\n",
        $skip['final1_missing']
    );

    printf(
        "一次1位＝二次1位  : %d\n",
        $skip['same_top']
    );

    printf(
        "統合1位 実着順なし: %d\n",
        $skip['actual_missing_final']
    );

    printf(
        "一次1位 実着順なし: %d\n",
        $skip['actual_missing_primary']
    );
}


echo "\n========================================\n";
echo "STEP 3-6 完全比較 完了\n";
echo "========================================\n";


function createStats(): array
{
    return [
        'count' => 0,
        'rank1' => 0,
        'rank2' => 0,
        'rank3' => 0,
        'sum' => 0,
    ];
}


function findRankOne(
    array $boats,
    string $column
): ?array {

    foreach ($boats as $boat) {

        if ($boat[$column] === 1) {
            return $boat;
        }
    }

    return null;
}


function findRankTwo(
    array $boats,
    string $column
): ?array {

    foreach ($boats as $boat) {

        if ($boat[$column] === 2) {
            return $boat;
        }
    }

    return null;
}


function addActual(
    array &$stats,
    int $actual
): void {

    if ($actual <= 0) {
        return;
    }

    $stats['count']++;
    $stats['sum'] += $actual;

    if ($actual === 1) {
        $stats['rank1']++;
    }

    if ($actual === 2) {
        $stats['rank2']++;
    }

    if ($actual === 3) {
        $stats['rank3']++;
    }
}


function calcRates(array $stats): array
{
    if ($stats['count'] === 0) {

        return [
            'first' => 0,
            'second' => 0,
            'third' => 0,
            'average' => 0,
        ];
    }

    $count = $stats['count'];

    return [
        'first' =>
            $stats['rank1']
            / $count
            * 100,

        'second' =>
            (
                $stats['rank1']
                +
                $stats['rank2']
            )
            / $count
            * 100,

        'third' =>
            (
                $stats['rank1']
                +
                $stats['rank2']
                +
                $stats['rank3']
            )
            / $count
            * 100,

        'average' =>
            $stats['sum']
            / $count,
    ];
}


function rate(
    int $value,
    int $total
): float {

    if ($total === 0) {
        return 0;
    }

    return $value / $total * 100;
}


function printStats(array $stats): void
{
    if ($stats['count'] === 0) {
        echo "件数       : 0\n";
        return;
    }

    $rates = calcRates($stats);

    printf(
        "件数       : %d\n",
        $stats['count']
    );

    printf(
        "1着        : %.2f%%\n",
        $rates['first']
    );

    printf(
        "2連対      : %.2f%%\n",
        $rates['second']
    );

    printf(
        "3連対      : %.2f%%\n",
        $rates['third']
    );

    printf(
        "平均着順   : %.3f\n",
        $rates['average']
    );
}