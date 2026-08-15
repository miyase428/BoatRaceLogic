<?php

/**
 * STEP 3-1
 *
 * 一次1位と二次1位が異なるレースについて、
 * 一次1位と二次1位のスコア関係を分析する。
 *
 * 使用例:
 * php analysis/analyze_score_gap.php analysis/output/final_prediction_boats_20260801_20260808.csv
 */

if ($argc < 2) {
    echo "Usage: php analyze_score_gap.php <CSVファイル>\n";
    exit(1);
}

$csvFile = $argv[1];

if (!file_exists($csvFile)) {
    echo "CSVファイルが見つかりません: {$csvFile}\n";
    exit(1);
}

echo "========================================\n";
echo "STEP 3-1 一次1位・二次1位 スコア差分析\n";
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
 * レース単位でデータ保持
 */
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
 * グループ定義
 */
$groups = [
    '一次 +10以上' => createStats(),
    '一次 +5～10' => createStats(),
    '一次 +2～5' => createStats(),
    'ほぼ互角 ±2' => createStats(),
    '二次 +2～5' => createStats(),
    '二次 +5～10' => createStats(),
    '二次 +10以上' => createStats(),
];


/**
 * 全体
 */
$totalDisagreement = 0;


/**
 * レース分析
 */
foreach ($races as $raceCode => $boats) {

    $firstBoat = findRankOne(
        $boats,
        'first_rank'
    );

    $secondBoat = findRankOne(
        $boats,
        'second_rank'
    );

    $finalBoat = findRankOne(
        $boats,
        'final_rank'
    );


    if (
        $firstBoat === null ||
        $secondBoat === null ||
        $finalBoat === null
    ) {
        continue;
    }


    /**
     * 一次1位と二次1位が同じ艇なら対象外
     */
    if (
        $firstBoat['lane_number']
        ===
        $secondBoat['lane_number']
    ) {
        continue;
    }


    $totalDisagreement++;


    /**
     * 一次1位のスコア
     */
    $firstScore =
        $firstBoat['first_total_score'];


    /**
     * 二次1位のスコア
     */
    $secondScore =
        $secondBoat['second_score'];


    /**
     * 差
     *
     * プラス:
     *   一次スコアの方が高い
     *
     * マイナス:
     *   二次スコアの方が高い
     */
    $gap =
        $firstScore - $secondScore;


    /**
     * グループ判定
     */
    $groupName = determineGroup($gap);

    if ($groupName === null) {
        continue;
    }


    /**
     * 一次1位の実着順
     */
    $firstActual =
        $firstBoat['actual_rank'];


    /**
     * 二次1位の実着順
     */
    $secondActual =
        $secondBoat['actual_rank'];


    /**
     * 統合1位の実着順
     */
    $finalActual =
        $finalBoat['actual_rank'];


    /**
     * 統計追加
     */
    addResult(
        $groups[$groupName],
        $firstActual,
        $secondActual,
        $finalActual,
        $gap
    );
}


/**
 * ========================================
 * 結果表示
 * ========================================
 */

echo "========================================\n";
echo "スコア差分析結果\n";
echo "========================================\n";

echo "一次1位・二次1位 不一致レース : "
    . $totalDisagreement
    . "\n\n";


foreach ($groups as $groupName => $stats) {

    echo "----------------------------------------\n";
    echo "{$groupName}\n";
    echo "----------------------------------------\n";

    if ($stats['count'] === 0) {

        echo "件数 : 0\n\n";

        continue;
    }


    echo "件数 : {$stats['count']}\n";


    /**
     * 平均スコア差
     */
    $averageGap =
        $stats['gap_sum']
        / $stats['count'];

    printf(
        "平均スコア差 : %+.2f\n",
        $averageGap
    );


    echo "\n";


    /**
     * 一次1位
     */
    printResult(
        '一次1位',
        $stats['first']
    );


    /**
     * 二次1位
     */
    printResult(
        '二次1位',
        $stats['second']
    );


    /**
     * 統合1位
     */
    printResult(
        '統合1位',
        $stats['final']
    );


    /**
     * 実着順でどちらが良かったか
     */
    $firstBetter =
        $stats['first_better'];

    $secondBetter =
        $stats['second_better'];

    $same =
        $stats['same'];


    echo "\n実着順比較:\n";

    printf(
        "  一次1位の方が上位 : %4d (%.2f%%)\n",
        $firstBetter,
        $firstBetter / $stats['count'] * 100
    );

    printf(
        "  二次1位の方が上位 : %4d (%.2f%%)\n",
        $secondBetter,
        $secondBetter / $stats['count'] * 100
    );

    printf(
        "  同着               : %4d (%.2f%%)\n",
        $same,
        $same / $stats['count'] * 100
    );


    echo "\n";
}


echo "========================================\n";
echo "分析完了\n";
echo "========================================\n";


/**
 * ========================================
 * 関数
 * ========================================
 */


/**
 * 統計オブジェクト
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
 * 順位1の艇を取得
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
 * スコア差からグループ判定
 */
function determineGroup(float $gap): ?string
{
    if ($gap >= 10) {

        return '一次 +10以上';

    } elseif ($gap >= 5) {

        return '一次 +5～10';

    } elseif ($gap >= 2) {

        return '一次 +2～5';

    } elseif ($gap > -2) {

        return 'ほぼ互角 ±2';

    } elseif ($gap > -5) {

        return '二次 +2～5';

    } elseif ($gap > -10) {

        return '二次 +5～10';

    } else {

        return '二次 +10以上';
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
     * 一次と二次のどちらが実着順上位か
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