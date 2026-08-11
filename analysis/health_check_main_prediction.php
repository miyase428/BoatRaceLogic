<?php
declare(strict_types=1);

/**
 * 現行最終予想 健康診断 STEP 2
 *
 * 本命・対抗・現行3連単買い目を検証する。
 *
 * 使用方法:
 *
 * php analysis/health_check_main_prediction.php \
 *   analysis/output/final_prediction_boats_20260801_20260808.csv \
 *   analysis/output/final_prediction_races_20260801_20260808.csv
 */


// ============================================================
// CSV読み込み
// ============================================================

function loadCsv(string $file): array
{
    if (!is_file($file)) {
        throw new RuntimeException(
            "CSVが見つかりません: {$file}"
        );
    }

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

    // UTF-8 BOM除去
    if (isset($header[0])) {
        $header[0] = preg_replace(
            '/^\xEF\xBB\xBF/',
            '',
            $header[0]
        );
    }

    $rows = [];

    while (($row = fgetcsv($fp)) !== false) {

        if (count($row) < count($header)) {
            continue;
        }

        $data = [];

        foreach ($header as $i => $name) {
            $data[$name] = $row[$i] ?? '';
        }

        $rows[] = $data;
    }

    fclose($fp);

    return $rows;
}


// ============================================================
// パーセント
// ============================================================

function rate(int $count, int $total): string
{
    if ($total === 0) {
        return '-';
    }

    return number_format(
        ($count / $total) * 100,
        2
    ) . '%';
}


// ============================================================
// 3連単フォーメーション判定
//
// 例:
//   1-256-23456
//
// 1着候補 = 1
// 2着候補 = 2,5,6
// 3着候補 = 2,3,4,5,6
// ============================================================

function parseFormation(string $formation): ?array
{
    $formation = trim($formation);

    if ($formation === '') {
        return null;
    }

    $parts = explode('-', $formation);

    if (count($parts) !== 3) {
        return null;
    }

    $result = [];

    foreach ($parts as $part) {

        $part = trim($part);

        if ($part === '') {
            return null;
        }

        $result[] = str_split($part);
    }

    return $result;
}


// ============================================================
// 3連単的中判定
// ============================================================

function isTrifectaHit(
    string $formation,
    int $actual1,
    int $actual2,
    int $actual3
): bool {

    $parsed = parseFormation($formation);

    if ($parsed === null) {
        return false;
    }

    return
        in_array(
            (string)$actual1,
            $parsed[0],
            true
        )
        &&
        in_array(
            (string)$actual2,
            $parsed[1],
            true
        )
        &&
        in_array(
            (string)$actual3,
            $parsed[2],
            true
        );
}


// ============================================================
// 引数
// ============================================================

if ($argc < 3) {

    echo "\n";
    echo "使用方法:\n";
    echo "php analysis/health_check_main_prediction.php ";
    echo "艇別CSV レース別CSV\n";
    echo "\n";

    exit(1);
}


$boatsCsv = $argv[1];
$racesCsv = $argv[2];


// ============================================================
// CSV読み込み
// ============================================================

try {

    $boatRows = loadCsv($boatsCsv);
    $raceRows = loadCsv($racesCsv);

} catch (Throwable $e) {

    fwrite(
        STDERR,
        $e->getMessage() . "\n"
    );

    exit(1);
}


// ============================================================
// STEP 2 集計
// ============================================================

$totalRaces = 0;


// ------------------------------------------------------------
// 本命
// ------------------------------------------------------------

$honmei = [
    'first' => 0,
    'second_or_better' => 0,
    'third_or_better' => 0,
];


// ------------------------------------------------------------
// 対抗
// ------------------------------------------------------------

$taikou = [
    'first' => 0,
    'second_or_better' => 0,
    'third_or_better' => 0,
];


// ------------------------------------------------------------
// 本命＋対抗
// ------------------------------------------------------------

$combination = [
    'both_top3' => 0,

    'honmei_first_taikou_second' => 0,

    'honmei_first_taikou_third' => 0,

    'honmei_first_taikou_top3' => 0,

    'taikou_first_honmei_top3' => 0,
];


// ------------------------------------------------------------
// 現行3連単買い目
// ------------------------------------------------------------

