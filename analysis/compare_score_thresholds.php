<?php

if ($argc < 2) {
    echo "使い方: php compare_score_thresholds.php <CSVファイル>\n";
    exit(1);
}

$csv_file = $argv[1];

if (!file_exists($csv_file)) {
    echo "CSVが見つかりません: {$csv_file}\n";
    exit(1);
}

echo "========================================\n";
echo "一次・二次スコア 閾値条件分析\n";
echo "========================================\n";
echo "CSV : {$csv_file}\n";
echo "========================================\n\n";

$fp = fopen($csv_file, 'r');

if ($fp === false) {
    echo "CSVを開けませんでした。\n";
    exit(1);
}

$header = fgetcsv($fp);

if ($header === false) {
    echo "CSVヘッダーを読み込めませんでした。\n";
    exit(1);
}

/*
 * BOM除去
 */
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

$index = [];

foreach ($header as $i => $name) {
    $index[$name] = $i;
}

$required = [
    'race_code',
    'first_total_score',
    'second_score',
    'actual_rank'
];

foreach ($required as $column) {
    if (!isset($index[$column])) {
        echo "必要な列がありません: {$column}\n";
        exit(1);
    }
}

$rows = [];
$race_codes = [];

while (($row = fgetcsv($fp)) !== false) {

    $race_code = trim($row[$index['race_code']] ?? '');

    if ($race_code === '') {
        continue;
    }

    $first_score = (float)($row[$index['first_total_score']] ?? 0);
    $second_score = (float)($row[$index['second_score']] ?? 0);
    $actual_rank = (int)($row[$index['actual_rank']] ?? 0);

    if ($actual_rank < 1 || $actual_rank > 6) {
        continue;
    }

    $rows[] = [
        'race_code' => $race_code,
        'first' => $first_score,
        'second' => $second_score,
        'actual' => $actual_rank
    ];

    $race_codes[$race_code] = true;
}

fclose($fp);

echo "有効艇数 : " . count($rows) . "\n";
echo "有効レース : " . count($race_codes) . "\n\n";

/*
 * 条件ごとの集計
 */
function calcStats(array $rows, float $firstMin, float $secondMin): array
{
    $count = 0;
    $first = 0;
    $second = 0;
    $third = 0;

    foreach ($rows as $row) {

        if ($row['first'] < $firstMin) {
            continue;
        }

        if ($row['second'] < $secondMin) {
            continue;
        }

        $count++;

        if ($row['actual'] === 1) {
            $first++;
        }

        if ($row['actual'] <= 2) {
            $second++;
        }

        if ($row['actual'] <= 3) {
            $third++;
        }
    }

    return [
        'count' => $count,
        'first' => $first,
        'second' => $second,
        'third' => $third
    ];
}

function pct(int $value, int $total): string
{
    if ($total === 0) {
        return '-';
    }

    return number_format($value / $total * 100, 2) . '%';
}


/*
 * ============================================================
 * 1. 一次スコア × 二次スコア
 *    AND条件
 * ============================================================
 */

echo "【1】一次スコア AND 二次スコア 閾値\n";
echo "-------------------------------------------------------------------------------\n";
echo sprintf(
    "%10s %10s %8s %10s %10s %10s\n",
    "一次≥", "二次≥", "件数", "1着率", "2連対率", "3連対率"
);
echo "-------------------------------------------------------------------------------\n";

$firstThresholds = [16, 18, 20, 22, 24];
$secondThresholds = [20, 25, 30, 35];

$results = [];

foreach ($firstThresholds as $firstMin) {
    foreach ($secondThresholds as $secondMin) {

        $stats = calcStats($rows, $firstMin, $secondMin);

        if ($stats['count'] < 20) {
            continue;
        }

        $results[] = [
            'firstMin' => $firstMin,
            'secondMin' => $secondMin,
            'stats' => $stats
        ];

        echo sprintf(
            "%10.1f %10.1f %8d %10s %10s %10s\n",
            $firstMin,
            $secondMin,
            $stats['count'],
            pct($stats['first'], $stats['count']),
            pct($stats['second'], $stats['count']),
            pct($stats['third'], $stats['count'])
        );
    }
}

