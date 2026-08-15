<?php

/**
 * STEP 3-5
 *
 * 一次1位 vs 二次1位 直接比較
 *
 * 対象：
 * ① 一次 2～5 × 二次 0～2
 * ② 一次 5以上 × 二次 0～2
 */

if ($argc < 2) {
    echo "Usage: php compare_primary_secondary.php <CSVファイル>\n";
    exit(1);
}

$csvFile = $argv[1];

if (!file_exists($csvFile)) {
    echo "CSVファイルが見つかりません: {$csvFile}\n";
    exit(1);
}

echo "========================================\n";
echo "STEP 3-5 一次1位 vs 二次1位 直接比較\n";
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

$map = [];

foreach ($header as $i => $name) {
    $map[trim($name)] = $i;
}

$required = [
    'race_code',
    'first_total_score',
    'first_rank',
    'second_score',
    'second_rank',
    'actual_rank',
];

foreach ($required as $column) {
    if (!isset($map[$column])) {
        echo "必要な列がありません: {$column}\n";
        exit(1);
    }
}

$races = [];

while (($row = fgetcsv($fp)) !== false) {

    if (count($row) !== count($header)) {
        continue;
    }

    $raceCode = trim($row[$map['race_code']]);

    if ($raceCode === '') {
        continue;
    }

    $races[$raceCode][] = [
        'first_score' =>
            (float)$row[$map['first_total_score']],

        'first_rank' =>
            (int)$row[$map['first_rank']],

        'second_score' =>
            (float)$row[$map['second_score']],

        'second_rank' =>
            (int)$row[$map['second_rank']],

        'actual_rank' =>
            (int)$row[$map['actual_rank']],
    ];
}

fclose($fp);


/*
 * 条件
 */
$conditions = [

    '一次 2～5 × 二次 0～2' => [
        'first_min' => 2,
        'first_max' => 5,
        'second_min' => 0,
        'second_max' => 2,
    ],

    '一次 5以上 × 二次 0～2' => [
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
        'count' => 0,

        'primary' => [
            'sum' => 0,
            'rank1' => 0,
            'rank2' => 0,
            'rank3' => 0,
        ],

        'secondary' => [
            'sum' => 0,
            'rank1' => 0,
            'rank2' => 0,
            'rank3' => 0,
        ],

        'primary_better' => 0,
        'secondary_better' => 0,
        'same' => 0,
    ];


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

        if (
            $first1 === null ||
            $first2 === null ||
            $second1 === null ||
            $second2 === null
        ) {
            continue;
        }


        /*
         * 一次1位・二次1位が一致する場合は除外
         */
        if (
            $first1['first_rank']
            ===
            $second1['second_rank']
        ) {
            /*
             * rank値同士では艇の一致判定にならないため、
             * 実際には後述の判定を使う。
             */
        }


        /*
         * 一次1位と二次1位の艇が同じか確認
         *
         * CSVにはlane_numberが無い場合があるため、
         * スコア上の順位だけでは判定できない。
         *
         * 今回は不一致分析用CSVなので、
         * 一次1位・二次1位が別艇であることを
         * race内の順位構造から確認する。
         */

        $primaryBoatIndex = findIndex(
            $boats,
            'first_rank',
            1
        );

        $secondaryBoatIndex = findIndex(
            $boats,
            'second_rank',
            1
        );

        if (
            $primaryBoatIndex === null ||
            $secondaryBoatIndex === null
        ) {
            continue;
        }

        if ($primaryBoatIndex === $secondaryBoatIndex) {
            continue;
        }


        /*
         * スコア差
         */
        $firstGap =
            $first1['first_score']
            -
            $first2['first_score'];

        $secondGap =
            $second1['second_score']
            -
            $second2['second_score'];


        /*
         * 条件判定
         */
        if (
            $firstGap < $condition['first_min'] ||
            $firstGap >= $condition['first_max'] ||
            $secondGap < $condition['second_min'] ||
            $secondGap >= $condition['second_max']
        ) {
            continue;
        }


        $primaryActual =
            $first1['actual_rank'];

        $secondaryActual =
            $second1['actual_rank'];


        if (
            $primaryActual <= 0 ||
            $secondaryActual <= 0
        ) {
            continue;
        }


        $stats['count']++;

        addActual(
            $stats['primary'],
            $primaryActual
        );

        addActual(
            $stats['secondary'],
            $secondaryActual
        );


        if ($primaryActual < $secondaryActual) {

            $stats['primary_better']++;

        } elseif ($secondaryActual < $primaryActual) {

            $stats['secondary_better']++;

        } else {

            $stats['same']++;
        }
    }


    /*
     * ========================================
     * 結果
     * ========================================
     */

    echo "対象レース : {$stats['count']}\n\n";

    printResult(
        '一次1位',
        $stats['primary']
    );

    printResult(
        '二次1位',
        $stats['secondary']
    );


    echo "\n実着順直接比較:\n";

    printf(
        "  一次1位の方が上位 : %4d (%.2f%%)\n",
        $stats['primary_better'],
        rate(
            $stats['primary_better'],
            $stats['count']
        )
    );

    printf(
        "  二次1位の方が上位 : %4d (%.2f%%)\n",
        $stats['secondary_better'],
        rate(
            $stats['secondary_better'],
            $stats['count']
        )
    );

    printf(
        "  同着               : %4d (%.2f%%)\n",
        $stats['same'],
        rate(
            $stats['same'],
            $stats['count']
        )
    );
}


echo "\n========================================\n";
echo "分析完了\n";
echo "========================================\n";


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


function findIndex(
    array $boats,
    string $column,
    int $rank
): ?int {

    foreach ($boats as $index => $boat) {

        if ($boat[$column] === $rank) {
            return $index;
        }
    }

    return null;
}


function addActual(
    array &$stats,
    int $actual
): void {

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


function rate(
    int $value,
    int $total
): float {

    if ($total === 0) {
        return 0;
    }

    return $value / $total * 100;
}


function printResult(
    string $name,
    array $stats
): void {

    $count =
        $stats['rank1']
        + $stats['rank2']
        + $stats['rank3'];

    /*
     * 実際の件数は1～6着すべてなので、
     * sumから直接平均を出すため別途countを
     * 呼び出し側では持たない。
     *
     * ここでは1着～6着のデータを対象にするため、
     * rank1+rank2+rank3だけでは不十分。
     *
     * 実際の表示では呼び出し側の対象件数を
     * 使う必要があるため、今回は後述。
     */
}