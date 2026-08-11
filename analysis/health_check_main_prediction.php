<?php
declare(strict_types=1);

/**
 * 現行最終予想 健康診断 STEP 2
 *
 * 本命・対抗・現行買い目の検証
 *
 * 使用CSV:
 *   final_prediction_boats_YYYYMMDD_YYYYMMDD.csv
 *   final_prediction_races_YYYYMMDD_YYYYMMDD.csv
 *
 * 使用方法:
 *
 * php analysis/health_check_main_prediction.php \
 *   analysis/output/final_prediction_boats_20260801_20260808.csv \
 *   analysis/output/final_prediction_races_20260801_20260808.csv
 */


// ============================================================
// 引数チェック
// ============================================================

if ($argc < 3) {

    echo "\n使用方法:\n";
    echo "  php analysis/health_check_main_prediction.php ";
    echo "艇別CSV レース別CSV\n\n";

    exit(1);
}


$boatsCsv =
    $argv[1];

$racesCsv =
    $argv[2];


if (!is_file($boatsCsv)) {

    fwrite(
        STDERR,
        "艇別CSVが見つかりません: {$boatsCsv}\n"
    );

    exit(1);
}


if (!is_file($racesCsv)) {

    fwrite(
        STDERR,
        "レース別CSVが見つかりません: {$racesCsv}\n"
    );

    exit(1);
}


// ============================================================
// CSV読み込み関数
// ============================================================

function loadCsv(string $file): array
{
    $fp = fopen($file, 'rb');

    if ($fp === false) {

        throw new RuntimeException(
            "CSVを開けません: {$file}"
        );
    }


    $header = fgetcsv($fp);

    if ($header === false) {

        fclose($fp);

        throw new RuntimeException(
            "CSVヘッダーを読み込めません: {$file}"
        );
    }


    // BOM除去
    if (isset($header[0])) {

        $header[0] =
            preg_replace(
                '/^\xEF\xBB\xBF/',
                '',
                $header[0]
            );
    }


    $rows = [];


    while (($row = fgetcsv($fp)) !== false) {

        if (
            count($row) < count($header)
        ) {
            continue;
        }


        $data = [];

        foreach ($header as $index => $name) {

            $data[$name] =
                $row[$index] ?? '';
        }


        $rows[] =
            $data;
    }


    fclose($fp);

    return $rows;
}


// ============================================================
// 順位判定
// ============================================================

function isRankTop3($rank): bool
{
    if ($rank === '' || $rank === null) {
        return false;
    }

    $rank = (int)$rank;

    return
        $rank >= 1 &&
        $rank <= 3;
}


// ============================================================
// 率計算
// ============================================================

function percent(
    int $count,
    int $total
): string {

    if ($total <= 0) {
        return '-';
    }

    return number_format(
        ($count / $total) * 100,
        2
    ) . '%';
}


// ============================================================
// データ読み込み
// ============================================================

try {

    $boatRows =
        loadCsv($boatsCsv);

    $raceRows =
        loadCsv($racesCsv);

} catch (Throwable $e) {

    fwrite(
        STDERR,
        $e->getMessage() . "\n"
    );

    exit(1);
}


// ============================================================
// 本命・対抗の集計
// ============================================================

$main = [
    'total' => 0,
    'first' => 0,
    'second_or_better' => 0,
    'third_or_better' => 0,
];


$opponent = [
    'total' => 0,
    'first' => 0,
    'second_or_better' => 0,
    'third_or_better' => 0,
];


// ============================================================
// 本命・対抗の艇別結果
// ============================================================

foreach ($boatRows as $row) {

    $actualRank =
        trim(
            $row['actual_rank'] ?? ''
        );

    if ($actualRank === '') {
        continue;
    }

    $actualRank =
        (int)$actualRank;


    // --------------------------------------------------------
    // 本命判定
    // --------------------------------------------------------

    $finalRank =
        (int)(
            $row['final_rank'] ?? 0
        );


    if ($finalRank === 1) {

        $main['total']++;


        if ($actualRank === 1) {
            $main['first']++;
        }


        if ($actualRank <= 2) {
            $main['second_or_better']++;
        }


        if ($actualRank <= 3) {
            $main['third_or_better']++;
        }
    }


    // --------------------------------------------------------
    // 対抗判定
    //
    // race別CSVの taikou_head と一致する艇
    // を後ほど利用するため、
    // ここでは集計しない。
    // --------------------------------------------------------
}


// ============================================================
// レース別 本命・対抗の集計
// ============================================================

$raceCount = 0;
$validRaceCount = 0;


// 本命＋対抗関連
$mainOpponent = [

    'both_top3' => 0,

    'main_first_opponent_second' => 0,

    'main_first_opponent_third' => 0,

    'main_first_opponent_top3' => 0,

    'opponent_first_main_top3' => 0,
];


