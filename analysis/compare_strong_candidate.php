<?php

if ($argc < 2) {
    echo "使い方: php compare_strong_candidate.php <CSVファイル>\n";
    exit(1);
}

$csv_file = $argv[1];

if (!file_exists($csv_file)) {
    echo "CSVが見つかりません: {$csv_file}\n";
    exit(1);
}

echo "========================================\n";
echo "強候補 vs 現在本命 比較分析\n";
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
 * ============================================================
 * CSVをレース単位で読み込む
 * ============================================================
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

function resultLabel(int $rank): string
{
    if ($rank === 1) {
        return '1着';
    }

    if ($rank === 2) {
        return '2着';
    }

    if ($rank === 3) {
        return '3着';
    }

    return '着外';
}

function pct(int $value, int $total): string
{
    if ($total === 0) {
        return '-';
    }

    return number_format($value / $total * 100, 2) . '%';
}

function calcStats(array $boats): array
{
    $count = count($boats);

    $first = 0;
    $second = 0;
    $third = 0;

    foreach ($boats as $boat) {

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


/*
 * ============================================================
 * 現在の本命
 *
 * 現行の考え方：
 * 統合スコア = 一次 + 二次×0.65
 *
 * 条件なしで最大艇を本命とする
 * ============================================================
 */

function selectCurrentFavorite(array $boats): array
{
    $candidates = [];

    foreach ($boats as $boat) {

        $boat['combined'] =
            $boat['first']
            + ($boat['second'] * 0.65);

        $candidates[] = $boat;
    }

    usort($candidates, function ($a, $b) {

        if ($a['combined'] == $b['combined']) {
            return $b['second'] <=> $a['second'];
        }

        return $b['combined'] <=> $a['combined'];
    });

    return $candidates[0];
}


/*
 * ============================================================
 * 強候補
 *
 * 一次 >= 18
 * 二次 >= 35
 *
 * 条件を満たす艇が複数いる場合は
 * 現在と同じ統合スコアで最上位を選ぶ
 * ============================================================
 */

function selectStrongCandidate(array $boats): ?array
{
    $candidates = [];

    foreach ($boats as $boat) {

        if ($boat['first'] < 18) {
            continue;
        }

        if ($boat['second'] < 35) {
            continue;
        }

        $boat['combined'] =
            $boat['first']
            + ($boat['second'] * 0.65);

        $candidates[] = $boat;
    }

    if (count($candidates) === 0) {
        return null;
    }

    usort($candidates, function ($a, $b) {

        if ($a['combined'] == $b['combined']) {
            return $b['second'] <=> $a['second'];
        }

        return $b['combined'] <=> $a['combined'];
    });

    return $candidates[0];
}


/*
 * ============================================================
 * 強候補の順位
 * ============================================================
 */

function getScoreRank(array $boats, int $lane): int
{
    $sorted = [];

    foreach ($boats as $boat) {

        $boat['combined'] =
            $boat['first']
            + ($boat['second'] * 0.65);

        $sorted[] = $boat;
    }

    usort($sorted, function ($a, $b) {

        if ($a['combined'] == $b['combined']) {
            return $b['second'] <=> $a['second'];
        }

        return $b['combined'] <=> $a['combined'];
    });

    foreach ($sorted as $rank => $boat) {

        if ($boat['lane'] === $lane) {
            return $rank + 1;
        }
    }

    return 99;
}


/*
 * ============================================================
 * 全レース比較
 * ============================================================
 */

$currentFavorites = [];
$strongCandidates = [];

$comparison = [];

$strongAvailable = 0;
$strongUnavailable = 0;

$sameBoat = 0;
$differentBoat = 0;

$strongBetter = 0;
$currentBetter = 0;
$sameResult = 0;

foreach ($races as $raceCode => $boats) {

    $current = selectCurrentFavorite($boats);

    $strong = selectStrongCandidate($boats);

    $currentFavorites[] = $current;

    if ($strong === null) {

        $strongUnavailable++;

        continue;
    }

    $strongAvailable++;
    $strongCandidates[] = $strong;

    if ($current['lane'] === $strong['lane']) {
        $sameBoat++;
    } else {
        $differentBoat++;
    }

    /*
     * 強候補と現在本命の着順比較
     */

    if ($strong['actual'] < $current['actual']) {
        $strongBetter++;
    } elseif ($strong['actual'] > $current['actual']) {
        $currentBetter++;
    } else {
        $sameResult++;
    }

    $comparison[] = [
        'race_code' => $raceCode,
        'current' => $current,
        'strong' => $strong
    ];
}


/*
 * ============================================================
 * 【1】基本結果
 * ============================================================
 */

echo "【1】強候補の基本結果\n";
echo "-------------------------------------------------------------------------------\n";

$stats = calcStats($strongCandidates);

echo sprintf(
    "強候補レース数 : %d\n",
    $stats['count']
);

echo sprintf(
    "選抜率         : %s\n",
    pct($stats['count'], count($races))
);

echo sprintf(
    "1着率          : %s\n",
    pct($stats['first'], $stats['count'])
);

echo sprintf(
    "2連対率        : %s\n",
    pct($stats['second'], $stats['count'])
);

echo sprintf(
    "3連対率        : %s\n",
    pct($stats['third'], $stats['count'])
);

echo "\n";

echo sprintf(
    "該当なし       : %d\n",
    $strongUnavailable
);

echo "-------------------------------------------------------------------------------\n\n";


/*
 * ============================================================
 * 【2】現在本命との一致率
 * ============================================================
 */

echo "【2】強候補と現在本命の一致状況\n";
echo "-------------------------------------------------------------------------------\n";

echo sprintf(
    "同じ艇         : %d / %d (%s)\n",
    $sameBoat,
    $strongAvailable,
    pct($sameBoat, $strongAvailable)
);

echo sprintf(
    "異なる艇       : %d / %d (%s)\n",
    $differentBoat,
    $strongAvailable,
    pct($differentBoat, $strongAvailable)
);

echo "-------------------------------------------------------------------------------\n\n";


/*
 * ============================================================
 * 【3】強候補 vs 現在本命
 * ============================================================
 */

echo "【3】強候補 vs 現在本命 着順比較\n";
echo "-------------------------------------------------------------------------------\n";

echo sprintf(
    "強候補の方が上位 : %d\n",
    $strongBetter
);

echo sprintf(
    "現在本命の方が上位 : %d\n",
    $currentBetter
);

echo sprintf(
    "同着             : %d\n",
    $sameResult
);

echo "\n";

$totalComparison =
    $strongBetter
    + $currentBetter
    + $sameResult;

echo sprintf(
    "強候補勝率       : %s\n",
    pct($strongBetter, $totalComparison)
);

echo sprintf(
    "現在本命勝率     : %s\n",
    pct($currentBetter, $totalComparison)
);

echo "-------------------------------------------------------------------------------\n\n";


/*
 * ============================================================
 * 【4】現在本命と強候補の成績比較
 * ============================================================
 */

echo "【4】現在本命 vs 強候補 成績比較\n";
echo "-------------------------------------------------------------------------------\n";

$currentStats = calcStats(
    array_map(
        function ($item) {
            return $item['current'];
        },
        $comparison
    )
);

$strongStats = calcStats(
    array_map(
        function ($item) {
            return $item['strong'];
        },
        $comparison
    )
);

echo sprintf(
    "%-16s %10s %10s %10s %10s\n",
    "",
    "件数",
    "1着率",
    "2連対率",
    "3連対率"
);

echo "-------------------------------------------------------------------------------\n";

echo sprintf(
    "%-16s %10d %10s %10s %10s\n",
    "現在本命",
    $currentStats['count'],
    pct($currentStats['first'], $currentStats['count']),
    pct($currentStats['second'], $currentStats['count']),
    pct($currentStats['third'], $currentStats['count'])
);

echo sprintf(
    "%-16s %10d %10s %10s %10s\n",
    "強候補",
    $strongStats['count'],
    pct($strongStats['first'], $strongStats['count']),
    pct($strongStats['second'], $strongStats['count']),
    pct($strongStats['third'], $strongStats['count'])
);

echo "-------------------------------------------------------------------------------\n\n";


/*
 * ============================================================
 * 【5】強候補が現在本命と異なる場合
 *
 * 強候補が何位だったのかを確認
 * ============================================================
 */

echo "【5】強候補が現在本命と異なったケース\n";
echo "-------------------------------------------------------------------------------\n";

$differentCases = [];

foreach ($comparison as $item) {

    if ($item['current']['lane'] === $item['strong']['lane']) {
        continue;
    }

    $currentRank = getScoreRank(
        $races[$item['race_code']],
        $item['current']['lane']
    );

    $strongRank = getScoreRank(
        $races[$item['race_code']],
        $item['strong']['lane']
    );

    $differentCases[] = [
        'race_code' => $item['race_code'],
        'current' => $item['current'],
        'strong' => $item['strong'],
        'current_rank' => $currentRank,
        'strong_rank' => $strongRank
    ];
}

echo sprintf(
    "異なるケース : %d\n\n",
    count($differentCases)
);

echo sprintf(
    "%-16s %5s %8s %8s %8s %8s %8s\n",
    "レース",
    "艇",
    "現在一次",
    "現在二次",
    "強候補艇",
    "強一次",
    "強二次"
);

echo "-------------------------------------------------------------------------------\n";

/*
 * 最初の30件だけ詳細表示
 */

$displayCount = 0;

foreach ($differentCases as $case) {

    echo sprintf(
        "%-16s %5d %8.1f %8.1f %8d %8.1f %8.1f\n",
        $case['race_code'],
        $case['current']['lane'],
        $case['current']['first'],
        $case['current']['second'],
        $case['strong']['lane'],
        $case['strong']['first'],
        $case['strong']['second']
    );

    $displayCount++;

    if ($displayCount >= 30) {
        break;
    }
}

if (count($differentCases) > 30) {
    echo "... 以下省略 ...\n";
}

echo "-------------------------------------------------------------------------------\n\n";


/*
 * ============================================================
 * 【6】強候補が1着だったケース
 * ============================================================
 */

echo "【6】強候補が1着だったケース\n";
echo "-------------------------------------------------------------------------------\n";

$strongFirstCases = [];

foreach ($comparison as $item) {

    if ($item['strong']['actual'] === 1) {
        $strongFirstCases[] = $item;
    }
}

echo sprintf(
    "強候補1着 : %d / %d (%s)\n",
    count($strongFirstCases),
    count($comparison),
    pct(count($strongFirstCases), count($comparison))
);

echo "\n";

/*
 * 現在本命も1着だったケース
 */

$bothFirst = 0;
$strongOnlyFirst = 0;
$currentOnlyFirst = 0;
$neitherFirst = 0;

foreach ($comparison as $item) {

    $strongFirstFlag =
        ($item['strong']['actual'] === 1);

    $currentFirstFlag =
        ($item['current']['actual'] === 1);

    if ($strongFirstFlag && $currentFirstFlag) {
        $bothFirst++;
    } elseif ($strongFirstFlag) {
        $strongOnlyFirst++;
    } elseif ($currentFirstFlag) {
        $currentOnlyFirst++;
    } else {
        $neitherFirst++;
    }
}

echo sprintf(
    "両方1着       : %d\n",
    $bothFirst
);

echo sprintf(
    "強候補のみ1着 : %d\n",
    $strongOnlyFirst
);

echo sprintf(
    "現在本命のみ1着 : %d\n",
    $currentOnlyFirst
);

echo sprintf(
    "どちらも1着でない : %d\n",
    $neitherFirst
);

echo "-------------------------------------------------------------------------------\n\n";


/*
 * ============================================================
 * 【7】強候補の艇番別成績
 * ============================================================
 */

echo "【7】強候補の艇番別成績\n";
echo "-------------------------------------------------------------------------------\n";

for ($lane = 1; $lane <= 6; $lane++) {

    $laneBoats = [];

    foreach ($strongCandidates as $boat) {

        if ($boat['lane'] === $lane) {
            $laneBoats[] = $boat;
        }
    }

    $laneStats = calcStats($laneBoats);

    echo sprintf(
        "%d号艇 : 件数=%4d  1着率=%8s  2連対率=%8s  3連対率=%8s\n",
        $lane,
        $laneStats['count'],
        pct($laneStats['first'], $laneStats['count']),
        pct($laneStats['second'], $laneStats['count']),
        pct($laneStats['third'], $laneStats['count'])
    );
}

echo "-------------------------------------------------------------------------------\n\n";


/*
 * ============================================================
 * 完了
 * ============================================================
 */

echo "========================================\n";
echo "分析完了\n";
echo "========================================\n";