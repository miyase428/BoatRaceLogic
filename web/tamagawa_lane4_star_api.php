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

function nullableFloat(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    $number = (float)$value;
    return is_finite($number) ? $number : null;
}

function avgNonNull(array $values): ?float
{
    $valid = [];
    foreach ($values as $value) {
        $number = nullableFloat($value);
        if ($number !== null) {
            $valid[] = $number;
        }
    }
    return $valid ? array_sum($valid) / count($valid) : null;
}

function calcExhibitionScore(float $diff): float
{
    if ($diff <= -0.10) return 5.0;
    if ($diff <= -0.05) return 4.0;
    if ($diff <= 0.05) return 3.0;
    if ($diff <= 0.10) return 2.0;
    return 1.0;
}

function calcStScore(float $st): float
{
    if ($st <= 0.00) return 3.0;
    if ($st <= 0.12) return 5.0;
    if ($st <= 0.20) return 3.0;
    if ($st <= 0.30) return 2.0;
    return 1.0;
}

function calcLapScore(float $value, float $avg): float
{
    $diff = $value - $avg;
    if ($diff <= -0.30) return 5.0;
    if ($diff <= -0.10) return 4.0;
    if ($diff <= 0.10) return 3.0;
    if ($diff <= 0.30) return 2.0;
    return 1.0;
}

function calcMawariScore(float $value, float $avg): float
{
    $diff = $value - $avg;
    if ($diff <= -0.20) return 5.0;
    if ($diff <= -0.05) return 4.0;
    if ($diff <= 0.05) return 3.0;
    if ($diff <= 0.20) return 2.0;
    return 1.0;
}

function calcStraightScore(float $value, float $avg): float
{
    $diff = $value - $avg;
    if ($diff <= -0.04) return 5.0;
    if ($diff <= -0.01) return 4.0;
    if ($diff <= 0.01) return 3.0;
    if ($diff <= 0.04) return 2.0;
    return 1.0;
}

/**
 * 現行本番Web（ApiClientProduction）と同じ二次スコアを再現する。
 * 旧2・4固定+1は加算しない。
 */
