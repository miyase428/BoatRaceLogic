<?php

/**
 * 現行最終予想 健康診断 STEP 2-6
 *
 * 一次評価・二次評価・最終評価の性能比較
 *
 * Usage:
 * php analysis/health_check_eval_performance.php \
 *   analysis/output/final_prediction_boats_20260801_20260808.csv
 */

if ($argc < 2) {
    echo "Usage:\n";
    echo "php analysis/health_check_eval_performance.php <boats_csv>\n";
    exit(1);
}

$csvFile = $argv[1];

if (!file_exists($csvFile)) {
    echo "CSVが見つかりません: {$csvFile}\n";
    exit(1);
}

/**
 * CSV読み込み
 */
$handle = fopen($csvFile, 'r');

if ($handle === false) {
    echo "CSVを開けません: {$csvFile}\n";
    exit(1);
}

$header = fgetcsv($handle);

if ($header === false) {
    echo "CSVが空です。\n";
    exit(1);
}

/**
 * BOM除去
 */
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

$columns = [];

foreach ($header as $index => $name) {
    $columns[$name] = $index;
}

/**
 * 必須項目確認
 */
$requiredColumns = [
    'race_code',
    'lane_number',
    'first_rank',
    'second_rank',
    'final_rank',
    'actual_rank',
];

foreach ($requiredColumns as $column) {
    if (!array_key_exists($column, $columns)) {
        echo "必要な列がありません: {$column}\n";
        fclose($handle);
        exit(1);
    }
}

/**
 * 評価段階の定義
 */
$evaluations = [
    'first' => [
        'label' => '一次評価',
        'rank_column' => 'first_rank',
    ],
    'second' => [
        'label' => '二次評価',
        'rank_column' => 'second_rank',
    ],
    'final' => [
        'label' => '最終評価',
        'rank_column' => 'final_rank',
    ],
];

/**
 * 集計用
 */
$stats = [];

foreach ($evaluations as $key => $evaluation) {
    $stats[$key] = [];

    for ($rank = 1; $rank <= 6; $rank++) {
        $stats[$key][$rank] = [
            'count' => 0,
            'first' => 0,
            'second' => 0,
            'third' => 0,
            'sum_actual_rank' => 0,
        ];
    }

    $stats[$key]['all'] = [
        'count' => 0,
        'sum_actual_rank' => 0,
    ];
}

$totalRows = 0;
$validRows = 0;
$invalidRows = 0;

/**
 * CSV集計
 */
while (($row = fgetcsv($handle)) !== false) {

    $totalRows++;

    $actualRank = trim($row[$columns['actual_rank']] ?? '');

    if ($actualRank === '' || !is_numeric($actualRank)) {
        $invalidRows++;
        continue;
    }

    $actualRank = (int)$actualRank;

    if ($actualRank < 1 || $actualRank > 6) {
        $invalidRows++;
        continue;
    }

    $validRows++;

    foreach ($evaluations as $key => $evaluation) {

        $rankValue = trim(
            $row[$columns[$evaluation['rank_column']]] ?? ''
        );

        if ($rankValue === '' || !is_numeric($rankValue)) {
            continue;
        }

        $predictionRank = (int)$rankValue;

        if ($predictionRank < 1 || $predictionRank > 6) {
            continue;
        }

        $stats[$key][$predictionRank]['count']++;
        $stats[$key][$predictionRank]['sum_actual_rank'] += $actualRank;

        if ($actualRank === 1) {
            $stats[$key][$predictionRank]['first']++;
        }

        if ($actualRank <= 2) {
            $stats[$key][$predictionRank]['second']++;
        }

        if ($actualRank <= 3) {
            $stats[$key][$predictionRank]['third']++;
        }

        $stats[$key]['all']['count']++;
        $stats[$key]['all']['sum_actual_rank'] += $actualRank;
    }
}

fclose($handle);

/**
 * パーセント表示
 */
function percent($value, $total)
{
    if ($total <= 0) {
        return '0.00%';
    }

    return number_format(($value / $total) * 100, 2) . '%';
}

/**
 * 平均値
 */
function average($sum, $count)
{
    if ($count <= 0) {
        return 0;
    }

    return $sum / $count;
}

