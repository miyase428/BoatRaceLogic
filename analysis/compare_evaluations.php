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

    $races[$raceCode]['boats'][$lane] = [
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
| 終了
|--------------------------------------------------------------------------
*/

echo "\n";
echo "========================================\n";
echo "分析完了\n";
echo "========================================\n";