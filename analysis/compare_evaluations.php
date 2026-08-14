<?php
declare(strict_types=1);

/**
 * 一次評価・二次評価・最終評価 比較分析
 *
 * 使用方法:
 *   php analysis/compare_evaluations.php analysis/output/final_prediction_boats_20260801_20260808.csv
 *
 * 比較対象:
 *   一次評価順位 → 二次評価順位 → 最終評価順位 → 実着順
 *
 * 主な分析:
 *   1. 一次・二次・最終の1位艇の成績比較
 *   2. 最終1位艇が一次評価で何位だったか
 *   3. 一次順位 → 最終順位の遷移
 *   4. 一次 → 二次 → 最終の1位への経路
 *   5. 一次1位を最終で下げたケース
 *   6. 一次2位以下を最終1位に上げたケース
 */

if ($argc !== 2) {
    fwrite(STDERR, "使用方法:\n");
    fwrite(
        STDERR,
        "  php analysis/compare_evaluations.php <boats_csv>\n"
    );
    exit(1);
}

$csvPath = $argv[1];

if (!is_file($csvPath)) {
    fwrite(STDERR, "CSVファイルがありません: {$csvPath}\n");
    exit(1);
}

$fp = fopen($csvPath, 'rb');

if ($fp === false) {
    fwrite(STDERR, "CSVファイルを開けません: {$csvPath}\n");
    exit(1);
}

/**
 * CSVヘッダー
 */
$header = fgetcsv($fp);

if ($header === false) {
    fclose($fp);
    fwrite(STDERR, "CSVが空です。\n");
    exit(1);
}

// BOM除去
if (isset($header[0])) {
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
}

$index = array_flip($header);

/**
 * 必須列
 */
$requiredColumns = [
    'race_code',
    'lane_number',
    'first_total_score',
    'second_score',
    'first_rank',
    'second_rank',
    'final_rank',
    'actual_rank',
];

foreach ($requiredColumns as $column) {
    if (!array_key_exists($column, $index)) {
        fclose($fp);

        fwrite(
            STDERR,
            "必要な列がありません: {$column}\n"
        );

        exit(1);
    }
}

/**
 * レース単位で6艇をまとめる
 */
$races = [];

while (($row = fgetcsv($fp)) !== false) {

    if (
        count($row) === 1 &&
        trim((string)$row[0]) === ''
    ) {
        continue;
    }

    $raceCode = trim(
        (string)($row[$index['race_code']] ?? '')
    );

    $lane = (int)(
        $row[$index['lane_number']] ?? 0
    );

    if (
        $raceCode === '' ||
        $lane < 1 ||
        $lane > 6
    ) {
        continue;
    }

    /**
     * 整数値取得
     */
    $getInt = static function (
        string $name
    ) use ($row, $index): ?int {

        $value = trim(
            (string)($row[$index[$name]] ?? '')
        );

        if ($value === '') {
            return null;
        }

        return (int)$value;
    };

    $getFloat = static function (
        string $name
    ) use ($row, $index): ?float {

        $value = trim(
            (string)($row[$index[$name]] ?? '')
        );

        if ($value === '') {
            return null;
        }

        return (float)$value;
    };

    $races[$raceCode]['boats'][$lane] = [
        'first_score'  => $getFloat('first_total_score'),
        'second_score' => $getFloat('second_score'),

        'first'  => $getInt('first_rank'),
        'second' => $getInt('second_rank'),
        'final'  => $getInt('final_rank'),
        'actual' => $getInt('actual_rank'),
    ];
}

fclose($fp);

/**
 * 6艇揃っているレースだけ対象
 */
$validRaces = [];

foreach ($races as $raceCode => $race) {

    if (
        isset($race['boats']) &&
        count($race['boats']) === 6
    ) {
        $validRaces[$raceCode] = $race['boats'];
    }
}

$totalRaces = count($validRaces);

if ($totalRaces === 0) {
    fwrite(
        STDERR,
        "6艇揃った有効レースがありません。\n"
    );

    exit(1);
}

/**
 * パーセント表示
 */
function pct(int $n, int $d): string
{
    if ($d <= 0) {
        return '-';
    }

    return number_format(
        $n * 100 / $d,
        2
    ) . '%';
}

/**
 * 指定評価の1位艇の成績
 */
function getFirstRankStats(
    array $boats,
    string $rankKey
): array {

    $stats = [
        'count' => 0,
        'first' => 0,
        'top2'  => 0,
        'top3'  => 0,
    ];

    foreach ($boats as $boat) {

        if (($boat[$rankKey] ?? null) !== 1) {
            continue;
        }

        $actual = $boat['actual'] ?? null;

        if ($actual === null) {
            continue;
        }

        $stats['count']++;

        if ($actual === 1) {
            $stats['first']++;
        }

        if ($actual <= 2) {
            $stats['top2']++;
        }

        if ($actual <= 3) {
            $stats['top3']++;
        }
    }

    return $stats;
}

