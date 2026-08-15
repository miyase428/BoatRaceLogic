<?php

/**
 * 一次1位・二次1位 不一致レース分析
 *
 * lane_number を使って「同じ艇か」を正確に判定する。
 *
 * 使用例:
 * php analysis/compare_disagreement.php analysis/output/final_prediction_boats_20260801_20260808.csv
 */

if ($argc < 2) {
    echo "Usage: php compare_disagreement.php <CSVファイル>\n";
    exit(1);
}

$csvFile = $argv[1];

if (!file_exists($csvFile)) {
    echo "CSVファイルが見つかりません: {$csvFile}\n";
    exit(1);
}

echo "========================================\n";
echo "一次1位・二次1位 不一致レース分析\n";
echo "========================================\n";
echo "CSV : {$csvFile}\n\n";

$fp = fopen($csvFile, 'r');

if ($fp === false) {
    echo "CSVを開けませんでした。\n";
    exit(1);
}

// ヘッダー
$header = fgetcsv($fp);

if ($header === false) {
    echo "CSVが空です。\n";
    exit(1);
}

// BOM除去
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
    'lane_number',
    'actual_rank',
    'first_rank',
    'second_rank',
    'final_rank',
];

foreach ($requiredColumns as $column) {
    if (!isset($headerMap[$column])) {
        echo "必要な列がありません: {$column}\n";
        exit(1);
    }
}


/**
 * レース単位でデータ保持
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

    $boat = [
        'lane_number' => (int)$row[$headerMap['lane_number']],
        'actual_rank' => (int)$row[$headerMap['actual_rank']],
        'first_rank'  => (int)$row[$headerMap['first_rank']],
        'second_rank' => (int)$row[$headerMap['second_rank']],
        'final_rank'  => (int)$row[$headerMap['final_rank']],
    ];

    if (!isset($races[$raceCode])) {
        $races[$raceCode] = [];
    }

    $races[$raceCode][] = $boat;
}

fclose($fp);


/**
 * 統計
 */
$stats = [
    '一致' => createStats(),
    '不一致_統合一次' => createStats(),
    '不一致_統合二次' => createStats(),
    '不一致_統合その他' => createStats(),
];


// 一次 vs 二次の勝敗
$disagreementWinner = [
    '一次の方が実着順上位' => 0,
    '二次の方が実着順上位' => 0,
    '同着' => 0,
];


/**
 * レース分析
 */
foreach ($races as $raceCode => $boats) {

    $firstBoat = findRankOne($boats, 'first_rank');
    $secondBoat = findRankOne($boats, 'second_rank');
    $finalBoat = findRankOne($boats, 'final_rank');

    // 3つとも存在しなければスキップ
    if ($firstBoat === null || $secondBoat === null || $finalBoat === null) {
        continue;
    }

    $firstLane = $firstBoat['lane_number'];
    $secondLane = $secondBoat['lane_number'];
    $finalLane = $finalBoat['lane_number'];

    $firstActual = $firstBoat['actual_rank'];
    $secondActual = $secondBoat['actual_rank'];
    $finalActual = $finalBoat['actual_rank'];

    /**
     * 一次1位と二次1位が一致
     */
    if ($firstLane === $secondLane) {

        addCaseResult(
            $stats['一致'],
            $firstActual,
            $secondActual,
            $finalActual
        );

        continue;
    }


    /**
     * ここから不一致
     */

    // 一次と二次、どちらが実着順で上だったか
    if ($firstActual < $secondActual) {

        $disagreementWinner['一次の方が実着順上位']++;

    } elseif ($secondActual < $firstActual) {

        $disagreementWinner['二次の方が実着順上位']++;

    } else {

        $disagreementWinner['同着']++;
    }


    /**
     * 統合がどちらを採用したか
     */
    if ($finalLane === $firstLane) {

        addCaseResult(
            $stats['不一致_統合一次'],
            $firstActual,
            $secondActual,
            $finalActual
        );

    } elseif ($finalLane === $secondLane) {

        addCaseResult(
            $stats['不一致_統合二次'],
            $firstActual,
            $secondActual,
            $finalActual
        );

    } else {

        addCaseResult(
            $stats['不一致_統合その他'],
            $firstActual,
            $secondActual,
            $finalActual
        );
    }
}


/**
 * ========================================
 * 全体サマリー
 * ========================================
 */

echo "========================================\n";
echo "全体サマリー\n";
echo "========================================\n";

printCaseSummary(
    '一次1位・二次1位が一致',
    $stats['一致']
);

printCaseSummary(
    '不一致・統合1位＝一次1位',
    $stats['不一致_統合一次']
);

printCaseSummary(
    '不一致・統合1位＝二次1位',
    $stats['不一致_統合二次']
);

printCaseSummary(
    '不一致・統合1位＝どちらでもない',
    $stats['不一致_統合その他']
);


/**
 * ========================================
 * 不一致レース全体
 * ========================================
 */

$disagreementCount =
    $stats['不一致_統合一次']['count']
    + $stats['不一致_統合二次']['count']
    + $stats['不一致_統合その他']['count'];

echo "\n========================================\n";
echo "一次1位・二次1位 不一致レース\n";
echo "========================================\n";

echo "件数 : {$disagreementCount}\n\n";

