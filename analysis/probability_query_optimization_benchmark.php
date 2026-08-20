<?php
declare(strict_types=1);

/**
 * Web確率計算で重いSQLの OLD/NEW 完全一致・速度比較。
 *
 * NEW候補:
 * 1) AI3連対率 prior: race_history_fact の6倍CROSS JOINをやめ、1走査で6コース集計
 * 2) Base場prior: race_master JOINを外し race_code < target で同一定義を表現
 * 3) 選手last100: race_master JOINを外し、player_id×race_code indexを使いやすくする
 *
 * race_code は YYYYMMDD + 場3文字 + R2桁の固定形式なので、
 * 現行の「過去日 OR 同日でrace_codeが小さい」は race_code < targetRaceCode と等価。
 * 本番反映前に、このスクリプトで実データ完全一致を確認する。
 *
 * Usage:
 *   php analysis/probability_query_optimization_benchmark.php 20260818SME12
 */

require_once __DIR__ . '/../common/db_connect.php';

$raceCode = strtoupper(trim((string)($argv[1] ?? '')));
if (!preg_match('/^\d{8}[A-Z0-9]{3}\d{2}$/', $raceCode)) {
    fwrite(STDERR, "Usage: php analysis/probability_query_optimization_benchmark.php YYYYMMDDXXXRR\n");
    exit(1);
}

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$targetStmt = $pdo->prepare(<<<SQL
SELECT
    COALESCE(rm.race_date, TO_DATE(SUBSTRING(re.race_code, 1, 8), 'YYYYMMDD')) AS race_date,
    re.lane_number,
    re.player_id::text AS player_id
FROM boat_race.race_entry re
LEFT JOIN boat_race.race_master rm ON rm.race_code = re.race_code
WHERE re.race_code = ?
ORDER BY re.lane_number
SQL);
$targetStmt->execute([$raceCode]);
$boats = $targetStmt->fetchAll(PDO::FETCH_ASSOC);
if (count($boats) !== 6) {
    throw new RuntimeException('対象レースの出走艇が6艇ではありません');
}

$targetDate = (string)$boats[0]['race_date'];
$placeCode = substr($raceCode, 8, 3);

function timedQuery(PDO $pdo, string $sql, array $params): array
{
    $t0 = hrtime(true);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ms = (hrtime(true) - $t0) / 1_000_000.0;
    return [$rows, $ms];
}

function normalizeRows(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $n = [];
        foreach ($row as $k => $v) {
            $n[(string)$k] = $v === null ? null : (string)$v;
        }
        $out[] = $n;
    }
    return $out;
}

function yesNo(bool $ok): string
{
    return $ok ? 'YES' : 'NO';
}

// -----------------------------------------------------------------------------
// 1) AI3連対率 course prior
// -----------------------------------------------------------------------------
$aiOldSql = <<<SQL
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

$aiNewSql = <<<SQL
SELECT
    COUNT(*) AS global_n,
    COUNT(*) FILTER (WHERE place_code = ?) AS venue_n,
    COUNT(*) FILTER (WHERE 1 IN (c1, c2, c3)) AS global_top3_1,
    COUNT(*) FILTER (WHERE 2 IN (c1, c2, c3)) AS global_top3_2,
    COUNT(*) FILTER (WHERE 3 IN (c1, c2, c3)) AS global_top3_3,
    COUNT(*) FILTER (WHERE 4 IN (c1, c2, c3)) AS global_top3_4,
    COUNT(*) FILTER (WHERE 5 IN (c1, c2, c3)) AS global_top3_5,
    COUNT(*) FILTER (WHERE 6 IN (c1, c2, c3)) AS global_top3_6,
    COUNT(*) FILTER (WHERE place_code = ? AND 1 IN (c1, c2, c3)) AS venue_top3_1,
    COUNT(*) FILTER (WHERE place_code = ? AND 2 IN (c1, c2, c3)) AS venue_top3_2,
    COUNT(*) FILTER (WHERE place_code = ? AND 3 IN (c1, c2, c3)) AS venue_top3_3,
    COUNT(*) FILTER (WHERE place_code = ? AND 4 IN (c1, c2, c3)) AS venue_top3_4,
    COUNT(*) FILTER (WHERE place_code = ? AND 5 IN (c1, c2, c3)) AS venue_top3_5,
    COUNT(*) FILTER (WHERE place_code = ? AND 6 IN (c1, c2, c3)) AS venue_top3_6
FROM boat_race.race_history_fact
WHERE course_valid
  AND race_code < ?
SQL;

[$aiOldRows, $aiOldMs] = timedQuery($pdo, $aiOldSql, [$targetDate, $targetDate, $raceCode, $placeCode, $placeCode]);
[$aiNewRaw, $aiNewMs] = timedQuery($pdo, $aiNewSql, [
    $placeCode,
    $placeCode, $placeCode, $placeCode, $placeCode, $placeCode, $placeCode,
    $raceCode,
]);
$aiOne = $aiNewRaw[0] ?? [];
$aiNewRows = [];
for ($course = 1; $course <= 6; $course++) {
    $aiNewRows[] = [
        'course' => (string)$course,
        'global_n' => (string)($aiOne['global_n'] ?? 0),
        'venue_n' => (string)($aiOne['venue_n'] ?? 0),
        'global_top3' => (string)($aiOne['global_top3_' . $course] ?? 0),
        'venue_top3' => (string)($aiOne['venue_top3_' . $course] ?? 0),
    ];
}
$aiOldNorm = normalizeRows($aiOldRows);
$aiNewNorm = normalizeRows($aiNewRows);
$aiMatch = $aiOldNorm === $aiNewNorm;