/**
 * 成績表示
 */
function printStats(
    string $label,
    array $stats
): void {

    echo sprintf(
        "%s: 件数=%d, 1着率=%s, 2連対率=%s, 3連対率=%s\n",
        $label,
        $stats['count'],
        pct(
            $stats['first'],
            $stats['count']
        ),
        pct(
            $stats['top2'],
            $stats['count']
        ),
        pct(
            $stats['top3'],
            $stats['count']
        )
    );
}

echo "\n";
echo "========================================\n";
echo "一次・二次・最終評価 比較分析\n";
echo "========================================\n";
echo "CSV : {$csvPath}\n";
echo "有効レース : {$totalRaces}\n";
echo "========================================\n";


/*
|--------------------------------------------------------------------------
| 1. 各評価1位艇の成績
|--------------------------------------------------------------------------
*/

echo "\n";
echo "【1】各評価1位艇の実績\n";

$firstStats = [
    'count' => 0,
    'first' => 0,
    'top2'  => 0,
    'top3'  => 0,
];

$secondStats = [
    'count' => 0,
    'first' => 0,
    'top2'  => 0,
    'top3'  => 0,
];

$finalStats = [
    'count' => 0,
    'first' => 0,
    'top2'  => 0,
    'top3'  => 0,
];

foreach ($validRaces as $boats) {

    $stats = getFirstRankStats(
        $boats,
        'first'
    );

    foreach ($stats as $key => $value) {
        $firstStats[$key] += $value;
    }

    $stats = getFirstRankStats(
        $boats,
        'second'
    );

    foreach ($stats as $key => $value) {
        $secondStats[$key] += $value;
    }

    $stats = getFirstRankStats(
        $boats,
        'final'
    );

    foreach ($stats as $key => $value) {
        $finalStats[$key] += $value;
    }
}

printStats(
    '一次評価1位',
    $firstStats
);

printStats(
    '二次評価1位',
    $secondStats
);

printStats(
    '最終評価1位',
    $finalStats
);


/*
|--------------------------------------------------------------------------
| 2. 最終評価1位艇の一次評価順位
|--------------------------------------------------------------------------
*/

echo "\n";
echo "【2】最終評価1位艇の一次評価順位\n";

$origin = [];

for ($rank = 1; $rank <= 6; $rank++) {

    $origin[$rank] = [
        'count' => 0,
        'first' => 0,
        'top2'  => 0,
        'top3'  => 0,
    ];
}

foreach ($validRaces as $boats) {

    foreach ($boats as $boat) {

        if (($boat['final'] ?? null) !== 1) {
            continue;
        }

        $firstRank = $boat['first'] ?? null;

        if (
            $firstRank === null ||
            $firstRank < 1 ||
            $firstRank > 6
        ) {
            continue;
        }

        $actual = $boat['actual'];

        $origin[$firstRank]['count']++;

        if ($actual === 1) {
            $origin[$firstRank]['first']++;
        }

        if (
            $actual !== null &&
            $actual <= 2
        ) {
            $origin[$firstRank]['top2']++;
        }

        if (
            $actual !== null &&
            $actual <= 3
        ) {
            $origin[$firstRank]['top3']++;
        }

        break;
    }
}

for ($rank = 1; $rank <= 6; $rank++) {

    $stats = $origin[$rank];

    echo sprintf(
        "一次%d位 → 最終1位: 件数=%d, 1着率=%s, 2連対率=%s, 3連対率=%s\n",
        $rank,
        $stats['count'],
        pct(
            $stats['first'],
            $stats['count']
        ),
        pct(
            $stats['top2'],
            $stats['count']
        ),
        pct(
            $stats['top3'],
            $stats['count']
        )
    );
}


/*
|--------------------------------------------------------------------------
| 3. 一次順位 → 最終順位
|--------------------------------------------------------------------------
*/

echo "\n";
echo "【3】一次順位 → 最終順位（件数）\n";

$matrix = [];

for ($first = 1; $first <= 6; $first++) {

    for ($final = 1; $final <= 6; $final++) {
        $matrix[$first][$final] = 0;
    }
}

foreach ($validRaces as $boats) {

    foreach ($boats as $boat) {

        $first = $boat['first'] ?? null;
        $final = $boat['final'] ?? null;

        if (
            $first !== null &&
            $final !== null &&
            $first >= 1 &&
            $first <= 6 &&
            $final >= 1 &&
            $final <= 6
        ) {
            $matrix[$first][$final]++;
        }
    }
}

