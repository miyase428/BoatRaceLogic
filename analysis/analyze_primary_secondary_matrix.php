<?php

/**
 * STEP 3-8
 *
 * 一次1位・二次1位が不一致のレースについて、
 *
 * 一次1位-2位差 × 二次1位-2位差
 *
 * を細分化して、
 * 「現在の統合1位」と「一次1位」のどちらが実績的に優れているかを比較する。
 *
 * 条件：
 *
 * 一次差
 *   0～2
 *   2～5
 *   5～10
 *   10以上
 *
 * 二次差
 *   0～1
 *   1～2
 *   2～5
 *   5以上
 *
 * 比較対象：
 *   現在の統合1位
 *   一次1位
 */

if ($argc < 2) {
    echo "Usage: php analyze_primary_secondary_matrix.php <CSVファイル>\n";
    exit(1);
}

$csvFile = $argv[1];

if (!file_exists($csvFile)) {
    echo "CSVファイルが見つかりません: {$csvFile}\n";
    exit(1);
}

echo "========================================\n";
echo "STEP 3-8 一次差 × 二次差 完全マトリクス分析\n";
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

echo "読み込みレース数 : " . count($races) . "\n";
echo "読み込み艇数     : " .
    array_sum(
        array_map(
            'count',
            $races
        )
    ) .
    "\n";


/*
 * 一次差の区分
 */
$primaryConditions = [

    '一次 0～2' => [
        'min' => 0,
        'max' => 2,
    ],

    '一次 2～5' => [
        'min' => 2,
        'max' => 5,
    ],

    '一次 5～10' => [
        'min' => 5,
        'max' => 10,
    ],

    '一次 10以上' => [
        'min' => 10,
        'max' => PHP_FLOAT_MAX,
    ],
];


/*
 * 二次差の区分
 */
$secondaryConditions = [

    '二次 0～1' => [
        'min' => 0,
        'max' => 1,
    ],

    '二次 1～2' => [
        'min' => 1,
        'max' => 2,
    ],

    '二次 2～5' => [
        'min' => 2,
        'max' => 5,
    ],

    '二次 5以上' => [
        'min' => 5,
        'max' => PHP_FLOAT_MAX,
    ],
];


foreach ($primaryConditions as $primaryName => $primaryCondition) {

    foreach ($secondaryConditions as $secondaryName => $secondaryCondition) {

        analyzeCondition(
            $races,
            $primaryName,
            $primaryCondition,
            $secondaryName,
            $secondaryCondition
        );
    }
}


echo "\n========================================\n";
echo "STEP 3-8 マトリクス分析 完了\n";
echo "========================================\n";


/**
 * 条件分析
 */