echo PHP_EOL;
echo "========================================" . PHP_EOL;
echo "現行最終予想 健康診断 STEP 2-6" . PHP_EOL;
echo "一次評価・二次評価・最終評価 性能比較" . PHP_EOL;
echo "========================================" . PHP_EOL;
echo "対象CSV : {$csvFile}" . PHP_EOL;
echo "対象艇  : {$validRows}" . PHP_EOL;

if ($invalidRows > 0) {
    echo "除外行  : {$invalidRows}" . PHP_EOL;
}

echo PHP_EOL;

/**
 * 各評価段階
 */
foreach ($evaluations as $key => $evaluation) {

    echo "========================================" . PHP_EOL;
    echo $evaluation['label'] . PHP_EOL;
    echo "========================================" . PHP_EOL;

    echo PHP_EOL;

    echo "順位  件数   1着率    2連対率   3連対率   平均実着順" . PHP_EOL;

    for ($rank = 1; $rank <= 6; $rank++) {

        $s = $stats[$key][$rank];

        $count = $s['count'];

        if ($count === 0) {
            continue;
        }

        $avgActual = average(
            $s['sum_actual_rank'],
            $count
        );

        printf(
            "%d位  %5d  %7s  %8s  %8s  %8.2f位\n",
            $rank,
            $count,
            percent($s['first'], $count),
            percent($s['second'], $count),
            percent($s['third'], $count),
            $avgActual
        );
    }

    $all = $stats[$key]['all'];

    echo PHP_EOL;

    if ($all['count'] > 0) {
        echo "全体平均実着順 : "
            . number_format(
                average($all['sum_actual_rank'], $all['count']),
                2
            )
            . "位"
            . PHP_EOL;
    }

    echo PHP_EOL;
}

/**
 * 比較表
 */
echo "========================================" . PHP_EOL;
echo "評価段階別 1位艇の比較" . PHP_EOL;
echo "========================================" . PHP_EOL;

echo PHP_EOL;

echo "評価段階   件数    1着率    2連対率   3連対率   平均実着順" . PHP_EOL;

foreach ($evaluations as $key => $evaluation) {

    $s = $stats[$key][1];

    if ($s['count'] === 0) {
        continue;
    }

    $avgActual = average(
        $s['sum_actual_rank'],
        $s['count']
    );

    printf(
        "%-8s %5d  %7s  %8s  %8s  %8.2f位\n",
        $evaluation['label'],
        $s['count'],
        percent($s['first'], $s['count']),
        percent($s['second'], $s['count']),
        percent($s['third'], $s['count']),
        $avgActual
    );
}

echo PHP_EOL;

/**
 * 評価段階別 3着以内率
 */
echo "========================================" . PHP_EOL;
echo "評価段階別 上位順位の3連対率" . PHP_EOL;
echo "========================================" . PHP_EOL;

echo PHP_EOL;

echo "評価段階   1位   2位   3位   4位   5位   6位" . PHP_EOL;

foreach ($evaluations as $key => $evaluation) {

    printf(
        "%-8s",
        $evaluation['label']
    );

    for ($rank = 1; $rank <= 6; $rank++) {

        $s = $stats[$key][$rank];

        if ($s['count'] === 0) {
            printf("   -  ");
        } else {
            printf(
                "%6s",
                percent($s['third'], $s['count'])
            );
        }
    }

    echo PHP_EOL;
}

echo PHP_EOL;

/**
 * 1位→6位までの平均実着順
 */
echo "========================================" . PHP_EOL;
echo "評価順位別 平均実着順" . PHP_EOL;
echo "========================================" . PHP_EOL;

echo PHP_EOL;

echo "評価段階   1位    2位    3位    4位    5位    6位" . PHP_EOL;

foreach ($evaluations as $key => $evaluation) {

    printf(
        "%-8s",
        $evaluation['label']
    );

    for ($rank = 1; $rank <= 6; $rank++) {

        $s = $stats[$key][$rank];

        if ($s['count'] === 0) {
            printf("   -  ");
        } else {
            $avgActual = average(
                $s['sum_actual_rank'],
                $s['count']
            );

            printf(
                "%6.2f",
                $avgActual
            );
        }
    }

    echo PHP_EOL;
}

echo PHP_EOL;

/**
 * まとめ
 */
echo "========================================" . PHP_EOL;
echo "STEP 2-6 完了" . PHP_EOL;
echo "========================================" . PHP_EOL;