<?php

if ($argc < 2) {
    echo "使い方: php {$argv[0]} CSVファイル\n";
    exit(1);
}

$csvFile = $argv[1];

if (!file_exists($csvFile)) {
    echo "CSVが見つかりません: {$csvFile}\n";
    exit(1);
}

echo "========================================\n";
echo "一次・二次スコア レース単位グリッド分析\n";
echo "========================================\n";
echo "CSV : {$csvFile}\n";
echo "========================================\n\n";

$fp = fopen($csvFile, 'r');

if (!$fp) {
    echo "CSVを開けませんでした。\n";
    exit(1);
}

$header = fgetcsv($fp);

if ($header === false) {
    echo "CSVヘッダーを読み込めませんでした。\n";
    exit(1);
}

/*
 * UTF-8 BOM除去
 */
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

$index = array_flip($header);

$required = [
    'race_code',
    'first_total_score',
    'second_score',
    'actual_rank'
];

foreach ($required as $col) {
    if (!isset($index[$col])) {
        echo "必要な列がありません: {$col}\n";
        exit(1);
    }
}

$races = [];

while (($row = fgetcsv($fp)) !== false) {

    $raceCode = trim($row[$index['race_code']]);

    if ($raceCode === '') {
        continue;
    }

    $first = trim($row[$index['first_total_score']]);
    $second = trim($row[$index['second_score']]);
    $actual = trim($row[$index['actual_rank']]);

    if ($first === '' || $second === '' || $actual === '') {
        continue;
    }

    if (!is_numeric($first) || !is_numeric($second) || !is_numeric($actual)) {
        continue;
    }

    $races[$raceCode][] = [
        'first'  => (float)$first,
        'second' => (float)$second,
        'actual' => (int)$actual,
    ];
}

fclose($fp);

echo "有効レース : " . count($races) . "\n\n";

/*
 * 検証する値
 */
$weights = [
    0.50,
    0.55,
    0.60,
    0.65,
    0.70,
    0.75,
    0.80,
    0.85,
    0.90,
    0.95,
    1.00,
];

$firstThresholds = [
    16.0,
    18.0,
    20.0,
    22.0,
    24.0,
];

$secondThresholds = [
    25.0,
    30.0,
    35.0,
];

/*
 * 結果格納
 */
$results = [];

foreach ($weights as $weight) {

    foreach ($firstThresholds as $firstMin) {

        foreach ($secondThresholds as $secondMin) {

            $selected = 0;
            $none = 0;

            $firstHit = 0;
            $secondHit = 0;
            $thirdHit = 0;

            foreach ($races as $raceCode => $boats) {

                $candidates = [];

                foreach ($boats as $boat) {

                    if (
                        $boat['first'] >= $firstMin &&
                        $boat['second'] >= $secondMin
                    ) {
                        $score =
                            $boat['first'] +
                            $boat['second'] * $weight;

                        $candidates[] = [
                            'score' => $score,
                            'actual' => $boat['actual'],
                        ];
                    }
                }

                /*
                 * 条件該当艇なし
                 */
                if (count($candidates) === 0) {
                    $none++;
                    continue;
                }

                /*
                 * 統合スコア最大艇を本命
                 */
                usort($candidates, function ($a, $b) {
                    return $b['score'] <=> $a['score'];
                });

                $selected++;

                $actual = $candidates[0]['actual'];

                if ($actual === 1) {
                    $firstHit++;
                }

                if ($actual <= 2) {
                    $secondHit++;
                }

                if ($actual <= 3) {
                    $thirdHit++;
                }
            }

            $results[] = [
                'weight' => $weight,
                'first' => $firstMin,
                'second' => $secondMin,
                'selected' => $selected,
                'none' => $none,
                'first_rate' =>
                    $selected > 0
                        ? $firstHit / $selected * 100
                        : 0,
                'second_rate' =>
                    $selected > 0
                        ? $secondHit / $selected * 100
                        : 0,
                'third_rate' =>
                    $selected > 0
                        ? $thirdHit / $selected * 100
                        : 0,
            ];
        }
    }
}

