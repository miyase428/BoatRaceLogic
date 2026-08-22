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
        "win"           => 0,
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
            "win"           => 0,
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
// 5. 1年・6ヶ月を同じ履歴走査1回で集計
// ------------------------------------------------------------
// 固定2期間検証で採用した race_entry 母集団方式はそのまま維持する。
//
// 重要:
//   集計基準日は CURRENT_DATE ではなく「対象 race_code の日付」。
//   race_master に対象レースがあれば race_date を使い、当日レースなど
//   race_master 未登録時は race_code 先頭8桁（YYYYMMDD）を日付として補完する。
//   過去レースの再計算時に、そのレースより後の成績が混入しないようにする。
//
// race_master は日付粒度なので、同日開催分は前後関係を安全に判定できない。
// 未来混入を避けるため、履歴は対象日の前日まで（rm.race_date < target_date）とする。
// 12ヶ月履歴を1回だけ取得し、6ヶ月分は対象日基準の条件付き集計で同時に算出する。
function load_kimarite_both($pdo, $race_code, $in_course) {
    $sql = "
WITH target_race AS (
    SELECT
        COALESCE(
            (
                SELECT race_date
                FROM boat_race.race_master
                WHERE race_code = :target_master_race_code
                LIMIT 1
            ),
            TO_DATE(SUBSTRING(:target_fallback_race_code FROM 1 FOR 8), 'YYYYMMDD')
        ) AS target_date
),

tm AS (
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
    WHERE re.race_code = :member_race_code
),

past AS (
    SELECT
        tr.target_date,
        rm.race_date,
        re.player_id,
        COALESCE(rd.entry_course, ex.entry_course)::integer AS entry_course,
        w.player_id AS winner_player_id,
        w.entry_course::integer AS winner_course,
        TRIM(COALESCE(w.technique, '')) AS winner_technique
    FROM boat_race.race_entry re
    JOIN boat_race.race_master rm
      ON rm.race_code = re.race_code
    CROSS JOIN target_race tr

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

    WHERE rm.race_date >= tr.target_date - INTERVAL '12 months'
      AND rm.race_date < tr.target_date
      AND re.player_id IN (SELECT player_id FROM today_members)
),

joined AS (
    SELECT
        tm.today_course AS course,
        tm.player_id AS target_player_id,
        p.*
    FROM today_members tm
    LEFT JOIN past p
      ON p.player_id = tm.player_id
     AND p.entry_course = tm.today_course::integer
),

agg AS (
    SELECT
        course,
        target_player_id AS player_id,

        COUNT(player_id) AS total_12,
        COUNT(player_id) FILTER (
            WHERE race_date >= target_date - INTERVAL '6 months'
        ) AS total_6,

        -- 決まり手とは別に、各コースでの純粋な1着率を持つ。
        COUNT(*) FILTER (
            WHERE winner_player_id = target_player_id
        ) AS win_12,
        COUNT(*) FILTER (
            WHERE race_date >= target_date - INTERVAL '6 months'
              AND winner_player_id = target_player_id
        ) AS win_6,

        -- nige は「1コース1着率」ではなく、本当に決まり手が逃げだった割合。
        COUNT(*) FILTER (
            WHERE course = 1
              AND winner_player_id = target_player_id
              AND winner_technique = '逃げ'
        ) AS nige_12,
        COUNT(*) FILTER (
            WHERE race_date >= target_date - INTERVAL '6 months'
              AND course = 1
              AND winner_player_id = target_player_id
              AND winner_technique = '逃げ'
        ) AS nige_6,

        COUNT(*) FILTER (
            WHERE course = 1
              AND winner_player_id <> target_player_id
              AND winner_technique = '差し'
        ) AS sasare_12,
        COUNT(*) FILTER (
            WHERE race_date >= target_date - INTERVAL '6 months'
              AND course = 1
              AND winner_player_id <> target_player_id
              AND winner_technique = '差し'
        ) AS sasare_6,

        COUNT(*) FILTER (
            WHERE course = 1
              AND winner_player_id <> target_player_id
              AND winner_technique = 'まくり'
        ) AS makurare_12,
        COUNT(*) FILTER (
            WHERE race_date >= target_date - INTERVAL '6 months'
              AND course = 1
              AND winner_player_id <> target_player_id
              AND winner_technique = 'まくり'
        ) AS makurare_6,

        COUNT(*) FILTER (
            WHERE course = 1
              AND winner_player_id <> target_player_id
              AND winner_technique = 'まくり差し'
        ) AS makurarezashi_12,
        COUNT(*) FILTER (
            WHERE race_date >= target_date - INTERVAL '6 months'
              AND course = 1
              AND winner_player_id <> target_player_id
              AND winner_technique = 'まくり差し'
        ) AS makurarezashi_6,

        COUNT(*) FILTER (
            WHERE course = 2
              AND winner_player_id <> target_player_id
              AND winner_course = 1
        ) AS nogashi_12,
        COUNT(*) FILTER (
            WHERE race_date >= target_date - INTERVAL '6 months'
              AND course = 2
              AND winner_player_id <> target_player_id
              AND winner_course = 1
        ) AS nogashi_6,

        COUNT(*) FILTER (
            WHERE course <> 1
              AND winner_player_id = target_player_id
              AND winner_technique = '差し'
        ) AS sashi_12,
        COUNT(*) FILTER (
            WHERE race_date >= target_date - INTERVAL '6 months'
              AND course <> 1
              AND winner_player_id = target_player_id
              AND winner_technique = '差し'
        ) AS sashi_6,

        COUNT(*) FILTER (
            WHERE course <> 1
              AND winner_player_id = target_player_id
              AND winner_technique = 'まくり'
        ) AS makuri_12,
        COUNT(*) FILTER (
            WHERE race_date >= target_date - INTERVAL '6 months'
              AND course <> 1
              AND winner_player_id = target_player_id
              AND winner_technique = 'まくり'
        ) AS makuri_6,

        COUNT(*) FILTER (
            WHERE course <> 1
              AND winner_player_id = target_player_id
              AND winner_technique = 'まくり差し'
        ) AS makurizashi_12,
        COUNT(*) FILTER (
            WHERE race_date >= target_date - INTERVAL '6 months'
              AND course <> 1
              AND winner_player_id = target_player_id
              AND winner_technique = 'まくり差し'
        ) AS makurizashi_6

    FROM joined
    GROUP BY course, target_player_id
)

SELECT *
FROM agg
ORDER BY course;
";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ":target_master_race_code" => $race_code,
        ":target_fallback_race_code" => $race_code,
        ":member_race_code" => $race_code,
        ":in1" => $in_course[1],
        ":in2" => $in_course[2],
        ":in3" => $in_course[3],
        ":in4" => $in_course[4],
        ":in5" => $in_course[5],
        ":in6" => $in_course[6],
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data_1year = [];
    $data_6month = [];
    for ($c = 1; $c <= 6; $c++) {
        $data_1year[$c] = empty_kimarite();
        $data_6month[$c] = empty_kimarite();
    }

    $countKeys = [
        'win',
        'nige',
        'sashi',
        'makuri',
        'makurizashi',
        'nogashi',
        'sasare',
        'makurare',
        'makurarezashi',
    ];

    foreach ($rows as $r) {
        $course = intval($r['course'] ?? 0);
        if ($course < 1 || $course > 6) {
            continue;
        }

        $total12 = intval($r['total_12'] ?? 0);
        $total6 = intval($r['total_6'] ?? 0);
        $data_1year[$course]['_sample_n'] = $total12;
        $data_6month[$course]['_sample_n'] = $total6;

        foreach ($countKeys as $key) {
            $cnt12 = intval($r[$key . '_12'] ?? 0);
            $cnt6 = intval($r[$key . '_6'] ?? 0);

            $data_1year[$course]['_counts'][$key] = $cnt12;
            $data_6month[$course]['_counts'][$key] = $cnt6;

            $data_1year[$course][$key] = $total12 > 0
                ? round(100.0 * $cnt12 / $total12, 1)
                : 0.0;
            $data_6month[$course][$key] = $total6 > 0
                ? round(100.0 * $cnt6 / $total6, 1)
                : 0.0;
        }
    }

    return [$data_1year, $data_6month];
}

// ------------------------------------------------------------
// 6. 1年・6ヶ月のデータを同時取得
// ------------------------------------------------------------
[$data_1year, $data_6month] = load_kimarite_both($pdo, $race_code, $in_course);

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