// 現行買い目
$betStats = [

    'honmei' => [
        'total' => 0,
        'hit' => 0,
    ],

    'taikou' => [
        'total' => 0,
        'hit' => 0,
    ],

    'either' => [
        'total' => 0,
        'hit' => 0,
    ],
];


foreach ($raceRows as $race) {

    $raceCode =
        trim(
            $race['race_code'] ?? ''
        );


    if ($raceCode === '') {
        continue;
    }


    $actual1 =
        (int)(
            $race['actual_1st'] ?? 0
        );

    $actual2 =
        (int)(
            $race['actual_2nd'] ?? 0
        );

    $actual3 =
        (int)(
            $race['actual_3rd'] ?? 0
        );


    if (
        $actual1 < 1 ||
        $actual2 < 1 ||
        $actual3 < 1
    ) {
        continue;
    }


    $validRaceCount++;


    // --------------------------------------------------------
    // 本命・対抗
    // --------------------------------------------------------

    $honmei =
        (int)(
            $race['honmei_head'] ?? 0
        );

    $taikou =
        (int)(
            $race['taikou_head'] ?? 0
        );


    if (
        $honmei >= 1 &&
        $taikou >= 1
    ) {

        $mainTop3 =
            in_array(
                $honmei,
                [$actual1, $actual2, $actual3],
                true
            );


        $opponentTop3 =
            in_array(
                $taikou,
                [$actual1, $actual2, $actual3],
                true
            );


        // 本命・対抗ともに3着以内
        if (
            $mainTop3 &&
            $opponentTop3
        ) {

            $mainOpponent['both_top3']++;
        }


        // 本命1着＋対抗2着
        if (
            $actual1 === $honmei &&
            $actual2 === $taikou
        ) {

            $mainOpponent[
                'main_first_opponent_second'
            ]++;
        }


        // 本命1着＋対抗3着
        if (
            $actual1 === $honmei &&
            $actual3 === $taikou
        ) {

            $mainOpponent[
                'main_first_opponent_third'
            ]++;
        }


        // 本命1着＋対抗3着以内
        if (
            $actual1 === $honmei &&
            $opponentTop3
        ) {

            $mainOpponent[
                'main_first_opponent_top3'
            ]++;
        }


        // 対抗1着＋本命3着以内
        if (
            $actual1 === $taikou &&
            $mainTop3
        ) {

            $mainOpponent[
                'opponent_first_main_top3'
            ]++;
        }
    }


    // --------------------------------------------------------
    // 現行買い目
    // --------------------------------------------------------

    $actualTrifecta =
        $actual1
        . '-'
        . $actual2
        . '-'
        . $actual3;


    $honmeiKai =
        trim(
            $race['honmei_kai'] ?? ''
        );


    $taikouKai =
        trim(
            $race['taikou_kai'] ?? ''
        );


    // 本命買い目
    if ($honmeiKai !== '') {

        $betStats['honmei']['total']++;


        if (
            strpos(
                ',' . str_replace(
                    '・',
                    ',',
                    $honmeiKai
                ) . ',',
                ',' . $actualTrifecta . ','
            ) !== false
        ) {

            $betStats['honmei']['hit']++;
        }
    }


    // --------------------------------------------------------
    // ただし、honmei_kai は
    // 「1-235-2345」のようなフォーメーション形式。
    //
    // そのため上記の単純比較では正確な判定にならない。
    //
    // 下の関数で正式判定する。
    // --------------------------------------------------------

    $honmeiHit =
        isTrifectaFormationHit(
            $honmeiKai,
            $actual1,
            $actual2,
            $actual3
        );


    $taikouHit =
        isTrifectaFormationHit(
            $taikouKai,
            $actual1,
            $actual2,
            $actual3
        );


    $betStats['honmei']['total']++;

    if ($honmeiHit) {
        $betStats['honmei']['hit']++;
    }


    $betStats['taikou']['total']++;

    if ($taikouHit) {
        $betStats['taikou']['hit']++;
    }


    $betStats['either']['total']++;

    if (
        $honmeiHit ||
        $taikouHit
    ) {

        $betStats['either']['hit']++;
    }


    $raceCount++;
}


// ============================================================
// 3連単フォーメーション判定
//
// 例:
//   1-235-2345
//
// なら、
//   1着 = 1
//   2着 = 2,3,5
//   3着 = 2,3,4,5
//
// ただし同一艇が同じレースで複数着にはならないため、
// 実際の着順との一致を確認する。
// ============================================================

