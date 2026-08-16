<?php

/**
 * STEP 3-9
 *
 * 一次・二次スコアそのものの組み合わせ分析
 *
 * 目的：
 *   一次差・二次差だけではなく、
 *   実際の一次1位スコア / 二次1位スコアなどを
 *   組み合わせて分析する。
 *
 * Usage:
 *   php analysis/analyze_score_combination.php \
 *   analysis/output/final_prediction_boats_20260715_20260814.csv
 */

if ($argc < 2) {
    echo "Usage: php analyze_score_combination.php <csv_file>\n";
    exit(1);
}

$csvFile = $argv[1];

if (!file_exists($csvFile)) {
    echo "CSVが見つかりません: {$csvFile}\n";
    exit(1);
}

$fp = fopen($csvFile, 'r');

if ($fp === false) {
    echo "CSVを開けません: {$csvFile}\n";
    exit(1);
}

$header = fgetcsv($fp);

if ($header === false) {
    echo "CSVヘッダーを読み込めません。\n";
    exit(1);
}

/*
 * BOM除去
 */
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

echo "========================================\n";
echo "STEP 3-9 一次・二次スコアそのものの組み合わせ分析\n";
echo "========================================\n";
echo "CSV : {$csvFile}\n";
echo "========================================\n\n";

$requiredColumns = [
    'race_code',
    'first_total_score',
    'second_score',
    'actual_rank',
];

foreach ($requiredColumns as $column) {

    if (!in_array($column, $header, true)) {

        echo "必要な列がありません: {$column}\n";
        exit(1);
    }
}

/*
 * CSV読み込み
 */
$rows = [];

while (($data = fgetcsv($fp)) !== false) {

    if (count($data) !== count($header)) {
        continue;
    }

    $row = array_combine($header, $data);

    if ($row === false) {
        continue;
    }

    if (
        $row['first_total_score'] === '' ||
        $row['second_score'] === '' ||
        $row['actual_rank'] === ''
    ) {
        continue;
    }

    $actualRank = (int)$row['actual_rank'];

    if ($actualRank < 1 || $actualRank > 6) {
        continue;
    }

    $row['_first'] =
        (float)$row['first_total_score'];

    $row['_second'] =
        (float)$row['second_score'];

    $row['_actual'] =
        $actualRank;

    $rows[] = $row;
}

fclose($fp);

echo "読み込み艇数 : " . count($rows) . "\n";

/*
 * レース単位
 */
$races = [];

foreach ($rows as $row) {

    $raceCode =
        $row['race_code'];

    if (!isset($races[$raceCode])) {
        $races[$raceCode] = [];
    }

    $races[$raceCode][] = $row;
}

echo "読み込みレース数 : " .
     count($races) .
     "\n\n";


/*
 * ------------------------------------------------------------
 * ユーティリティ
 * ------------------------------------------------------------
 */

function percent(
    int $num,
    int $den
): string {

    if ($den === 0) {
        return '-';
    }

    return number_format(
        $num / $den * 100,
        2
    ) . '%';
}


function average(
    float $sum,
    int $count
): string {

    if ($count === 0) {
        return '-';
    }

    return number_format(
        $sum / $count,
        3
    );
}


/*
 * ------------------------------------------------------------
 * レースごとの特徴量作成
 * ------------------------------------------------------------
 */

$raceFeatures = [];

