<?php

declare(strict_types=1);

/**
 * STEP 2-4
 * 本命・対抗とも3着以内なのに、
 * 現行最終買い目で取りこぼした艇の正体分析
 *
 * Usage:
 * php analysis/health_check_miss_detail.php \
 *   analysis/output/final_prediction_boats_20260801_20260808.csv \
 *   analysis/output/final_prediction_races_20260801_20260808.csv
 */

if ($argc < 3) {
    echo "Usage:\n";
    echo "php analysis/health_check_miss_detail.php boats.csv races.csv\n";
    exit(1);
}

$boatsCsv  = $argv[1];
$racesCsv  = $argv[2];

if (!file_exists($boatsCsv)) {
    echo "エラー: 艇別CSVがありません: {$boatsCsv}\n";
    exit(1);
}

if (!file_exists($racesCsv)) {
    echo "エラー: レース別CSVがありません: {$racesCsv}\n";
    exit(1);
}

/**
 * CSV読み込み
 */
function readCsv(string $file): array
{
    $fp = fopen($file, 'r');

    if ($fp === false) {
        throw new RuntimeException("CSVを開けません: {$file}");
    }

    $header = fgetcsv($fp);

    if ($header === false) {
        fclose($fp);
        return [];
    }

    // BOM除去
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

    $rows = [];

    while (($data = fgetcsv($fp)) !== false) {
        if (count($data) === 1 && trim((string)$data[0]) === '') {
            continue;
        }

        $row = [];

        foreach ($header as $i => $key) {
            $row[$key] = $data[$i] ?? '';
        }

        $rows[] = $row;
    }

    fclose($fp);

    return $rows;
}

/**
 * 買い目文字列に艇番が含まれているか
 *
 * 例:
 * 1-235-23456
 * → 1 / 2 / 3 / 4 / 5 / 6
 */
function isBoatIncluded(string $bet, int $boat): bool
{
    if ($bet === '') {
        return false;
    }

    return strpos($bet, (string)$boat) !== false;
}

/**
 * 3連単買い目に実際の組み合わせが含まれているか
 *
 * 例:
 * 実際 1-3-2
 * 買い目 1-246-2346
 *
 * → 3が2着候補にないので外れ
 */
function isTrifectaIncluded(
    string $bet,
    int $first,
    int $second,
    int $third
): bool {
    if ($bet === '') {
        return false;
    }

    $parts = explode('-', $bet);

    if (count($parts) !== 3) {
        return false;
    }

    return
        strpos($parts[0], (string)$first) !== false &&
        strpos($parts[1], (string)$second) !== false &&
        strpos($parts[2], (string)$third) !== false;
}

/**
 * 数値変換
 */
function num($value): ?float
{
    if ($value === '' || $value === null) {
        return null;
    }

    return is_numeric($value) ? (float)$value : null;
}

/**
 * CSV読み込み
 */
$boats = readCsv($boatsCsv);
$races = readCsv($racesCsv);

/**
 * race_code => race
 */
$raceMap = [];

foreach ($races as $race) {
    $raceMap[$race['race_code']] = $race;
}

/**
 * race_code => boats
 */
$boatMap = [];

foreach ($boats as $boat) {
    $boatMap[$boat['race_code']][] = $boat;
}

/**
 * ============================================================
 * STEP 2-3と同じ条件で「取りこぼしレース」を抽出
 * ============================================================
 */
$misses = [];

