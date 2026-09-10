<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../common/PayoutSignalFeatureBuilder.php';
require_once __DIR__ . '/../common/PayoutSignalClassifier.php';

date_default_timezone_set('Asia/Tokyo');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function upsetDetailJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

$raceCode = strtoupper(trim((string)($_GET['race_code'] ?? '')));
if (!preg_match('/^\d{8}[A-Z]{3}(0[1-9]|1[0-2])$/', $raceCode)) {
    upsetDetailJson([
        'status' => 'error',
        'error' => 'race_code が不正です。',
    ], 400);
}

$datePrefix = substr($raceCode, 0, 8);
$raceNo = (int)substr($raceCode, 11, 2);

try {
    $pdo = getPDO();

    // TOP荒れ警戒と同じく、当日展示・二次評価・補正後1着率は使わない。
    // 当該レースの6選手について、当日より前の直近12か月・今回コース一致成績を集計する。
    $sql = <<<SQL
WITH target_date AS (
    SELECT TO_DATE(:target_date, 'YYYYMMDD') AS d
),
target_members AS (
    SELECT
        re.race_code,
        SUBSTRING(re.race_code FROM 9 FOR 3) AS place,
        CAST(SUBSTRING(re.race_code FROM 12 FOR 2) AS integer) AS race_no,
        re.lane_number::integer AS course,
        re.player_id
    FROM boat_race.race_entry re
    WHERE re.race_code = :race_code
      AND re.lane_number BETWEEN 1 AND 6
),
past AS (
    SELECT
        tm.race_code AS target_race_code,
        tm.course,
        tm.player_id AS target_player_id,
        re.race_code AS past_race_code,
        rm.race_date,
        COALESCE(rd.entry_course, ex.entry_course)::integer AS past_course,
        w.player_id AS winner_player_id,
        TRIM(COALESCE(w.technique, '')) AS winner_technique
    FROM target_members tm
    CROSS JOIN target_date td
    JOIN boat_race.race_entry re
      ON re.player_id = tm.player_id
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
        SELECT rrd.player_id, rrd.technique
        FROM boat_race.race_result_detail rrd
        WHERE rrd.race_code = re.race_code
          AND TRIM(rrd.rank) = '1'
        LIMIT 1
    ) w ON TRUE
    WHERE rm.race_date >= td.d - INTERVAL '12 months'
      AND rm.race_date < td.d
),
matched AS (
    SELECT *
    FROM past
    WHERE past_course = course
),
agg AS (
    SELECT
        tm.race_code,
        tm.place,
        tm.race_no,
        tm.course,
        tm.player_id,
        COUNT(m.past_race_code)::int AS total_12,
        COUNT(*) FILTER (WHERE m.winner_player_id = tm.player_id)::int AS win_12,
        COUNT(*) FILTER (
            WHERE tm.course = 1
              AND m.winner_player_id = tm.player_id
              AND m.winner_technique = '逃げ'
        )::int AS nige_12,
        COUNT(*) FILTER (
            WHERE tm.course <> 1
              AND m.winner_player_id = tm.player_id
              AND m.winner_technique = '差し'
        )::int AS sashi_12,
        COUNT(*) FILTER (
            WHERE tm.course <> 1
              AND m.winner_player_id = tm.player_id
              AND m.winner_technique = 'まくり'
        )::int AS makuri_12
    FROM target_members tm
    LEFT JOIN matched m
      ON m.target_race_code = tm.race_code
     AND m.course = tm.course
     AND m.target_player_id = tm.player_id
    GROUP BY tm.race_code, tm.place, tm.race_no, tm.course, tm.player_id
)
SELECT * FROM agg ORDER BY course
SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':target_date' => $datePrefix,
        ':race_code' => $raceCode,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) !== 6) {
        upsetDetailJson([
            'status' => 'waiting',
            'race_code' => $raceCode,
            'error' => '6艇分の判定材料がそろっていません。',
        ]);
    }

    $kimarite = [];
    foreach ($rows as $r) {
        $course = (int)($r['course'] ?? 0);
        $total = (int)($r['total_12'] ?? 0);
        $pct = static fn(int $count): float => $total > 0
            ? round(100.0 * $count / $total, 1)
            : 0.0;

        $kimarite[$course] = [
            '1year' => [
                '_sample_n' => $total,
                'nige' => $pct((int)($r['nige_12'] ?? 0)),
                'win' => $pct((int)($r['win_12'] ?? 0)),
                'sashi' => $pct((int)($r['sashi_12'] ?? 0)),
                'makuri' => $pct((int)($r['makuri_12'] ?? 0)),
            ],
        ];
    }

    $built = PayoutSignalFeatureBuilder::build($raceNo, $kimarite, []);
    if (($built['status'] ?? '') !== 'ok') {
        upsetDetailJson([
            'status' => 'waiting',
            'race_code' => $raceCode,
            'error' => (string)($built['error'] ?? '判定材料が不足しています。'),
        ]);
    }

    $classified = PayoutSignalClassifier::classify($built['input']);
    $payout = is_array($classified['payout'] ?? null) ? $classified['payout'] : [];
    $chaos = is_array($classified['chaos'] ?? null) ? $classified['chaos'] : [];

    $levels = [];
    foreach (['medium', 'high', 'big'] as $key) {
        $bucket = is_array($payout[$key] ?? null) ? $payout[$key] : [];
        $levels[$key] = [
            'label' => (string)($bucket['label'] ?? ''),
            'range' => (string)($bucket['range'] ?? ''),
            'level' => (string)($bucket['level'] ?? 'low'),
            'score' => (int)($bucket['score'] ?? 0),
            'max_score' => (int)($bucket['max_score'] ?? 0),
            'signals' => array_values(is_array($bucket['signals'] ?? null) ? $bucket['signals'] : []),
            'pairs' => array_values(is_array($bucket['pairs'] ?? null) ? $bucket['pairs'] : []),
            'roles' => is_array($bucket['roles'] ?? null) ? $bucket['roles'] : [],
        ];
    }

    upsetDetailJson([
        'status' => 'ok',
        'race_code' => $raceCode,
        'version' => (string)($classified['version'] ?? ''),
        'input' => $built['input'],
        'payout' => $levels,
        'chaos' => [
            'primary' => (string)($chaos['primary'] ?? '平常'),
            'types' => array_values(is_array($chaos['types'] ?? null) ? $chaos['types'] : []),
            'reasons' => array_values(is_array($chaos['reasons'] ?? null) ? $chaos['reasons'] : []),
        ],
        'note' => 'TOP荒れ警戒と同じ展示不要判定です。Web本命・対抗はこの判定には使用していません。',
    ]);
} catch (Throwable $e) {
    upsetDetailJson([
        'status' => 'error',
        'race_code' => $raceCode,
        'error' => $e->getMessage(),
    ], 500);
}