echo "        最終1  最終2  最終3  最終4  最終5  最終6\n";

for ($first = 1; $first <= 6; $first++) {

    echo sprintf(
        "一次%d位 ",
        $first
    );

    for ($final = 1; $final <= 6; $final++) {

        echo sprintf(
            "%7d",
            $matrix[$first][$final]
        );
    }

    echo "\n";
}


/*
|--------------------------------------------------------------------------
| 4. 一次 → 二次 → 最終1位への経路
|--------------------------------------------------------------------------
*/

echo "\n";
echo "【4】最終1位への経路（一次→二次→最終）\n";

$paths = [];

foreach ($validRaces as $boats) {

    foreach ($boats as $boat) {

        if (($boat['final'] ?? null) !== 1) {
            continue;
        }

        $first = $boat['first'] ?? null;
        $second = $boat['second'] ?? null;

        $key =
            ($first ?? '-') .
            '→' .
            ($second ?? '-') .
            '→1';

        if (!isset($paths[$key])) {

            $paths[$key] = [
                'count' => 0,
                'first' => 0,
                'top2'  => 0,
                'top3'  => 0,
            ];
        }

        $paths[$key]['count']++;

        $actual = $boat['actual'];

        if ($actual === 1) {
            $paths[$key]['first']++;
        }

        if (
            $actual !== null &&
            $actual <= 2
        ) {
            $paths[$key]['top2']++;
        }

        if (
            $actual !== null &&
            $actual <= 3
        ) {
            $paths[$key]['top3']++;
        }

        break;
    }
}

/**
 * 一次順位 → 二次順位の順でソート
 */
uksort(
    $paths,
    static function (
        string $a,
        string $b
    ): int {

        $pa = array_map(
            'intval',
            preg_split('/→/', $a)
        );

        $pb = array_map(
            'intval',
            preg_split('/→/', $b)
        );

        return [
            $pa[0],
            $pa[1]
        ] <=> [
            $pb[0],
            $pb[1]
        ];
    }
);

foreach ($paths as $path => $stats) {

    echo sprintf(
        "%s: 件数=%d, 1着率=%s, 2連対率=%s, 3連対率=%s\n",
        $path,
        $stats['count'],
        pct(
            $stats['first'],
            $stats['count']
        ),
        pct(
            $stats['top2'],
            $stats['count']
        ),
        pct(
            $stats['top3'],
            $stats['count']
        )
    );
}


/*
|--------------------------------------------------------------------------
| 5. 重要ケース
|--------------------------------------------------------------------------
*/

echo "\n";
echo "【5】重要ケース\n";

$cases = [

    'first1_final1' => [
        'count' => 0,
        'first' => 0,
        'top2'  => 0,
        'top3'  => 0,
    ],

    'first1_final2plus' => [
        'count' => 0,
        'first' => 0,
        'top2'  => 0,
        'top3'  => 0,
    ],

    'first2plus_final1' => [
        'count' => 0,
        'first' => 0,
        'top2'  => 0,
        'top3'  => 0,
    ],
];

foreach ($validRaces as $boats) {

    foreach ($boats as $boat) {

        $first = $boat['first'] ?? null;
        $final = $boat['final'] ?? null;

        if (
            $first === null ||
            $final === null
        ) {
            continue;
        }

        if (
            $first === 1 &&
            $final === 1
        ) {

            $key = 'first1_final1';

        } elseif (
            $first === 1 &&
            $final >= 2
        ) {

            $key = 'first1_final2plus';

        } elseif (
            $first >= 2 &&
            $final === 1
        ) {

            $key = 'first2plus_final1';

        } else {

            continue;
        }

        $cases[$key]['count']++;

        $actual = $boat['actual'];

        if ($actual === 1) {
            $cases[$key]['first']++;
        }

        if (
            $actual !== null &&
            $actual <= 2
        ) {
            $cases[$key]['top2']++;
        }

        if (
            $actual !== null &&
            $actual <= 3
        ) {
            $cases[$key]['top3']++;
        }
    }
}

printStats(
    '一次1位 → 最終1位',
    $cases['first1_final1']
);

printStats(
    '一次1位 → 最終2位以下',
    $cases['first1_final2plus']
);

printStats(
    '一次2位以下 → 最終1位',
    $cases['first2plus_final1']
);

/*
|--------------------------------------------------------------------------
| 6. 一次評価1位が二次評価でどう動いたか
|--------------------------------------------------------------------------
*/

echo "\n";
echo "【6】一次評価1位 → 二次評価順位\n";

$first1SecondStats = [];

