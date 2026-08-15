<?php

/**
 * STEP 3-2
 *
 * 一次評価・二次評価それぞれについて、
 * 1位と2位のスコア差を分析する。
 *
 * 対象：
 * 一次1位と二次1位が異なるレース
 *
 * 使用例：
 * php analysis/analyze_rank_gap.php analysis/output/final_prediction_boats_20260801_20260808.csv
 */

if ($argc < 2) {
    echo "Usage: php analyze_rank_gap.php <CSVファイル>\n";
    exit(1);
}

$csvFile = $argv[1];

if (!file_exists($csvFile)) {
    echo "CSVファイルが見つかりません: {$csvFile}\n";
    exit(1);
}

echo "========================================\n";
echo "STEP 3-2 一次・二次 1位-2位 スコア差分析\n";
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
if (isset($header[0])) {
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
}

$headerMap = [];

foreach ($header as $index => $name) {
    $headerMap[trim($name)] = $index;
}


/**
 * 必須列
 */
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


/**
 * レース単位で保持
 */
$races = [];

while (($row = fgetcsv($fp)) !== false) {

    if (count($row) !== count($header)) {
        continue;
    }

    $raceCode =
        trim($row[$headerMap['race_code']]);

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

    if (!isset($races[$raceCode])) {
        $races[$raceCode] = [];
    }

    $races[$raceCode][] = $boat;
}

fclose($fp);


/**
 * グループ
 *
 * 1位と2位のスコア差
 */
$groups = [

    '差 0～2' =>
        createStats(),

    '差 2～5' =>
        createStats(),

    '差 5～10' =>
        createStats(),

    '差 10～20' =>
        createStats(),

    '差 20以上' =>
        createStats(),
];


/**
 * 複合条件用
 *
 * 一次の1位-2位差
 * 二次の1位-2位差
 */
$combinationGroups = [

    '一次強・二次弱' =>
        createStats(),

    '一次弱・二次強' =>
        createStats(),

    '両方強' =>
        createStats(),

    '両方弱' =>
        createStats(),
];


$totalDisagreement = 0;


/**
 * ========================================
 * レース分析
 * ========================================
 */

foreach ($races as $raceCode => $boats) {

    /**
     * 一次1位
     */
    $first1 = findRankOne(
        $boats,
        'first_rank'
    );

    /**
     * 一次2位
     */
    $first2 = findRankTwo(
        $boats,
        'first_rank'
    );

    /**
     * 二次1位
     */
    $second1 = findRankOne(
        $boats,
        'second_rank'
    );

    /**
     * 二次2位
     */
    $second2 = findRankTwo(
        $boats,
        'second_rank'
    );

    /**
     * 統合1位
     */
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


    /**
     * 一次1位と二次1位が一致する場合は除外
     */
    if (
        $first1['lane_number']
        ===
        $second1['lane_number']
    ) {
        continue;
    }


    $totalDisagreement++;


    /**
     * ------------------------------------
     * 一次 1位-2位差
     * ------------------------------------
     */
    $firstGap =
        $first1['first_total_score']
        -
        $first2['first_total_score'];


    /**
     * ------------------------------------
     * 二次 1位-2位差
     * ------------------------------------
     */
    $secondGap =
        $second1['second_score']
        -
        $second2['second_score'];


    /**
     * 一次の差グループ
     */
    $firstGroup =
        determineGapGroup($firstGap);

    /**
     * 二次の差グループ
     */
    $secondGroup =
        determineGapGroup($secondGap);


    /**
     * 一次側の統計
     */
    addResult(
        $groups[$firstGroup],
        $first1['actual_rank'],
        $second1['actual_rank'],
        $final1['actual_rank'],
        $firstGap
    );


    /**
     * ------------------------------------
     * 複合条件
     *
     * まず「差5」を境界として使用。
     * 後で結果を見て最適な境界を探す。
     * ------------------------------------
     */

    $firstStrong =
        ($firstGap >= 5);

    $secondStrong =
        ($secondGap >= 5);


    if ($firstStrong && !$secondStrong) {

        $combination =
            '一次強・二次弱';

    } elseif (!$firstStrong && $secondStrong) {

        $combination =
            '一次弱・二次強';

    } elseif ($firstStrong && $secondStrong) {

        $combination =
            '両方強';

    } else {

        $combination =
            '両方弱';
    }


    addResult(
        $combinationGroups[$combination],
        $first1['actual_rank'],
        $second1['actual_rank'],
        $final1['actual_rank'],
        $firstGap
    );
}


