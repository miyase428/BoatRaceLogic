<?php

require_once __DIR__ . '/../common/db_connect.php';

if ($argc < 3) {
    echo "Usage:\n";
    echo "php health_check_rank_reason.php <boats_csv> <races_csv>\n";
    exit(1);
}

$boatsCsv  = $argv[1];
$racesCsv  = $argv[2];

if (!file_exists($boatsCsv)) {
    echo "艇別CSVが見つかりません: {$boatsCsv}\n";
    exit(1);
}

if (!file_exists($racesCsv)) {
    echo "レース別CSVが見つかりません: {$racesCsv}\n";
    exit(1);
}

function readCsvAssoc(string $file): array
{
    $fp = fopen($file, 'r');

    if ($fp === false) {
        throw new RuntimeException("CSVを開けません: {$file}");
    }

    $rows = [];

    $header = fgetcsv($fp);

    if ($header === false) {
        fclose($fp);
        return [];
    }

    // BOM除去
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

    while (($row = fgetcsv($fp)) !== false) {
        if (count($row) !== count($header)) {
            continue;
        }

        $data = array_combine($header, $row);

        if ($data !== false) {
            $rows[] = $data;
        }
    }

    fclose($fp);

    return $rows;
}

function pct(int $num, int $den): string
{
    if ($den === 0) {
        return '0.00%';
    }

    return number_format($num / $den * 100, 2) . '%';
}

$boats = readCsvAssoc($boatsCsv);
$races = readCsvAssoc($racesCsv);

/*
 * race_code ごとに艇データをまとめる
 */
$boatsByRace = [];

foreach ($boats as $boat) {
    $raceCode = trim($boat['race_code'] ?? '');

    if ($raceCode === '') {
        continue;
    }

    $boatsByRace[$raceCode][] = $boat;
}

/*
 * 実際の3着内に入った艇を取得
 */
function getActualTop3(array $boats): array
{
    $result = [];

    foreach ($boats as $boat) {
        $actualRank = (int)($boat['actual_rank'] ?? 0);

        if ($actualRank >= 1 && $actualRank <= 3) {
            $result[(int)$boat['lane_number']] = $actualRank;
        }
    }

    return $result;
}

/*
 * 順位から艇を取得
 */
function getBoatByRank(array $boats, string $rankColumn, int $rank): ?array
{
    foreach ($boats as $boat) {
        if ((int)($boat[$rankColumn] ?? 0) === $rank) {
            return $boat;
        }
    }

    return null;
}

/*
 * 本命・対抗が両方3着以内なのに
 * 現行買い目で取り逃したレースだけ対象にする。
 *
 * ここでは races CSV の本命買い目・対抗買い目を使用。
 */
$targetRows = [];

foreach ($races as $race) {

    $raceCode = trim($race['race_code'] ?? '');

    if ($raceCode === '' || !isset($boatsByRace[$raceCode])) {
        continue;
    }

    $boatsForRace = $boatsByRace[$raceCode];

    $actual = getActualTop3($boatsForRace);

    if (count($actual) !== 3) {
        continue;
    }

    $honmei = (int)($race['honmei_head'] ?? 0);
    $taikou = (int)($race['taikou_head'] ?? 0);

    if ($honmei < 1 || $taikou < 1) {
        continue;
    }

    /*
     * 本命・対抗が両方3着以内
     */
    if (!isset($actual[$honmei]) || !isset($actual[$taikou])) {
        continue;
    }

    /*
     * 実際の3着艇
     */
    $actualThird = null;

    foreach ($actual as $lane => $rank) {
        if ($rank === 3) {
            $actualThird = $lane;
            break;
        }
    }

    if ($actualThird === null) {
        continue;
    }

    /*
     * 本命買い目・対抗買い目
     */
    $honmeiKai = trim($race['honmei_kai'] ?? '');
    $taikouKai = trim($race['taikou_kai'] ?? '');

    $actualTrifecta = trim($race['actual_trifecta'] ?? '');

    /*
     * 現行買い目に実際の3連単が含まれているか確認
     */
    $honmeiHit = false;
    $taikouHit = false;

    if ($honmeiKai !== '') {
        $honmeiHit = strpos($honmeiKai, $actualTrifecta) !== false;
    }

    if ($taikouKai !== '') {
        $taikouHit = strpos($taikouKai, $actualTrifecta) !== false;
    }

    /*
     * 両方とも外れているレースだけ対象
     */
    if ($honmeiHit || $taikouHit) {
        continue;
    }

    /*
     * 実際の3着艇が「買い目に入っていない艇」
     */
    $targetBoat = null;

    foreach ($boatsForRace as $boat) {
        if ((int)$boat['lane_number'] === $actualThird) {
            $targetBoat = $boat;
            break;
        }
    }

    if ($targetBoat === null) {
        continue;
    }

    $targetRows[] = [
        'race' => $race,
        'boats' => $boatsForRace,
        'target' => $targetBoat,
        'actual_third' => $actualThird
    ];
}

