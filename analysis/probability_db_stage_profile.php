<?php
declare(strict_types=1);

/**
 * BaseWinRateLogic / AiTrioRateLogic が使う主要DB処理の段階計測。
 * 本番値は変更せず、次の高速化対象を特定するための診断専用。
 *
 * Usage:
 *   php analysis/probability_db_stage_profile.php 20260818SME12
 */

require_once __DIR__ . '/../common/db_connect.php';

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php analysis/probability_db_stage_profile.php YYYYMMDDXXXRR\n");
    exit(1);
}

$raceCode = strtoupper(trim((string)$argv[1]));
if (strlen($raceCode) < 13) {
    fwrite(STDERR, "race_codeが不正です\n");
    exit(1);
}

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function timed(string $label, callable $fn, array &$times) {
    $t0 = hrtime(true);
    $value = $fn();
    $ms = (hrtime(true) - $t0) / 1_000_000.0;
    $times[] = [$label, $ms];
    printf("%-38s %10.1f ms  (%6.2f sec)\n", $label, $ms, $ms / 1000.0);
    return $value;
}

$times = [];
$all0 = hrtime(true);

printf("%s\n", str_repeat('=', 92));
echo "確率計算 DB内部 段階計測\n";
printf("%s\n", str_repeat('=', 92));
printf("race_code : %s\n\n", $raceCode);

$targetSql = <<<SQL
SELECT
    COALESCE(rm.race_date, TO_DATE(SUBSTRING(re.race_code, 1, 8), 'YYYYMMDD')) AS race_date,
    rm.stadium_name,
    re.lane_number,
    re.player_id::text AS player_id,
    re.player_name
FROM boat_race.race_entry re
LEFT JOIN boat_race.race_master rm ON rm.race_code = re.race_code
WHERE re.race_code = ?
ORDER BY re.lane_number
SQL;

$rows = timed('1 loadTarget', function () use ($pdo, $targetSql, $raceCode) {
    $stmt = $pdo->prepare($targetSql);
    $stmt->execute([$raceCode]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}, $times);

if (count($rows) !== 6) {
    throw new RuntimeException('対象レースの出走艇が6艇ではありません');
}

$targetDate = (string)$rows[0]['race_date'];
$placeCode = substr($raceCode, 8, 3);

$aiPriorSql = <<<SQL
WITH base AS (
    SELECT c1, c2, c3, place_code
    FROM boat_race.race_history_fact
    WHERE course_valid
      AND (
            race_date < ?::date
            OR (race_date = ?::date AND race_code < ?)
          )
),
courses AS (
    SELECT generate_series(1, 6)::int AS course
)
SELECT
    c.course,
    COUNT(*) AS global_n,
    COUNT(*) FILTER (WHERE b.place_code = ?) AS venue_n,
    COUNT(*) FILTER (WHERE c.course IN (b.c1, b.c2, b.c3)) AS global_top3,
    COUNT(*) FILTER (
        WHERE b.place_code = ?
          AND c.course IN (b.c1, b.c2, b.c3)
    ) AS venue_top3
FROM base b
CROSS JOIN courses c
GROUP BY c.course
ORDER BY c.course
SQL;

timed('2 AI loadCoursePrior', function () use ($pdo, $aiPriorSql, $targetDate, $raceCode, $placeCode) {
    $stmt = $pdo->prepare($aiPriorSql);
    $stmt->execute([$targetDate, $targetDate, $raceCode, $placeCode, $placeCode]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}, $times);

$basePriorSql = <<<SQL
WITH winner_rows AS (
    SELECT
        rrd.race_code,
        COUNT(*) AS winner_count,
        MIN(
            CASE
                WHEN rrd.entry_course::text ~ '^[1-6]$'
                THEN rrd.entry_course::int
                ELSE NULL
            END
        ) AS winner_course
    FROM boat_race.race_result_detail rrd
    JOIN boat_race.race_master rm ON rm.race_code = rrd.race_code
    WHERE rrd.rank = '1'
      AND SUBSTRING(rrd.race_code, 9, 3) = ?
      AND (
            rm.race_date < ?::date
            OR (rm.race_date = ?::date AND rrd.race_code < ?)
          )
    GROUP BY rrd.race_code
)
SELECT winner_course, COUNT(*) AS wins
FROM winner_rows
WHERE winner_count = 1
  AND winner_course BETWEEN 1 AND 6
GROUP BY winner_course
ORDER BY winner_course
SQL;

timed('3 Base loadVenueCoursePrior', function () use ($pdo, $basePriorSql, $placeCode, $targetDate, $raceCode) {
    $stmt = $pdo->prepare($basePriorSql);
    $stmt->execute([$placeCode, $targetDate, $targetDate, $raceCode]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}, $times);

$last100Sql = <<<SQL
SELECT
    re.race_code,
    re.lane_number,
    rrd.rank,
    rrd.entry_course AS result_course,
    el.entry_course AS exhibition_course
FROM boat_race.race_entry re
JOIN boat_race.race_master rm ON rm.race_code = re.race_code
LEFT JOIN boat_race.race_result_detail rrd
  ON rrd.race_code = re.race_code
 AND rrd.player_id = re.player_id
LEFT JOIN LATERAL (
    SELECT x.entry_course
    FROM boat_race.exhibition_live x
    WHERE x.race_code = re.race_code
      AND x.player_id = re.player_id
    LIMIT 1
) el ON TRUE
WHERE re.player_id::text = ?
  AND (
        rm.race_date < ?::date
        OR (rm.race_date = ?::date AND re.race_code < ?)
      )
ORDER BY rm.race_date DESC, re.race_code DESC
LIMIT 100
SQL;

$last100Total = 0.0;
foreach ($rows as $row) {
    $lane = (int)$row['lane_number'];
    $playerId = trim((string)$row['player_id']);
    $label = sprintf('4-%d %d号艇 loadLast100', $lane, $lane);
    $t0 = hrtime(true);
    $stmt = $pdo->prepare($last100Sql);
    $stmt->execute([$playerId, $targetDate, $targetDate, $raceCode]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ms = (hrtime(true) - $t0) / 1_000_000.0;
    $last100Total += $ms;
    $times[] = [$label, $ms];
    printf("%-38s %10.1f ms  (%6.2f sec) rows=%d player=%s\n", $label, $ms, $ms / 1000.0, count($history), $playerId);
}

printf("\n%-38s %10.1f ms  (%6.2f sec)\n", '4 last100 6選手合計', $last100Total, $last100Total / 1000.0);

$totalMs = (hrtime(true) - $all0) / 1_000_000.0;
$sumMs = array_sum(array_map(static fn(array $x): float => (float)$x[1], $times));

printf("\n%s\n", str_repeat('-', 92));
echo "重い順\n";
usort($times, static fn(array $a, array $b): int => $b[1] <=> $a[1]);
foreach ($times as [$label, $ms]) {
    $pct = $sumMs > 0 ? ($ms / $sumMs * 100.0) : 0.0;
    printf("%-38s %8.2f sec  %5.1f%%\n", $label, $ms / 1000.0, $pct);
}
printf("%s\n", str_repeat('-', 92));
printf("計測対象合計 : %.2f sec\n", $sumMs / 1000.0);
printf("実測全体     : %.2f sec\n", $totalMs / 1000.0);
printf("target       : %s / %s\n", $targetDate, $placeCode);
printf("%s\n", str_repeat('=', 92));
