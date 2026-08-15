<?php

/**
 * STEP 3-6
 *
 * 現在の統合1位 vs 一次1位
 * 一次優勢条件で「統合1位 → 一次1位」に変更した場合の
 * 仮想シミュレーション
 *
 * 対象条件
 *   ① 一次 2～5 × 二次 0～2
 *   ② 一次 5以上 × 二次 0～2
 *
 * 比較
 *   現在の統合1位
 *   ↓
 *   一次1位へ変更
 *
 * 実着順でどちらが良いかを比較する
 */


// ============================================================
// 引数チェック
// ============================================================

if ($argc < 2) {
    echo "Usage: php simulate_primary_override.php <CSVファイル>\n";
    exit(1);
}

$csvFile = $argv[1];

if (!file_exists($csvFile)) {
    echo "CSVファイルが見つかりません: {$csvFile}\n";
    exit(1);
}


// ============================================================
// 開始
// ============================================================

echo "========================================\n";
echo "STEP 3-6 統合1位 → 一次1位 仮変更シミュレーション\n";
echo "========================================\n";
echo "CSV : {$csvFile}\n\n";


// ============================================================
// CSV読み込み
// ============================================================

$fp = fopen($csvFile, 'r');

if ($fp === false) {
    echo "CSVを開けませんでした。\n";
    exit(1);
}


// PHP 8.4対応
$header = fgetcsv($fp, null, ',', '"', '');

if ($header === false) {
    echo "CSVが空です。\n";
    fclose($fp);
    exit(1);
}


// BOM除去
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);


// ============================================================
// ヘッダーマップ
// ============================================================

$map = [];

foreach ($header as $i => $name) {
    $map[trim($name)] = $i;
}


// ============================================================
// 必須列
// ============================================================

$required = [
    'race_code',
    'lane_number',
    'first_total_score',
    'first_rank',
    'second_score',
    'second_rank',
    'final_rank',
    'actual_rank',
];

foreach ($required as $column) {

    if (!array_key_exists($column, $map)) {

        echo "必要な列がありません: {$column}\n";
        echo "\n現在のCSVヘッダー:\n";

        foreach ($map as $name => $index) {
            echo "  {$name}\n";
        }

        fclose($fp);
        exit(1);
    }
}


// ============================================================
// レース単位でデータ格納
// ============================================================

$races = [];

$totalRows = 0;

while (($row = fgetcsv($fp, null, ',', '"', '')) !== false) {

    if (count($row) !== count($header)) {
        continue;
    }

    $totalRows++;

    $raceCode = trim($row[$map['race_code']]);

    if ($raceCode === '') {
        continue;
    }


    $races[$raceCode][] = [

        'lane_number' =>
            (int)$row[$map['lane_number']],

        'first_total_score' =>
            (float)$row[$map['first_total_score']],

        'first_rank' =>
            (int)$row[$map['first_rank']],

        'second_score' =>
            (float)$row[$map['second_score']],

        'second_rank' =>
            (int)$row[$map['second_rank']],

        'final_rank' =>
            (int)$row[$map['final_rank']],

        'actual_rank' =>
            (int)$row[$map['actual_rank']],
    ];
}

fclose($fp);


// ============================================================
// 読み込み確認
// ============================================================

echo "読み込みレース数 : " . count($races) . "\n";
echo "読み込み艇数     : {$totalRows}\n";


// ============================================================
// 統計作成
// ============================================================

function createStats(): array
{
    return [
        'count' => 0,
        'rank1' => 0,
        'rank2' => 0,
        'rank3' => 0,
        'sum' => 0.0,
    ];
}


// ============================================================
// 実着順を統計へ追加
// ============================================================

function addStats(
    array &$stats,
    int $actualRank
): void {

    if ($actualRank <= 0) {
        return;
    }

    $stats['count']++;

    $stats['sum'] += $actualRank;

    if ($actualRank === 1) {
        $stats['rank1']++;
    }

    if ($actualRank === 2) {
        $stats['rank2']++;
    }

    if ($actualRank === 3) {
        $stats['rank3']++;
    }
}


// ============================================================
// パーセント
// ============================================================

function pct(
    int $value,
    int $total
): float {

    if ($total <= 0) {
        return 0.0;
    }

    return ($value / $total) * 100;
}


// ============================================================
// 統計表示
// ============================================================

