<?php

/**
 * STEP 3-4
 *
 * 一次優勢候補において、
 * 現在の統合1位が誰を選択しているかを分析する。
 *
 * 対象：
 * 一次1位と二次1位が不一致
 *
 * 特に見る条件：
 *   一次 2～5 × 二次 0～2
 *   一次 5以上 × 二次 0～2
 */

if ($argc < 2) {
    echo "Usage: php analyze_final_choice.php <CSVファイル>\n";
    exit(1);
}

$csvFile = $argv[1];

if (!file_exists($csvFile)) {
    echo "CSVファイルが見つかりません: {$csvFile}\n";
    exit(1);
}

echo "========================================\n";
echo "STEP 3-4 一次優勢候補 最終選択分析\n";
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

    $raceCode = trim(
        $row[$headerMap['race_code']]
    );

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


/*
 * 分析対象
 */
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

    $stats = [
        'primary' => createStats(),
        'secondary' => createStats(),
        'other' => createStats(),
    ];

    $total = 0;

    foreach ($races as $raceCode => $boats) {

        $first1 = findRankOne(
            $boats,
            'first_rank'
        );

        $first2 = findRankTwo(
            $boats,
            'first_rank'
        );

        $second1 = findRankOne(
            $boats,
            'second_rank'
        );

        $second2 = findRankTwo(
            $boats,
            'second_rank'
        );

        $final1 = findRankOne(
            $boats,
            'final_rank'
        );

        if (
            $first1 === null ||
            $first2 === null ||
            $second1 === null ||
            $second2 === null ||
            $final1 === null
        ) {
            continue;
        }

        /*
         * 一次・二次が一致しているレースは除外
         */
        if (
            $first1['lane_number']
            ===
            $second1['lane_number']
        ) {
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

        $total++;

        /*
         * 最終1位が誰なのか
         */
        if (
            $final1['lane_number']
            ===
            $first1['lane_number']
        ) {

            $choice = 'primary';

        } elseif (
            $final1['lane_number']
            ===
            $second1['lane_number']
        ) {

            $choice = 'secondary';

        } else {

            $choice = 'other';
        }

        addActual(
            $stats[$choice],
            $final1['actual_rank']
        );
    }


    echo "対象レース : {$total}\n\n";

    printChoice(
        '一次1位を選択',
        $stats['primary']
    );

    printChoice(
        '二次1位を選択',
        $stats['secondary']
    );

    printChoice(
        'どちらでもない艇を選択',
        $stats['other']
    );
}


echo "\n========================================\n";
echo "分析完了\n";
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


function printChoice(
    string $name,
    array $stats
): void {

    echo "----------------------------------------\n";
    echo "{$name}\n";
    echo "----------------------------------------\n";

    if ($stats['count'] === 0) {
        echo "件数 : 0\n";
        return;
    }

    $count = $stats['count'];

    $firstRate =
        $stats['rank1']
        / $count
        * 100;

    $secondRate =
        (
            $stats['rank1']
            +
            $stats['rank2']
        )
        / $count
        * 100;

    $thirdRate =
        (
            $stats['rank1']
            +
            $stats['rank2']
            +
            $stats['rank3']
        )
        / $count
        * 100;

    $average =
        $stats['sum']
        / $count;

    printf(
        "件数       : %d\n",
        $count
    );

    printf(
        "1着        : %.2f%%\n",
        $firstRate
    );

    printf(
        "2連対      : %.2f%%\n",
        $secondRate
    );

    printf(
        "3連対      : %.2f%%\n",
        $thirdRate
    );

    printf(
        "平均着順   : %.3f\n",
        $average
    );
}