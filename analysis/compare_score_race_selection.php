<?php

if ($argc < 2) {
    echo "使い方: php compare_score_race_selection.php <CSVファイル>\n";
    exit(1);
}

$csv_file = $argv[1];

if (!file_exists($csv_file)) {
    echo "CSVが見つかりません: {$csv_file}\n";
    exit(1);
}

echo "========================================\n";
echo "一次・二次スコア レース単位選抜分析\n";
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

$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

$index = [];

foreach ($header as $i => $name) {
    $index[$name] = $i;
}

$required = [
    'race_code',
    'lane_number',
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

/*
 * レース単位でデータを格納
 */
$races = [];

while (($row = fgetcsv($fp)) !== false) {

    $race_code = trim($row[$index['race_code']] ?? '');

    if ($race_code === '') {
        continue;
    }

    $lane = (int)($row[$index['lane_number']] ?? 0);
    $first = (float)($row[$index['first_total_score']] ?? 0);
    $second = (float)($row[$index['second_score']] ?? 0);
    $actual = (int)($row[$index['actual_rank']] ?? 0);

    if ($lane < 1 || $lane > 6) {
        continue;
    }

    if ($actual < 1 || $actual > 6) {
        continue;
    }

    $races[$race_code][] = [
        'lane' => $lane,
        'first' => $first,
        'second' => $second,
        'actual' => $actual
    ];
}

fclose($fp);

echo "有効レース : " . count($races) . "\n\n";


/*
 * ============================================================
 * 統計関数
 * ============================================================
 */

function calcResult(array $selected): array
{
    $count = count($selected);

    if ($count === 0) {
        return [
            'count' => 0,
            'first' => 0,
            'second' => 0,
            'third' => 0
        ];
    }

    $first = 0;
    $second = 0;
    $third = 0;

    foreach ($selected as $boat) {

        if ($boat['actual'] === 1) {
            $first++;
        }

        if ($boat['actual'] <= 2) {
            $second++;
        }

        if ($boat['actual'] <= 3) {
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
 * レースごとに候補を1艇へ絞る
 *
 * 条件を満たす艇が複数いる場合は
 * 統合スコア = 一次 + 二次×重み
 * の最大艇を選ぶ
 * ============================================================
 */

function selectByCondition(
    array $races,
    float $firstMin,
    float $secondMin,
    float $weight
): array {

    $selected = [];
    $no_candidate = 0;

    foreach ($races as $raceCode => $boats) {

        $candidates = [];

        foreach ($boats as $boat) {

            if ($boat['first'] < $firstMin) {
                continue;
            }

            if ($boat['second'] < $secondMin) {
                continue;
            }

            $score =
                $boat['first']
                + ($boat['second'] * $weight);

            $boat['combined'] = $score;

            $candidates[] = $boat;
        }

        if (count($candidates) === 0) {
            $no_candidate++;
            continue;
        }

        usort($candidates, function ($a, $b) {

            if ($a['combined'] == $b['combined']) {
                return $b['second'] <=> $a['second'];
            }

            return $b['combined'] <=> $a['combined'];
        });

        $selected[] = $candidates[0];
    }

    return [
        'selected' => $selected,
        'no_candidate' => $no_candidate
    ];
}


/*
 * ============================================================
 * 1. 条件＋統合スコアによる本命選抜
 * ============================================================
 */

echo "【1】条件を満たした艇から統合スコア最大を本命にする\n";
echo "統合スコア = 一次 + 二次×0.65\n";
echo "-------------------------------------------------------------------------------\n";

echo sprintf(
    "%8s %8s %10s %10s %10s %10s %10s\n",
    "一次≥",
    "二次≥",
    "本命数",
    "該当なし",
    "1着率",
    "2連対率",
    "3連対率"
);

echo "-------------------------------------------------------------------------------\n";

$firstThresholds = [16, 18, 20, 22, 24];
$secondThresholds = [25, 30, 35];

$raceResults = [];

foreach ($firstThresholds as $firstMin) {

    foreach ($secondThresholds as $secondMin) {

        $result = selectByCondition(
            $races,
            $firstMin,
            $secondMin,
            0.65
        );

        $stats = calcResult($result['selected']);

        $raceResults[] = [
            'firstMin' => $firstMin,
            'secondMin' => $secondMin,
            'stats' => $stats,
            'no_candidate' => $result['no_candidate']
        ];

        echo sprintf(
            "%8.1f %8.1f %10d %10d %10s %10s %10s\n",
            $firstMin,
            $secondMin,
            $stats['count'],
            $result['no_candidate'],
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

usort($raceResults, function ($a, $b) {

    $rateA = $a['stats']['count'] > 0
        ? $a['stats']['first'] / $a['stats']['count']
        : 0;

    $rateB = $b['stats']['count'] > 0
        ? $b['stats']['first'] / $b['stats']['count']
        : 0;

    return $rateB <=> $rateA;
});

echo "【2】レース単位 1着率ランキング\n";
echo "-------------------------------------------------------------------------------\n";

echo sprintf(
    "%4s %8s %8s %10s %10s %10s %10s\n",
    "順位",
    "一次≥",
    "二次≥",
    "本命数",
    "該当なし",
    "1着率",
    "3連対率"
);

echo "-------------------------------------------------------------------------------\n";

$rank = 1;

foreach ($raceResults as $result) {

    $stats = $result['stats'];

    echo sprintf(
        "%4d %8.1f %8.1f %10d %10d %10s %10s\n",
        $rank,
        $result['firstMin'],
        $result['secondMin'],
        $stats['count'],
        $result['no_candidate'],
        pct($stats['first'], $stats['count']),
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
 * 3. 条件なしの統合スコアとの比較
 * ============================================================
 */

echo "【3】条件なし・統合スコア最大艇\n";
echo "-------------------------------------------------------------------------------\n";

$selected = [];

foreach ($races as $raceCode => $boats) {

    foreach ($boats as &$boat) {

        $boat['combined'] =
            $boat['first']
            + ($boat['second'] * 0.65);
    }

    unset($boat);

    usort($boats, function ($a, $b) {

        if ($a['combined'] == $b['combined']) {
            return $b['second'] <=> $a['second'];
        }

        return $b['combined'] <=> $a['combined'];
    });

    $selected[] = $boats[0];
}

$stats = calcResult($selected);

echo sprintf(
    "本命数=%d, 1着率=%s, 2連対率=%s, 3連対率=%s\n",
    $stats['count'],
    pct($stats['first'], $stats['count']),
    pct($stats['second'], $stats['count']),
    pct($stats['third'], $stats['count'])
);

echo "-------------------------------------------------------------------------------\n\n";


/*
 * ============================================================
 * 4. 条件別「本命を出せる割合」
 * ============================================================
 */

echo "【4】条件別 本命選抜率\n";
echo "-------------------------------------------------------------------------------\n";

echo sprintf(
    "%8s %8s %10s %10s %10s\n",
    "一次≥",
    "二次≥",
    "対象レース",
    "本命選抜",
    "選抜率"
);

echo "-------------------------------------------------------------------------------\n";

$totalRaces = count($races);

foreach ($raceResults as $result) {

    $selectedCount = $result['stats']['count'];

    echo sprintf(
        "%8.1f %8.1f %10d %10d %10s\n",
        $result['firstMin'],
        $result['secondMin'],
        $totalRaces,
        $selectedCount,
        pct($selectedCount, $totalRaces)
    );
}

echo "-------------------------------------------------------------------------------\n\n";


echo "========================================\n";
echo "分析完了\n";
echo "========================================\n";