function printStats(
    string $name,
    array $stats
): void {

    echo "----------------------------------------\n";
    echo "{$name}\n";
    echo "----------------------------------------\n";

    $count = $stats['count'];

    if ($count <= 0) {
        echo "件数       : 0\n";
        return;
    }


    printf(
        "件数       : %d\n",
        $count
    );

    printf(
        "1着        : %.2f%%\n",
        pct(
            $stats['rank1'],
            $count
        )
    );

    printf(
        "2連対      : %.2f%%\n",
        pct(
            $stats['rank1'] + $stats['rank2'],
            $count
        )
    );

    printf(
        "3連対      : %.2f%%\n",
        pct(
            $stats['rank1']
            + $stats['rank2']
            + $stats['rank3'],
            $count
        )
    );

    printf(
        "平均着順   : %.3f\n",
        $stats['sum'] / $count
    );
}


// ============================================================
// 分析条件
// ============================================================

$conditions = [

    '一次 2～5 × 二次 0～2' => [

        'first_min' => 2.0,
        'first_max' => 5.0,

        'second_min' => 0.0,
        'second_max' => 2.0,
    ],

    '一次 5以上 × 二次 0～2' => [

        'first_min' => 5.0,
        'first_max' => PHP_FLOAT_MAX,

        'second_min' => 0.0,
        'second_max' => 2.0,
    ],
];


// ============================================================
// 条件ごとの分析
// ============================================================

