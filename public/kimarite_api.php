<?php
header("Content-Type: application/json; charset=utf-8");

// ------------------------------------------------------------
// 1. race_code と in_course を受け取る
// ------------------------------------------------------------
$race_code = $_POST['race_code'] ?? null;
$in        = $_POST['in_course'] ?? null;

if (!$race_code || !$in || strlen($in) !== 6) {
    echo json_encode([
        "status" => "error",
        "message" => "race_code and 6-digit in_course are required"
    ]);
    exit;
}

// ------------------------------------------------------------
// 2. 進入コースを分解
// ------------------------------------------------------------
$in_course = [
    1 => intval($in[0]),
    2 => intval($in[1]),
    3 => intval($in[2]),
    4 => intval($in[3]),
    5 => intval($in[4]),
    6 => intval($in[5]),
];

// ------------------------------------------------------------
// 3. PostgreSQL 接続
// ------------------------------------------------------------
$pdo = new PDO(
    "pgsql:host=192.168.0.205;dbname=devdb",
    "miyase428",
    "herunia0113",
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// ------------------------------------------------------------
// 4. 決まり手テンプレート
// ------------------------------------------------------------
function empty_kimarite() {
    return [
        "nige"          => 0,
        "sashi"         => 0,
        "makuri"        => 0,
        "makurizashi"   => 0,
        "nogashi"       => 0,
        "sasare"        => 0,
        "makurare"      => 0,
        "makurarezashi" => 0
    ];
}

// ------------------------------------------------------------
// 5. 期間ごとの SQL（完全軽量化版）
// ------------------------------------------------------------
function load_kimarite($pdo, $race_code, $in_course, $months) {

    $sql = "
WITH tm AS (
    SELECT *
    FROM (VALUES
        (1, :in1),
        (2, :in2),
        (3, :in3),
        (4, :in4),
        (5, :in5),
        (6, :in6)
    ) AS v(waku, today_course)
),

today_members AS (
    SELECT
        re.player_id,
        tm.today_course
    FROM boat_race.race_entry re
    JOIN tm ON tm.waku = re.lane_number
    WHERE re.race_code = :race_code
),

past AS (
    SELECT
        rrd.player_id,
        rrd.entry_course,
        TRIM(rrd.rank) AS rank,
        rrd.technique,
        (
            SELECT entry_course
            FROM boat_race.race_result_detail r1
            WHERE r1.race_code = rrd.race_code
              AND TRIM(r1.rank) = '1'
            LIMIT 1
        ) AS winner_course
    FROM boat_race.race_result_detail rrd
    JOIN boat_race.race_master rm
      ON rrd.race_code = rm.race_code
    WHERE rm.race_date >= CURRENT_DATE - INTERVAL '{$months} months'
      AND rrd.player_id IN (SELECT player_id FROM today_members)
)

SELECT
    tm.today_course AS course,
    CASE
        WHEN p.entry_course = 1 AND p.rank = '1' THEN 'nige'
        WHEN p.entry_course = 1 AND p.rank != '1' AND p.technique = '差し' THEN 'sasare'
        WHEN p.entry_course = 1 AND p.rank != '1' AND p.technique = 'まくり' THEN 'makurare'
        WHEN p.entry_course = 1 AND p.rank != '1' AND p.technique = 'まくり差し' THEN 'makurarezashi'

        WHEN p.entry_course = 2 AND p.rank != '1' AND p.winner_course = 1 THEN 'nogashi'

        WHEN p.rank = '1' AND p.technique = '差し' THEN 'sashi'
        WHEN p.rank = '1' AND p.technique = 'まくり' THEN 'makuri'
        WHEN p.rank = '1' AND p.technique = 'まくり差し' THEN 'makurizashi'
        ELSE NULL
    END AS technique_type,
    COUNT(*) AS cnt,
    SUM(COUNT(*)) OVER (PARTITION BY tm.today_course) AS total_cnt
FROM past p
JOIN today_members tm
  ON p.player_id = tm.player_id
 AND p.entry_course = tm.today_course::integer
GROUP BY tm.today_course, technique_type
ORDER BY tm.today_course;
";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ":race_code" => $race_code,
        ":in1" => $in_course[1],
        ":in2" => $in_course[2],
        ":in3" => $in_course[3],
        ":in4" => $in_course[4],
        ":in5" => $in_course[5],
        ":in6" => $in_course[6],
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- コース別にテンプレートを埋める ---
    $result = [];
    for ($c = 1; $c <= 6; $c++) {
        $result[$c] = empty_kimarite();
    }

    foreach ($rows as $r) {
        if ($r["technique_type"] === null) continue;
        $course = intval($r["course"]);
        $rate = round(100.0 * $r["cnt"] / $r["total_cnt"], 1);
        $result[$course][$r["technique_type"]] = $rate;
    }

    return $result;
}

// ------------------------------------------------------------
// 6. 1年・6ヶ月のデータを取得
// ------------------------------------------------------------
$data_1year  = load_kimarite($pdo, $race_code, $in_course, 12);
$data_6month = load_kimarite($pdo, $race_code, $in_course, 6);

// ------------------------------------------------------------
// 7. JSON 出力
// ------------------------------------------------------------
$output = [];

for ($c = 1; $c <= 6; $c++) {
    $output[$c] = [
        "1year"  => $data_1year[$c],
        "6month" => $data_6month[$c]
    ];
}

echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);