foreach ($races as $raceCode => $race) {

    /*
     * 6艇未満は除外
     */
    if (count($race) !== 6) {
        continue;
    }

    /*
     * 一次順位
     */
    $firstRanked = $race;

    usort(
        $firstRanked,
        function ($a, $b) {

            if ($a['_first'] == $b['_first']) {
                return 0;
            }

            return
                ($a['_first'] > $b['_first'])
                ? -1
                : 1;
        }
    );

    /*
     * 二次順位
     */
    $secondRanked = $race;

    usort(
        $secondRanked,
        function ($a, $b) {

            if ($a['_second'] == $b['_second']) {
                return 0;
            }

            return
                ($a['_second'] > $b['_second'])
                ? -1
                : 1;
        }
    );


    /*
     * 一次1位・2位
     */
    $first1 =
        $firstRanked[0];

    $first2 =
        $firstRanked[1];


    /*
     * 二次1位・2位
     */
    $second1 =
        $secondRanked[0];

    $second2 =
        $secondRanked[1];


    /*
     * 一次差
     */
    $firstGap =
        $first1['_first']
        - $first2['_first'];


    /*
     * 二次差
     */
    $secondGap =
        $second1['_second']
        - $second2['_second'];


    /*
     * 現在の単純統合
     *
     * 一次＋二次
     */
    $combinedRanked = $race;

    foreach ($combinedRanked as &$row) {

        $row['_combined'] =
            $row['_first']
            + $row['_second'];
    }

    unset($row);

    usort(
        $combinedRanked,
        function ($a, $b) {

            if ($a['_combined'] == $b['_combined']) {
                return 0;
            }

            return
                ($a['_combined'] > $b['_combined'])
                ? -1
                : 1;
        }
    );


    $combined1 =
        $combinedRanked[0];


    $raceFeatures[] = [

        'race_code' =>
            $raceCode,

        /*
         * 一次
         */
        'first1_score' =>
            $first1['_first'],

        'first2_score' =>
            $first2['_first'],

        'first_gap' =>
            $firstGap,

        /*
         * 二次
         */
        'second1_score' =>
            $second1['_second'],

        'second2_score' =>
            $second2['_second'],

        'second_gap' =>
            $secondGap,

        /*
         * 一次1位
         */
        'first1_actual' =>
            $first1['_actual'],

        /*
         * 現在の統合1位
         */
        'combined1_actual' =>
            $combined1['_actual'],

        /*
         * 順位比較
         */
        'first1_lane' =>
            (int)$first1['lane_number'],

        'combined1_lane' =>
            (int)$combined1['lane_number'],
    ];
}

echo "分析対象レース : " .
     count($raceFeatures) .
     "\n\n";


/*
 * ------------------------------------------------------------
 * 集計関数
 * ------------------------------------------------------------
 */

function addResult(
    array &$bucket,
    int $actual
): void {

    $bucket['count']++;

    if ($actual === 1) {
        $bucket['first']++;
    }

    if ($actual <= 2) {
        $bucket['second']++;
    }

    if ($actual <= 3) {
        $bucket['third']++;
    }

    $bucket['sum_rank'] +=
        $actual;
}


function newResult(): array {

    return [

        'count' => 0,

        'first' => 0,

        'second' => 0,

        'third' => 0,

        'sum_rank' => 0.0,
    ];
}


function printComparison(
    array $current,
    array $primary
): void {

    echo "現在の統合1位\n";

    echo sprintf(
        "  件数=%d  1着=%s  2連対=%s  3連対=%s  平均着順=%s\n",

        $current['count'],

        percent(
            $current['first'],
            $current['count']
        ),

        percent(
            $current['second'],
            $current['count']
        ),

        percent(
            $current['third'],
            $current['count']
        ),

        average(
            $current['sum_rank'],
            $current['count']
        )
    );


    echo "一次1位\n";

    echo sprintf(
        "  件数=%d  1着=%s  2連対=%s  3連対=%s  平均着順=%s\n",

        $primary['count'],

        percent(
            $primary['first'],
            $primary['count']
        ),

        percent(
            $primary['second'],
            $primary['count']
        ),

        percent(
            $primary['third'],
            $primary['count']
        ),

        average(
            $primary['sum_rank'],
            $primary['count']
        )
    );
}


/*
 * ------------------------------------------------------------
 * 全体
 * ------------------------------------------------------------
 */

$overallCurrent =
    newResult();

$overallPrimary =
    newResult();