for ($secondRank = 1; $secondRank <= 6; $secondRank++) {
    $first1SecondStats[$secondRank] = [
        'count' => 0,
        'first' => 0,
        'top2'  => 0,
        'top3'  => 0,
    ];
}

foreach ($validRaces as $boats) {

    foreach ($boats as $boat) {

        if (($boat['first'] ?? null) !== 1) {
            continue;
        }

        $secondRank = $boat['second'] ?? null;

        if (
            $secondRank === null ||
            $secondRank < 1 ||
            $secondRank > 6
        ) {
            continue;
        }

        $actual = $boat['actual'];

        $first1SecondStats[$secondRank]['count']++;

        if ($actual === 1) {
            $first1SecondStats[$secondRank]['first']++;
        }

        if (
            $actual !== null &&
            $actual <= 2
        ) {
            $first1SecondStats[$secondRank]['top2']++;
        }

        if (
            $actual !== null &&
            $actual <= 3
        ) {
            $first1SecondStats[$secondRank]['top3']++;
        }

        break;
    }
}

for ($secondRank = 1; $secondRank <= 6; $secondRank++) {

    $stats = $first1SecondStats[$secondRank];

    echo sprintf(
        "一次1位 → 二次%d位: 件数=%d, 1着率=%s, 2連対率=%s, 3連対率=%s\n",
        $secondRank,
        $stats['count'],
        pct(
            $stats['first'],
            $stats['count']
        ),
        pct(
            $stats['top2'],
            $stats['count']
        ),
        pct(
            $stats['top3'],
            $stats['count']
        )
    );
}


/*
|--------------------------------------------------------------------------
| 7. 一次1位 → 二次順位 → 最終順位
|--------------------------------------------------------------------------
*/

echo "\n";
echo "【7】一次1位 → 二次順位 → 最終順位\n";

$first1Path = [];

for ($secondRank = 1; $secondRank <= 6; $secondRank++) {

    for ($finalRank = 1; $finalRank <= 6; $finalRank++) {

        $first1Path[$secondRank][$finalRank] = 0;
    }
}

foreach ($validRaces as $boats) {

    foreach ($boats as $boat) {

        if (($boat['first'] ?? null) !== 1) {
            continue;
        }

        $secondRank = $boat['second'] ?? null;
        $finalRank = $boat['final'] ?? null;

        if (
            $secondRank === null ||
            $finalRank === null ||
            $secondRank < 1 ||
            $secondRank > 6 ||
            $finalRank < 1 ||
            $finalRank > 6
        ) {
            continue;
        }

        $first1Path[$secondRank][$finalRank]++;

        break;
    }
}

echo "二次\\最終    1      2      3      4      5      6\n";

for ($secondRank = 1; $secondRank <= 6; $secondRank++) {

    echo sprintf(
        "二次%d位 ",
        $secondRank
    );

    for ($finalRank = 1; $finalRank <= 6; $finalRank++) {

        echo sprintf(
            "%7d",
            $first1Path[$secondRank][$finalRank]
        );
    }

    echo "\n";
}


/*
|--------------------------------------------------------------------------
| 8. 一次1位 → 二次順位 → 最終1位になった場合の実績
|--------------------------------------------------------------------------
*/

echo "\n";
echo "【8】一次1位 → 二次順位 → 最終1位 の実績\n";

$first1SecondFinal1 = [];

for ($secondRank = 1; $secondRank <= 6; $secondRank++) {

    $first1SecondFinal1[$secondRank] = [
        'count' => 0,
        'first' => 0,
        'top2'  => 0,
        'top3'  => 0,
    ];
}

foreach ($validRaces as $boats) {

    foreach ($boats as $boat) {

        if (($boat['first'] ?? null) !== 1) {
            continue;
        }

        if (($boat['final'] ?? null) !== 1) {
            continue;
        }

        $secondRank = $boat['second'] ?? null;

        if (
            $secondRank === null ||
            $secondRank < 1 ||
            $secondRank > 6
        ) {
            continue;
        }

        $actual = $boat['actual'];

        $first1SecondFinal1[$secondRank]['count']++;

        if ($actual === 1) {
            $first1SecondFinal1[$secondRank]['first']++;
        }

        if (
            $actual !== null &&
            $actual <= 2
        ) {
            $first1SecondFinal1[$secondRank]['top2']++;
        }

        if (
            $actual !== null &&
            $actual <= 3
        ) {
            $first1SecondFinal1[$secondRank]['top3']++;
        }

        break;
    }
}

