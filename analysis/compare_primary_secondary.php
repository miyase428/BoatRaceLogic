<?php

/**
 * STEP 3-5
 *
 * 一次1位 vs 二次1位 直接比較
 *
 * 条件：
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
    'lane_number',
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
        'lane_number' =>
            (int)$row[$map['lane_number']],

        'first_total_score' =>
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
 * ========================================
 * 分析条件
 * ========================================
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

        'primary' => createStats(),

        'secondary' => createStats(),

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
         * 一次1位と二次1位が同じ艇なら除外
         */
        if (
            $first1['lane_number']
            ===
            $second1['lane_number']
        ) {
            continue;
        }


        /*
         * 1位-2位スコア差
         */
        $firstGap =
            $first1['first_total_score']
            -
            $first2['first_total_score'];

        $secondGap =
            $second1['second_score']
            -
            $second2['second_score'];


        /*
         * 条件判定
         */
        if (
            $firstGap < $condition['first_min']
            ||
            $firstGap >= $condition['first_max']
            ||
            $secondGap < $condition['second_min']
            ||
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


        /*
         * 対象レース数
         */
        $stats['count']++;


        /*
         * 一次1位
         */
        addActual(
            $stats['primary'],
            $primaryActual
        );


        /*
         * 二次1位
         */
        addActual(
            $stats['secondary'],
            $secondaryActual
        );


        /*
         * 直接比較
         */
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


/*
 * ========================================
 * 関数
 * ========================================
 */

function createStats(): array
{
    return [
        'rank1' => 0,
        'rank2' => 0,
        'rank3' => 0,
        'sum' => 0,
        'count' => 0,
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


function rate(
    int $value,
    int $total
): float {

    if ($total <= 0) {
        return 0.0;
    }

    return $value / $total * 100;
}


function printResult(
    string $name,
    array $stats
): void {

    $count = $stats['count'];

    if ($count <= 0) {

        echo "----------------------------------------\n";
        echo "{$name}\n";
        echo "----------------------------------------\n";
        echo "件数 : 0\n";

        return;
    }

    $firstRate =
        rate(
            $stats['rank1'],
            $count
        );

    $secondRate =
        rate(
            $stats['rank1']
            +
            $stats['rank2'],
            $count
        );

    $thirdRate =
        rate(
            $stats['rank1']
            +
            $stats['rank2']
            +
            $stats['rank3'],
            $count
        );

    $average =
        $stats['sum']
        /
        $count;


    echo "----------------------------------------\n";
    echo "{$name}\n";
    echo "----------------------------------------\n";

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