/**
 * ========================================
 * 結果表示
 * ========================================
 */

echo "========================================\n";
echo "一次1位・二次1位 不一致レース\n";
echo "========================================\n";

echo "件数 : {$totalDisagreement}\n\n";


/**
 * ----------------------------------------
 * 一次1位-2位差
 * ----------------------------------------
 */

echo "========================================\n";
echo "一次評価 1位-2位 スコア差\n";
echo "========================================\n";

foreach ($groups as $name => $stats) {

    printGroup(
        $name,
        $stats
    );
}


/**
 * ----------------------------------------
 * 複合条件
 * ----------------------------------------
 */

echo "\n========================================\n";
echo "一次・二次 1位-2位差 複合分析\n";
echo "========================================\n";

foreach ($combinationGroups as $name => $stats) {

    printGroup(
        $name,
        $stats
    );
}


echo "\n========================================\n";
echo "分析完了\n";
echo "========================================\n";


/**
 * ========================================
 * 関数
 * ========================================
 */


/**
 * 統計作成
 */
function createStats(): array
{
    return [

        'count' => 0,

        'gap_sum' => 0,

        'first' => [
            'count' => 0,
            'rank1' => 0,
            'rank2' => 0,
            'rank3' => 0,
            'sum' => 0,
        ],

        'second' => [
            'count' => 0,
            'rank1' => 0,
            'rank2' => 0,
            'rank3' => 0,
            'sum' => 0,
        ],

        'final' => [
            'count' => 0,
            'rank1' => 0,
            'rank2' => 0,
            'rank3' => 0,
            'sum' => 0,
        ],

        'first_better' => 0,

        'second_better' => 0,

        'same' => 0,
    ];
}


/**
 * 順位1を取得
 */
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


/**
 * 順位2を取得
 */
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


/**
 * スコア差グループ
 */
function determineGapGroup(
    float $gap
): string {

    if ($gap < 2) {

        return '差 0～2';

    } elseif ($gap < 5) {

        return '差 2～5';

    } elseif ($gap < 10) {

        return '差 5～10';

    } elseif ($gap < 20) {

        return '差 10～20';

    } else {

        return '差 20以上';
    }
}


/**
 * 結果追加
 */
function addResult(
    array &$stats,
    int $firstActual,
    int $secondActual,
    int $finalActual,
    float $gap
): void {

    $stats['count']++;

    $stats['gap_sum'] += $gap;


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


    /**
     * 実着順比較
     */
    if ($firstActual < $secondActual) {

        $stats['first_better']++;

    } elseif ($secondActual < $firstActual) {

        $stats['second_better']++;

    } else {

        $stats['same']++;
    }
}


/**
 * 実着順追加
 */
function addActual(
    array &$data,
    int $actualRank
): void {

    if ($actualRank <= 0) {
        return;
    }

    $data['count']++;

    $data['sum'] += $actualRank;


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


/**
 * グループ表示
 */
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


    echo "件数 : {$stats['count']}\n";


    $averageGap =
        $stats['gap_sum']
        /
        $stats['count'];

    printf(
        "平均一次1位-2位差 : %.2f\n",
        $averageGap
    );


    echo "\n";


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
        $stats['first_better']
            / $stats['count']
            * 100
    );


    printf(
        "  二次1位の方が上位 : %4d (%.2f%%)\n",
        $stats['second_better'],
        $stats['second_better']
            / $stats['count']
            * 100
    );


    printf(
        "  同着               : %4d (%.2f%%)\n",
        $stats['same'],
        $stats['same']
            / $stats['count']
            * 100
    );
}


/**
 * 結果表示
 */
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
        $data['rank1']
        / $count
        * 100;


    $secondRate =
        (
            $data['rank1']
            + $data['rank2']
        )
        / $count
        * 100;


    $thirdRate =
        (
            $data['rank1']
            + $data['rank2']
            + $data['rank3']
        )
        / $count
        * 100;


    $averageRank =
        $data['sum']
        / $count;


    printf(
        "%-8s 件数=%4d  1着=%6.2f%%  2連対=%6.2f%%  3連対=%6.2f%%  平均着順=%.3f\n",
        $name,
        $count,
        $firstRate,
        $secondRate,
        $thirdRate,
        $averageRank
    );
}