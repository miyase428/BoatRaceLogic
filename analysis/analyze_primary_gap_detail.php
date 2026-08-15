<?php

/**
 * STEP 3-7
 *
 * 一次5以上 × 二次0～2 の細分化分析
 *
 * 分解：
 *   一次差 5～10 × 二次差 0～1
 *   一次差 5～10 × 二次差 1～2
 *   一次差 10以上 × 二次差 0～1
 *   一次差 10以上 × 二次差 1～2
 *
 * 各条件について
 *   ・現在の統合1位
 *   ・一次1位へ変更
 *   ・実着順直接比較
 *   ・1着遷移
 *   ・3連対遷移
 *   ・改善幅
 * を比較する。
 */

if ($argc < 2) {
    echo "Usage: php analyze_primary_gap_detail.php <CSVファイル>\n";
    exit(1);
}

$csvFile = $argv[1];

if (!file_exists($csvFile)) {
    echo "CSVファイルが見つかりません: {$csvFile}\n";
    exit(1);
}

echo "========================================\n";
echo "STEP 3-7 一次5以上 × 二次0～2 細分化分析\n";
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


$conditions = [

    '一次 5～10 × 二次 0～1' => [
        'first_min'  => 5,
        'first_max'  => 10,
        'second_min' => 0,
        'second_max' => 1,
    ],

    '一次 5～10 × 二次 1～2' => [
        'first_min'  => 5,
        'first_max'  => 10,
        'second_min' => 1,
        'second_max' => 2,
    ],

    '一次 10以上 × 二次 0～1' => [
        'first_min'  => 10,
        'first_max'  => PHP_FLOAT_MAX,
        'second_min' => 0,
        'second_max' => 1,
    ],

    '一次 10以上 × 二次 1～2' => [
        'first_min'  => 10,
        'first_max'  => PHP_FLOAT_MAX,
        'second_min' => 1,
        'second_max' => 2,
    ],
];