// -----------------------------------------------------------------------------
// 2) Base venue course prior
// -----------------------------------------------------------------------------
$baseOldSql = <<<SQL
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
    JOIN boat_race.race_master rm
      ON rm.race_code = rrd.race_code
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

$baseNewSql = <<<SQL
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
    WHERE rrd.rank = '1'
      AND SUBSTRING(rrd.race_code, 9, 3) = ?
      AND rrd.race_code < ?
    GROUP BY rrd.race_code
)
SELECT winner_course, COUNT(*) AS wins
FROM winner_rows
WHERE winner_count = 1
  AND winner_course BETWEEN 1 AND 6
GROUP BY winner_course
ORDER BY winner_course
SQL;

[$baseOldRows, $baseOldMs] = timedQuery($pdo, $baseOldSql, [$placeCode, $targetDate, $targetDate, $raceCode]);
[$baseNewRows, $baseNewMs] = timedQuery($pdo, $baseNewSql, [$placeCode, $raceCode]);
$baseMatch = normalizeRows($baseOldRows) === normalizeRows($baseNewRows);

// -----------------------------------------------------------------------------
// 3) 6選手 last100
// -----------------------------------------------------------------------------
$lastOldSql = <<<SQL
SELECT
    re.race_code,
    re.lane_number,
    rrd.rank,
    rrd.entry_course AS result_course,
    el.entry_course AS exhibition_course
FROM boat_race.race_entry re
JOIN boat_race.race_master rm
  ON rm.race_code = re.race_code
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

$lastNewSql = <<<SQL
SELECT
    re.race_code,
    re.lane_number,
    rrd.rank,
    rrd.entry_course AS result_course,
    el.entry_course AS exhibition_course
FROM boat_race.race_entry re
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
  AND re.race_code < ?
ORDER BY re.race_code DESC
LIMIT 100
SQL;

$lastOldTotal = 0.0;
$lastNewTotal = 0.0;
$lastAllMatch = true;
$lastLines = [];
foreach ($boats as $boat) {
    $lane = (int)$boat['lane_number'];
    $playerId = (string)$boat['player_id'];
    [$oldRows, $oldMs] = timedQuery($pdo, $lastOldSql, [$playerId, $targetDate, $targetDate, $raceCode]);
    [$newRows, $newMs] = timedQuery($pdo, $lastNewSql, [$playerId, $raceCode]);
    $match = normalizeRows($oldRows) === normalizeRows($newRows);
    $lastAllMatch = $lastAllMatch && $match;
    $lastOldTotal += $oldMs;
    $lastNewTotal += $newMs;
    $lastLines[] = [$lane, $playerId, $oldMs, $newMs, count($oldRows), $match];
}

function ratio(float $oldMs, float $newMs): float
{
    return $newMs > 0.0 ? $oldMs / $newMs : 0.0;
}

echo str_repeat('=', 96) . PHP_EOL;
echo "確率計算SQL 高速化候補 OLD/NEW比較" . PHP_EOL;
echo str_repeat('=', 96) . PHP_EOL;
echo "race_code : {$raceCode}" . PHP_EOL;
echo "target    : {$targetDate} / {$placeCode}" . PHP_EOL;
echo str_repeat('-', 96) . PHP_EOL;
printf("AI prior   OLD %8.1f ms / NEW %8.1f ms / %6.2fx / 一致 %s\n",
    $aiOldMs, $aiNewMs, ratio($aiOldMs, $aiNewMs), yesNo($aiMatch));
printf("Base prior OLD %8.1f ms / NEW %8.1f ms / %6.2fx / 一致 %s\n",
    $baseOldMs, $baseNewMs, ratio($baseOldMs, $baseNewMs), yesNo($baseMatch));
echo str_repeat('-', 96) . PHP_EOL;
echo "選手last100" . PHP_EOL;
foreach ($lastLines as [$lane, $playerId, $oldMs, $newMs, $n, $match]) {
    printf("%d号艇 player=%s  OLD %7.1f ms / NEW %7.1f ms / %6.2fx / rows=%d / 一致 %s\n",
        $lane, $playerId, $oldMs, $newMs, ratio($oldMs, $newMs), $n, yesNo($match));
}
printf("last100合計       OLD %8.1f ms / NEW %8.1f ms / %6.2fx / 一致 %s\n",
    $lastOldTotal, $lastNewTotal, ratio($lastOldTotal, $lastNewTotal), yesNo($lastAllMatch));
echo str_repeat('-', 96) . PHP_EOL;
$allMatch = $aiMatch && $baseMatch && $lastAllMatch;
printf("3項目総計         OLD %8.1f ms / NEW %8.1f ms / %6.2fx\n",
    $aiOldMs + $baseOldMs + $lastOldTotal,
    $aiNewMs + $baseNewMs + $lastNewTotal,
    ratio($aiOldMs + $baseOldMs + $lastOldTotal, $aiNewMs + $baseNewMs + $lastNewTotal));
echo "完全一致           : " . yesNo($allMatch) . PHP_EOL;
if (!$allMatch) {
    echo "※ NO がある項目は本番反映しません。" . PHP_EOL;
}
echo str_repeat('=', 96) . PHP_EOL;

exit($allMatch ? 0 : 2);
