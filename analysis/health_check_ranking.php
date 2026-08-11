<?php
declare(strict_types=1);

/**
 * 現行最終予想 健康診断
 *
 * STEP 1
 * 一次評価・二次評価・最終評価について
 * 順位別の1着率・2連対率・3連対率を集計する。
 *
 * 使い方:
 *
 * php analysis/health_check_ranking.php \
 *   analysis/output/final_prediction_boats_20260801_20260808.csv
 */

if ($argc < 2) {
    echo "\n使用方法:\n";
    echo "  php analysis/health_check_ranking.php CSVファイル\n\n";
    exit(1);
}

$csvFile = $argv[1];

if (!is_file($csvFile)) {
    fwrite(
        STDERR,
        "CSVファイルが見つかりません: {$csvFile}\n"
    );
    exit(1);
}


// ============================================================
// CSV読み込み
// ============================================================

$fp = fopen($csvFile, 'rb');

if ($fp === false) {
    fwrite(
        STDERR,
        "CSVファイルを開けません: {$csvFile}\n"
    );
    exit(1);
}

$header = fgetcsv($fp);

if ($header === false) {
    fwrite(
        STDERR,
        "CSVヘッダーを読み込めません。\n"
    );
    exit(1);
}


// UTF-8 BOM除去
if (isset($header[0])) {
    $header[0] = preg_replace(
        '/^\xEF\xBB\xBF/',
        '',
        $header[0]
    );
}


// ヘッダー → index
$columnIndex = [];

foreach ($header as $index => $name) {
    $columnIndex[$name] = $index;
}


// 必須項目
$requiredColumns = [
    'race_code',
    'lane_number',
    'first_rank',
    'second_rank',
    'final_rank',
    'actual_rank',
];

foreach ($requiredColumns as $column) {

    if (!array_key_exists($column, $columnIndex)) {

        fwrite(
            STDERR,
            "必要な列がありません: {$column}\n"
        );

        exit(1);
    }
}


// ============================================================
// 集計用データ
// ============================================================

$evaluations = [
    'first' => [
        1 => ['total' => 0, 'first' => 0, 'second_or_better' => 0, 'third_or_better' => 0],
        2 => ['total' => 0, 'first' => 0, 'second_or_better' => 0, 'third_or_better' => 0],
        3 => ['total' => 0, 'first' => 0, 'second_or_better' => 0, 'third_or_better' => 0],
        4 => ['total' => 0, 'first' => 0, 'second_or_better' => 0, 'third_or_better' => 0],
        5 => ['total' => 0, 'first' => 0, 'second_or_better' => 0, 'third_or_better' => 0],
        6 => ['total' => 0, 'first' => 0, 'second_or_better' => 0, 'third_or_better' => 0],
    ],

    'second' => [
        1 => ['total' => 0, 'first' => 0, 'second_or_better' => 0, 'third_or_better' => 0],
        2 => ['total' => 0, 'first' => 0, 'second_or_better' => 0, 'third_or_better' => 0],
        3 => ['total' => 0, 'first' => 0, 'second_or_better' => 0, 'third_or_better' => 0],
        4 => ['total' => 0, 'first' => 0, 'second_or_better' => 0, 'third_or_better' => 0],
        5 => ['total' => 0, 'first' => 0, 'second_or_better' => 0, 'third_or_better' => 0],
        6 => ['total' => 0, 'first' => 0, 'second_or_better' => 0, 'third_or_better' => 0],
    ],

    'final' => [
        1 => ['total' => 0, 'first' => 0, 'second_or_better' => 0, 'third_or_better' => 0],
        2 => ['total' => 0, 'first' => 0, 'second_or_better' => 0, 'third_or_better' => 0],
        3 => ['total' => 0, 'first' => 0, 'second_or_better' => 0, 'third_or_better' => 0],
        4 => ['total' => 0, 'first' => 0, 'second_or_better' => 0, 'third_or_better' => 0],
        5 => ['total' => 0, 'first' => 0, 'second_or_better' => 0, 'third_or_better' => 0],
        6 => ['total' => 0, 'first' => 0, 'second_or_better' => 0, 'third_or_better' => 0],
    ],
];


// ============================================================
// レース重複防止
//
// 1レースにつき6艇あるため、
// 「評価順位ごとの集計」は各艇をそのまま1件として扱う。
// ============================================================

$raceCodes = [];

$totalBoatRows = 0;
$validBoatRows = 0;


// ============================================================
// CSV処理
// ============================================================

