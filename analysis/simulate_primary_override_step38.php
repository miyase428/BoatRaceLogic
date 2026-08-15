<?php

/**
 * STEP 3-8
 *
 * 仮ルール:
 *   一次スコア差 5～10 × 二次スコア差 1～2
 *   → 現在の統合1位を一次1位へ変更
 *
 * 目的:
 *   STEP 4「一次評価を最終予想へ組み込む修正」の前に、
 *   仮変更が複数期間で有効かを検証する。
 *
 * 比較:
 *   現在の統合1位
 *   vs
 *   仮変更後の一次1位
 *
 * 出力:
 *   - 対象レース数
 *   - 実際に1位艇が変更される件数
 *   - 1着/2連対/3連対/平均着順
 *   - 実着順直接比較
 *   - 1着を拾った/失った件数
 *   - 3連対を拾った/失った件数
 */

if ($argc < 2) {
    echo "Usage: php simulate_primary_override_step38.php <CSVファイル>\n";
    exit(1);
}

$csvFile = $argv[1];

if (!file_exists($csvFile)) {
    echo "CSVファイルが見つかりません: {$csvFile}\n";
    exit(1);
}

echo "========================================\n";
echo "STEP 3-8 一次5～10 × 二次1～2 仮変更シミュレーション\n";
echo "========================================\n";
echo "CSV : {$csvFile}\n\n";

$fp = fopen($csvFile, 'r');

if ($fp === false) {
    echo "CSVを開けませんでした。\n";
    exit(1);
}

$header = fgetcsv($fp);

if ($header === false) {
    echo "CSVが空です。\n";
    fclose($fp);
    exit(1);
}

/*
 * BOM除去
 */
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

/*
 * ヘッダー → インデックス
 */
$map = [];

foreach ($header as $i => $name) {
    $map[trim($name)] = $i;
}

/*
 * 必須列
 */
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
        fclose($fp);
        exit(1);
    }
}

/*
 * レース単位にまとめる
 */
$races = [];

$totalRows = 0;

while (($row = fgetcsv($fp)) !== false) {

    if (count($row) !== count($header)) {
        continue;
    }

    $totalRows++;

    $raceCode = trim($row[$map['race_code']]);

    if ($raceCode === '') {
        continue;
    }

    $races[$raceCode][] = [
        'lane_number' => (int)$row[$map['lane_number']],

        'first_total_score'
            => (float)$row[$map['first_total_score']],

        'first_rank'
            => (int)$row[$map['first_rank']],

        'second_score'
            => (float)$row[$map['second_score']],

        'second_rank'
            => (int)$row[$map['second_rank']],

        'final_rank'
            => (int)$row[$map['final_rank']],

        'actual_rank'
            => (int)$row[$map['actual_rank']],
    ];
}

fclose($fp);

echo "読み込みレース数 : " . count($races) . "\n";
echo "読み込み艇数     : {$totalRows}\n";

echo "\n";
echo "仮ルール:\n";
echo "  一次スコア差 5～10\n";
echo "  かつ\n";
echo "  二次スコア差 1～2\n";
echo "  ↓\n";
echo "  統合1位を一次1位へ変更\n";

/*
 * 統計初期化
 */
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

/*
 * 統計追加
 */