echo "-------------------------------------------------------------------------------\n\n";


/*
 * ============================================================
 * 2. 1着率ランキング
 * ============================================================
 */

usort($results, function ($a, $b) {

    $rateA = $a['stats']['first'] / $a['stats']['count'];
    $rateB = $b['stats']['first'] / $b['stats']['count'];

    return $rateB <=> $rateA;
});

echo "【2】1着率ランキング（件数20以上）\n";
echo "-------------------------------------------------------------------------------\n";
echo sprintf(
    "%4s %10s %10s %8s %10s %10s %10s\n",
    "順位",
    "一次≥",
    "二次≥",
    "件数",
    "1着率",
    "2連対率",
    "3連対率"
);
echo "-------------------------------------------------------------------------------\n";

$rank = 1;

foreach ($results as $result) {

    $stats = $result['stats'];

    echo sprintf(
        "%4d %10.1f %10.1f %8d %10s %10s %10s\n",
        $rank,
        $result['firstMin'],
        $result['secondMin'],
        $stats['count'],
        pct($stats['first'], $stats['count']),
        pct($stats['second'], $stats['count']),
        pct($stats['third'], $stats['count'])
    );

    $rank++;

    if ($rank > 15) {
        break;
    }
}

echo "-------------------------------------------------------------------------------\n\n";


/*
 * ============================================================
 * 3. 実用候補
 *    件数100以上
 * ============================================================
 */

$largeResults = array_filter(
    $results,
    function ($result) {
        return $result['stats']['count'] >= 100;
    }
);

usort($largeResults, function ($a, $b) {

    $rateA = $a['stats']['first'] / $a['stats']['count'];
    $rateB = $b['stats']['first'] / $b['stats']['count'];

    return $rateB <=> $rateA;
});

echo "【3】実用候補（件数100以上）\n";
echo "-------------------------------------------------------------------------------\n";
echo sprintf(
    "%4s %10s %10s %8s %10s %10s %10s\n",
    "順位",
    "一次≥",
    "二次≥",
    "件数",
    "1着率",
    "2連対率",
    "3連対率"
);
echo "-------------------------------------------------------------------------------\n";

$rank = 1;

foreach ($largeResults as $result) {

    $stats = $result['stats'];

    echo sprintf(
        "%4d %10.1f %10.1f %8d %10s %10s %10s\n",
        $rank,
        $result['firstMin'],
        $result['secondMin'],
        $stats['count'],
        pct($stats['first'], $stats['count']),
        pct($stats['second'], $stats['count']),
        pct($stats['third'], $stats['count'])
    );

    $rank++;

    if ($rank > 15) {
        break;
    }
}

echo "-------------------------------------------------------------------------------\n\n";


/*
 * ============================================================
 * 4. OR条件
 * ============================================================
 */

echo "【4】一次スコア OR 二次スコア 条件\n";
echo "※ どちらか一方を満たす艇\n";
echo "-------------------------------------------------------------------------------\n";
echo sprintf(
    "%10s %10s %8s %10s %10s %10s\n",
    "一次≥", "二次≥", "件数", "1着率", "2連対率", "3連対率"
);
echo "-------------------------------------------------------------------------------\n";

foreach ($firstThresholds as $firstMin) {
    foreach ($secondThresholds as $secondMin) {

        $count = 0;
        $first = 0;
        $second = 0;
        $third = 0;

        foreach ($rows as $row) {

            if (
                $row['first'] < $firstMin &&
                $row['second'] < $secondMin
            ) {
                continue;
            }

            $count++;

            if ($row['actual'] === 1) {
                $first++;
            }

            if ($row['actual'] <= 2) {
                $second++;
            }

            if ($row['actual'] <= 3) {
                $third++;
            }
        }

        if ($count < 20) {
            continue;
        }

        echo sprintf(
            "%10.1f %10.1f %8d %10s %10s %10s\n",
            $firstMin,
            $secondMin,
            $count,
            pct($first, $count),
            pct($second, $count),
            pct($third, $count)
        );
    }
}

echo "-------------------------------------------------------------------------------\n\n";

echo "========================================\n";
echo "分析完了\n";
echo "========================================\n";