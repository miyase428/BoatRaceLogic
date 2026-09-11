<?php
declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

date_default_timezone_set('Asia/Tokyo');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function validDate(string $value): bool
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $dt !== false && $dt->format('Y-m-d') === $value;
}

function termInfoForDate(DateTimeImmutable $date): string
{
    $year = (int)$date->format('Y');
    $month = (int)$date->format('n');
    if ($month <= 4) {
        return sprintf('%02d10', ($year - 1) % 100);
    }
    if ($month <= 10) {
        return sprintf('%02d04', $year % 100);
    }
    return sprintf('%02d10', $year % 100);
}

$dateText = trim((string)($_GET['date'] ?? ''));
if (!validDate($dateText)) {
    respond(['status' => 'error', 'error' => 'invalid date'], 400);
}

$date = new DateTimeImmutable($dateText);
$historyStart = $date->modify('-12 months')->format('Y-m-d');
$term = termInfoForDate($date);
$datePrefix = $date->format('Ymd') . 'TMG';

try {
    $pdo = getPDO();

    // 当日の進入は展示進入を優先し、展示前は枠番を仮コースとして使う。
    $targetSql = <<<'SQL'
WITH ex_map AS (
    SELECT DISTINCT ON (el.race_code, el.player_id)
        el.race_code,
        el.player_id,
        el.entry_course::integer AS entry_course
    FROM boat_race.exhibition_live el
    WHERE el.race_code LIKE :prefix
      AND el.entry_course BETWEEN 1 AND 6
    ORDER BY el.race_code, el.player_id
), current_entries AS (
    SELECT
        re.race_code,
        re.player_id::text AS player_id,
        COALESCE(ex.entry_course, re.lane_number::integer) AS entry_course
    FROM boat_race.race_entry re
    LEFT JOIN ex_map ex
      ON ex.race_code = re.race_code
     AND ex.player_id = re.player_id
    WHERE re.race_code LIKE :prefix
)
SELECT
    race_code,
    MAX(player_id) FILTER (WHERE entry_course = 3) AS lane3_player_id,
    MAX(player_id) FILTER (WHERE entry_course = 4) AS lane4_player_id
FROM current_entries
GROUP BY race_code
ORDER BY race_code
SQL;

    $stmt = $pdo->prepare($targetSql);
    $stmt->execute([':prefix' => $datePrefix . '%']);
    $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$targets) {
        respond([
            'status' => 'ok',
            'date' => $dateText,
            'profile_months' => 12,
            'condition' => '4コースまくり率15%以上 + 4が3より平均ST順位上',
            'matches' => [],
        ]);
    }

    $playerIds = [];
    foreach ($targets as $row) {
        foreach (['lane3_player_id', 'lane4_player_id'] as $key) {
            $pid = trim((string)($row[$key] ?? ''));
            if ($pid !== '') {
                $playerIds[$pid] = true;
            }
        }
    }
    $playerIds = array_keys($playerIds);

    if (!$playerIds) {
        respond([
            'status' => 'ok',
            'date' => $dateText,
            'profile_months' => 12,
            'condition' => '4コースまくり率15%以上 + 4が3より平均ST順位上',
            'matches' => [],
        ]);
    }

    $placeholders = implode(',', array_fill(0, count($playerIds), '?'));

    // 期別のコース別平均ST順位。数値が小さいほど順位が上。
    $rankSql = "SELECT player_id::text, course3_average_rank, course4_average_rank\n"
        . "FROM boat_race.racer_results\n"
        . "WHERE term_info::text = ? AND player_id::text IN ({$placeholders})";
    $rankStmt = $pdo->prepare($rankSql);
    $rankStmt->execute(array_merge([$term], $playerIds));
    $ranks = [];
    foreach ($rankStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = trim((string)$row['player_id']);
        $ranks[$pid] = [
            3 => is_numeric($row['course3_average_rank'] ?? null) ? (float)$row['course3_average_rank'] : null,
            4 => is_numeric($row['course4_average_rank'] ?? null) ? (float)$row['course4_average_rank'] : null,
        ];
    }

    // 4コース選手の過去12ヶ月4コース履歴。対象日は含めない。
    $lane4Ids = [];
    foreach ($targets as $row) {
        $pid = trim((string)($row['lane4_player_id'] ?? ''));
        if ($pid !== '') {
            $lane4Ids[$pid] = true;
        }
    }
    $lane4Ids = array_keys($lane4Ids);
    $profiles = [];

    if ($lane4Ids) {
        $lane4Placeholders = implode(',', array_fill(0, count($lane4Ids), '?'));
        $historySql = <<<SQL
WITH hr AS (
    SELECT race_code, race_date
    FROM boat_race.race_master
    WHERE race_date >= ?::date
      AND race_date < ?::date
),
rd_map AS (
    SELECT DISTINCT ON (rrd.race_code, rrd.player_id)
        rrd.race_code,
        rrd.player_id,
        rrd.entry_course::integer AS entry_course
    FROM boat_race.race_result_detail rrd
    JOIN hr ON hr.race_code = rrd.race_code
    WHERE rrd.entry_course BETWEEN 1 AND 6
    ORDER BY rrd.race_code, rrd.player_id
),
ex_map AS (
    SELECT DISTINCT ON (el.race_code, el.player_id)
        el.race_code,
        el.player_id,
        el.entry_course::integer AS entry_course
    FROM boat_race.exhibition_live el
    JOIN hr ON hr.race_code = el.race_code
    WHERE el.entry_course BETWEEN 1 AND 6
    ORDER BY el.race_code, el.player_id
),
winner AS (
    SELECT DISTINCT ON (rrd.race_code)
        rrd.race_code,
        rrd.player_id::text AS winner_player_id,
        TRIM(COALESCE(rrd.technique, '')) AS winner_technique
    FROM boat_race.race_result_detail rrd
    JOIN hr ON hr.race_code = rrd.race_code
    WHERE TRIM(rrd.rank::text) = '1'
    ORDER BY rrd.race_code
)
SELECT
    re.player_id::text AS player_id,
    COUNT(*) AS history_n,
    COUNT(*) FILTER (
        WHERE w.winner_player_id = re.player_id::text
          AND w.winner_technique = 'まくり'
    ) AS makuri_n
FROM boat_race.race_entry re
JOIN hr ON hr.race_code = re.race_code
LEFT JOIN rd_map rd
  ON rd.race_code = re.race_code
 AND rd.player_id = re.player_id
LEFT JOIN ex_map ex
  ON ex.race_code = re.race_code
 AND ex.player_id = re.player_id
JOIN winner w ON w.race_code = re.race_code
WHERE re.player_id::text IN ({$lane4Placeholders})
  AND COALESCE(rd.entry_course, ex.entry_course) = 4
GROUP BY re.player_id
SQL;
        $historyStmt = $pdo->prepare($historySql);
        $historyStmt->execute(array_merge([$historyStart, $dateText], $lane4Ids));
        foreach ($historyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = trim((string)$row['player_id']);
            $n = (int)($row['history_n'] ?? 0);
            $makuriN = (int)($row['makuri_n'] ?? 0);
            $profiles[$pid] = [
                'n' => $n,
                'makuri_n' => $makuriN,
                'makuri_rate' => $n > 0 ? (100.0 * $makuriN / $n) : null,
            ];
        }
    }

    $matches = [];
    foreach ($targets as $row) {
        $raceCode = trim((string)($row['race_code'] ?? ''));
        $pid3 = trim((string)($row['lane3_player_id'] ?? ''));
        $pid4 = trim((string)($row['lane4_player_id'] ?? ''));
        if ($raceCode === '' || $pid3 === '' || $pid4 === '') {
            continue;
        }

        $rank3 = $ranks[$pid3][3] ?? null;
        $rank4 = $ranks[$pid4][4] ?? null;
        $profile = $profiles[$pid4] ?? null;
        $makuriRate = is_array($profile) ? ($profile['makuri_rate'] ?? null) : null;

        if (!is_numeric($rank3) || !is_numeric($rank4) || !is_numeric($makuriRate)) {
            continue;
        }
        $rank3 = (float)$rank3;
        $rank4 = (float)$rank4;
        $makuriRate = (float)$makuriRate;
        if ($rank3 < 1.0 || $rank3 > 6.0 || $rank4 < 1.0 || $rank4 > 6.0) {
            continue;
        }

        if ($makuriRate >= 15.0 && $rank4 < $rank3) {
            $matches[$raceCode] = [
                'race_code' => $raceCode,
                'makuri_rate' => round($makuriRate, 2),
                'history_n' => (int)($profile['n'] ?? 0),
                'lane3_avg_rank' => round($rank3, 2),
                'lane4_avg_rank' => round($rank4, 2),
            ];
        }
    }

    respond([
        'status' => 'ok',
        'date' => $dateText,
        'profile_months' => 12,
        'condition' => '4コースまくり率15%以上 + 4が3より平均ST順位上',
        'matches' => $matches,
    ]);
} catch (Throwable $e) {
    respond([
        'status' => 'error',
        'error' => $e->getMessage(),
    ], 500);
}