for ($secondRank = 1; $secondRank <= 6; $secondRank++) {

    $stats = $first1SecondFinal1[$secondRank];

    echo sprintf(
        "一次1位 → 二次%d位 → 最終1位: 件数=%d, 1着率=%s, 2連対率=%s, 3連対率=%s\n",
        $secondRank,
        $stats['count'],
        pct(
            $stats['first'],
            $stats['count']
        ),
        pct(
            $stats['top2'],
            $stats['count']
        ),
        pct(
            $stats['top3'],
            $stats['count']
        )
    );
}

/*
|--------------------------------------------------------------------------
| 9. 一次評価 × 二次評価 36パターン分析
|--------------------------------------------------------------------------
*/

echo "\n";
echo "【9】一次評価 × 二次評価 36パターン\n";

$combinationStats = [];

for ($firstRank = 1; $firstRank <= 6; $firstRank++) {

    for ($secondRank = 1; $secondRank <= 6; $secondRank++) {

        $combinationStats[$firstRank][$secondRank] = [
            'count' => 0,
            'first' => 0,
            'top2'  => 0,
            'top3'  => 0,
        ];
    }
}

foreach ($validRaces as $boats) {

    foreach ($boats as $boat) {

        $firstRank = $boat['first'] ?? null;
        $secondRank = $boat['second'] ?? null;
        $actual = $boat['actual'] ?? null;

        if (
            $firstRank === null ||
            $secondRank === null ||
            $firstRank < 1 ||
            $firstRank > 6 ||
            $secondRank < 1 ||
            $secondRank > 6
        ) {
            continue;
        }

        $combinationStats[$firstRank][$secondRank]['count']++;

        if ($actual === 1) {
            $combinationStats[$firstRank][$secondRank]['first']++;
        }

        if (
            $actual !== null &&
            $actual <= 2
        ) {
            $combinationStats[$firstRank][$secondRank]['top2']++;
        }

        if (
            $actual !== null &&
            $actual <= 3
        ) {
            $combinationStats[$firstRank][$secondRank]['top3']++;
        }
    }
}


/*
|--------------------------------------------------------------------------
| 件数
|--------------------------------------------------------------------------
*/

echo "\n";
echo "【9-1】件数\n";

echo "一次\\二次";

for ($secondRank = 1; $secondRank <= 6; $secondRank++) {
    echo sprintf("%8d", $secondRank);
}

echo "\n";

for ($firstRank = 1; $firstRank <= 6; $firstRank++) {

    echo sprintf(
        "一次%d位 ",
        $firstRank
    );

    for ($secondRank = 1; $secondRank <= 6; $secondRank++) {

        echo sprintf(
            "%8d",
            $combinationStats[$firstRank][$secondRank]['count']
        );
    }

    echo "\n";
}


/*
|--------------------------------------------------------------------------
| 1着率
|--------------------------------------------------------------------------
*/

echo "\n";
echo "【9-2】1着率\n";

echo "一次\\二次";

for ($secondRank = 1; $secondRank <= 6; $secondRank++) {
    echo sprintf("%8d", $secondRank);
}

echo "\n";

for ($firstRank = 1; $firstRank <= 6; $firstRank++) {

    echo sprintf(
        "一次%d位 ",
        $firstRank
    );

    for ($secondRank = 1; $secondRank <= 6; $secondRank++) {

        $stats =
            $combinationStats[$firstRank][$secondRank];

        echo sprintf(
            "%8s",
            pct(
                $stats['first'],
                $stats['count']
            )
        );
    }

    echo "\n";
}


/*
|--------------------------------------------------------------------------
| 2連対率
|--------------------------------------------------------------------------
*/

echo "\n";
echo "【9-3】2連対率\n";

echo "一次\\二次";

for ($secondRank = 1; $secondRank <= 6; $secondRank++) {
    echo sprintf("%8d", $secondRank);
}

echo "\n";

for ($firstRank = 1; $firstRank <= 6; $firstRank++) {

    echo sprintf(
        "一次%d位 ",
        $firstRank
    );

    for ($secondRank = 1; $secondRank <= 6; $secondRank++) {

        $stats =
            $combinationStats[$firstRank][$secondRank];

        echo sprintf(
            "%8s",
            pct(
                $stats['top2'],
                $stats['count']
            )
        );
    }

    echo "\n";
}


/*
|--------------------------------------------------------------------------
| 3連対率
|--------------------------------------------------------------------------
*/

echo "\n";
echo "【9-4】3連対率\n";

echo "一次\\二次";

for ($secondRank = 1; $secondRank <= 6; $secondRank++) {
    echo sprintf("%8d", $secondRank);
}

echo "\n";

for ($firstRank = 1; $firstRank <= 6; $firstRank++) {

    echo sprintf(
        "一次%d位 ",
        $firstRank
    );

    for ($secondRank = 1; $secondRank <= 6; $secondRank++) {

        $stats =
            $combinationStats[$firstRank][$secondRank];

        echo sprintf(
            "%8s",
            pct(
                $stats['top3'],
                $stats['count']
            )
        );
    }

    echo "\n";
}


