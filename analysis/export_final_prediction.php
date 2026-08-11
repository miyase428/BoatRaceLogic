<?php
declare(strict_types=1);

/**
 * 現行最終予想 検証用CSV出力
 *
 * 指定した race_code について、
 * 現在のWeb予想ロジックを実行し、
 * 艇別・レース別のCSVを出力する。
 *
 * 実行例:
 *   php analysis/export_final_prediction.php 20260810WKM12
 */

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../web/api/ApiClient.php';
require_once __DIR__ . '/../web/logic/PredictionLogic.php';


// ============================================================
// 1. race_code取得
// ============================================================

$raceCode = $argv[1] ?? '';

if ($raceCode === '') {
    fwrite(STDERR, "race_codeを指定してください。\n");
    fwrite(STDERR, "例: php analysis/export_final_prediction.php 20260810WKM12\n");
    exit(1);
}


// ============================================================
// 2. DB接続
// ============================================================

try {
    $pdo = getPDO();
} catch (Throwable $e) {
    fwrite(STDERR, "DB接続エラー: {$e->getMessage()}\n");
    exit(1);
}


// ============================================================
// 3. race_master取得
// ============================================================

$sql = <<<SQL
SELECT
    race_code,
    race_day,
    race_date,
    stadium_name,
    race_number
FROM boat_race.race_master
WHERE race_code = :race_code
LIMIT 1
SQL;

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':race_code' => $raceCode
]);

$raceMaster = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$raceMaster) {
    fwrite(STDERR, "race_masterに該当するrace_codeがありません: {$raceCode}\n");
    exit(1);
}


// ============================================================
// 4. race_result_detail取得
// ============================================================

$sql = <<<SQL
SELECT
    rank,
    lane_number,
    player_id,
    player_name,
    motor_number,
    boat_number,
    exhibition_time,
    entry_course,
    start_timing,
    goal_time,
    technique
FROM boat_race.race_result_detail
WHERE race_code = :race_code
ORDER BY lane_number
SQL;

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':race_code' => $raceCode
]);

$resultDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$resultDetails) {
    fwrite(STDERR, "race_result_detailに結果がありません: {$raceCode}\n");
    exit(1);
}


// ============================================================
// 5. 実着順を艇番別に整理
// ============================================================

$actualRank = [];

foreach ($resultDetails as $row) {
    $lane = (int)$row['lane_number'];

    $rank = $row['rank'];

    if ($rank !== null && $rank !== '') {
        $actualRank[$lane] = (int)$rank;
    }
}


// ============================================================
// 6. 実際の3連単
// ============================================================

$actualByRank = [];

foreach ($actualRank as $lane => $rank) {
    if ($rank >= 1 && $rank <= 6) {
        $actualByRank[$rank] = $lane;
    }
}

$actual1st = $actualByRank[1] ?? '';
$actual2nd = $actualByRank[2] ?? '';
$actual3rd = $actualByRank[3] ?? '';

$actualTrifecta = '';

if (
    $actual1st !== '' &&
    $actual2nd !== '' &&
    $actual3rd !== ''
) {
    $actualTrifecta =
        $actual1st . '-' .
        $actual2nd . '-' .
        $actual3rd;
}


// ============================================================
// 7. 現在のWebと同じAPIクライアントを使用
// ============================================================

$apiClient = new ApiClient();


// ============================================================
// 8. 一次評価
// ============================================================

[$entries, $results, $calcError] =
    $apiClient->fetchCalcScores($raceCode);

if ($calcError !== '') {
    fwrite(STDERR, "calc_scoresエラー: {$calcError}\n");
    exit(1);
}

if (!is_array($results) || count($results) === 0) {
    fwrite(STDERR, "一次評価データが取得できませんでした。\n");
    exit(1);
}


// ============================================================
// 9. 場コード
// ============================================================
//
// race_code:
// 20260810WKM12
//        ^^^
//        場コード
//

$placeCode = substr($raceCode, 8, 3);


// ============================================================
// 10. 決まり手データ
// ============================================================

$inCourse = '123456';

[$kimariteData, $kimariteError] =
    $apiClient->fetchKimarite(
        $raceCode,
        $inCourse
    );

