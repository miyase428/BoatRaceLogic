<?php

/**
 * 一次スコア × 二次スコア 統合方式比較
 *
 * Usage:
 * php analysis/compare_score_methods.php analysis/output/final_prediction_boats_20260801_20260808.csv
 */

if ($argc < 2) {
    echo "Usage: php compare_score_methods.php <csv_file>\n";
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
echo "一次・二次スコア 統合方式比較\n";
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

    $row['_first'] = (float)$row['first_total_score'];
    $row['_second'] = (float)$row['second_score'];
    $row['_actual'] = $actualRank;

    $rows[] = $row;
}

fclose($fp);

echo "有効艇数 : " . count($rows) . "\n";

/*
 * レース単位
 */
$races = [];

foreach ($rows as $row) {

    $raceCode = $row['race_code'];

    if (!isset($races[$raceCode])) {
        $races[$raceCode] = [];
    }

    $races[$raceCode][] = $row;
}

echo "有効レース : " . count($races) . "\n\n";

/*
 * スコア範囲
 */
$firstScores = array_column($rows, '_first');
$secondScores = array_column($rows, '_second');

$firstMin = min($firstScores);
$firstMax = max($firstScores);

$secondMin = min($secondScores);
$secondMax = max($secondScores);

echo "【スコア範囲】\n";
echo "一次 : " . number_format($firstMin, 4) .
     " ～ " . number_format($firstMax, 4) . "\n";

echo "二次 : " . number_format($secondMin, 4) .
     " ～ " . number_format($secondMax, 4) . "\n\n";

/*
 * 正規化
 */
function normalizeScore(
    float $score,
    float $min,
    float $max
): float {

    if ($max <= $min) {
        return 0.0;
    }

    return ($score - $min) / ($max - $min);
}

/*
 * 統合方式
 */
$methods = [

    '一次のみ' => function ($row) {
        return $row['_first'];
    },

    '二次のみ' => function ($row) {
        return $row['_second'];
    },

    '一次＋二次' => function ($row) {
        return $row['_first'] + $row['_second'];
    },

    '一次×0.5＋二次' => function ($row) {
        return $row['_first'] * 0.5 + $row['_second'];
    },

    '一次＋二次×0.5' => function ($row) {
        return $row['_first'] + $row['_second'] * 0.5;
    },

    '正規化一次＋二次' => function ($row) use (
        $firstMin,
        $firstMax,
        $secondMin,
        $secondMax
    ) {

        $first = normalizeScore(
            $row['_first'],
            $firstMin,
            $firstMax
        );

        $second = normalizeScore(
            $row['_second'],
            $secondMin,
            $secondMax
        );

        return $first + $second;
    },

    '一次×二次' => function ($row) {
        return $row['_first'] * $row['_second'];
    },
];

/*
 * 評価
 */
function evaluateMethod(
    array $races,
    callable $scoreFunction
): array {

    $result = [
        1 => [
            'races' => 0,
            'first' => 0,
            'second' => 0,
            'third' => 0,
        ],
        2 => [
            'races' => 0,
            'first' => 0,
            'second' => 0,
            'third' => 0,
        ],
        3 => [
            'races' => 0,
            'first' => 0,
            'second' => 0,
            'third' => 0,
        ],
    ];

    foreach ($races as $race) {

        if (count($race) < 6) {
            continue;
        }

        foreach ($race as &$row) {
            $row['_combined'] = $scoreFunction($row);
        }

        unset($row);

        usort(
            $race,
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

        /*
         * 上位1・2・3艇
         */
        for ($top = 1; $top <= 3; $top++) {

            $selected = array_slice($race, 0, $top);

            $result[$top]['races']++;

            foreach ($selected as $row) {

                if ($row['_actual'] === 1) {
                    $result[$top]['first']++;
                }

                if ($row['_actual'] <= 2) {
                    $result[$top]['second']++;
                }

                if ($row['_actual'] <= 3) {
                    $result[$top]['third']++;
                }
            }
        }
    }

    return $result;
}

/*
 * パーセント
 */
function percent(int $num, int $den): string
{
    if ($den === 0) {
        return '-';
    }

    return number_format(
        $num / $den * 100,
        2
    ) . '%';
}

/*
 * 実行
 */
foreach ($methods as $methodName => $scoreFunction) {

    echo "【{$methodName}】\n";
    echo "----------------------------------------\n";

    $result = evaluateMethod(
        $races,
        $scoreFunction
    );

    for ($top = 1; $top <= 3; $top++) {

        $r = $result[$top];

        /*
         * 上位N艇の「艇ベース」率
         *
         * 例：
         * 上位3艇 × 1139レース = 3417艇
         */
        $count = $r['races'] * $top;

        echo sprintf(
            "上位%d艇: 対象=%d艇, 1着率=%s, 2連対率=%s, 3連対率=%s\n",
            $top,
            $count,
            percent($r['first'], $count),
            percent($r['second'], $count),
            percent($r['third'], $count)
        );
    }

    echo "\n";
}

echo "========================================\n";
echo "分析完了\n";
echo "========================================\n";