<?php
header("Content-Type: application/json; charset=UTF-8");

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
// ST評価関数
//--------------------------------------
function calcStScore($st) {
    if ($st <= -0.05) return 1;
    if ($st < 0)     return 2;
    if ($st <= 0.05) return 5;
    if ($st <= 0.12) return 4;
    if ($st <= 0.20) return 2;
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
// DB接続
//--------------------------------------
$dsn = "pgsql:host=192.168.0.205;port=5432;dbname=devdb;";
$user = "miyase428";
$pass = "herunia0113";

try {
    $pdo = new PDO($dsn, $user, $pass);
} catch (PDOException $e) {
    echo json_encode(["error" => "DB接続エラー"]);
    exit;
}

//--------------------------------------
// race_code 取得
//--------------------------------------
$race_code = $_POST["race_code"] ?? "";

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
// ⑤ 6艇の平均値を先にまとめて計算
//--------------------------------------
$lap_times      = array_column($rows, 'lap_time');
$avg_lap        = array_sum($lap_times) / count($lap_times);

$mawari_times   = array_column($rows, 'around_time');
$avg_mawari     = array_sum($mawari_times) / count($mawari_times);

$straight_times = array_column($rows, 'straight_time');
$avg_straight   = array_sum($straight_times) / count($straight_times);

//--------------------------------------
// ⑥ JSON 生成
//--------------------------------------
$result = [];

foreach ($rows as $row) {

    $course  = strval($row["tenji_course"]);
    $ex_diff = (float)$row["exhibition_time"] - $avg_ex;

    // 各スコア計算
    $ex_score       = calcExhibitionScore($ex_diff);
    $st_score       = calcStScore((float)$row["start_timing"]);
    $lap_score      = calcLapScore((float)$row["lap_time"], $avg_lap);
    $mawari_score   = calcMawariScore((float)$row["around_time"], $avg_mawari);
    $straight_score = calcStraightScore((float)$row["straight_time"], $avg_straight);
    $attack_potential = $st_score + $straight_score;

    // 展示足トータル（O列）
    $ex_total = $ex_score + $lap_score + $mawari_score + $straight_score;

    $result[$course] = [
        "teiban"          => (int)$row["teiban"],
        "tenji_course"    => (int)$row["tenji_course"],
        "exhibition"      => (float)$row["exhibition_time"],
        "ex_diff"         => $ex_diff,
        "ex_score"        => $ex_score,
        "st"              => (float)$row["start_timing"],
        "st_score"        => $st_score,
        "lap"             => (float)$row["lap_time"],
        "lap_score"       => $lap_score,
        "mawari"          => (float)$row["around_time"],
        "mawari_score"    => $mawari_score,
        "straight"        => (float)$row["straight_time"],
        "straight_score"  => $straight_score,
        "ex_total"        => $ex_total,
        "attack_potential" => $attack_potential
    ];
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;