foreach ($conditions as $conditionName => $condition) {

    echo "\n";
    echo "========================================\n";
    echo "{$conditionName}\n";
    echo "========================================\n";


    // --------------------------------------------------------
    // 統計
    // --------------------------------------------------------

    $current = createStats();

    $override = createStats();


    // --------------------------------------------------------
    // レース数
    // --------------------------------------------------------

    $targetRaces = 0;


    // --------------------------------------------------------
    // 変更数
    // --------------------------------------------------------

    $changed = 0;

    $unchanged = 0;


    // --------------------------------------------------------
    // 実着順比較
    // --------------------------------------------------------

    $overrideBetter = 0;

    $currentBetter = 0;

    $same = 0;


    // --------------------------------------------------------
    // 対象外
    // --------------------------------------------------------

    $skipNoFirst1 = 0;

    $skipNoFirst2 = 0;

    $skipNoSecond1 = 0;

    $skipNoSecond2 = 0;

    $skipNoFinal1 = 0;

    $skipSameBoat = 0;


    // ========================================================
    // レースループ
    // ========================================================

    foreach ($races as $raceCode => $boats) {


        // ----------------------------------------------------
        // 各順位の艇
        // ----------------------------------------------------

        $first1 = null;

        $first2 = null;

        $second1 = null;

        $second2 = null;

        $final1 = null;


        // ----------------------------------------------------
        // 探索
        // ----------------------------------------------------

        foreach ($boats as $boat) {

            if ($boat['first_rank'] === 1) {
                $first1 = $boat;
            }

            if ($boat['first_rank'] === 2) {
                $first2 = $boat;
            }

            if ($boat['second_rank'] === 1) {
                $second1 = $boat;
            }

            if ($boat['second_rank'] === 2) {
                $second2 = $boat;
            }

            if ($boat['final_rank'] === 1) {
                $final1 = $boat;
            }
        }


        // ----------------------------------------------------
        // 必須データ確認
        // ----------------------------------------------------

        if ($first1 === null) {
            $skipNoFirst1++;
            continue;
        }

        if ($first2 === null) {
            $skipNoFirst2++;
            continue;
        }

        if ($second1 === null) {
            $skipNoSecond1++;
            continue;
        }

        if ($second2 === null) {
            $skipNoSecond2++;
            continue;
        }

        if ($final1 === null) {
            $skipNoFinal1++;
            continue;
        }


        // ----------------------------------------------------
        // 一次1位と二次1位が同じ艇なら対象外
        // ----------------------------------------------------

        if (
            $first1['lane_number']
            ===
            $second1['lane_number']
        ) {

            $skipSameBoat++;

            continue;
        }


        // ----------------------------------------------------
        // 一次1位-2位スコア差
        // ----------------------------------------------------

        $firstGap =
            $first1['first_total_score']
            -
            $first2['first_total_score'];


        // ----------------------------------------------------
        // 二次1位-2位スコア差
        // ----------------------------------------------------

        $secondGap =
            $second1['second_score']
            -
            $second2['second_score'];


        // ----------------------------------------------------
        // 条件判定
        // ----------------------------------------------------

        if (
            $firstGap < $condition['first_min']
            ||
            $firstGap >= $condition['first_max']
            ||
            $secondGap < $condition['second_min']
            ||
            $secondGap >= $condition['second_max']
        ) {
            continue;
        }


        // ----------------------------------------------------
        // 対象レース
        // ----------------------------------------------------

        $targetRaces++;


        // ----------------------------------------------------
        // 現在の統合1位
        // ----------------------------------------------------

        $currentActual =
            $final1['actual_rank'];


        // ----------------------------------------------------
        // 一次1位へ変更した場合
        // ----------------------------------------------------

        $overrideActual =
            $first1['actual_rank'];


        // ----------------------------------------------------
        // 統計
        // ----------------------------------------------------

        addStats(
            $current,
            $currentActual
        );

        addStats(
            $override,
            $overrideActual
        );


        // ----------------------------------------------------
        // 実際に艇が変更されるか
        // ----------------------------------------------------

        if (
            $final1['lane_number']
            ===
            $first1['lane_number']
        ) {

            $unchanged++;

        } else {

            $changed++;
        }


        // ----------------------------------------------------
        // 実着順比較
        // ----------------------------------------------------

        if ($overrideActual < $currentActual) {

            $overrideBetter++;

        } elseif ($currentActual < $overrideActual) {

            $currentBetter++;

        } else {

            $same++;
        }
    }


    // ========================================================
    // 結果表示
    // ========================================================

    echo "対象レース : {$targetRaces}\n\n";


    // --------------------------------------------------------
    // 現在の統合1位
    // --------------------------------------------------------

    printStats(
        '現在の統合1位',
        $current
    );


    // --------------------------------------------------------
    // 一次1位へ変更
    // --------------------------------------------------------

    printStats(
        '一次1位へ変更',
        $override
    );


    // ========================================================
    // 変更状況
    // ========================================================

    echo "\n";
    echo "----------------------------------------\n";
    echo "仮変更の状況\n";
    echo "----------------------------------------\n";

    printf(
        "実際に艇が変更される : %d\n",
        $changed
    );

    printf(
        "同じ艇のまま         : %d\n",
        $unchanged
    );


    // ========================================================
    // 直接比較
    // ========================================================

    echo "\n";
    echo "実着順直接比較:\n";

    printf(
        "  一次1位へ変更した方が上位 : %4d (%.2f%%)\n",
        $overrideBetter,
        pct(
            $overrideBetter,
            $targetRaces
        )
    );

    printf(
        "  現在の統合1位の方が上位   : %4d (%.2f%%)\n",
        $currentBetter,
        pct(
            $currentBetter,
            $targetRaces
        )
    );

    printf(
        "  同着                       : %4d (%.2f%%)\n",
        $same,
        pct(
            $same,
            $targetRaces
        )
    );


    // ========================================================
    // 改善幅
    // ========================================================

    if (
        $current['count'] > 0
        &&
        $override['count'] > 0
    ) {

        $currentFirstRate =
            pct(
                $current['rank1'],
                $current['count']
            );

        $overrideFirstRate =
            pct(
                $override['rank1'],
                $override['count']
            );


        $currentSecondRate =
            pct(
                $current['rank1']
                +
                $current['rank2'],
                $current['count']
            );

        $overrideSecondRate =
            pct(
                $override['rank1']
                +
                $override['rank2'],
                $override['count']
            );


        $currentThirdRate =
            pct(
                $current['rank1']
                +
                $current['rank2']
                +
                $current['rank3'],
                $current['count']
            );

        $overrideThirdRate =
            pct(
                $override['rank1']
                +
                $override['rank2']
                +
                $override['rank3'],
                $override['count']
            );


        $currentAverage =
            $current['sum']
            /
            $current['count'];

        $overrideAverage =
            $override['sum']
            /
            $override['count'];


        echo "\n";
        echo "----------------------------------------\n";
        echo "改善幅\n";
        echo "----------------------------------------\n";


        printf(
            "1着率       : %+.2fポイント\n",
            $overrideFirstRate
            -
            $currentFirstRate
        );


        printf(
            "2連対率     : %+.2fポイント\n",
            $overrideSecondRate
            -
            $currentSecondRate
        );


        printf(
            "3連対率     : %+.2fポイント\n",
            $overrideThirdRate
            -
            $currentThirdRate
        );


        /*
         * 平均着順は小さいほど良い
         */
        printf(
            "平均着順    : %+.3f\n",
            $currentAverage
            -
            $overrideAverage
        );
    }


    // ========================================================
    // デバッグ情報
    // ========================================================

    echo "\n";
    echo "----------------------------------------\n";
    echo "除外状況\n";
    echo "----------------------------------------\n";

    printf(
        "一次1位なし       : %d\n",
        $skipNoFirst1
    );

    printf(
        "一次2位なし       : %d\n",
        $skipNoFirst2
    );

    printf(
        "二次1位なし       : %d\n",
        $skipNoSecond1
    );

    printf(
        "二次2位なし       : %d\n",
        $skipNoSecond2
    );

    printf(
        "統合1位なし       : %d\n",
        $skipNoFinal1
    );

    printf(
        "一次1位＝二次1位  : %d\n",
        $skipSameBoat
    );
}


// ============================================================
// 完了
// ============================================================

echo "\n";
echo "========================================\n";
echo "シミュレーション完了\n";
echo "========================================\n";