$bets = [
    'honmei' => [
        'count' => 0,
        'hit' => 0,
    ],

    'taikou' => [
        'count' => 0,
        'hit' => 0,
    ],

    'either' => [
        'count' => 0,
        'hit' => 0,
    ],
];


// ============================================================
// レース別集計
// ============================================================

foreach ($raceRows as $race) {

    $raceCode = trim(
        $race['race_code'] ?? ''
    );

    if ($raceCode === '') {
        continue;
    }


    $honmeiHead = (int)(
        $race['honmei_head'] ?? 0
    );

    $taikouHead = (int)(
        $race['taikou_head'] ?? 0
    );


    $actual1 = (int)(
        $race['actual_1st'] ?? 0
    );

    $actual2 = (int)(
        $race['actual_2nd'] ?? 0
    );

    $actual3 = (int)(
        $race['actual_3rd'] ?? 0
    );


    // 結果がないレースは除外
    if (
        $actual1 < 1 ||
        $actual2 < 1 ||
        $actual3 < 1
    ) {
        continue;
    }


    $totalRaces++;


    // ========================================================
    // 本命
    // ========================================================

    if ($honmeiHead >= 1) {

        if ($actual1 === $honmeiHead) {
            $honmei['first']++;
        }

        if (
            $actual1 === $honmeiHead ||
            $actual2 === $honmeiHead
        ) {
            $honmei['second_or_better']++;
        }

        if (
            $actual1 === $honmeiHead ||
            $actual2 === $honmeiHead ||
            $actual3 === $honmeiHead
        ) {
            $honmei['third_or_better']++;
        }
    }


    // ========================================================
    // 対抗
    // ========================================================

    if ($taikouHead >= 1) {

        if ($actual1 === $taikouHead) {
            $taikou['first']++;
        }

        if (
            $actual1 === $taikouHead ||
            $actual2 === $taikouHead
        ) {
            $taikou['second_or_better']++;
        }

        if (
            $actual1 === $taikouHead ||
            $actual2 === $taikouHead ||
            $actual3 === $taikouHead
        ) {
            $taikou['third_or_better']++;
        }
    }


    // ========================================================
    // 本命＋対抗
    // ========================================================

    $honmeiTop3 =
        in_array(
            $honmeiHead,
            [$actual1, $actual2, $actual3],
            true
        );

    $taikouTop3 =
        in_array(
            $taikouHead,
            [$actual1, $actual2, $actual3],
            true
        );


    // 両方3着以内
    if (
        $honmeiTop3 &&
        $taikouTop3
    ) {
        $combination['both_top3']++;
    }


    // 本命1着・対抗2着
    if (
        $actual1 === $honmeiHead &&
        $actual2 === $taikouHead
    ) {
        $combination[
            'honmei_first_taikou_second'
        ]++;
    }


    // 本命1着・対抗3着
    if (
        $actual1 === $honmeiHead &&
        $actual3 === $taikouHead
    ) {
        $combination[
            'honmei_first_taikou_third'
        ]++;
    }


    // 本命1着・対抗3着以内
    if (
        $actual1 === $honmeiHead &&
        $taikouTop3
    ) {
        $combination[
            'honmei_first_taikou_top3'
        ]++;
    }


    // 対抗1着・本命3着以内
    if (
        $actual1 === $taikouHead &&
        $honmeiTop3
    ) {
        $combination[
            'taikou_first_honmei_top3'
        ]++;
    }


    // ========================================================
    // 現行買い目
    // ========================================================

    $honmeiKai = trim(
        $race['honmei_kai'] ?? ''
    );

    $taikouKai = trim(
        $race['taikou_kai'] ?? ''
    );


    $honmeiHit =
        isTrifectaHit(
            $honmeiKai,
            $actual1,
            $actual2,
            $actual3
        );


    $taikouHit =
        isTrifectaHit(
            $taikouKai,
            $actual1,
            $actual2,
            $actual3
        );


    $bets['honmei']['count']++;

    if ($honmeiHit) {
        $bets['honmei']['hit']++;
    }


    $bets['taikou']['count']++;

    if ($taikouHit) {
        $bets['taikou']['hit']++;
    }


    $bets['either']['count']++;

    if (
        $honmeiHit ||
        $taikouHit
    ) {
        $bets['either']['hit']++;
    }
}