function analyzeCondition(
    array $races,
    string $primaryName,
    array $primaryCondition,
    string $secondaryName,
    array $secondaryCondition
): void {

    $total = 0;

    $stats = [
        'final' => createStats(),
        'primary' => createStats(),
    ];

    $direct = [
        'primary_better' => 0,
        'final_better' => 0,
        'same' => 0,
    ];

    $transition = [
        'final_1_primary_1' => 0,
        'final_1_primary_not_1' => 0,
        'final_not_1_primary_1' => 0,

        'final_3_primary_not_3' => 0,
        'final_not_3_primary_3' => 0,
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
         * 一次1位と二次1位が一致している場合は、
         * 今回の分析対象外。
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


        /*
         * 一次差
         */
        if (
            $firstGap < $primaryCondition['min'] ||
            $firstGap >= $primaryCondition['max']
        ) {
            continue;
        }


        /*
         * 二次差
         */
        if (
            $secondGap < $secondaryCondition['min'] ||
            $secondGap >= $secondaryCondition['max']
        ) {
            continue;
        }


        $total++;


        /*
         * 統合1位
         */
        if ($final1['actual_rank'] > 0) {

            addActual(
                $stats['final'],
                $final1['actual_rank']
            );
        }


        /*
         * 一次1位
         */
        if ($first1['actual_rank'] > 0) {

            addActual(
                $stats['primary'],
                $first1['actual_rank']
            );
        }


        /*
         * 直接比較
         *
         * 両方とも実着順が存在する場合のみ。
         */
        if (
            $final1['actual_rank'] > 0 &&
            $first1['actual_rank'] > 0
        ) {

            if (
                $first1['actual_rank']
                <
                $final1['actual_rank']
            ) {

                $direct['primary_better']++;

            } elseif (
                $final1['actual_rank']
                <
                $first1['actual_rank']
            ) {

                $direct['final_better']++;

            } else {

                $direct['same']++;
            }


            /*
             * 1着遷移
             */
            if (
                $final1['actual_rank'] === 1 &&
                $first1['actual_rank'] === 1
            ) {

                $transition['final_1_primary_1']++;

            } elseif (
                $final1['actual_rank'] === 1 &&
                $first1['actual_rank'] !== 1
            ) {

                $transition['final_1_primary_not_1']++;

            } elseif (
                $final1['actual_rank'] !== 1 &&
                $first1['actual_rank'] === 1
            ) {

                $transition['final_not_1_primary_1']++;
            }


            /*
             * 3連対遷移
             */
            $finalTop3 =
                $final1['actual_rank'] <= 3;

            $primaryTop3 =
                $first1['actual_rank'] <= 3;


            if (
                $finalTop3 &&
                !$primaryTop3
            ) {

                $transition['final_3_primary_not_3']++;

            } elseif (
                !$finalTop3 &&
                $primaryTop3
            ) {

                $transition['final_not_3_primary_3']++;
            }
        }
    }


    /*
     * 出力
     */
    echo "\n========================================\n";
    echo "{$primaryName} × {$secondaryName}\n";
    echo "========================================\n";

    echo "対象レース       : {$total}\n";


    $directCount =
        $direct['primary_better']
        +
        $direct['final_better']
        +
        $direct['same'];


    echo "直接比較可能     : {$directCount}\n\n";


    printStats(
        '現在の統合1位',
        $stats['final']
    );


    printStats(
        '一次1位へ変更',
        $stats['primary']
    );


    if ($directCount > 0) {

        echo "\n----------------------------------------\n";
        echo "実着順直接比較\n";
        echo "----------------------------------------\n";

        printf(
            "一次1位へ変更した方が上位 : %4d (%.2f%%)\n",
            $direct['primary_better'],
            $direct['primary_better'] / $directCount * 100
        );

        printf(
            "現在の統合1位の方が上位   : %4d (%.2f%%)\n",
            $direct['final_better'],
            $direct['final_better'] / $directCount * 100
        );

        printf(
            "同着                       : %4d (%.2f%%)\n",
            $direct['same'],
            $direct['same'] / $directCount * 100
        );


        echo "\n----------------------------------------\n";
        echo "1着遷移\n";
        echo "----------------------------------------\n";

        printf(
            "統合1位 1着 → 一次1位 1着     : %d\n",
            $transition['final_1_primary_1']
        );

        printf(
            "統合1位 1着 → 一次1位 2着以下 : %d\n",
            $transition['final_1_primary_not_1']
        );

        printf(
            "統合1位 2着以下 → 一次1位 1着 : %d\n",
            $transition['final_not_1_primary_1']
        );


        echo "\n----------------------------------------\n";
        echo "3連対遷移\n";
        echo "----------------------------------------\n";

        printf(
            "統合1位 3連対 → 一次1位 3連対外 : %d\n",
            $transition['final_3_primary_not_3']
        );

        printf(
            "統合1位 3連対外 → 一次1位 3連対 : %d\n",
            $transition['final_not_3_primary_3']
        );
    }


    /*
     * 改善幅
     */
    if (
        $stats['final']['count'] > 0 &&
        $stats['primary']['count'] > 0
    ) {

        $finalFirstRate =
            $stats['final']['rank1']
            /
            $stats['final']['count']
            *
            100;

        $primaryFirstRate =
            $stats['primary']['rank1']
            /
            $stats['primary']['count']
            *
            100;


        $finalSecondRate =
            (
                $stats['final']['rank1']
                +
                $stats['final']['rank2']
            )
            /
            $stats['final']['count']
            *
            100;

        $primarySecondRate =
            (
                $stats['primary']['rank1']
                +
                $stats['primary']['rank2']
            )
            /
            $stats['primary']['count']
            *
            100;


        $finalThirdRate =
            (
                $stats['final']['rank1']
                +
                $stats['final']['rank2']
                +
                $stats['final']['rank3']
            )
            /
            $stats['final']['count']
            *
            100;

        $primaryThirdRate =
            (
                $stats['primary']['rank1']
                +
                $stats['primary']['rank2']
                +
                $stats['primary']['rank3']
            )
            /
            $stats['primary']['count']
            *
            100;


        $finalAverage =
            $stats['final']['sum']
            /
            $stats['final']['count'];

        $primaryAverage =
            $stats['primary']['sum']
            /
            $stats['primary']['count'];


        echo "\n----------------------------------------\n";
        echo "改善幅\n";
        echo "----------------------------------------\n";

        printf(
            "1着率       : %+0.2fポイント\n",
            $primaryFirstRate - $finalFirstRate
        );

        printf(
            "2連対率     : %+0.2fポイント\n",
            $primarySecondRate - $finalSecondRate
        );

        printf(
            "3連対率     : %+0.2fポイント\n",
            $primaryThirdRate - $finalThirdRate
        );

        /*
         * 平均着順は「小さい方が改善」なので、
         * 統合 - 一次
         * とする。
         */
        printf(
            "平均着順    : %+0.3f\n",
            $finalAverage - $primaryAverage
        );
    }
}


/**
 * 統計初期化
 */
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


/**
 * ランク1取得
 */
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


/**
 * ランク2取得
 */
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


/**
 * 実着順を統計に追加
 */
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


/**
 * 統計表示
 */
function printStats(
    string $name,
    array $stats
): void {

    echo "\n----------------------------------------\n";
    echo "{$name}\n";
    echo "----------------------------------------\n";

    if ($stats['count'] === 0) {

        echo "件数       : 0\n";

        return;
    }


    $count = $stats['count'];


    $firstRate =
        $stats['rank1']
        /
        $count
        *
        100;


    $secondRate =
        (
            $stats['rank1']
            +
            $stats['rank2']
        )
        /
        $count
        *
        100;


    $thirdRate =
        (
            $stats['rank1']
            +
            $stats['rank2']
            +
            $stats['rank3']
        )
        /
        $count
        *
        100;


    $average =
        $stats['sum']
        /
        $count;


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