foreach ($raceFeatures as $f) {

    addResult(
        $overallCurrent,
        $f['combined1_actual']
    );

    addResult(
        $overallPrimary,
        $f['first1_actual']
    );
}

echo "========================================\n";
echo "【全体比較】\n";
echo "========================================\n";

printComparison(
    $overallCurrent,
    $overallPrimary
);

echo "\n";


/*
 * ------------------------------------------------------------
 * 一次1位スコア帯
 *
 * calc_scores.php の閾値も意識
 *
 * <15
 * 15～18
 * 18～20
 * 20～22
 * 22～24
 * 24以上
 * ------------------------------------------------------------
 */

function firstScoreBand(float $score): string {

    if ($score < 15) {
        return '15未満';
    }

    if ($score < 18) {
        return '15～18';
    }

    if ($score < 20) {
        return '18～20';
    }

    if ($score < 22) {
        return '20～22';
    }

    if ($score < 24) {
        return '22～24';
    }

    return '24以上';
}


$firstScoreGroups = [];

foreach ($raceFeatures as $f) {

    $band =
        firstScoreBand(
            $f['first1_score']
        );

    if (!isset($firstScoreGroups[$band])) {

        $firstScoreGroups[$band] = [

            'current' =>
                newResult(),

            'primary' =>
                newResult(),
        ];
    }

    addResult(
        $firstScoreGroups[$band]['current'],
        $f['combined1_actual']
    );

    addResult(
        $firstScoreGroups[$band]['primary'],
        $f['first1_actual']
    );
}

echo "========================================\n";
echo "【一次1位スコア帯 × 成績】\n";
echo "========================================\n";

foreach ($firstScoreGroups as $band => $group) {

    echo "\n--- 一次1位 {$band} ---\n";

    printComparison(
        $group['current'],
        $group['primary']
    );
}


/*
 * ------------------------------------------------------------
 * 二次1位スコア帯
 * ------------------------------------------------------------
 */

function secondScoreBand(float $score): string {

    if ($score < 5) {
        return '5未満';
    }

    if ($score < 10) {
        return '5～10';
    }

    if ($score < 15) {
        return '10～15';
    }

    if ($score < 20) {
        return '15～20';
    }

    if ($score < 25) {
        return '20～25';
    }

    if ($score < 30) {
        return '25～30';
    }

    if ($score < 35) {
        return '30～35';
    }

    return '35以上';
}


$secondScoreGroups = [];

foreach ($raceFeatures as $f) {

    $band =
        secondScoreBand(
            $f['second1_score']
        );

    if (!isset($secondScoreGroups[$band])) {

        $secondScoreGroups[$band] = [

            'current' =>
                newResult(),

            'primary' =>
                newResult(),
        ];
    }

    addResult(
        $secondScoreGroups[$band]['current'],
        $f['combined1_actual']
    );

    addResult(
        $secondScoreGroups[$band]['primary'],
        $f['first1_actual']
    );
}

echo "\n========================================\n";
echo "【二次1位スコア帯 × 成績】\n";
echo "========================================\n";

foreach ($secondScoreGroups as $band => $group) {

    echo "\n--- 二次1位 {$band} ---\n";

    printComparison(
        $group['current'],
        $group['primary']
    );
}


/*
 * ------------------------------------------------------------
 * 一次1位スコア × 二次1位スコア
 *
 * STEP 3-9のメイン
 * ------------------------------------------------------------
 */

$scoreMatrix = [];

foreach ($raceFeatures as $f) {

    $firstBand =
        firstScoreBand(
            $f['first1_score']
        );

    $secondBand =
        secondScoreBand(
            $f['second1_score']
        );

    if (
        !isset(
            $scoreMatrix[$firstBand][$secondBand]
        )
    ) {

        $scoreMatrix[$firstBand][$secondBand] = [

            'current' =>
                newResult(),

            'primary' =>
                newResult(),
        ];
    }

    addResult(
        $scoreMatrix[$firstBand][$secondBand]['current'],
        $f['combined1_actual']
    );

    addResult(
        $scoreMatrix[$firstBand][$secondBand]['primary'],
        $f['first1_actual']
    );
}