// ============================================================
// 結果表示
// ============================================================

echo "\n";
echo "========================================\n";
echo "現行最終予想 健康診断 STEP 2\n";
echo "========================================\n";

echo "対象レース : {$totalRaces}\n";


echo "\n";
echo "========================================\n";
echo "本命 成績\n";
echo "========================================\n";

echo "1着率       : "
    . $honmei['first']
    . " / "
    . $totalRaces
    . " ("
    . rate(
        $honmei['first'],
        $totalRaces
    )
    . ")\n";

echo "2連対率     : "
    . $honmei['second_or_better']
    . " / "
    . $totalRaces
    . " ("
    . rate(
        $honmei['second_or_better'],
        $totalRaces
    )
    . ")\n";

echo "3連対率     : "
    . $honmei['third_or_better']
    . " / "
    . $totalRaces
    . " ("
    . rate(
        $honmei['third_or_better'],
        $totalRaces
    )
    . ")\n";


echo "\n";
echo "========================================\n";
echo "対抗 成績\n";
echo "========================================\n";

echo "1着率       : "
    . $taikou['first']
    . " / "
    . $totalRaces
    . " ("
    . rate(
        $taikou['first'],
        $totalRaces
    )
    . ")\n";

echo "2連対率     : "
    . $taikou['second_or_better']
    . " / "
    . $totalRaces
    . " ("
    . rate(
        $taikou['second_or_better'],
        $totalRaces
    )
    . ")\n";

echo "3連対率     : "
    . $taikou['third_or_better']
    . " / "
    . $totalRaces
    . " ("
    . rate(
        $taikou['third_or_better'],
        $totalRaces
    )
    . ")\n";


echo "\n";
echo "========================================\n";
echo "本命・対抗 組み合わせ\n";
echo "========================================\n";

echo "両方3着以内              : "
    . $combination['both_top3']
    . " / "
    . $totalRaces
    . " ("
    . rate(
        $combination['both_top3'],
        $totalRaces
    )
    . ")\n";

echo "本命1着＋対抗2着         : "
    . $combination['honmei_first_taikou_second']
    . " / "
    . $totalRaces
    . " ("
    . rate(
        $combination['honmei_first_taikou_second'],
        $totalRaces
    )
    . ")\n";

echo "本命1着＋対抗3着         : "
    . $combination['honmei_first_taikou_third']
    . " / "
    . $totalRaces
    . " ("
    . rate(
        $combination['honmei_first_taikou_third'],
        $totalRaces
    )
    . ")\n";

echo "本命1着＋対抗3着以内     : "
    . $combination['honmei_first_taikou_top3']
    . " / "
    . $totalRaces
    . " ("
    . rate(
        $combination['honmei_first_taikou_top3'],
        $totalRaces
    )
    . ")\n";

echo "対抗1着＋本命3着以内     : "
    . $combination['taikou_first_honmei_top3']
    . " / "
    . $totalRaces
    . " ("
    . rate(
        $combination['taikou_first_honmei_top3'],
        $totalRaces
    )
    . ")\n";


echo "\n";
echo "========================================\n";
echo "現行3連単買い目\n";
echo "========================================\n";

echo "本命買い目 的中 : "
    . $bets['honmei']['hit']
    . " / "
    . $bets['honmei']['count']
    . " ("
    . rate(
        $bets['honmei']['hit'],
        $bets['honmei']['count']
    )
    . ")\n";

echo "対抗買い目 的中 : "
    . $bets['taikou']['hit']
    . " / "
    . $bets['taikou']['count']
    . " ("
    . rate(
        $bets['taikou']['hit'],
        $bets['taikou']['count']
    )
    . ")\n";

echo "どちらか的中   : "
    . $bets['either']['hit']
    . " / "
    . $bets['either']['count']
    . " ("
    . rate(
        $bets['either']['hit'],
        $bets['either']['count']
    )
    . ")\n";


echo "\n";
echo "========================================\n";
echo "STEP 2 健康診断 完了\n";
echo "========================================\n";