if ($kimariteError !== '') {
    fwrite(STDERR, "警告: 決まり手API: {$kimariteError}\n");
}


// ============================================================
// 11. 展示データ
// ============================================================

[$tenjiList, $tenjiError] =
    $apiClient->fetchTenji(
        $raceCode,
        $results,
        $placeCode
    );

if ($tenjiError !== '') {
    fwrite(STDERR, "警告: 展示API: {$tenjiError}\n");
}

if (!is_array($tenjiList) || count($tenjiList) === 0) {
    fwrite(STDERR, "展示データが取得できませんでした。\n");
    exit(1);
}


// ============================================================
// 12. tenji_test
// ============================================================

$tenjiTestData =
    $apiClient->fetchTenjiTest(
        $raceCode,
        $tenjiList
    );

if (!is_array($tenjiTestData)) {
    $tenjiTestData = [];
}


// ============================================================
// 13. 現行PredictionLogicを実行
// ============================================================

$predictionLogic = new PredictionLogic();

$finalPredictions =
    $predictionLogic->buildFinalPredictions(
        $tenjiList,
        $kimariteData,
        $tenjiTestData
    );


// ============================================================
// 14. Summary
// ============================================================

$summary =
    $predictionLogic->buildSummary(
        $finalPredictions
    );


// ============================================================
// 15. スコアから順位を作る関数
// ============================================================

function makeRanks(
    array $rows,
    string $scoreKey
): array {

    $scores = [];

    foreach ($rows as $lane => $row) {
        $scores[$lane] =
            (float)($row[$scoreKey] ?? 0);
    }

    arsort($scores, SORT_NUMERIC);

    $rankMap = [];

    $rank = 1;

    foreach ($scores as $lane => $score) {

        $rankMap[$lane] = $rank;

        $rank++;
    }

    return $rankMap;
}


// ============================================================
// 16. 艇別データ作成
// ============================================================

$boatRows = [];

for ($lane = 1; $lane <= 6; $lane++) {

    $result =
        $results[$lane - 1]
        ?? [];

    $tenji =
        $tenjiList[$lane - 1]
        ?? [];

    $test =
        $tenjiTestData[$lane - 1]
        ?? [];

    $final =
        $finalPredictions[$lane]
        ?? [];


    $boatRows[$lane] = [

        'lane_number' => $lane,

        'player_id' =>
            $result['player_id']
            ?? '',

        'player_name' =>
            $result['player_name']
            ?? ($result['player'] ?? ''),

        // 一次評価
        'first_total_score' =>
            $result['total_score']
            ?? 0,

        'first_type' =>
            $result['type']
            ?? '',

        'first_eval' =>
            $result['ichiji_eval']
            ?? '',

        // 3連対率
        'three_in_rate_6m' =>
            $final['rate6_dec']
            ?? ($test['three_in_rate_6m'] ?? 0),

        'three_in_rate_3m' =>
            $final['rate3_dec']
            ?? ($test['three_in_rate_3m'] ?? 0),

        // 二次評価
        'second_score' =>
            $tenji['final_2nd_score']
            ?? 0,

        // その他
        'kitai' =>
            $final['kitai_dec']
            ?? 0,

        'final_type' =>
            $final['type']
            ?? '',

        'type_bonus' =>
            $final['typeBonus']
            ?? 0,

        'final3' =>
            $final['final3']
            ?? 0,

        'get_bonus' =>
            $final['getBonus']
            ?? 0,

        'kiru' =>
            $final['kiru']
            ?? 0,

        'actual_rank' =>
            $actualRank[$lane]
            ?? '',
    ];
}


// ============================================================
// 17. 各段階の順位
// ============================================================

$firstRank =
    makeRanks(
        $boatRows,
        'first_total_score'
    );

$secondRank =
    makeRanks(
        $boatRows,
        'second_score'
    );

$finalRank =
    makeRanks(
        $boatRows,
        'final3'
    );


// ============================================================
// 18. 出力ディレクトリ
// ============================================================

$outputDir =
    __DIR__ . '/output';

