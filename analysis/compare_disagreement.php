<?php

/**
 * 一次1位と二次1位が異なるレースを分析
 *
 * 確認する内容:
 *  1. 一次1位 = 二次1位
 *  2. 一次1位 ≠ 二次1位
 *     - 統合1位 = 一次1位
 *     - 統合1位 = 二次1位
 *     - 統合1位がどちらでもない
 *
 * 各ケースについて、
 *  一次1位・二次1位・統合1位の実着順を比較する。
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
    'actual_rank',
    'first_rank',
    'second_rank',
    'final_rank',
];

foreach ($requiredColumns as $column) {
    if (!isset($headerMap[$column])) {
        echo "必要な列がありません: {$column}\n";
        echo "CSVの列名:\n";
        print_r($header);
        exit(1);
    }
}

/**
 * レース単位で保持
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

    $actualRank = (int)$row[$headerMap['actual_rank']];
    $firstRank  = (int)$row[$headerMap['first_rank']];
    $secondRank = (int)$row[$headerMap['second_rank']];
    $finalRank  = (int)$row[$headerMap['final_rank']];

    if (!isset($races[$raceCode])) {
        $races[$raceCode] = [];
    }

    $races[$raceCode][] = [
        'actual_rank' => $actualRank,
        'first_rank'  => $firstRank,
        'second_rank' => $secondRank,
        'final_rank'  => $finalRank,
    ];
}

fclose($fp);


/**
 * 分析用データ
 */
$stats = [
    '一致' => createStats(),
    '統合＝一次' => createStats(),
    '統合＝二次' => createStats(),
    '統合＝どちらでもない' => createStats(),
];


// 不一致レースの詳細
$disagreementDetails = [];


/**
 * レースごとに分析
 */
foreach ($races as $raceCode => $boats) {

    $firstBoat = null;
    $secondBoat = null;
    $finalBoat = null;

    foreach ($boats as $boat) {

        if ($boat['first_rank'] === 1) {
            $firstBoat = $boat;
        }

        if ($boat['second_rank'] === 1) {
            $secondBoat = $boat;
        }

        if ($boat['final_rank'] === 1) {
            $finalBoat = $boat;
        }
    }

    // 3つの1位が取得できない場合はスキップ
    if ($firstBoat === null || $secondBoat === null || $finalBoat === null) {
        continue;
    }

    $firstActual  = $firstBoat['actual_rank'];
    $secondActual = $secondBoat['actual_rank'];
    $finalActual  = $finalBoat['actual_rank'];

    /**
     * 一次1位と二次1位が一致
     */
    if ($firstActual === $secondActual) {

        addCaseResult(
            $stats['一致'],
            $firstActual,
            $secondActual,
            $finalActual
        );

        continue;
    }

    /**
     * 不一致
     *
     * 「艇そのもの」が同じかを判定する必要があるため、
     * 今回は実着順ではなく配列上の対象艇を比較する。
     *
     * race_code内で同じ行を参照できるよう、
     * actual_rankだけでは判定しない。
     */

    // 一次1位と二次1位が別艇かどうか
    // 実着順が違う場合は必ず別艇なので、
    // このCSVでは actual_rank の違いを使って判定する。
    //
    // ただし同着データなど特殊ケースがある場合は、
    // 後で boat_number 等を使った判定に変更可能。

    if ($firstActual !== $secondActual) {

        if ($finalActual === $firstActual) {

            addCaseResult(
                $stats['統合＝一次'],
                $firstActual,
                $secondActual,
                $finalActual
            );

            $case = '統合＝一次';

        } elseif ($finalActual === $secondActual) {

            addCaseResult(
                $stats['統合＝二次'],
                $firstActual,
                $secondActual,
                $finalActual
            );

            $case = '統合＝二次';

        } else {

            addCaseResult(
                $stats['統合＝どちらでもない'],
                $firstActual,
                $secondActual,
                $finalActual
            );

            $case = '統合＝どちらでもない';
        }

        $disagreementDetails[] = [
            'race_code' => $raceCode,
            'case' => $case,
            'first_actual' => $firstActual,
            'second_actual' => $secondActual,
            'final_actual' => $finalActual,
        ];
    }
}


/**
 * 全体サマリー
 */
echo "========================================\n";
echo "全体サマリー\n";
echo "========================================\n";

