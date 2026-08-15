<?php

/**
 * STEP 3-3
 *
 * 一次1位-2位差 × 二次1位-2位差
 * 3×3クロス分析
 *
 * 対象：
 * 一次1位と二次1位が不一致のレース
 *
 * グループ：
 *   0～2
 *   2～5
 *   5以上
 */

if ($argc < 2) {
    echo "Usage: php analyze_rank_gap_cross.php <CSVファイル>\n";
    exit(1);
}

$csvFile = $argv[1];

if (!file_exists($csvFile)) {
    echo "CSVファイルが見つかりません: {$csvFile}\n";
    exit(1);
}

echo "========================================\n";
echo "STEP 3-3 一次×二次 1位-2位差 クロス分析\n";
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

// BOM除去
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

$headerMap = [];

foreach ($header as $index => $name) {
    $headerMap[trim($name)] = $index;
}

$requiredColumns = [
    'race_code',
    'lane_number',
    'first_total_score',
    'first_rank',
    'second_score',
    'second_rank',
    'final_rank',
    'actual_rank',
];

foreach ($requiredColumns as $column) {

    if (!isset($headerMap[$column])) {
        echo "必要な列がありません: {$column}\n";
        exit(1);
    }
}


/*
 * レース単位
 */
$races = [];

while (($row = fgetcsv($fp)) !== false) {

    if (count($row) !== count($header)) {
        continue;
    }

    $raceCode = trim($row[$headerMap['race_code']]);

    if ($raceCode === '') {
        continue;
    }

    $boat = [
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

    $races[$raceCode][] = $boat;
}

fclose($fp);


/*
 * 3×3グループ
 */
$groups = [];

$firstGroups = [
    '0～2',
    '2～5',
    '5以上',
];

$secondGroups = [
    '0～2',
    '2～5',
    '5以上',
];

foreach ($firstGroups as $firstGroup) {

    foreach ($secondGroups as $secondGroup) {

        $groups[$firstGroup][$secondGroup] =
            createStats();
    }
}


$totalDisagreement = 0;


/*
 * ========================================
 * レース分析
 * ========================================
 */

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
     * 一次1位と二次1位が一致するレースは除外
     */
    if (
        $first1['lane_number']
        ===
        $second1['lane_number']
    ) {
        continue;
    }

    $totalDisagreement++;


    /*
     * 1位-2位差
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
     * グループ化
     */
    $firstGroup =
        determineGapGroup($firstGap);

    $secondGroup =
        determineGapGroup($secondGap);


    /*
     * 統計追加
     */
    addResult(
        $groups[$firstGroup][$secondGroup],
        $first1['actual_rank'],
        $second1['actual_rank'],
        $final1['actual_rank']
    );
}


/*
 * ========================================
 * 結果表示
 * ========================================
 */

echo "========================================\n";
echo "一次1位・二次1位 不一致レース\n";
echo "========================================\n";

echo "件数 : {$totalDisagreement}\n\n";


/*
 * ========================================
 * 3×3クロス表
 * ========================================
 */

echo "========================================\n";
echo "一次1位-2位差 × 二次1位-2位差\n";
echo "========================================\n";

foreach ($firstGroups as $firstGroup) {

    foreach ($secondGroups as $secondGroup) {

        $stats =
            $groups[$firstGroup][$secondGroup];

        printGroup(
            "一次 {$firstGroup} × 二次 {$secondGroup}",
            $stats
        );
    }
}


/*
 * ========================================
 * 一次が二次より強いケース
 * ========================================
 */

echo "\n========================================\n";
echo "一次優勢候補\n";
echo "========================================\n";

