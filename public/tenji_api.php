<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../common/db_connect.php';

//--------------------------------------
// 展示タイム評価関数
//--------------------------------------
function calcExhibitionScore($diff) {
    if ($diff <= -0.10) return 5;
    if ($diff <= -0.05) return 4;
    if ($diff <=  0.05) return 3;
    if ($diff <=  0.10) return 2;
    return 1;
}

//--------------------------------------
// ST評価関数（ST_BAND）
//
// 検証結果に基づき、展示STは「早いほど高評価」ではなく
// 0.00～0.12を良好帯域として評価する。
//--------------------------------------
function calcStScore($st) {
    if ($st <= 0.00) return 3;
    if ($st <= 0.12) return 5;
    if ($st <= 0.20) return 3;
    if ($st <= 0.30) return 2;
    return 1;
}

//--------------------------------------
// 周回評価関数
//--------------------------------------
function calcLapScore($lap, $avg_lap) {
    $diff = $lap - $avg_lap;

    if ($diff <= -0.30) return 5;
    if ($diff <= -0.10) return 4;
    if ($diff <=  0.10) return 3;
    if ($diff <=  0.30) return 2;
    return 1;
}

//--------------------------------------
// 周り足評価関数
//--------------------------------------
function calcMawariScore($mawari, $avg_mawari) {
    $diff = $mawari - $avg_mawari;

    if ($diff <= -0.20) return 5;
    if ($diff <= -0.05) return 4;
    if ($diff <=  0.05) return 3;
    if ($diff <=  0.20) return 2;
    return 1;
}

//--------------------------------------
// 直線評価関数
//--------------------------------------
function calcStraightScore($straight, $avg_straight) {
    $diff = $straight - $avg_straight;

    if ($diff <= -0.04) return 5;
    if ($diff <= -0.01) return 4;
    if ($diff <=  0.01) return 3;
    if ($diff <=  0.04) return 2;
    return 1;
}

//--------------------------------------
// NULL安全化ヘルパー
//--------------------------------------
function nullableFloat($value): ?float {
    if ($value === null || $value === '') {
        return null;
    }
    return (float)$value;
}

function avgNonNull(array $values): ?float {
    $valid = [];
    foreach ($values as $value) {
        if ($value !== null && $value !== '') {
            $valid[] = (float)$value;
        }
    }

    if (count($valid) === 0) {
        return null;
    }

    return array_sum($valid) / count($valid);
}

//--------------------------------------
// DB接続
//--------------------------------------
try {
    $pdo = getPDO();
} catch (Throwable $e) {
    echo json_encode(["error" => "DB接続エラー"]);
    exit;
}

//--------------------------------------
// race_code 取得
//--------------------------------------
#$race_code = $_POST["race_code"] ?? "";
$race_code = $_GET["race_code"]
          ?? $_POST["race_code"]
          ?? "";

if ($race_code === "") {
    echo json_encode(["error" => "race_code がありません"]);
    exit;
}

//--------------------------------------
// ① race_code から場コード3桁を抽出
//--------------------------------------
$jyo = substr($race_code, 8, 3);   // 例：OMR

//--------------------------------------
// ② stadium_master から場名を取得
//--------------------------------------
$sql_name = "
    SELECT stadium_name
    FROM boat_race.stadium_master
    WHERE stadium_code = :jyo
    LIMIT 1
";

$stmt_name = $pdo->prepare($sql_name);
$stmt_name->execute([':jyo' => $jyo]);
$stadium_name = $stmt_name->fetchColumn();

if (!$stadium_name) {
    echo json_encode(["error" => "場名が取得できません"]);
    exit;
}

//--------------------------------------
// ③ exhibition_avg_6m から6か月展示平均を取得
//--------------------------------------
$sql_avg = "
    SELECT avg_exhibition_time_6m
    FROM boat_race.exhibition_avg_6m
    WHERE stadium_name = :stadium_name
";

$stmt_avg = $pdo->prepare($sql_avg);
$stmt_avg->execute([':stadium_name' => $stadium_name]);
$avg_ex = (float)$stmt_avg->fetchColumn();

if ($avg_ex <= 0) {
    echo json_encode(["error" => "6か月展示平均が取得できません"]);
    exit;
}

//--------------------------------------
// ④ 指定レースの展示データを取得
//--------------------------------------
$sql = "
SELECT
    re.lane_number AS teiban,
    el.entry_course AS tenji_course,
    el.exhibition_time,
    el.start_timing,
    el.lap_time,
    el.around_time,
    el.straight_time
FROM boat_race.exhibition_live el
JOIN boat_race.race_entry re
  ON el.race_code = re.race_code
 AND el.player_id = re.player_id
WHERE el.race_code = :race_code
ORDER BY el.entry_course;
";

$stmt = $pdo->prepare($sql);
$stmt->execute([':race_code' => $race_code]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

//--------------------------------------
// ⑤ レース内平均を非NULL艇だけで計算
//
// 取得元仕様や展示不参加で一部NULLになることがある。
// NULLを0秒として平均・評価すると、その艇が異常に高評価されるため、
// 平均は値が存在する艇だけで計算する。
//--------------------------------------
$avg_lap = avgNonNull(array_column($rows, 'lap_time'));
$avg_mawari = avgNonNull(array_column($rows, 'around_time'));
$avg_straight = avgNonNull(array_column($rows, 'straight_time'));

//--------------------------------------
// ⑥ JSON 生成
//--------------------------------------
$result = [];

foreach ($rows as $row) {

    $course = strval($row["tenji_course"]);

    $exhibition = nullableFloat($row["exhibition_time"]);
    $st = nullableFloat($row["start_timing"]);
    $lap = nullableFloat($row["lap_time"]);
    $mawari = nullableFloat($row["around_time"]);
    $straight = nullableFloat($row["straight_time"]);

    $ex_diff = $exhibition === null ? null : $exhibition - $avg_ex;

    // NULLは「良い/悪い」と判断できないため中立3点。
    // 全艇で当該指標が非提供の場合も全艇3点となり、順位差を生まない。
    $ex_score = $ex_diff === null ? 3 : calcExhibitionScore($ex_diff);
    $st_score = $st === null ? 3 : calcStScore($st);
    $lap_score = ($lap === null || $avg_lap === null) ? 3 : calcLapScore($lap, $avg_lap);
    $mawari_score = ($mawari === null || $avg_mawari === null) ? 3 : calcMawariScore($mawari, $avg_mawari);
    $straight_score = ($straight === null || $avg_straight === null) ? 3 : calcStraightScore($straight, $avg_straight);

    $attack_potential = $st_score + $straight_score;
    $stable_score = $lap_score + $mawari_score;

    // 展示足トータル（O列）
    $ex_total = $ex_score + $lap_score + $mawari_score + $straight_score;

    $result[$course] = [
        "teiban"           => (int)$row["teiban"],
        "tenji_course"     => (int)$row["tenji_course"],
        "exhibition"       => $exhibition,
        "ex_diff"          => $ex_diff,
        "ex_score"         => $ex_score,
        "st"               => $st,
        "st_score"         => $st_score,
        "lap"              => $lap,
        "lap_score"        => $lap_score,
        "mawari"           => $mawari,
        "mawari_score"     => $mawari_score,
        "straight"         => $straight,
        "straight_score"   => $straight_score,
        "ex_total"         => $ex_total,
        "attack_potential" => $attack_potential,
        "stable_score"     => $stable_score
    ];
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