foreach ($raceMap as $raceCode => $race) {

    $boatsForRace = $boatMap[$raceCode] ?? [];

    if (count($boatsForRace) === 0) {
        continue;
    }

    $honmei = (int)$race['honmei_head'];
    $taikou = (int)$race['taikou_head'];

    $actual1 = (int)$race['actual_1st'];
    $actual2 = (int)$race['actual_2nd'];
    $actual3 = (int)$race['actual_3rd'];

    if ($actual1 <= 0 || $actual2 <= 0 || $actual3 <= 0) {
        continue;
    }

    /**
     * 本命・対抗が3着以内か
     */
    $honmeiTop3 =
        ($actual1 === $honmei ||
         $actual2 === $honmei ||
         $actual3 === $honmei);

    $taikouTop3 =
        ($actual1 === $taikou ||
         $actual2 === $taikou ||
         $actual3 === $taikou);

    /**
     * 両方3着以内でなければSTEP 2-4対象外
     */
    if (!$honmeiTop3 || !$taikouTop3) {
        continue;
    }

    /**
     * 本命買い目
     */
    $honmeiBetHit = isTrifectaIncluded(
        $race['honmei_kai'],
        $actual1,
        $actual2,
        $actual3
    );

    /**
     * 対抗買い目
     */
    $taikouBetHit = isTrifectaIncluded(
        $race['taikou_kai'],
        $actual1,
        $actual2,
        $actual3
    );

    /**
     * どちらかで的中していれば取りこぼしではない
     */
    if ($honmeiBetHit || $taikouBetHit) {
        continue;
    }

    /**
     * 本命・対抗以外の「不足艇」
     *
     * 実際の3着以内から本命・対抗を除いた艇。
     */
    $actualTop3 = [$actual1, $actual2, $actual3];

    $missingBoats = [];

    foreach ($actualTop3 as $boatNo) {
        if ($boatNo !== $honmei && $boatNo !== $taikou) {
            $missingBoats[] = $boatNo;
        }
    }

    /**
     * 艇別情報を取得
     */
    foreach ($boatsForRace as $boat) {

        $lane = (int)$boat['lane_number'];

        if (!in_array($lane, $missingBoats, true)) {
            continue;
        }

        /**
         * 実際の着順
         */
        $actualRank = (int)$boat['actual_rank'];

        /**
         * 買い目に含まれていたか
         *
         * 1着候補・2着候補・3着候補のどこかに
         * 不足艇が存在したかを見る。
         */
        $honmeiContains =
            isBoatIncluded($race['honmei_kai'], $lane);

        $taikouContains =
            isBoatIncluded($race['taikou_kai'], $lane);

        $misses[] = [
            'race_code'        => $raceCode,
            'race_date'        => $boat['race_date'],
            'stadium_name'     => $boat['stadium_name'],
            'race_number'      => $boat['race_number'],

            'honmei'           => $honmei,
            'taikou'           => $taikou,

            'actual_1st'        => $actual1,
            'actual_2nd'        => $actual2,
            'actual_3rd'        => $actual3,

            'missing_boat'      => $lane,
            'actual_rank'       => $actualRank,

            'first_rank'       => (int)$boat['first_rank'],
            'second_rank'      => (int)$boat['second_rank'],
            'final_rank'       => (int)$boat['final_rank'],

            'first_total_score'=> num($boat['first_total_score']),
            'second_score'     => num($boat['second_score']),
            'final3'           => num($boat['final3']),

            'three_in_rate_6m' => num($boat['three_in_rate_6m']),
            'three_in_rate_3m' => num($boat['three_in_rate_3m']),

            'kitai'            => num($boat['kitai']),

            'first_type'       => $boat['first_type'],
            'final_type'       => $boat['final_type'],

            'type_bonus'       => num($boat['type_bonus']),
            'get_bonus'        => num($boat['get_bonus']),
            'kiru'             => (int)$boat['kiru'],

            'honmei_contains'  => $honmeiContains ? 1 : 0,
            'taikou_contains'  => $taikouContains ? 1 : 0,
        ];
    }
}

/**
 * ============================================================
 * 集計
 * ============================================================
 */

$totalMissBoats = count($misses);

/**
 * final_rank別
 */
$finalRankCount = array_fill(1, 6, 0);

foreach ($misses as $row) {
    $rank = $row['final_rank'];

    if ($rank >= 1 && $rank <= 6) {
        $finalRankCount[$rank]++;
    }
}

/**
 * first_rank別
 */
$firstRankCount = array_fill(1, 6, 0);

foreach ($misses as $row) {
    $rank = $row['first_rank'];

    if ($rank >= 1 && $rank <= 6) {
        $firstRankCount[$rank]++;
    }
}

/**
 * second_rank別
 */
$secondRankCount = array_fill(1, 6, 0);

foreach ($misses as $row) {
    $rank = $row['second_rank'];

    if ($rank >= 1 && $rank <= 6) {
        $secondRankCount[$rank]++;
    }
}

/**
 * kiru別
 */
$kiruCount = [
    0 => 0,
    1 => 0,
];

/**
 * 最終順位が上位だった取りこぼし
 */
$finalTop3 = 0;
$finalTop2 = 0;
$finalRank4to6 = 0;

foreach ($misses as $row) {

    if ($row['final_rank'] <= 3) {
        $finalTop3++;
    }

    if ($row['final_rank'] <= 2) {
        $finalTop2++;
    }

    if ($row['final_rank'] >= 4) {
        $finalRank4to6++;
    }

    $kiru = $row['kiru'];

    if (isset($kiruCount[$kiru])) {
        $kiruCount[$kiru]++;
    }
}

/**
 * ============================================================
 * CSV出力
 * ============================================================
 */

$outputDir = dirname($boatsCsv);