/*
|--------------------------------------------------------------------------
| 10. 順位逆転パターン比較
|--------------------------------------------------------------------------
*/

echo "\n";
echo "【10】順位逆転パターン比較\n";

$comparisonPairs = [
    [1, 2, 2, 1],
    [1, 3, 3, 1],
    [1, 4, 4, 1],
    [1, 5, 5, 1],
    [1, 6, 6, 1],
    [2, 3, 3, 2],
    [2, 4, 4, 2],
    [2, 5, 5, 2],
    [2, 6, 6, 2],
    [3, 4, 4, 3],
    [3, 5, 5, 3],
    [3, 6, 6, 3],
    [4, 5, 5, 4],
    [4, 6, 6, 4],
    [5, 6, 6, 5],
];

foreach ($comparisonPairs as $pair) {

    [$f1, $s1, $f2, $s2] = $pair;

    $a =
        $combinationStats[$f1][$s1];

    $b =
        $combinationStats[$f2][$s2];

    echo sprintf(
        "一次%d→二次%d vs 一次%d→二次%d\n",
        $f1,
        $s1,
        $f2,
        $s2
    );

    echo sprintf(
        "  A: 件数=%d, 1着率=%s, 2連対率=%s, 3連対率=%s\n",
        $a['count'],
        pct($a['first'], $a['count']),
        pct($a['top2'], $a['count']),
        pct($a['top3'], $a['count'])
    );

    echo sprintf(
        "  B: 件数=%d, 1着率=%s, 2連対率=%s, 3連対率=%s\n",
        $b['count'],
        pct($b['first'], $b['count']),
        pct($b['top2'], $b['count']),
        pct($b['top3'], $b['count'])
    );
}

/*
|--------------------------------------------------------------------------
| 11. 一次・二次スコア分布
|--------------------------------------------------------------------------
*/

echo "\n";
echo "【11】一次・二次スコア分布\n";

$firstScoreValues = [];
$secondScoreValues = [];

foreach ($validRaces as $boats) {

    foreach ($boats as $boat) {

        $firstScore = $boat['first_score'] ?? null;
        $secondScore = $boat['second_score'] ?? null;

        if ($firstScore !== null) {
            $firstScoreValues[] = $firstScore;
        }

        if ($secondScore !== null) {
            $secondScoreValues[] = $secondScore;
        }
    }
}

if (count($firstScoreValues) > 0) {

    echo sprintf(
        "一次評価スコア: 件数=%d, 最小=%.4f, 最大=%.4f, 平均=%.4f\n",
        count($firstScoreValues),
        min($firstScoreValues),
        max($firstScoreValues),
        array_sum($firstScoreValues) / count($firstScoreValues)
    );
}

if (count($secondScoreValues) > 0) {

    echo sprintf(
        "二次評価スコア: 件数=%d, 最小=%.4f, 最大=%.4f, 平均=%.4f\n",
        count($secondScoreValues),
        min($secondScoreValues),
        max($secondScoreValues),
        array_sum($secondScoreValues) / count($secondScoreValues)
    );
}


/*
|--------------------------------------------------------------------------
| 12. 一次順位 → 二次順位の変化量
|--------------------------------------------------------------------------
*/

echo "\n";
echo "【12】一次順位 → 二次順位の変化量\n";

/*
 * 変化量
 *
 * 0  : 順位変化なし
 * +1 : 1位 → 2位 のように順位を1つ下げた
 * +2 : 2つ下げた
 * -1 : 2位 → 1位 のように順位を1つ上げた
 *
 * 「二次順位 - 一次順位」で計算する。
 */

$rankChangeStats = [];

for ($change = -5; $change <= 5; $change++) {

    $rankChangeStats[$change] = [
        'count' => 0,
        'first' => 0,
        'top2'  => 0,
        'top3'  => 0,
    ];
}

foreach ($validRaces as $boats) {

    foreach ($boats as $boat) {

        $firstRank = $boat['first'] ?? null;
        $secondRank = $boat['second'] ?? null;
        $actual = $boat['actual'] ?? null;

        if (
            $firstRank === null ||
            $secondRank === null ||
            $actual === null
        ) {
            continue;
        }

        $change = $secondRank - $firstRank;

        if (!isset($rankChangeStats[$change])) {
            continue;
        }

        $rankChangeStats[$change]['count']++;

        if ($actual === 1) {
            $rankChangeStats[$change]['first']++;
        }

        if ($actual <= 2) {
            $rankChangeStats[$change]['top2']++;
        }

        if ($actual <= 3) {
            $rankChangeStats[$change]['top3']++;
        }
    }
}