if ($disagreementCount > 0) {

    echo "実着順で良かった方\n";

    foreach ($disagreementWinner as $name => $count) {

        printf(
            "  %-22s : %4d (%.2f%%)\n",
            $name,
            $count,
            $count / $disagreementCount * 100
        );
    }
}


/**
 * ========================================
 * 統合判断の正解率
 * ========================================
 */

$integrationCorrect = 0;
$integrationWrong = 0;
$integrationOther = 0;

foreach ($races as $raceCode => $boats) {

    $firstBoat = findRankOne($boats, 'first_rank');
    $secondBoat = findRankOne($boats, 'second_rank');
    $finalBoat = findRankOne($boats, 'final_rank');

    if (
        $firstBoat === null ||
        $secondBoat === null ||
        $finalBoat === null
    ) {
        continue;
    }

    if (
        $firstBoat['lane_number'] ===
        $secondBoat['lane_number']
    ) {
        continue;
    }

    $firstLane = $firstBoat['lane_number'];
    $secondLane = $secondBoat['lane_number'];
    $finalLane = $finalBoat['lane_number'];

    $firstActual = $firstBoat['actual_rank'];
    $secondActual = $secondBoat['actual_rank'];
    $finalActual = $finalBoat['actual_rank'];

    /**
     * 統合が一次を選択
     */
    if ($finalLane === $firstLane) {

        if ($firstActual < $secondActual) {
            $integrationCorrect++;
        } else {
            $integrationWrong++;
        }

    /**
     * 統合が二次を選択
     */
    } elseif ($finalLane === $secondLane) {

        if ($secondActual < $firstActual) {
            $integrationCorrect++;
        } else {
            $integrationWrong++;
        }

    /**
     * どちらでもない
     */
    } else {

        $integrationOther++;
    }
}

echo "\n========================================\n";
echo "統合判断の方向性\n";
echo "========================================\n";

$judgedCount = $integrationCorrect + $integrationWrong;

echo "一次・二次のどちらかを選択したケース : {$judgedCount}\n";

if ($judgedCount > 0) {

    printf(
        "実着順で良い方を選択              : %d (%.2f%%)\n",
        $integrationCorrect,
        $integrationCorrect / $judgedCount * 100
    );

    printf(
        "実着順で悪い方を選択              : %d (%.2f%%)\n",
        $integrationWrong,
        $integrationWrong / $judgedCount * 100
    );
}

printf(
    "どちらでもない                    : %d\n",
    $integrationOther
);


/**
 * ========================================
 * 関数
 * ========================================
 */

function createStats(): array
{
    return [
        'count' => 0,

        'first' => [
            'count' => 0,
            'rank1' => 0,
            'rank2' => 0,
            'rank3' => 0,
            'sum' => 0,
        ],

        'second' => [
            'count' => 0,
            'rank1' => 0,
            'rank2' => 0,
            'rank3' => 0,
            'sum' => 0,
        ],

        'final' => [
            'count' => 0,
            'rank1' => 0,
            'rank2' => 0,
            'rank3' => 0,
            'sum' => 0,
        ],
    ];
}


/**
 * 指定した順位列が1の艇を取得
 */
function findRankOne(array $boats, string $rankColumn): ?array
{
    foreach ($boats as $boat) {

        if ($boat[$rankColumn] === 1) {
            return $boat;
        }
    }

    return null;
}


/**
 * ケースに結果を追加
 */
function addCaseResult(
    array &$stats,
    int $firstActual,
    int $secondActual,
    int $finalActual
): void {

    $stats['count']++;

    addActual($stats['first'], $firstActual);
    addActual($stats['second'], $secondActual);
    addActual($stats['final'], $finalActual);
}


/**
 * 実着順を追加
 */
function addActual(array &$data, int $actualRank): void
{
    if ($actualRank <= 0) {
        return;
    }

    $data['count']++;
    $data['sum'] += $actualRank;

    if ($actualRank === 1) {
        $data['rank1']++;
    }

    if ($actualRank === 2) {
        $data['rank2']++;
    }

    if ($actualRank === 3) {
        $data['rank3']++;
    }
}


/**
 * ケース表示
 */
function printCaseSummary(
    string $title,
    array $stats
): void {

    echo "\n----------------------------------------\n";
    echo "{$title}\n";
    echo "----------------------------------------\n";

    if ($stats['count'] === 0) {
        echo "件数 : 0\n";
        return;
    }

    echo "件数 : {$stats['count']}\n\n";

    printResult('一次1位', $stats['first']);
    printResult('二次1位', $stats['second']);
    printResult('統合1位', $stats['final']);
}


/**
 * 結果表示
 */
function printResult(
    string $name,
    array $data
): void {

    if ($data['count'] === 0) {
        echo "{$name} : データなし\n";
        return;
    }

    $count = $data['count'];

    $firstRate =
        $data['rank1'] / $count * 100;

    $secondRate =
        ($data['rank1'] + $data['rank2'])
        / $count * 100;

    $thirdRate =
        (
            $data['rank1']
            + $data['rank2']
            + $data['rank3']
        )
        / $count * 100;

    $avg =
        $data['sum'] / $count;

    printf(
        "%-8s 件数=%4d  1着=%6.2f%%  2連対=%6.2f%%  3連対=%6.2f%%  平均着順=%.3f\n",
        $name,
        $count,
        $firstRate,
        $secondRate,
        $thirdRate,
        $avg
    );
}