function buildSecondEval(array $rows, float $avgExhibition, string $lane4PlayerId): ?array
{
    if (count($rows) !== 6) {
        return null;
    }

    $courses = [];
    $players = [];
    foreach ($rows as $row) {
        $course = (int)($row['entry_course'] ?? 0);
        $pid = trim((string)($row['player_id'] ?? ''));
        if ($course < 1 || $course > 6 || $pid === '' || isset($courses[$course]) || isset($players[$pid])) {
            return null;
        }
        $courses[$course] = true;
        $players[$pid] = true;
    }
    if (count($courses) !== 6) {
        return null;
    }

    $avgLap = avgNonNull(array_column($rows, 'lap_time'));
    $avgMawari = avgNonNull(array_column($rows, 'around_time'));
    $avgStraight = avgNonNull(array_column($rows, 'straight_time'));

    $topScore = null;
    $lane4Eval = null;

    foreach ($rows as $row) {
        $exhibition = nullableFloat($row['exhibition_time'] ?? null);
        $st = nullableFloat($row['start_timing'] ?? null);
        $lap = nullableFloat($row['lap_time'] ?? null);
        $mawari = nullableFloat($row['around_time'] ?? null);
        $straight = nullableFloat($row['straight_time'] ?? null);

        $exScore = $exhibition === null ? 3.0 : calcExhibitionScore($exhibition - $avgExhibition);
        $stScore = $st === null ? 3.0 : calcStScore($st);
        $lapScore = ($lap === null || $avgLap === null) ? 3.0 : calcLapScore($lap, $avgLap);
        $mawariScore = ($mawari === null || $avgMawari === null) ? 3.0 : calcMawariScore($mawari, $avgMawari);
        $straightScore = ($straight === null || $avgStraight === null) ? 3.0 : calcStraightScore($straight, $avgStraight);

        $exTotal = $exScore + $lapScore + $mawariScore + $straightScore;
        $attackPotential = $stScore + $straightScore;
        $stableScore = $lapScore + $mawariScore;
        $finalScore = $exTotal + $attackPotential + $stableScore;

        $topScore = $topScore === null ? $finalScore : max($topScore, $finalScore);

        if (trim((string)$row['player_id']) === $lane4PlayerId) {
            $lane4Eval = [
                'second_score' => $finalScore,
                'attack_potential' => $attackPotential,
                'straight_score' => $straightScore,
                'st_score' => $stScore,
            ];
        }
    }

    if ($lane4Eval === null || $topScore === null) {
        return null;
    }

    $lane4Eval['top_score'] = $topScore;
    $lane4Eval['gap_to_top'] = $topScore - $lane4Eval['second_score'];
    return $lane4Eval;
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
    WHERE el.race_code LIKE :prefix_ex
      AND el.entry_course BETWEEN 1 AND 6
    ORDER BY el.race_code, el.player_id, el.created_date DESC NULLS LAST
), current_entries AS (
    SELECT
        re.race_code,
        re.player_id::text AS player_id,
        COALESCE(ex.entry_course, re.lane_number::integer) AS entry_course
    FROM boat_race.race_entry re
    LEFT JOIN ex_map ex
      ON ex.race_code = re.race_code
     AND ex.player_id = re.player_id
    WHERE re.race_code LIKE :prefix_entry
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
    $stmt->execute([
        ':prefix_ex' => $datePrefix . '%',
        ':prefix_entry' => $datePrefix . '%',
    ]);
    $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $baseResponse = [
        'status' => 'ok',
        'date' => $dateText,
        'profile_months' => 12,
        'conditions' => [
            'star' => '4コースまくり率15%以上 + 4が3より平均ST順位上',
            'double_star' => '★ + 二次24以上 + TOP差5以内',
            'triple_star' => '★★ + 二次27以上 + 直線評価4以上（検証中）',
        ],
        'matches' => [],
    ];

    if (!$targets) {
        respond($baseResponse);
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
        respond($baseResponse);
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
    ORDER BY el.race_code, el.player_id, el.created_date DESC NULLS LAST
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

    // 展示取得済みレースだけ★★/★★★へ昇格判定する。
    $avgExhibition = null;
    $avgStmt = $pdo->prepare(
        "SELECT avg_exhibition_time_6m FROM boat_race.exhibition_avg_6m WHERE stadium_name = :stadium LIMIT 1"
    );
    $avgStmt->execute([':stadium' => '多摩川']);
    $avgValue = $avgStmt->fetchColumn();
    if (is_numeric($avgValue) && (float)$avgValue > 0) {
        $avgExhibition = (float)$avgValue;
    }

    $exhibitionByRace = [];
    if ($avgExhibition !== null) {
        $exSql = <<<'SQL'
WITH latest AS (
    SELECT DISTINCT ON (el.race_code, el.player_id)
        el.race_code,
        el.player_id::text AS player_id,
        el.entry_course::integer AS entry_course,
        el.exhibition_time,
        el.start_timing,
        el.lap_time,
        el.around_time,
        el.straight_time
    FROM boat_race.exhibition_live el
    WHERE el.race_code LIKE :prefix
    ORDER BY el.race_code, el.player_id, el.created_date DESC NULLS LAST
)
SELECT *
FROM latest
WHERE entry_course BETWEEN 1 AND 6
ORDER BY race_code, entry_course
SQL;
        $exStmt = $pdo->prepare($exSql);
        $exStmt->execute([':prefix' => $datePrefix . '%']);
        foreach ($exStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $raceCode = trim((string)$row['race_code']);
            $exhibitionByRace[$raceCode][] = $row;
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

        if ($makuriRate < 15.0 || $rank4 >= $rank3) {
            continue;
        }

        $starLevel = 1;
        $secondary = null;
        if ($avgExhibition !== null && isset($exhibitionByRace[$raceCode])) {
            $secondary = buildSecondEval($exhibitionByRace[$raceCode], $avgExhibition, $pid4);
            if (is_array($secondary)) {
                $score = (float)$secondary['second_score'];
                $gap = (float)$secondary['gap_to_top'];
                $straightScore = (float)$secondary['straight_score'];

                if ($score >= 24.0 && $gap <= 5.0) {
                    $starLevel = 2;
                    if ($score >= 27.0 && $straightScore >= 4.0) {
                        $starLevel = 3;
                    }
                }
            }
        }

        $detail = [
            'race_code' => $raceCode,
            'star_level' => $starLevel,
            'star_text' => str_repeat('★', $starLevel),
            'signal' => match ($starLevel) {
                3 => '4頭強',
                2 => '4軸',
                default => '4攻め',
            },
            'makuri_rate' => round($makuriRate, 2),
            'history_n' => (int)($profile['n'] ?? 0),
            'lane3_avg_rank' => round($rank3, 2),
            'lane4_avg_rank' => round($rank4, 2),
            'secondary_ready' => is_array($secondary),
        ];

        if (is_array($secondary)) {
            $detail['second_score'] = round((float)$secondary['second_score'], 2);
            $detail['top_score'] = round((float)$secondary['top_score'], 2);
            $detail['gap_to_top'] = round((float)$secondary['gap_to_top'], 2);
            $detail['straight_score'] = round((float)$secondary['straight_score'], 2);
            $detail['st_score'] = round((float)$secondary['st_score'], 2);
            $detail['attack_potential'] = round((float)$secondary['attack_potential'], 2);
        }

        $matches[$raceCode] = $detail;
    }

    $baseResponse['matches'] = $matches;
    respond($baseResponse);
} catch (Throwable $e) {
    respond([
        'status' => 'error',
        'error' => $e->getMessage(),
    ], 500);
}