/*
 * 表示順
 */
$firstBandOrder = [

    '15未満',
    '15～18',
    '18～20',
    '20～22',
    '22～24',
    '24以上',
];

$secondBandOrder = [

    '5未満',
    '5～10',
    '10～15',
    '15～20',
    '20～25',
    '25～30',
    '30～35',
    '35以上',
];


echo "\n========================================\n";
echo "【一次1位スコア × 二次1位スコア】\n";
echo "========================================\n";

foreach ($firstBandOrder as $firstBand) {

    if (
        !isset(
            $scoreMatrix[$firstBand]
        )
    ) {
        continue;
    }

    foreach (
        $secondBandOrder as $secondBand
    ) {

        if (
            !isset(
                $scoreMatrix[$firstBand][$secondBand]
            )
        ) {
            continue;
        }

        $group =
            $scoreMatrix[$firstBand][$secondBand];

        $count =
            $group['primary']['count'];

        /*
         * 少なすぎる組み合わせは
         * 数字だけで判断しないようにする。
         */
        echo "\n";
        echo "----------------------------------------\n";

        echo "一次1位 {$firstBand}";
        echo " × ";
        echo "二次1位 {$secondBand}\n";

        echo "件数 : {$count}\n";

        printComparison(
            $group['current'],
            $group['primary']
        );
    }
}


/*
 * ------------------------------------------------------------
 * 一次差 × 二次差 × スコア帯
 *
 * 特に
 * 一次差5～10 × 二次差1～2
 * を再確認
 * ------------------------------------------------------------
 */

function firstGapBand(float $gap): string {

    if ($gap < 2) {
        return '0～2';
    }

    if ($gap < 5) {
        return '2～5';
    }

    if ($gap < 10) {
        return '5～10';
    }

    return '10以上';
}


function secondGapBand(float $gap): string {

    if ($gap < 1) {
        return '0～1';
    }

    if ($gap < 2) {
        return '1～2';
    }

    if ($gap < 5) {
        return '2～5';
    }

    return '5以上';
}


$targetGroups = [];

foreach ($raceFeatures as $f) {

    $firstGapBand =
        firstGapBand(
            $f['first_gap']
        );

    $secondGapBand =
        secondGapBand(
            $f['second_gap']
        );

    $key =
        $firstGapBand .
        ' × ' .
        $secondGapBand;

    if (!isset($targetGroups[$key])) {

        $targetGroups[$key] = [

            'count' => 0,

            'current' =>
                newResult(),

            'primary' =>
                newResult(),

            'first_score_sum' =>
                0.0,

            'second_score_sum' =>
                0.0,
        ];
    }

    $targetGroups[$key]['count']++;

    $targetGroups[$key]['first_score_sum'] +=
        $f['first1_score'];

    $targetGroups[$key]['second_score_sum'] +=
        $f['second1_score'];

    addResult(
        $targetGroups[$key]['current'],
        $f['combined1_actual']
    );

    addResult(
        $targetGroups[$key]['primary'],
        $f['first1_actual']
    );
}


echo "\n========================================\n";
echo "【一次差 × 二次差 × スコア実値】\n";
echo "========================================\n";

foreach ($targetGroups as $key => $group) {

    echo "\n----------------------------------------\n";

    echo "{$key}\n";

    echo "件数 : {$group['count']}\n";

    echo "一次1位平均スコア : " .
        average(
            $group['first_score_sum'],
            $group['count']
        ) .
        "\n";

    echo "二次1位平均スコア : " .
        average(
            $group['second_score_sum'],
            $group['count']
        ) .
        "\n";

    printComparison(
        $group['current'],
        $group['primary']
    );
}


echo "\n========================================\n";
echo "STEP 3-9 分析完了\n";
echo "========================================\n";