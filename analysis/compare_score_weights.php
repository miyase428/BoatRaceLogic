<?php

if ($argc < 2) {
    echo "Usage: php compare_score_weights.php <csv>\n";
    exit(1);
}

$csvFile = $argv[1];

if (!file_exists($csvFile)) {
    echo "CSVが見つかりません: {$csvFile}\n";
    exit(1);
}

$fp = fopen($csvFile, 'r');

if (!$fp) {
    echo "CSVを開けません: {$csvFile}\n";
    exit(1);
}

// BOM除去
$header = fgetcsv($fp);

if ($header === false) {
    echo "CSVが空です。\n";
    exit(1);
}

$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

$index = array_flip($header);

$required = [
    'race_code',
    'first_total_score',
    'second_score',
    'actual_rank',
];

foreach ($required as $column) {
    if (!isset($index[$column])) {
        echo "必要な列がありません: {$column}\n";
        exit(1);
    }
}

$races = [];

while (($row = fgetcsv($fp)) !== false) {

    $raceCode = trim($row[$index['race_code']]);

    $first = trim($row[$index['first_total_score']]);
    $second = trim($row[$index['second_score']]);
    $actual = trim($row[$index['actual_rank']]);

    if ($raceCode === '' || $first === '' || $second === '' || $actual === '') {
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

echo "========================================\n";
echo "一次・二次スコア 重み比較\n";
echo "========================================\n";
echo "CSV : {$csvFile}\n";
echo "有効レース : " . count($races) . "\n";
echo "========================================\n\n";


/**
 * 指定した重みで順位付けして成績を計算
 */
function evaluateWeight(array $races, float $weight): array
{
    $stats = [
        1 => ['count' => 0, 'first' => 0, 'second' => 0, 'third' => 0],
        2 => ['count' => 0, 'first' => 0, 'second' => 0, 'third' => 0],
        3 => ['count' => 0, 'first' => 0, 'second' => 0, 'third' => 0],
    ];

    foreach ($races as $boats) {

        foreach ($boats as &$boat) {
            $boat['combined'] =
                $boat['first'] +
                ($boat['second'] * $weight);
        }
        unset($boat);

        usort($boats, function ($a, $b) {
            if ($a['combined'] == $b['combined']) {
                return 0;
            }

            return ($a['combined'] > $b['combined']) ? -1 : 1;
        });

        foreach ([1, 2, 3] as $rank) {

            if (!isset($boats[$rank - 1])) {
                continue;
            }

            $boat = $boats[$rank - 1];
            $actual = $boat['actual'];

            $stats[$rank]['count']++;

            if ($actual === 1) {
                $stats[$rank]['first']++;
            }

            if ($actual <= 2) {
                $stats[$rank]['second']++;
            }

            if ($actual <= 3) {
                $stats[$rank]['third']++;
            }
        }
    }

    return $stats;
}


function rate(int $value, int $count): string
{
    if ($count === 0) {
        return '-';
    }

    return number_format(($value / $count) * 100, 2) . '%';
}


/*
 * 0.00 ～ 2.00
 * 0.05刻み
 */
$weights = [];

for ($i = 0; $i <= 40; $i++) {
    $weights[] = $i / 20;
}


echo "【一次 + 二次×重み】\n";
echo "--------------------------------------------------------------------------------\n";
printf(
    "%8s %8s %8s %10s %10s %10s %10s %10s %10s\n",
    "二次重み",
    "順位",
    "対象",
    "1着率",
    "2連対率",
    "3連対率",
    "",
    "",
    ""
);
echo "--------------------------------------------------------------------------------\n";


$results = [];

foreach ($weights as $weight) {

    $stats = evaluateWeight($races, $weight);

    for ($rank = 1; $rank <= 3; $rank++) {

        $s = $stats[$rank];

        printf(
            "%8.2f %8d %8d %10s %10s %10s\n",
            $weight,
            $rank,
            $s['count'],
            rate($s['first'], $s['count']),
            rate($s['second'], $s['count']),
            rate($s['third'], $s['count'])
        );
    }

    echo "--------------------------------------------------------------------------------\n";

    $s = $stats[1];

    $results[] = [
        'weight' => $weight,
        'first'  => $s['count'] > 0 ? $s['first'] / $s['count'] : 0,
        'second' => $s['count'] > 0 ? $s['second'] / $s['count'] : 0,
        'third'  => $s['count'] > 0 ? $s['third'] / $s['count'] : 0,
    ];
}


/*
 * 1位艇の1着率ランキング
 */
usort($results, function ($a, $b) {
    return $b['first'] <=> $a['first'];
});

echo "\n";
echo "【1位艇・1着率ランキング】\n";
echo "--------------------------------------------------------------------------------\n";
printf(
    "%5s %10s %10s %10s\n",
    "順位",
    "二次重み",
    "1着率",
    "3連対率"
);
echo "--------------------------------------------------------------------------------\n";

$limit = min(10, count($results));

for ($i = 0; $i < $limit; $i++) {

    $r = $results[$i];

    printf(
        "%5d %10.2f %10.2f%% %10.2f%%\n",
        $i + 1,
        $r['weight'],
        $r['first'] * 100,
        $r['third'] * 100
    );
}

echo "--------------------------------------------------------------------------------\n";

echo "\n========================================\n";
echo "分析完了\n";
echo "========================================\n";