for ($change = -5; $change <= 5; $change++) {

    $stats = $rankChangeStats[$change];

    if ($change < 0) {
        $label = sprintf(
            "%d位上昇",
            abs($change)
        );
    } elseif ($change > 0) {
        $label = sprintf(
            "%d位下降",
            $change
        );
    } else {
        $label = "順位変化なし";
    }

    echo sprintf(
        "%s: 件数=%d, 1着率=%s, 2連対率=%s, 3連対率=%s\n",
        $label,
        $stats['count'],
        pct($stats['first'], $stats['count']),
        pct($stats['top2'], $stats['count']),
        pct($stats['top3'], $stats['count'])
    );
}


/*
|--------------------------------------------------------------------------
| 13. 一次1位 → 二次で下げられた艇のスコア分析
|--------------------------------------------------------------------------
*/

echo "\n";
echo "【13】一次1位 → 二次で下げられた艇のスコア分析\n";

/*
 * 一次1位艇について、
 *
 * 1 → 1
 * 1 → 2
 * 1 → 3
 * ...
 *
 * それぞれの
 *
 * ・一次スコア
 * ・二次スコア
 * ・スコア差
 * ・実績
 *
 * を調べる。
 */

$first1ScoreStats = [];

for ($secondRank = 1; $secondRank <= 6; $secondRank++) {

    $first1ScoreStats[$secondRank] = [
        'count' => 0,

        'first_score_sum' => 0.0,
        'second_score_sum' => 0.0,
        'score_diff_sum' => 0.0,

        'first_score_min' => null,
        'first_score_max' => null,

        'second_score_min' => null,
        'second_score_max' => null,

        'first' => 0,
        'top2'  => 0,
        'top3'  => 0,
    ];
}

foreach ($validRaces as $boats) {

    foreach ($boats as $boat) {

        if (($boat['first'] ?? null) !== 1) {
            continue;
        }

        $secondRank = $boat['second'] ?? null;

        if (
            $secondRank === null ||
            $secondRank < 1 ||
            $secondRank > 6
        ) {
            continue;
        }

        $firstScore = $boat['first_score'] ?? null;
        $secondScore = $boat['second_score'] ?? null;
        $actual = $boat['actual'] ?? null;

        if (
            $firstScore === null ||
            $secondScore === null
        ) {
            continue;
        }

        $stats =& $first1ScoreStats[$secondRank];

        $stats['count']++;

        $stats['first_score_sum'] += $firstScore;
        $stats['second_score_sum'] += $secondScore;

        $scoreDiff =
            $secondScore - $firstScore;

        $stats['score_diff_sum'] += $scoreDiff;

        if (
            $stats['first_score_min'] === null ||
            $firstScore < $stats['first_score_min']
        ) {
            $stats['first_score_min'] = $firstScore;
        }

        if (
            $stats['first_score_max'] === null ||
            $firstScore > $stats['first_score_max']
        ) {
            $stats['first_score_max'] = $firstScore;
        }

        if (
            $stats['second_score_min'] === null ||
            $secondScore < $stats['second_score_min']
        ) {
            $stats['second_score_min'] = $secondScore;
        }

        if (
            $stats['second_score_max'] === null ||
            $secondScore > $stats['second_score_max']
        ) {
            $stats['second_score_max'] = $secondScore;
        }

        if ($actual !== null) {

            if ($actual === 1) {
                $stats['first']++;
            }

            if ($actual <= 2) {
                $stats['top2']++;
            }

            if ($actual <= 3) {
                $stats['top3']++;
            }
        }

        unset($stats);

        /*
         * 一次1位はレース内で1艇だけなので終了
         */
        break;
    }
}

for ($secondRank = 1; $secondRank <= 6; $secondRank++) {

    $stats = $first1ScoreStats[$secondRank];

    $count = $stats['count'];

    if ($count <= 0) {

        echo sprintf(
            "一次1位 → 二次%d位: データなし\n",
            $secondRank
        );

        continue;
    }

    $firstAvg =
        $stats['first_score_sum'] / $count;

    $secondAvg =
        $stats['second_score_sum'] / $count;

    $diffAvg =
        $stats['score_diff_sum'] / $count;

    echo sprintf(
        "一次1位 → 二次%d位: 件数=%d, 一次平均=%.4f, 二次平均=%.4f, 平均変化=%.4f, 一次範囲=%.4f～%.4f, 二次範囲=%.4f～%.4f, 1着率=%s, 2連対率=%s, 3連対率=%s\n",
        $secondRank,
        $count,
        $firstAvg,
        $secondAvg,
        $diffAvg,
        $stats['first_score_min'],
        $stats['first_score_max'],
        $stats['second_score_min'],
        $stats['second_score_max'],
        pct($stats['first'], $count),
        pct($stats['top2'], $count),
        pct($stats['top3'], $count)
    );
}