function addStats(array &$stats, int $actualRank): void
{
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

/*
 * パーセント
 */
function pct(int $value, int $total): float
{
    if ($total <= 0) {
        return 0.0;
    }

    return ($value / $total) * 100;
}

/*
 * 統計表示
 */
function printStats(string $name, array $stats): void
{
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
        pct($stats['rank1'], $count)
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


/*
 * 統計
 */
$current = createStats();
$override = createStats();

/*
 * 対象レース
 */
$targetRaces = 0;

/*
 * 実際に1位艇が変更された件数
 */
$changed = 0;

/*
 * 統合1位と一次1位が同じだった件数
 */
$unchanged = 0;

/*
 * 比較不能
 */
$invalidActual = 0;


/*
 * 直接比較
 */
$overrideBetter = 0;
$currentBetter = 0;
$same = 0;


/*
 * 1着遷移
 */
$current1ToOverride1 = 0;
$current1ToOverrideNon1 = 0;
$currentNon1ToOverride1 = 0;


/*
 * 3連対遷移
 */
$current3ToOverrideNon3 = 0;
$currentNon3ToOverride3 = 0;


/*
 * レースごとに処理
 */
foreach ($races as $raceCode => $boats) {

    $first1 = null;
    $first2 = null;

    $second1 = null;
    $second2 = null;

    $final1 = null;

    /*
     * 一次1位・2位
     * 二次1位・2位
     * 統合1位
     */
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

    /*
     * 必要な順位が揃っていない場合はスキップ
     */
    if (
        $first1 === null ||
        $first2 === null ||
        $second1 === null ||
        $second2 === null ||
        $final1 === null
    ) {
        continue;
    }


    /*
     * 一次スコア差
     *
     * 一次1位 - 一次2位
     */
    $firstGap =
        $first1['first_total_score']
        - $first2['first_total_score'];


    /*
     * 二次スコア差
     *
     * 二次1位 - 二次2位
     */
    $secondGap =
        $second1['second_score']
        - $second2['second_score'];


    /*
     * STEP 3-8 仮ルール
     *
     * 一次 5～10
     * 二次 1～2
     *
     * 下限は含む
     * 上限は含まない
     *
     * つまり
     *
     * 5 <= 一次差 < 10
     * 1 <= 二次差 < 2
     */
    if (
        $firstGap < 5.0 ||
        $firstGap >= 10.0 ||
        $secondGap < 1.0 ||
        $secondGap >= 2.0
    ) {
        continue;
    }


    /*
     * 実着順が取れない場合
     */
    if (
        $first1['actual_rank'] <= 0 ||
        $final1['actual_rank'] <= 0
    ) {
        $invalidActual++;
        continue;
    }


    $targetRaces++;


    /*
     * 現在の統合1位
     */
    $currentActual =
        $final1['actual_rank'];


    /*
     * 仮変更後の一次1位
     */
    $overrideActual =
        $first1['actual_rank'];


    /*
     * 統計追加
     */
    addStats(
        $current,
        $currentActual
    );

    addStats(
        $override,
        $overrideActual
    );


    /*
     * 実際に艇が変わったか
     */
    if (
        $final1['lane_number']
        ===
        $first1['lane_number']
    ) {
        $unchanged++;
    } else {
        $changed++;
    }


    /*
     * 実着順直接比較
     */
    if ($overrideActual < $currentActual) {

        $overrideBetter++;

    } elseif ($currentActual < $overrideActual) {

        $currentBetter++;

    } else {

        $same++;
    }


    /*
     * 1着遷移
     */

    if (
        $currentActual === 1 &&
        $overrideActual === 1
    ) {

        $current1ToOverride1++;

    } elseif (
        $currentActual === 1 &&
        $overrideActual !== 1
    ) {

        $current1ToOverrideNon1++;

    } elseif (
        $currentActual !== 1 &&
        $overrideActual === 1
    ) {

        $currentNon1ToOverride1++;
    }


    /*
     * 3連対遷移
     */

    $currentTop3 =
        $currentActual <= 3;

    $overrideTop3 =
        $overrideActual <= 3;


    if (
        $currentTop3 &&
        !$overrideTop3
    ) {

        $current3ToOverrideNon3++;

    } elseif (
        !$currentTop3 &&
        $overrideTop3
    ) {

        $currentNon3ToOverride3++;
    }
}


/*
 * ========================================
 * 結果表示
 * ========================================
 */

echo "\n";

echo "========================================\n";
echo "STEP 3-8 仮ルール検証結果\n";
echo "========================================\n";

echo "対象レース       : {$targetRaces}\n";
echo "実際に艇が変更   : {$changed}\n";
echo "同じ艇のまま     : {$unchanged}\n";
echo "比較不能         : {$invalidActual}\n";


/*
 * 現在の統合1位
 */
echo "\n";

printStats(
    '現在の統合1位',
    $current
);


/*
 * 一次1位へ変更
 */
echo "\n";

printStats(
    '一次1位へ変更',
    $override
);


/*
 * 直接比較
 */
echo "\n";

echo "----------------------------------------\n";
echo "実着順直接比較\n";
echo "----------------------------------------\n";

printf(
    "一次1位へ変更した方が上位 : %d (%.2f%%)\n",
    $overrideBetter,
    pct(
        $overrideBetter,
        $targetRaces
    )
);

printf(
    "現在の統合1位の方が上位   : %d (%.2f%%)\n",
    $currentBetter,
    pct(
        $currentBetter,
        $targetRaces
    )
);

printf(
    "同着                       : %d (%.2f%%)\n",
    $same,
    pct(
        $same,
        $targetRaces
    )
);


/*
 * 1着遷移
 */
echo "\n";

echo "----------------------------------------\n";
echo "1着遷移\n";
echo "----------------------------------------\n";

printf(
    "統合1位 1着 → 一次1位 1着     : %d\n",
    $current1ToOverride1
);

printf(
    "統合1位 1着 → 一次1位 2着以下 : %d\n",
    $current1ToOverrideNon1
);

printf(
    "統合1位 2着以下 → 一次1位 1着 : %d\n",
    $currentNon1ToOverride1
);


/*
 * 3連対遷移
 */
echo "\n";

echo "----------------------------------------\n";
echo "3連対遷移\n";
echo "----------------------------------------\n";

printf(
    "統合1位 3連対 → 一次1位 3連対外 : %d\n",
    $current3ToOverrideNon3
);

printf(
    "統合1位 3連対外 → 一次1位 3連対 : %d\n",
    $currentNon3ToOverride3
);


/*
 * 改善幅
 */
echo "\n";

echo "----------------------------------------\n";
echo "改善幅\n";
echo "----------------------------------------\n";

if ($targetRaces > 0) {

    /*
     * 1着率
     */
    $currentFirst =
        pct(
            $current['rank1'],
            $current['count']
        );

    $overrideFirst =
        pct(
            $override['rank1'],
            $override['count']
        );


    /*
     * 2連対率
     */
    $currentSecond =
        pct(
            $current['rank1']
            + $current['rank2'],
            $current['count']
        );

    $overrideSecond =
        pct(
            $override['rank1']
            + $override['rank2'],
            $override['count']
        );


    /*
     * 3連対率
     */
    $currentThird =
        pct(
            $current['rank1']
            + $current['rank2']
            + $current['rank3'],
            $current['count']
        );

    $overrideThird =
        pct(
            $override['rank1']
            + $override['rank2']
            + $override['rank3'],
            $override['count']
        );


    /*
     * 平均着順
     */
    $currentAvg =
        $current['sum']
        / $current['count'];

    $overrideAvg =
        $override['sum']
        / $override['count'];


    printf(
        "1着率       : %+.2fポイント\n",
        $overrideFirst - $currentFirst
    );

    printf(
        "2連対率     : %+.2fポイント\n",
        $overrideSecond - $currentSecond
    );

    printf(
        "3連対率     : %+.2fポイント\n",
        $overrideThird - $currentThird
    );

    /*
     * 平均着順は小さい方が良いので
     * 現在 - 変更後
     * とする。
     */
    printf(
        "平均着順    : %+.3f（小さいほど改善）\n",
        $currentAvg - $overrideAvg
    );

} else {

    echo "比較可能な統計なし\n";
}


echo "\n";

echo "========================================\n";
echo "STEP 3-8 完了\n";
echo "========================================\n";