while (($row = fgetcsv($fp)) !== false) {

    if (
        count($row) < count($header)
    ) {
        continue;
    }

    $totalBoatRows++;

    $raceCode =
        trim(
            $row[$columnIndex['race_code']] ?? ''
        );

    $lane =
        (int)(
            $row[$columnIndex['lane_number']] ?? 0
        );

    $actualRankValue =
        trim(
            $row[$columnIndex['actual_rank']] ?? ''
        );

    // 結果がない艇は除外
    if (
        $raceCode === '' ||
        $lane < 1 ||
        $lane > 6 ||
        $actualRankValue === ''
    ) {
        continue;
    }

    $actualRank =
        (int)$actualRankValue;

    if (
        $actualRank < 1 ||
        $actualRank > 6
    ) {
        continue;
    }

    $validBoatRows++;


    // --------------------------------------------------------
    // 各評価の順位
    // --------------------------------------------------------

    $rankValues = [
        'first' =>
            (int)(
                $row[$columnIndex['first_rank']] ?? 0
            ),

        'second' =>
            (int)(
                $row[$columnIndex['second_rank']] ?? 0
            ),

        'final' =>
            (int)(
                $row[$columnIndex['final_rank']] ?? 0
            ),
    ];


    foreach ($rankValues as $evaluation => $rank) {

        if (
            $rank < 1 ||
            $rank > 6
        ) {
            continue;
        }

        $evaluations[$evaluation][$rank]['total']++;


        // 1着
        if ($actualRank === 1) {

            $evaluations[$evaluation][$rank]['first']++;
        }


        // 2連対
        if ($actualRank <= 2) {

            $evaluations[$evaluation][$rank]['second_or_better']++;
        }


        // 3連対
        if ($actualRank <= 3) {

            $evaluations[$evaluation][$rank]['third_or_better']++;
        }
    }


    $raceCodes[$raceCode] = true;
}

fclose($fp);


// ============================================================
// レース数
// ============================================================

$totalRaces =
    count($raceCodes);


// ============================================================
// 表示用関数
// ============================================================

function rate(
    int $count,
    int $total
): string {

    if ($total === 0) {
        return '-';
    }

    return number_format(
        ($count / $total) * 100,
        2
    ) . '%';
}


// ============================================================
// 結果表示
// ============================================================

echo "\n";
echo "========================================\n";
echo "現行最終予想 健康診断\n";
echo "========================================\n";

echo "対象CSV     : {$csvFile}\n";
echo "対象レース  : {$totalRaces}\n";
echo "対象艇      : {$validBoatRows}\n";

echo "\n";


// ============================================================
// 各評価を表示
// ============================================================

$evaluationNames = [
    'first' =>
        '一次評価',

    'second' =>
        '二次評価',

    'final' =>
        '最終評価',
];


foreach ($evaluationNames as $evaluation => $evaluationName) {

    echo "========================================\n";
    echo "{$evaluationName} 順位別成績\n";
    echo "========================================\n";

    printf(
        "%-6s %8s %8s %8s %8s %8s\n",
        '順位',
        '件数',
        '1着数',
        '1着率',
        '2連対率',
        '3連対率'
    );

    echo str_repeat('-', 56) . "\n";


    for ($rank = 1; $rank <= 6; $rank++) {

        $data =
            $evaluations[$evaluation][$rank];

        printf(
            "%-6d %8d %8d %8s %8s %8s\n",

            $rank,

            $data['total'],

            $data['first'],

            rate(
                $data['first'],
                $data['total']
            ),

            rate(
                $data['second_or_better'],
                $data['total']
            ),

            rate(
                $data['third_or_better'],
                $data['total']
            )
        );
    }

    echo "\n";
}


// ============================================================
// CSV出力
// ============================================================

$outputDir =
    dirname($csvFile);

$baseName =
    pathinfo(
        $csvFile,
        PATHINFO_FILENAME
    );

$outputCsv =
    $outputDir
    . '/'
    . $baseName
    . '_health_ranking.csv';


$out =
    fopen(
        $outputCsv,
        'wb'
    );

if ($out !== false) {

    fwrite(
        $out,
        "\xEF\xBB\xBF"
    );


    fputcsv(
        $out,
        [
            'evaluation',
            'rank',
            'count',
            'first_count',
            'first_rate',
            'second_or_better_count',
            'second_or_better_rate',
            'third_or_better_count',
            'third_or_better_rate',
        ]
    );


    foreach (
        $evaluationNames
        as $evaluation => $evaluationName
    ) {

        for ($rank = 1; $rank <= 6; $rank++) {

            $data =
                $evaluations[$evaluation][$rank];

            fputcsv(
                $out,
                [
                    $evaluationName,

                    $rank,

                    $data['total'],

                    $data['first'],

                    rate(
                        $data['first'],
                        $data['total']
                    ),

                    $data['second_or_better'],

                    rate(
                        $data['second_or_better'],
                        $data['total']
                    ),

                    $data['third_or_better'],

                    rate(
                        $data['third_or_better'],
                        $data['total']
                    ),
                ]
            );
        }
    }


    fclose($out);

    echo "集計CSV:\n";
    echo "{$outputCsv}\n";
}

echo "\n";