foreach ($conditions as $conditionName => $condition) {

    echo "\n========================================\n";
    echo "{$conditionName}\n";
    echo "========================================\n";


    $stats = [
        'final'   => createStats(),
        'primary' => createStats(),
    ];

    $total = 0;
    $direct = 0;

    $comparePrimaryBetter = 0;
    $compareFinalBetter   = 0;
    $compareSame          = 0;

    $finalWinToPrimaryWin   = 0;
    $finalWinToPrimaryLose  = 0;
    $finalLoseToPrimaryWin  = 0;

    $finalTop3ToPrimaryOut  = 0;
    $finalOutToPrimaryTop3  = 0;


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
         * 一次1位と二次1位が同じレースは除外
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
         * 統合1位と一次1位の実着順が
         * 両方とも有効な場合だけ直接比較
         */
        if (
            $final1['actual_rank'] <= 0 ||
            $first1['actual_rank'] <= 0
        ) {
            continue;
        }


        $direct++;


        /*
         * 統合1位
         */
        addActual(
            $stats['final'],
            $final1['actual_rank']
        );


        /*
         * 一次1位
         */
        addActual(
            $stats['primary'],
            $first1['actual_rank']
        );


        /*
         * 実着順比較
         */
        if (
            $first1['actual_rank']
            <
            $final1['actual_rank']
        ) {

            $comparePrimaryBetter++;

        } elseif (
            $final1['actual_rank']
            <
            $first1['actual_rank']
        ) {

            $compareFinalBetter++;

        } else {

            $compareSame++;
        }


        /*
         * 1着遷移
         */
        $finalWin =
            ($final1['actual_rank'] === 1);

        $primaryWin =
            ($first1['actual_rank'] === 1);


        if ($finalWin && $primaryWin) {

            $finalWinToPrimaryWin++;

        } elseif ($finalWin && !$primaryWin) {

            $finalWinToPrimaryLose++;

        } elseif (!$finalWin && $primaryWin) {

            $finalLoseToPrimaryWin++;
        }


        /*
         * 3連対遷移
         */
        $finalTop3 =
            ($final1['actual_rank'] <= 3);

        $primaryTop3 =
            ($first1['actual_rank'] <= 3);


        if ($finalTop3 && !$primaryTop3) {

            $finalTop3ToPrimaryOut++;

        } elseif (!$finalTop3 && $primaryTop3) {

            $finalOutToPrimaryTop3++;
        }
    }


    echo "対象レース       : {$total}\n";
    echo "直接比較可能     : {$direct}\n\n";


    printStats(
        '現在の統合1位',
        $stats['final']
    );

    printStats(
        '一次1位へ変更',
        $stats['primary']
    );


    echo "----------------------------------------\n";
    echo "実着順直接比較\n";
    echo "----------------------------------------\n";

    if ($direct > 0) {

        printf(
            "一次1位へ変更した方が上位 : %4d (%.2f%%)\n",
            $comparePrimaryBetter,
            $comparePrimaryBetter / $direct * 100
        );

        printf(
            "現在の統合1位の方が上位   : %4d (%.2f%%)\n",
            $compareFinalBetter,
            $compareFinalBetter / $direct * 100
        );

        printf(
            "同着                       : %4d (%.2f%%)\n",
            $compareSame,
            $compareSame / $direct * 100
        );

    } else {

        echo "比較可能なデータなし\n";
    }


    echo "\n----------------------------------------\n";
    echo "1着遷移\n";
    echo "----------------------------------------\n";

    printf(
        "統合1位 1着 → 一次1位 1着     : %d\n",
        $finalWinToPrimaryWin
    );

    printf(
        "統合1位 1着 → 一次1位 2着以下 : %d\n",
        $finalWinToPrimaryLose
    );

    printf(
        "統合1位 2着以下 → 一次1位 1着 : %d\n",
        $finalLoseToPrimaryWin
    );


    echo "\n----------------------------------------\n";
    echo "3連対遷移\n";
    echo "----------------------------------------\n";

    printf(
        "統合1位 3連対 → 一次1位 3連対外 : %d\n",
        $finalTop3ToPrimaryOut
    );

    printf(
        "統合1位 3連対外 → 一次1位 3連対 : %d\n",
        $finalOutToPrimaryTop3
    );


    echo "\n----------------------------------------\n";
    echo "改善幅\n";
    echo "----------------------------------------\n";


    if (
        $stats['final']['count'] > 0 &&
        $stats['primary']['count'] > 0
    ) {

        $finalFirstRate =
            $stats['final']['rank1']
            /
            $stats['final']['count']
            * 100;

        $primaryFirstRate =
            $stats['primary']['rank1']
            /
            $stats['primary']['count']
            * 100;


        $finalSecondRate =
            (
                $stats['final']['rank1']
                +
                $stats['final']['rank2']
            )
            /
            $stats['final']['count']
            * 100;

        $primarySecondRate =
            (
                $stats['primary']['rank1']
                +
                $stats['primary']['rank2']
            )
            /
            $stats['primary']['count']
            * 100;


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
            * 100;

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
            * 100;


        $finalAverage =
            $stats['final']['sum']
            /
            $stats['final']['count'];

        $primaryAverage =
            $stats['primary']['sum']
            /
            $stats['primary']['count'];


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
         * 平均着順は小さいほど改善
         */
        printf(
            "平均着順    : %+0.3f\n",
            $finalAverage - $primaryAverage
        );

    } else {

        echo "比較可能な統計なし\n";
    }
}


echo "\n========================================\n";
echo "STEP 3-7 細分化分析 完了\n";
echo "========================================\n";


function createStats(): array
{
    return [
        'count' => 0,
        'rank1' => 0,
        'rank2' => 0,
        'rank3' => 0,
        'sum'   => 0,
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


function printStats(
    string $name,
    array $stats
): void {

    echo "----------------------------------------\n";
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
        * 100;

    $secondRate =
        (
            $stats['rank1']
            +
            $stats['rank2']
        )
        /
        $count
        * 100;

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
        * 100;

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