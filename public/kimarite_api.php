<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . '/../common/db_connect.php';

// ------------------------------------------------------------
// 1. race_code と in_course を受け取る
// ------------------------------------------------------------
$race_code = $_GET['race_code']
          ?? $_POST['race_code']
          ?? null;

$in = $_GET['in_course']
   ?? $_POST['in_course']
   ?? null;

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
$pdo = getPDO();

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
        "makurarezashi" => 0,
        "_sample_n"     => 0,
        "_counts"       => [
            "nige"          => 0,
            "sashi"         => 0,
            "makuri"        => 0,
            "makurizashi"   => 0,
            "nogashi"       => 0,
            "sasare"        => 0,
            "makurare"      => 0,
            "makurarezashi" => 0,
        ],
    ];
}

// ------------------------------------------------------------
// 5. 期間ごとの決まり手集計
// ------------------------------------------------------------
// 固定2期間検証で採用した race_entry 母集団方式。
// - 母集団: race_entry（完了レースのみ）
// - 実進入: race_result_detail.entry_course を優先
//           本人 result_detail 行が欠けた場合だけ exhibition_live.entry_course で補完
// - 1コース敗戦系 / 2コース逃がし: 勝者側の決まり手・勝者コースから再構築
// - 3～6コース勝利系: 勝者が本人かつ勝者の決まり手から集計
// - 集計対象: 全場 / 選手×今回の展示進入コース
//
// 既存仕様を維持し、期間の基準日は CURRENT_DATE のまま。
function load_kimarite($pdo, $race_code, $in_course, $months) {
    if (!in_array((int)$months, [6, 12], true)) {
        throw new InvalidArgumentException('months must be 6 or 12');
    }

    $sql = "
WITH tm AS (
    SELECT *
    FROM (VALUES
        (1, CAST(:in1 AS integer)),
        (2, CAST(:in2 AS integer)),
        (3, CAST(:in3 AS integer)),
        (4, CAST(:in4 AS integer)),
        (5, CAST(:in5 AS integer)),
        (6, CAST(:in6 AS integer))
    ) AS v(waku, today_course)
),

today_members AS (
    SELECT
        re.player_id,
        tm.today_course
    FROM boat_race.race_entry re
    JOIN tm
      ON tm.waku = re.lane_number
    WHERE re.race_code = :race_code
),

past AS (
    SELECT
        re.player_id,
        COALESCE(rd.entry_course, ex.entry_course)::integer AS entry_course,
        w.player_id AS winner_player_id,
        w.entry_course::integer AS winner_course,
        TRIM(COALESCE(w.technique, '')) AS winner_technique
    FROM boat_race.race_entry re
    JOIN boat_race.race_master rm
      ON rm.race_code = re.race_code

    LEFT JOIN LATERAL (
        SELECT rrd.entry_course
        FROM boat_race.race_result_detail rrd
        WHERE rrd.race_code = re.race_code
          AND rrd.player_id = re.player_id
          AND rrd.entry_course BETWEEN 1 AND 6
        LIMIT 1
    ) rd ON TRUE

    LEFT JOIN LATERAL (
        SELECT el.entry_course
        FROM boat_race.exhibition_live el
        WHERE el.race_code = re.race_code
          AND el.player_id = re.player_id
          AND el.entry_course BETWEEN 1 AND 6
        LIMIT 1
    ) ex ON TRUE

    JOIN LATERAL (
        SELECT
            rrd.player_id,
            rrd.entry_course,
            rrd.technique
        FROM boat_race.race_result_detail rrd
        WHERE rrd.race_code = re.race_code
          AND TRIM(rrd.rank) = '1'
        LIMIT 1
    ) w ON TRUE

    WHERE rm.race_date >= CURRENT_DATE - INTERVAL '{$months} months'
      AND re.player_id IN (SELECT player_id FROM today_members)
),

agg AS (
    SELECT
        tm.today_course AS course,
        COUNT(p.player_id) AS total_cnt,

        COUNT(*) FILTER (
            WHERE tm.today_course = 1
              AND p.winner_player_id = tm.player_id
        ) AS nige_cnt,

        COUNT(*) FILTER (
            WHERE tm.today_course = 1
              AND p.winner_player_id <> tm.player_id
              AND p.winner_technique = '差し'
        ) AS sasare_cnt,

        COUNT(*) FILTER (
            WHERE tm.today_course = 1
              AND p.winner_player_id <> tm.player_id
              AND p.winner_technique = 'まくり'
        ) AS makurare_cnt,

        COUNT(*) FILTER (
            WHERE tm.today_course = 1
              AND p.winner_player_id <> tm.player_id
              AND p.winner_technique = 'まくり差し'
        ) AS makurarezashi_cnt,

        COUNT(*) FILTER (
            WHERE tm.today_course = 2
              AND p.winner_player_id <> tm.player_id
              AND p.winner_course = 1
        ) AS nogashi_cnt,

        COUNT(*) FILTER (
            WHERE tm.today_course <> 1
              AND p.winner_player_id = tm.player_id
              AND p.winner_technique = '差し'
        ) AS sashi_cnt,

        COUNT(*) FILTER (
            WHERE tm.today_course <> 1
              AND p.winner_player_id = tm.player_id
              AND p.winner_technique = 'まくり'
        ) AS makuri_cnt,

        COUNT(*) FILTER (
            WHERE tm.today_course <> 1
              AND p.winner_player_id = tm.player_id
              AND p.winner_technique = 'まくり差し'
        ) AS makurizashi_cnt

    FROM today_members tm
    LEFT JOIN past p
      ON p.player_id = tm.player_id
     AND p.entry_course = tm.today_course::integer
    GROUP BY tm.today_course, tm.player_id
)

SELECT *
FROM agg
ORDER BY course;
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

    $result = [];
    for ($c = 1; $c <= 6; $c++) {
        $result[$c] = empty_kimarite();
    }

    $countColumns = [
        'nige'          => 'nige_cnt',
        'sashi'         => 'sashi_cnt',
        'makuri'        => 'makuri_cnt',
        'makurizashi'   => 'makurizashi_cnt',
        'nogashi'       => 'nogashi_cnt',
        'sasare'        => 'sasare_cnt',
        'makurare'      => 'makurare_cnt',
        'makurarezashi' => 'makurarezashi_cnt',
    ];

    foreach ($rows as $r) {
        $course = intval($r['course'] ?? 0);
        if ($course < 1 || $course > 6) {
            continue;
        }

        $total = intval($r['total_cnt'] ?? 0);
        $result[$course]['_sample_n'] = $total;

        foreach ($countColumns as $key => $column) {
            $cnt = intval($r[$column] ?? 0);
            $result[$course]['_counts'][$key] = $cnt;
            $result[$course][$key] = $total > 0
                ? round(100.0 * $cnt / $total, 1)
                : 0.0;
        }
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