echo "========================================\n";
echo "現行最終予想 健康診断 STEP 2-7\n";
echo "順位を下げた理由・各評価要素の影響分析\n";
echo "========================================\n";

echo "対象取りこぼしレース : " . count($targetRows) . "\n";

/*
 * ============================================================
 * 1. 実際の3着艇が各評価で何位だったか
 * ============================================================
 */

$rankStats = [
    'first_rank' => array_fill(1, 6, 0),
    'second_rank' => array_fill(1, 6, 0),
    'final_rank' => array_fill(1, 6, 0),
];

foreach ($targetRows as $row) {

    $boat = $row['target'];

    $firstRank  = (int)($boat['first_rank'] ?? 0);
    $secondRank = (int)($boat['second_rank'] ?? 0);
    $finalRank  = (int)($boat['final_rank'] ?? 0);

    if ($firstRank >= 1 && $firstRank <= 6) {
        $rankStats['first_rank'][$firstRank]++;
    }

    if ($secondRank >= 1 && $secondRank <= 6) {
        $rankStats['second_rank'][$secondRank]++;
    }

    if ($finalRank >= 1 && $finalRank <= 6) {
        $rankStats['final_rank'][$finalRank]++;
    }
}

echo "\n========================================\n";
echo "実際の3着艇の評価順位\n";
echo "========================================\n";

echo "\n一次評価\n";

for ($i = 1; $i <= 6; $i++) {
    echo sprintf(
        "%d位 : %4d件 (%6s)\n",
        $i,
        $rankStats['first_rank'][$i],
        pct($rankStats['first_rank'][$i], count($targetRows))
    );
}

echo "\n二次評価\n";

for ($i = 1; $i <= 6; $i++) {
    echo sprintf(
        "%d位 : %4d件 (%6s)\n",
        $i,
        $rankStats['second_rank'][$i],
        pct($rankStats['second_rank'][$i], count($targetRows))
    );
}

echo "\n最終評価\n";

for ($i = 1; $i <= 6; $i++) {
    echo sprintf(
        "%d位 : %4d件 (%6s)\n",
        $i,
        $rankStats['final_rank'][$i],
        pct($rankStats['final_rank'][$i], count($targetRows))
    );
}

/*
 * ============================================================
 * 2. 順位ダウンの大きさ
 * ============================================================
 */

$dropStats = [];

$firstTop3ToFinalBottom = 0;
$firstTop3ToFinalTop3 = 0;

foreach ($targetRows as $row) {

    $boat = $row['target'];

    $firstRank = (int)($boat['first_rank'] ?? 0);
    $finalRank = (int)($boat['final_rank'] ?? 0);

    if ($firstRank < 1 || $finalRank < 1) {
        continue;
    }

    $diff = $finalRank - $firstRank;

    if (!isset($dropStats[$diff])) {
        $dropStats[$diff] = 0;
    }

    $dropStats[$diff]++;

    if ($firstRank <= 3) {

        if ($finalRank <= 3) {
            $firstTop3ToFinalTop3++;
        } else {
            $firstTop3ToFinalBottom++;
        }
    }
}

ksort($dropStats);

echo "\n========================================\n";
echo "一次評価 → 最終評価 順位変化\n";
echo "========================================\n";

foreach ($dropStats as $diff => $count) {

    $label = '';

    if ($diff < 0) {
        $label = '+' . abs($diff) . '位アップ';
    } elseif ($diff > 0) {
        $label = '-' . $diff . '位ダウン';
    } else {
        $label = '変化なし';
    }

    echo sprintf(
        "%-10s : %4d件 (%6s)\n",
        $label,
        $count,
        pct($count, count($targetRows))
    );
}

echo "\n一次評価1～3位 → 最終評価1～3位 : "
    . $firstTop3ToFinalTop3 . "件 ("
    . pct($firstTop3ToFinalTop3, $firstTop3ToFinalTop3 + $firstTop3ToFinalBottom)
    . ")\n";

