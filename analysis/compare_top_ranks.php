<?php

/**
 * 一次1位・二次1位・統合1位を実着順で比較
 *
 * 使用例:
 * php analysis/compare_top_ranks.php analysis/output/final_prediction_boats_20260801_20260808.csv
 */

if ($argc < 2) {
    echo "Usage: php compare_top_ranks.php <CSVファイル>\n";
    exit(1);
}

$csvFile = $argv[1];

if (!file_exists($csvFile)) {
    echo "CSVファイルが見つかりません: {$csvFile}\n";
    exit(1);
}

echo "========================================\n";
echo "一次1位・二次1位・統合1位 実着順比較\n";
echo "========================================\n";
echo "CSV : {$csvFile}\n\n";

$fp = fopen($csvFile, 'r');

if ($fp === false) {
    echo "CSVを開けませんでした。\n";
    exit(1);
}

// BOM除去
$header = fgetcsv($fp);

if ($header === false) {
    echo "CSVが空です。\n";
    exit(1);
}

if (isset($header[0])) {
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
}

$headerMap = [];

foreach ($header as $index => $name) {
    $headerMap[trim($name)] = $index;
}

// 必須列
$requiredColumns = [
    'race_code',
    'actual_rank',
    'first_rank',
    'second_rank',
    'final_rank',
];

foreach ($requiredColumns as $column) {
    if (!isset($headerMap[$column])) {
        echo "必要な列がありません: {$column}\n";
        echo "CSVの列名:\n";
        print_r($header);
        exit(1);
    }
}

/**
 * 集計用データ
 */
$stats = [
    '一次1位' => [
        'count' => 0,
        'rank_counts' => [],
        'sum_rank' => 0,
    ],
    '二次1位' => [
        'count' => 0,
        'rank_counts' => [],
        'sum_rank' => 0,
    ],
    '統合1位' => [
        'count' => 0,
        'rank_counts' => [],
        'sum_rank' => 0,
    ],
];

/**
 * レース単位でデータを保持
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

    $actualRank = (int)$row[$headerMap['actual_rank']];
    $firstRank  = (int)$row[$headerMap['first_rank']];
    $secondRank = (int)$row[$headerMap['second_rank']];
    $finalRank  = (int)$row[$headerMap['final_rank']];

    if (!isset($races[$raceCode])) {
        $races[$raceCode] = [];
    }

    $races[$raceCode][] = [
        'actual_rank' => $actualRank,
        'first_rank'  => $firstRank,
        'second_rank' => $secondRank,
        'final_rank'  => $finalRank,
    ];
}

fclose($fp);

/**
 * 各レースから
 * 一次1位・二次1位・統合1位を取得
 */
foreach ($races as $raceCode => $boats) {

    foreach ($boats as $boat) {

        $actualRank = $boat['actual_rank'];

        // 一次1位
        if ($boat['first_rank'] === 1) {
            addResult(
                $stats['一次1位'],
                $actualRank
            );
        }

        // 二次1位
        if ($boat['second_rank'] === 1) {
            addResult(
                $stats['二次1位'],
                $actualRank
            );
        }

        // 統合1位
        if ($boat['final_rank'] === 1) {
            addResult(
                $stats['統合1位'],
                $actualRank
            );
        }
    }
}

/**
 * 表示
 */
foreach ($stats as $name => $data) {

    echo "----------------------------------------\n";
    echo "{$name}\n";
    echo "----------------------------------------\n";

    $count = $data['count'];

    if ($count === 0) {
        echo "データなし\n\n";
        continue;
    }

    $first  = $data['rank_counts'][1] ?? 0;
    $second = $data['rank_counts'][2] ?? 0;
    $third  = $data['rank_counts'][3] ?? 0;

    $top3 = $first + $second + $third;

    $firstRate = $first / $count * 100;
    $secondRate = ($first + $second) / $count * 100;
    $thirdRate = $top3 / $count * 100;

    $avgRank = $data['sum_rank'] / $count;

    echo sprintf("件数       : %d\n", $count);
    echo sprintf("1着        : %d (%.2f%%)\n", $first, $firstRate);
    echo sprintf("2着        : %d (%.2f%%)\n", $second, $second / $count * 100);
    echo sprintf("3着        : %d (%.2f%%)\n", $third, $third / $count * 100);
    echo sprintf("2連対率    : %.2f%%\n", $secondRate);
    echo sprintf("3連対率    : %.2f%%\n", $thirdRate);
    echo sprintf("平均着順   : %.3f\n", $avgRank);

    echo "\n実着順分布:\n";

    for ($rank = 1; $rank <= 6; $rank++) {
        $rankCount = $data['rank_counts'][$rank] ?? 0;
        $rate = $rankCount / $count * 100;

        echo sprintf(
            "  %d着 : %4d (%.2f%%)\n",
            $rank,
            $rankCount,
            $rate
        );
    }

    echo "\n";
}

echo "========================================\n";
echo "レース数 : " . count($races) . "\n";
echo "========================================\n";


/**
 * 集計ヘルパー
 */
function addResult(array &$stat, int $actualRank): void
{
    if ($actualRank <= 0) {
        return;
    }

    $stat['count']++;
    $stat['sum_rank'] += $actualRank;

    if (!isset($stat['rank_counts'][$actualRank])) {
        $stat['rank_counts'][$actualRank] = 0;
    }

    $stat['rank_counts'][$actualRank]++;
}