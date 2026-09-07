<?php
require_once __DIR__ . '/../common/db_connect.php';

date_default_timezone_set('Asia/Tokyo');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function solidCandidatesJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function solidCandidatesValidDate(string $value): bool
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $dt !== false && $dt->format('Y-m-d') === $value;
}

$dateText = trim((string)($_GET['date'] ?? date('Y-m-d')));
if (!solidCandidatesValidDate($dateText)) {
    solidCandidatesJson([
        'status' => 'error',
        'error' => '日付の形式が不正です。',
        'rows' => [],
    ], 400);
}

$datePrefix = str_replace('-', '', $dateText);
$placeNames = [
    'KRY' => '桐生',   'TDA' => '戸田',   'EDG' => '江戸川', 'HWJ' => '平和島',
    'TMG' => '多摩川', 'HMN' => '浜名湖', 'GMG' => '蒲郡',   'TKN' => '常滑',
    'TSU' => '津',     'MKN' => '三国',   'BWK' => 'びわこ', 'SME' => '住之江',
    'AMG' => '尼崎',   'NRT' => '鳴門',   'MRG' => '丸亀',   'KJM' => '児島',
    'MYJ' => '宮島',   'TKY' => '徳山',   'SMS' => '下関',   'WKM' => '若松',
    'ASY' => '芦屋',   'FKO' => '福岡',   'KRT' => '唐津',   'OMR' => '大村',
];

try {
    $pdo = getPDO();

    $sql = <<<SQL
WITH rates AS (
    SELECT
        re.race_code,
        re.lane_number,
        MAX(ps.national_win_rate)::numeric AS national_win_rate
    FROM boat_race.race_entry re
    LEFT JOIN boat_race.player_stats ps
      ON ps.race_code = re.race_code
     AND ps.player_id = re.player_id
    WHERE re.race_code LIKE :rate_prefix
    GROUP BY re.race_code, re.lane_number
), results AS (
    SELECT race_code, COUNT(*)::int AS result_count
    FROM boat_race.race_result_detail
    WHERE race_code LIKE :result_prefix
    GROUP BY race_code
), exhibitions AS (
    SELECT
        race_code,
        COUNT(*) FILTER (
            WHERE exhibition_time IS NOT NULL OR start_timing IS NOT NULL
        )::int AS exhibition_count
    FROM boat_race.exhibition_live
    WHERE race_code LIKE :exhibition_prefix
    GROUP BY race_code
), race_rates AS (
    SELECT
        r.race_code,
        MAX(r.national_win_rate) FILTER (WHERE r.lane_number = 1) AS lane1_rate,
        MAX(r.national_win_rate) FILTER (WHERE r.lane_number BETWEEN 2 AND 6) AS outer_max,
        COUNT(DISTINCT r.lane_number)::int AS lane_count
    FROM rates r
    GROUP BY r.race_code
)
SELECT
    rr.race_code,
    SUBSTRING(rr.race_code FROM 9 FOR 3) AS place,
    CAST(SUBSTRING(rr.race_code FROM 12 FOR 2) AS integer) AS race_no,
    rr.lane1_rate,
    rr.outer_max,
    COALESCE(res.result_count, 0)::int AS result_count,
    COALESCE(ex.exhibition_count, 0)::int AS exhibition_count
FROM race_rates rr
LEFT JOIN results res ON res.race_code = rr.race_code
LEFT JOIN exhibitions ex ON ex.race_code = rr.race_code
WHERE rr.lane_count = 6
  AND COALESCE(res.result_count, 0) < 3
  AND rr.lane1_rate >= 6.5
  AND rr.outer_max <= 6.5
ORDER BY (rr.lane1_rate - rr.outer_max) DESC, rr.lane1_rate DESC, race_no ASC
SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':rate_prefix' => $datePrefix . '%',
        ':result_prefix' => $datePrefix . '%',
        ':exhibition_prefix' => $datePrefix . '%',
    ]);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $place = (string)($row['place'] ?? '');
        if (!isset($placeNames[$place])) {
            continue;
        }

        $lane1 = is_numeric($row['lane1_rate'] ?? null) ? (float)$row['lane1_rate'] : null;
        $outer = is_numeric($row['outer_max'] ?? null) ? (float)$row['outer_max'] : null;
        if ($lane1 === null || $outer === null) {
            continue;
        }

        $rows[] = [
            'race_code' => (string)($row['race_code'] ?? ''),
            'place' => $place,
            'venue' => $placeNames[$place],
            'race_no' => (int)($row['race_no'] ?? 0),
            'lane1_rate' => round($lane1, 2),
            'outer_max' => round($outer, 2),
            'gap' => round($lane1 - $outer, 2),
            'status' => ((int)($row['exhibition_count'] ?? 0) >= 5) ? '展示済' : '展示前',
        ];
    }

    solidCandidatesJson([
        'status' => 'ok',
        'date' => $dateText,
        'rows' => $rows,
        'total' => count($rows),
        'generated_at' => date(DATE_ATOM),
    ]);
} catch (Throwable $e) {
    solidCandidatesJson([
        'status' => 'error',
        'error' => $e->getMessage(),
        'rows' => [],
    ], 500);
}