foreach ($stats as $caseName => $stat) {

    echo "\n----------------------------------------\n";
    echo "{$caseName}\n";
    echo "----------------------------------------\n";

    if ($stat['count'] === 0) {
        echo "件数 : 0\n";
        continue;
    }

    echo sprintf(
        "件数       : %d\n",
        $stat['count']
    );

    echo "\n";

    printResult(
        '一次1位',
        $stat['first']
    );

    printResult(
        '二次1位',
        $stat['second']
    );

    printResult(
        '統合1位',
        $stat['final']
    );
}


/**
 * 不一致レースだけの比較
 */
$totalDisagreement = count($disagreementDetails);

echo "\n========================================\n";
echo "一次1位・二次1位 不一致レース\n";
echo "========================================\n";

echo "件数 : {$totalDisagreement}\n\n";

if ($totalDisagreement > 0) {

    $firstWins = 0;
    $secondWins = 0;
    $finalWins = 0;

    foreach ($disagreementDetails as $detail) {

        if ($detail['first_actual'] === 1) {
            $firstWins++;
        }

        if ($detail['second_actual'] === 1) {
            $secondWins++;
        }

        if ($detail['final_actual'] === 1) {
            $finalWins++;
        }
    }

    echo "不一致レースにおける1着数\n";
    echo sprintf(
        "  一次1位   : %d (%.2f%%)\n",
        $firstWins,
        $firstWins / $totalDisagreement * 100
    );

    echo sprintf(
        "  二次1位   : %d (%.2f%%)\n",
        $secondWins,
        $secondWins / $totalDisagreement * 100
    );

    echo sprintf(
        "  統合1位   : %d (%.2f%%)\n",
        $finalWins,
        $finalWins / $totalDisagreement * 100
    );
}


/**
 * 統合が一次を選んだケース
 */
printDetailedCase(
    '統合＝一次',
    $stats['統合＝一次']
);

/**
 * 統合が二次を選んだケース
 */
printDetailedCase(
    '統合＝二次',
    $stats['統合＝二次']
);

/**
 * 統合がどちらでもないケース
 */
printDetailedCase(
    '統合＝どちらでもない',
    $stats['統合＝どちらでもない']
);


/**
 * 統計オブジェクト作成
 */
function createStats(): array
{
    return [
        'count' => 0,

        'first' => [
            'count' => 0,
            'first' => 0,
            'second' => 0,
            'third' => 0,
            'sum' => 0,
        ],

        'second' => [
            'count' => 0,
            'first' => 0,
            'second' => 0,
            'third' => 0,
            'sum' => 0,
        ],

        'final' => [
            'count' => 0,
            'first' => 0,
            'second' => 0,
            'third' => 0,
            'sum' => 0,
        ],
    ];
}


/**
 * ケースに結果を追加
 */
function addCaseResult(
    array &$stat,
    int $firstActual,
    int $secondActual,
    int $finalActual
): void {

    $stat['count']++;

    addActual($stat['first'], $firstActual);
    addActual($stat['second'], $secondActual);
    addActual($stat['final'], $finalActual);
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
        $data['first']++;
    }

    if ($actualRank === 2) {
        $data['second']++;
    }

    if ($actualRank === 3) {
        $data['third']++;
    }
}


/**
 * 結果表示
 */
function printResult(string $name, array $data): void
{
    if ($data['count'] === 0) {
        echo "{$name} : データなし\n";
        return;
    }

    $count = $data['count'];

    $firstRate = $data['first'] / $count * 100;
    $secondRate = ($data['first'] + $data['second']) / $count * 100;
    $thirdRate = (
        $data['first']
        + $data['second']
        + $data['third']
    ) / $count * 100;

    $avg = $data['sum'] / $count;

    echo sprintf(
        "%-8s 件数=%4d  1着=%6.2f%%  2連対=%6.2f%%  3連対=%6.2f%%  平均着順=%.3f\n",
        $name,
        $count,
        $firstRate,
        $secondRate,
        $thirdRate,
        $avg
    );
}


/**
 * ケース詳細表示
 */
function printDetailedCase(
    string $caseName,
    array $stat
): void {

    if ($stat['count'] === 0) {
        return;
    }

    echo "\n----------------------------------------\n";
    echo "{$caseName} 詳細\n";
    echo "----------------------------------------\n";

    echo "件数 : {$stat['count']}\n\n";

    printResult('一次1位', $stat['first']);
    printResult('二次1位', $stat['second']);
    printResult('統合1位', $stat['final']);
}