if (!is_dir($outputDir)) {

    if (!mkdir($outputDir, 0775, true)) {

        fwrite(
            STDERR,
            "出力ディレクトリを作成できません。\n"
        );

        exit(1);
    }
}


// ============================================================
// 19. 艇別CSV
// ============================================================

$boatsCsv =
    $outputDir .
    '/final_prediction_boats.csv';

$fp =
    fopen(
        $boatsCsv,
        'wb'
    );

if ($fp === false) {

    fwrite(
        STDERR,
        "CSVを開けません: {$boatsCsv}\n"
    );

    exit(1);
}


// Excel用UTF-8 BOM
fwrite(
    $fp,
    "\xEF\xBB\xBF"
);


fputcsv(
    $fp,
    [
        'race_code',
        'race_date',
        'stadium_name',
        'race_number',

        'lane_number',
        'player_id',
        'player_name',

        'first_total_score',
        'first_type',
        'first_eval',
        'first_rank',

        'three_in_rate_6m',
        'three_in_rate_3m',

        'second_score',
        'second_rank',

        'kitai',
        'final_type',
        'type_bonus',
        'final3',
        'get_bonus',
        'kiru',
        'final_rank',

        'actual_rank',
    ]
);


foreach ($boatRows as $lane => $row) {

    fputcsv(
        $fp,
        [
            $raceCode,
            $raceMaster['race_date'],
            $raceMaster['stadium_name'],
            $raceMaster['race_number'],

            $lane,
            $row['player_id'],
            $row['player_name'],

            $row['first_total_score'],
            $row['first_type'],
            $row['first_eval'],
            $firstRank[$lane] ?? '',

            $row['three_in_rate_6m'],
            $row['three_in_rate_3m'],

            $row['second_score'],
            $secondRank[$lane] ?? '',

            $row['kitai'],
            $row['final_type'],
            $row['type_bonus'],
            $row['final3'],
            $row['get_bonus'],
            $row['kiru'],
            $finalRank[$lane] ?? '',

            $row['actual_rank'],
        ]
    );
}

fclose($fp);


// ============================================================
// 20. レース別CSV
// ============================================================

$racesCsv =
    $outputDir .
    '/final_prediction_races.csv';

$fp =
    fopen(
        $racesCsv,
        'wb'
    );

if ($fp === false) {

    fwrite(
        STDERR,
        "CSVを開けません: {$racesCsv}\n"
    );

    exit(1);
}


fwrite(
    $fp,
    "\xEF\xBB\xBF"
);


fputcsv(
    $fp,
    [
        'race_code',
        'race_date',
        'stadium_name',
        'race_number',

        'honmei_head',
        'taikou_head',

        'honmei_aite_str',
        'taikou_aite_str',
        'kiru_str',

        'honmei_kai',
        'taikou_kai',

        'actual_1st',
        'actual_2nd',
        'actual_3rd',
        'actual_trifecta',
    ]
);


fputcsv(
    $fp,
    [
        $raceCode,
        $raceMaster['race_date'],
        $raceMaster['stadium_name'],
        $raceMaster['race_number'],

        $summary['honmei_head']
            ?? '',

        $summary['taikou_head']
            ?? '',

        $summary['honmei_aite_str']
            ?? '',

        $summary['taikou_aite_str']
            ?? '',

        $summary['kiru_str']
            ?? '',

        $summary['honmei_kai']
            ?? '',

        $summary['taikou_kai']
            ?? '',

        $actual1st,
        $actual2nd,
        $actual3rd,
        $actualTrifecta,
    ]
);


fclose($fp);


// ============================================================
// 21. 完了表示
// ============================================================

echo "\n";
echo "========================================\n";
echo "現行最終予想 検証CSV出力完了\n";
echo "========================================\n";

echo "race_code : {$raceCode}\n";

echo "本命       : "
    . ($summary['honmei_head'] ?? '')
    . "\n";

echo "対抗       : "
    . ($summary['taikou_head'] ?? '')
    . "\n";

echo "実際の3連単 : {$actualTrifecta}\n";

echo "\n";

echo "艇別CSV:\n";
echo $boatsCsv . "\n";

echo "\n";

echo "レース別CSV:\n";
echo $racesCsv . "\n";

echo "\n";