$baseName = basename(
    $boatsCsv,
    '.csv'
);

$outputFile =
    $outputDir .
    '/' .
    $baseName .
    '_miss_detail.csv';

$fp = fopen($outputFile, 'w');

if ($fp === false) {
    echo "エラー: 出力CSVを作成できません。\n";
    exit(1);
}

/**
 * BOM
 */
fwrite($fp, "\xEF\xBB\xBF");

$header = [
    'race_code',
    'race_date',
    'stadium_name',
    'race_number',

    'honmei',
    'taikou',

    'actual_1st',
    'actual_2nd',
    'actual_3rd',

    'missing_boat',
    'actual_rank',

    'first_rank',
    'second_rank',
    'final_rank',

    'first_total_score',
    'second_score',
    'final3',

    'three_in_rate_6m',
    'three_in_rate_3m',

    'kitai',

    'first_type',
    'final_type',

    'type_bonus',
    'get_bonus',
    'kiru',

    'honmei_contains',
    'taikou_contains',
];

fputcsv($fp, $header);

foreach ($misses as $row) {

    $data = [];

    foreach ($header as $key) {
        $data[] = $row[$key] ?? '';
    }

    fputcsv($fp, $data);
}

fclose($fp);

/**
 * ============================================================
 * 表示
 * ============================================================
 */

echo "\n";
echo "========================================\n";
echo "現行最終予想 健康診断 STEP 2-4\n";
echo "取りこぼした艇の正体分析\n";
echo "========================================\n";

echo "対象取りこぼし艇 : {$totalMissBoats}\n";

echo "\n";
echo "========================================\n";
echo "最終順位別\n";
echo "========================================\n";

for ($i = 1; $i <= 6; $i++) {

    $count = $finalRankCount[$i];

    $rate = $totalMissBoats > 0
        ? ($count / $totalMissBoats * 100)
        : 0;

    printf(
        "%d位 : %4d件 (%6.2f%%)\n",
        $i,
        $count,
        $rate
    );
}

echo "\n";
echo "========================================\n";
echo "一次評価順位別\n";
echo "========================================\n";

for ($i = 1; $i <= 6; $i++) {

    $count = $firstRankCount[$i];

    $rate = $totalMissBoats > 0
        ? ($count / $totalMissBoats * 100)
        : 0;

    printf(
        "%d位 : %4d件 (%6.2f%%)\n",
        $i,
        $count,
        $rate
    );
}

echo "\n";
echo "========================================\n";
echo "二次評価順位別\n";
echo "========================================\n";

for ($i = 1; $i <= 6; $i++) {

    $count = $secondRankCount[$i];

    $rate = $totalMissBoats > 0
        ? ($count / $totalMissBoats * 100)
        : 0;

    printf(
        "%d位 : %4d件 (%6.2f%%)\n",
        $i,
        $count,
        $rate
    );
}

echo "\n";
echo "========================================\n";
echo "最終評価の上位取りこぼし\n";
echo "========================================\n";

$rateTop2 = $totalMissBoats > 0
    ? ($finalTop2 / $totalMissBoats * 100)
    : 0;

$rateTop3 = $totalMissBoats > 0
    ? ($finalTop3 / $totalMissBoats * 100)
    : 0;

$rateLow = $totalMissBoats > 0
    ? ($finalRank4to6 / $totalMissBoats * 100)
    : 0;

printf(
    "最終評価1～2位 : %4d件 (%6.2f%%)\n",
    $finalTop2,
    $rateTop2
);

printf(
    "最終評価1～3位 : %4d件 (%6.2f%%)\n",
    $finalTop3,
    $rateTop3
);

printf(
    "最終評価4～6位 : %4d件 (%6.2f%%)\n",
    $finalRank4to6,
    $rateLow
);

echo "\n";
echo "========================================\n";
echo "切る判定\n";
echo "========================================\n";

$rateKiru0 = $totalMissBoats > 0
    ? ($kiruCount[0] / $totalMissBoats * 100)
    : 0;

$rateKiru1 = $totalMissBoats > 0
    ? ($kiruCount[1] / $totalMissBoats * 100)
    : 0;

printf(
    "kiru=0 : %4d件 (%6.2f%%)\n",
    $kiruCount[0],
    $rateKiru0
);

printf(
    "kiru=1 : %4d件 (%6.2f%%)\n",
    $kiruCount[1],
    $rateKiru1
);

echo "\n";
echo "========================================\n";
echo "詳細CSV\n";
echo "========================================\n";

echo $outputFile . "\n";

echo "\n";
echo "STEP 2-4 完了\n";
echo "========================================\n";