/*
|--------------------------------------------------------------------------
| 14. 一次順位 × 二次順位 × スコア差
|--------------------------------------------------------------------------
*/

echo "\n";
echo "【14】一次順位 × 二次順位別 スコア差\n";

/*
 * スコア差そのものを評価するのではなく、
 * 「同じ順位パターンの中で二次評価がどれくらい動かしているか」
 * を確認するための診断用。
 */

$scoreDiffPatterns = [];

for ($firstRank = 1; $firstRank <= 6; $firstRank++) {

    for ($secondRank = 1; $secondRank <= 6; $secondRank++) {

        $scoreDiffPatterns[$firstRank][$secondRank] = [
            'count' => 0,
            'sum'   => 0.0,
            'min'   => null,
            'max'   => null,
        ];
    }
}

foreach ($validRaces as $boats) {

    foreach ($boats as $boat) {

        $firstRank = $boat['first'] ?? null;
        $secondRank = $boat['second'] ?? null;

        $firstScore = $boat['first_score'] ?? null;
        $secondScore = $boat['second_score'] ?? null;

        if (
            $firstRank === null ||
            $secondRank === null ||
            $firstScore === null ||
            $secondScore === null
        ) {
            continue;
        }

        $diff =
            $secondScore - $firstScore;

        $stats =&
            $scoreDiffPatterns[$firstRank][$secondRank];

        $stats['count']++;
        $stats['sum'] += $diff;

        if (
            $stats['min'] === null ||
            $diff < $stats['min']
        ) {
            $stats['min'] = $diff;
        }

        if (
            $stats['max'] === null ||
            $diff > $stats['max']
        ) {
            $stats['max'] = $diff;
        }

        unset($stats);
    }
}

echo "一次→二次   件数    平均変化      最小        最大\n";

for ($firstRank = 1; $firstRank <= 6; $firstRank++) {

    for ($secondRank = 1; $secondRank <= 6; $secondRank++) {

        $stats =
            $scoreDiffPatterns[$firstRank][$secondRank];

        if ($stats['count'] <= 0) {
            continue;
        }

        $avg =
            $stats['sum'] / $stats['count'];

        echo sprintf(
            "%d→%d      %4d    %+9.4f    %+9.4f    %+9.4f\n",
            $firstRank,
            $secondRank,
            $stats['count'],
            $avg,
            $stats['min'],
            $stats['max']
        );
    }
}


/*
|--------------------------------------------------------------------------
| 15. 一次1位 → 二次下落幅別の実績
|--------------------------------------------------------------------------
*/

echo "\n";
echo "【15】一次1位 → 二次下落幅別の実績\n";

$dropStats = [];

for ($drop = 0; $drop <= 5; $drop++) {

    $dropStats[$drop] = [
        'count' => 0,
        'first' => 0,
        'top2'  => 0,
        'top3'  => 0,
    ];
}

foreach ($validRaces as $boats) {

    foreach ($boats as $boat) {

        if (($boat['first'] ?? null) !== 1) {
            continue;
        }

        $secondRank = $boat['second'] ?? null;
        $actual = $boat['actual'] ?? null;

        if (
            $secondRank === null ||
            $actual === null
        ) {
            continue;
        }

        $drop = $secondRank - 1;

        if ($drop < 0 || $drop > 5) {
            continue;
        }

        $dropStats[$drop]['count']++;

        if ($actual === 1) {
            $dropStats[$drop]['first']++;
        }

        if ($actual <= 2) {
            $dropStats[$drop]['top2']++;
        }

        if ($actual <= 3) {
            $dropStats[$drop]['top3']++;
        }

        break;
    }
}

for ($drop = 0; $drop <= 5; $drop++) {

    $stats = $dropStats[$drop];

    if ($drop === 0) {
        $label = "一次1位 → 二次1位";
    } else {
        $label = sprintf(
            "一次1位 → %d位下落",
            $drop
        );
    }

    echo sprintf(
        "%s: 件数=%d, 1着率=%s, 2連対率=%s, 3連対率=%s\n",
        $label,
        $stats['count'],
        pct($stats['first'], $stats['count']),
        pct($stats['top2'], $stats['count']),
        pct($stats['top3'], $stats['count'])
    );
}

/*
|--------------------------------------------------------------------------
| 終了
|--------------------------------------------------------------------------
*/

echo "\n";
echo "========================================\n";
echo "分析完了\n";
echo "========================================\n";