function isTrifectaFormationHit(
    string $formation,
    int $actual1,
    int $actual2,
    int $actual3
): bool {

    if ($formation === '') {
        return false;
    }


    $formation =
        str_replace(
            [' ', '　'],
            '',
            $formation
        );


    $parts =
        explode(
            '-',
            $formation
        );


    if (count($parts) !== 3) {
        return false;
    }


    $first =
        str_split($parts[0]);

    $second =
        str_split($parts[1]);

    $third =
        str_split($parts[2]);


    return
        in_array(
            (string)$actual1,
            $first,
            true
        )
        &&
        in_array(
            (string)$actual2,
            $second,
            true
        )
        &&
        in_array(
            (string)$actual3,
            $third,
            true
        );
}


// ============================================================
// 本命・対抗の率
// ============================================================

$mainRate =
    [
        'first' =>
            percent(
                $main['first'],
                $main['total']
            ),

        'second_or_better' =>
            percent(
                $main['second_or_better'],
                $main['total']
            ),

        'third_or_better' =>
            percent(
                $main['third_or_better'],
                $main['total']
            ),
    ];


$opponentRate =
    [
        'first' =>
            percent(
                $opponent['first'],
                $opponent['total']
            ),

        'second_or_better' =>
            percent(
                $opponent['second_or_better'],
                $opponent['total']
            ),

        'third_or_better' =>
            percent(
                $opponent['third_or_better'],
                $opponent['total']
            ),
    ];


// ============================================================
// 結果表示
// ============================================================

echo "\n";
echo "========================================\n";
echo "現行最終予想 健康診断 STEP 2\n";
echo "========================================\n";

echo "対象レース : {$validRaceCount}\n";
echo "\n";


echo "========================================\n";
echo "本命 成績\n";
echo "========================================\n";

echo "対象       : {$main['total']}\n";
echo "1着        : {$main['first']} ({$mainRate['first']})\n";
echo "2連対      : {$main['second_or_better']} ({$mainRate['second_or_better']})\n";
echo "3連対      : {$main['third_or_better']} ({$mainRate['third_or_better']})\n";

echo "\n";


echo "========================================\n";
echo "対抗 成績\n";
echo "========================================\n";

echo "対象       : {$opponent['total']}\n";
echo "1着        : {$opponent['first']} ({$opponentRate['first']})\n";
echo "2連対      : {$opponent['second_or_better']} ({$opponentRate['second_or_better']})\n";
echo "3連対      : {$opponent['third_or_better']} ({$opponentRate['third_or_better']})\n";

echo "\n";


echo "========================================\n";
echo "本命・対抗 組み合わせ\n";
echo "========================================\n";

echo "本命・対抗ともに3着以内 : "
    . $mainOpponent['both_top3']
    . " ("
    . percent(
        $mainOpponent['both_top3'],
        $validRaceCount
    )
    . ")\n";


echo "本命1着 ＋ 対抗2着      : "
    . $mainOpponent['main_first_opponent_second']
    . " ("
    . percent(
        $mainOpponent['main_first_opponent_second'],
        $validRaceCount
    )
    . ")\n";


echo "本命1着 ＋ 対抗3着      : "
    . $mainOpponent['main_first_opponent_third']
    . " ("
    . percent(
        $mainOpponent['main_first_opponent_third'],
        $validRaceCount
    )
    . ")\n";


echo "本命1着 ＋ 対抗3着以内 : "
    . $mainOpponent['main_first_opponent_top3']
    . " ("
    . percent(
        $mainOpponent['main_first_opponent_top3'],
        $validRaceCount
    )
    . ")\n";


echo "対抗1着 ＋ 本命3着以内 : "
    . $mainOpponent['opponent_first_main_top3']
    . " ("
    . percent(
        $mainOpponent['opponent_first_main_top3'],
        $validRaceCount
    )
    . ")\n";

echo "\n";


echo "========================================\n";
echo "現行3連単買い目\n";
echo "========================================\n";


echo "本命買い目 : "
    . $betStats['honmei']['hit']
    . " / "
    . $betStats['honmei']['total']
    . " ("
    . percent(
        $betStats['honmei']['hit'],
        $betStats['honmei']['total']
    )
    . ")\n";


echo "対抗買い目 : "
    . $betStats['taikou']['hit']
    . " / "
    . $betStats['taikou']['total']
    . " ("
    . percent(
        $betStats['taikou']['hit'],
        $betStats['taikou']['total']
    )
    . ")\n";


echo "どちらか的中 : "
    . $betStats['either']['hit']
    . " / "
    . $betStats['either']['total']
    . " ("
    . percent(
        $betStats['either']['hit'],
        $betStats['either']['total']
    )
    . ")\n";


echo "\n";
echo "========================================\n";
echo "STEP 2 完了\n";
echo "========================================\n\n";