echo "一次評価1～3位 → 最終評価4～6位 : "
    . $firstTop3ToFinalBottom . "件 ("
    . pct($firstTop3ToFinalBottom, $firstTop3ToFinalTop3 + $firstTop3ToFinalBottom)
    . ")\n";

/*
 * ============================================================
 * 3. 最終評価で順位を下げる主な要素
 * ============================================================
 */

$stats = [
    'type_bonus_positive' => 0,
    'get_bonus_positive' => 0,
    'kiru_positive' => 0,

    'type_bonus_negative' => 0,
    'get_bonus_negative' => 0,
    'kiru_negative' => 0,

    'second_higher' => 0,
    'second_lower' => 0,
];

foreach ($targetRows as $row) {

    $boat = $row['target'];

    $firstRank  = (int)($boat['first_rank'] ?? 0);
    $secondRank = (int)($boat['second_rank'] ?? 0);
    $finalRank  = (int)($boat['final_rank'] ?? 0);

    $typeBonus = (float)($boat['type_bonus'] ?? 0);
    $getBonus  = (float)($boat['get_bonus'] ?? 0);
    $kiru      = (int)($boat['kiru'] ?? 0);

    if ($typeBonus > 0) {
        $stats['type_bonus_positive']++;
    }

    if ($getBonus > 0) {
        $stats['get_bonus_positive']++;
    }

    if ($kiru > 0) {
        $stats['kiru_positive']++;
    }

    if ($typeBonus < 0) {
        $stats['type_bonus_negative']++;
    }

    if ($getBonus < 0) {
        $stats['get_bonus_negative']++;
    }

    if ($kiru < 0) {
        $stats['kiru_negative']++;
    }

    if ($secondRank < $firstRank) {
        $stats['second_higher']++;
    } elseif ($secondRank > $firstRank) {
        $stats['second_lower']++;
    }
}

echo "\n========================================\n";
echo "評価要素の発生状況\n";
echo "========================================\n";

echo "type_bonus > 0 : "
    . $stats['type_bonus_positive'] . "件 ("
    . pct($stats['type_bonus_positive'], count($targetRows))
    . ")\n";

echo "get_bonus > 0  : "
    . $stats['get_bonus_positive'] . "件 ("
    . pct($stats['get_bonus_positive'], count($targetRows))
    . ")\n";

echo "kiru = 1       : "
    . $stats['kiru_positive'] . "件 ("
    . pct($stats['kiru_positive'], count($targetRows))
    . ")\n";

echo "\n二次評価で順位アップ : "
    . $stats['second_higher'] . "件 ("
    . pct($stats['second_higher'], count($targetRows))
    . ")\n";

echo "二次評価で順位ダウン : "
    . $stats['second_lower'] . "件 ("
    . pct($stats['second_lower'], count($targetRows))
    . ")\n";

/*
 * ============================================================
 * 4. CSV出力
 * ============================================================
 */

$outputDir = __DIR__ . '/output';

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0775, true);
}

$baseName = pathinfo($boatsCsv, PATHINFO_FILENAME);

$outputFile = $outputDir . '/' . $baseName . '_rank_reason.csv';

$fp = fopen($outputFile, 'w');

if ($fp === false) {
    throw new RuntimeException("出力CSVを作成できません: {$outputFile}");
}

fputcsv($fp, [
    'race_code',
    'actual_third',
    'first_rank',
    'second_rank',
    'final_rank',
    'first_total_score',
    'second_score',
    'type_bonus',
    'get_bonus',
    'kiru',
    'final3',
    'rank_change_first_to_final',
    'rank_change_second_to_final'
]);

foreach ($targetRows as $row) {

    $boat = $row['target'];

    $firstRank  = (int)($boat['first_rank'] ?? 0);
    $secondRank = (int)($boat['second_rank'] ?? 0);
    $finalRank  = (int)($boat['final_rank'] ?? 0);

    fputcsv($fp, [
        $row['race']['race_code'] ?? '',
        $row['actual_third'],
        $firstRank,
        $secondRank,
        $finalRank,
        $boat['first_total_score'] ?? '',
        $boat['second_score'] ?? '',
        $boat['type_bonus'] ?? '',
        $boat['get_bonus'] ?? '',
        $boat['kiru'] ?? '',
        $boat['final3'] ?? '',
        $finalRank - $firstRank,
        $finalRank - $secondRank,
    ]);
}

fclose($fp);

echo "\nCSV出力:\n";
echo $outputFile . "\n";

echo "\n# STEP 2-7 完了\n";