foreach ($firstGroups as $firstGroup) {

    foreach ($secondGroups as $secondGroup) {

        /*
         * 一次5以上 × 二次0～2
         * のような条件を見やすくする
         */
        $stats =
            $groups[$firstGroup][$secondGroup];

        if ($stats['count'] === 0) {
            continue;
        }

        $firstRate =
            getRate(
                $stats['first']['rank1'],
                $stats['first']['count']
            );

        $secondRate =
            getRate(
                $stats['second']['rank1'],
                $stats['second']['count']
            );

        $finalRate =
            getRate(
                $stats['final']['rank1'],
                $stats['final']['count']
            );

        if (
            $firstRate > $secondRate
            ||
            $finalRate < $firstRate
        ) {

            echo "\n";
            echo "----------------------------------------\n";
            echo "一次 {$firstGroup} × 二次 {$secondGroup}\n";
            echo "----------------------------------------\n";

            echo "件数 : {$stats['count']}\n";

            printf(
                "一次1位 1着率 : %.2f%%\n",
                $firstRate
            );

            printf(
                "二次1位 1着率 : %.2f%%\n",
                $secondRate
            );

            printf(
                "統合1位 1着率 : %.2f%%\n",
                $finalRate
            );

            printf(
                "一次1位 実着順上位 : %.2f%%\n",
                getRate(
                    $stats['first_better'],
                    $stats['count']
                )
            );

            printf(
                "二次1位 実着順上位 : %.2f%%\n",
                getRate(
                    $stats['second_better'],
                    $stats['count']
                )
            );
        }
    }
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

        'count' => 0,

        'first' => [
            'count' => 0,
            'rank1' => 0,
            'rank2' => 0,
            'rank3' => 0,
        ],

        'second' => [
            'count' => 0,
            'rank1' => 0,
            'rank2' => 0,
            'rank3' => 0,
        ],

        'final' => [
            'count' => 0,
            'rank1' => 0,
            'rank2' => 0,
            'rank3' => 0,
        ],

        'first_better' => 0,
        'second_better' => 0,
        'same' => 0,
    ];
}


function findRankOne(
    array $boats,
    string $rankColumn
): ?array {

    foreach ($boats as $boat) {

        if ($boat[$rankColumn] === 1) {
            return $boat;
        }
    }

    return null;
}


function findRankTwo(
    array $boats,
    string $rankColumn
): ?array {

    foreach ($boats as $boat) {

        if ($boat[$rankColumn] === 2) {
            return $boat;
        }
    }

    return null;
}


function determineGapGroup(
    float $gap
): string {

    if ($gap < 2) {
        return '0～2';
    }

    if ($gap < 5) {
        return '2～5';
    }

    return '5以上';
}


function addResult(
    array &$stats,
    int $firstActual,
    int $secondActual,
    int $finalActual
): void {

    $stats['count']++;

    addActual(
        $stats['first'],
        $firstActual
    );

    addActual(
        $stats['second'],
        $secondActual
    );

    addActual(
        $stats['final'],
        $finalActual
    );


    /*
     * 実着順比較
     */
    if ($firstActual > 0 && $secondActual > 0) {

        if ($firstActual < $secondActual) {

            $stats['first_better']++;

        } elseif ($secondActual < $firstActual) {

            $stats['second_better']++;

        } else {

            $stats['same']++;
        }
    }
}


function addActual(
    array &$data,
    int $actualRank
): void {

    if ($actualRank <= 0) {
        return;
    }

    $data['count']++;

    if ($actualRank === 1) {
        $data['rank1']++;
    }

    if ($actualRank === 2) {
        $data['rank2']++;
    }

    if ($actualRank === 3) {
        $data['rank3']++;
    }
}


function getRate(
    int $value,
    int $count
): float {

    if ($count <= 0) {
        return 0.0;
    }

    return $value / $count * 100;
}


function printGroup(
    string $name,
    array $stats
): void {

    echo "\n----------------------------------------\n";
    echo "{$name}\n";
    echo "----------------------------------------\n";

    if ($stats['count'] === 0) {

        echo "件数 : 0\n";

        return;
    }

    echo "件数 : {$stats['count']}\n\n";


    printResult(
        '一次1位',
        $stats['first']
    );

    printResult(
        '二次1位',
        $stats['second']
    );

    printResult(
        '統合1位',
        $stats['final']
    );


    echo "\n実着順比較:\n";

    printf(
        "  一次1位の方が上位 : %4d (%.2f%%)\n",
        $stats['first_better'],
        getRate(
            $stats['first_better'],
            $stats['count']
        )
    );

    printf(
        "  二次1位の方が上位 : %4d (%.2f%%)\n",
        $stats['second_better'],
        getRate(
            $stats['second_better'],
            $stats['count']
        )
    );

    printf(
        "  同着               : %4d (%.2f%%)\n",
        $stats['same'],
        getRate(
            $stats['same'],
            $stats['count']
        )
    );
}


function printResult(
    string $name,
    array $data
): void {

    if ($data['count'] === 0) {

        echo "{$name} : データなし\n";

        return;
    }

    $count = $data['count'];

    $firstRate =
        getRate(
            $data['rank1'],
            $count
        );

    $secondRate =
        getRate(
            $data['rank1'] + $data['rank2'],
            $count
        );

    $thirdRate =
        getRate(
            $data['rank1']
            + $data['rank2']
            + $data['rank3'],
            $count
        );

    printf(
        "%-8s 件数=%4d  1着=%6.2f%%  2連対=%6.2f%%  3連対=%6.2f%%\n",
        $name,
        $count,
        $firstRate,
        $secondRate,
        $thirdRate
    );
}