/*
 * 1着率順
 */
$ranking = $results;

usort($ranking, function ($a, $b) {

    if ($a['selected'] !== $b['selected']) {
        return $b['selected'] <=> $a['selected'];
    }

    return $b['first_rate'] <=> $a['first_rate'];
});

/*
 * 全結果表示
 */
echo "【1】重み × 閾値 全組み合わせ\n";
echo "-----------------------------------------------------------------------------------------------\n";
echo "二次重み 一次≥ 二次≥ 本命数 該当なし 選抜率 1着率 2連対率 3連対率\n";
echo "-----------------------------------------------------------------------------------------------\n";

foreach ($results as $r) {

    $total = $r['selected'] + $r['none'];

    $selectionRate =
        $total > 0
            ? $r['selected'] / $total * 100
            : 0;

    printf(
        "%7.2f %6.1f %6.1f %6d %8d %7.2f%% %7.2f%% %8.2f%% %8.2f%%\n",
        $r['weight'],
        $r['first'],
        $r['second'],
        $r['selected'],
        $r['none'],
        $selectionRate,
        $r['first_rate'],
        $r['second_rate'],
        $r['third_rate']
    );
}

/*
 * 実用候補
 *
 * 本命数100以上を対象。
 */
$practical = array_filter(
    $results,
    function ($r) {
        return $r['selected'] >= 100;
    }
);

usort(
    $practical,
    function ($a, $b) {
        return $b['first_rate'] <=> $a['first_rate'];
    }
);

echo "\n";
echo "【2】実用候補（本命100件以上）1着率ランキング\n";
echo "-----------------------------------------------------------------------------------------------\n";
echo "順位 二次重み 一次≥ 二次≥ 本命数 該当なし 選抜率 1着率 2連対率 3連対率\n";
echo "-----------------------------------------------------------------------------------------------\n";

$rank = 1;

foreach ($practical as $r) {

    $total = $r['selected'] + $r['none'];

    $selectionRate =
        $total > 0
            ? $r['selected'] / $total * 100
            : 0;

    printf(
        "%4d %8.2f %6.1f %6.1f %6d %8d %7.2f%% %7.2f%% %8.2f%% %8.2f%%\n",
        $rank,
        $r['weight'],
        $r['first'],
        $r['second'],
        $r['selected'],
        $r['none'],
        $selectionRate,
        $r['first_rate'],
        $r['second_rate'],
        $r['third_rate']
    );

    $rank++;

    if ($rank > 20) {
        break;
    }
}

/*
 * 特に注目する条件
 */
echo "\n";
echo "【3】特に注目する条件\n";
echo "-----------------------------------------------------------------------------------------------\n";
echo "二次重み 一次≥ 二次≥ 本命数 選抜率 1着率 2連対率 3連対率\n";
echo "-----------------------------------------------------------------------------------------------\n";

$targetConditions = [
    [16.0, 30.0],
    [16.0, 35.0],
    [18.0, 30.0],
    [18.0, 35.0],
    [20.0, 30.0],
    [20.0, 35.0],
    [22.0, 30.0],
    [22.0, 35.0],
];

foreach ($weights as $weight) {

    foreach ($targetConditions as [$firstMin, $secondMin]) {

        foreach ($results as $r) {

            if (
                abs($r['weight'] - $weight) < 0.00001 &&
                abs($r['first'] - $firstMin) < 0.00001 &&
                abs($r['second'] - $secondMin) < 0.00001
            ) {

                $total = $r['selected'] + $r['none'];

                $selectionRate =
                    $total > 0
                        ? $r['selected'] / $total * 100
                        : 0;

                printf(
                    "%7.2f %6.1f %6.1f %6d %7.2f%% %7.2f%% %8.2f%% %8.2f%%\n",
                    $r['weight'],
                    $r['first'],
                    $r['second'],
                    $r['selected'],
                    $selectionRate,
                    $r['first_rate'],
                    $r['second_rate'],
                    $r['third_rate']
                );

                break;
            }
        }
    }
}

echo "\n";
echo "========================================\n";
echo "分析完了\n";
echo "========================================\n";