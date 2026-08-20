<?php
declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

$raceCode = strtoupper(trim((string)($argv[1] ?? '')));
if (!preg_match('/^(\d{8})([A-Z0-9]{3})(\d{2})$/', $raceCode, $m)) {
    fwrite(STDERR, "Usage: php analysis/head1_fact_benchmark.php YYYYMMDDXXXRR\n");
    exit(1);
}

$targetDateObj = DateTimeImmutable::createFromFormat('!Ymd', $m[1]);
if (!$targetDateObj) {
    fwrite(STDERR, "日付解析エラー\n");
    exit(1);
}
$targetDate = $targetDateObj->format('Y-m-d');
$historyStart = $targetDateObj->modify('-730 days')->format('Y-m-d');
$placeCode = $m[2];
$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function timedQuery(PDO $pdo, string $sql, array $params): array
{
    $t0 = hrtime(true);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ms = (hrtime(true) - $t0) / 1_000_000.0;
    return [$rows, $ms];
}

function validCourse(mixed $value): ?int
{
    if ($value === null || $value === '' || !is_numeric($value)) return null;
    $course = (int)$value;
    return ($course >= 1 && $course <= 6) ? $course : null;
}

function priorCanonical(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $key = (string)($row['place_code'] ?? '') . '-' . (int)($row['second_course'] ?? 0);
        $out[$key] = (int)($row['race_n'] ?? 0);
    }
    ksort($out);
    return $out;
}

$exists = $pdo->query("SELECT to_regclass('boat_race.race_history_fact')")->fetchColumn();
if (!$exists) {
    fwrite(STDERR, "race_history_fact がありません。先に rebuild_race_history_fact.php を実行してください。\n");
    exit(1);
}

$cols = $pdo->query(<<<SQL
SELECT column_name
FROM information_schema.columns
WHERE table_schema = 'boat_race'
  AND table_name = 'race_history_fact'
  AND column_name IN ('head1_prior_valid', 'head1_player_eligible')
SQL)->fetchAll(PDO::FETCH_COLUMN);
if (count($cols) !== 2) {
    fwrite(STDERR, "2着率用Fact列がありません。最新の rebuild_race_history_fact.php を再実行してください。\n");
    exit(1);
}

$oldPriorSql = <<<'SQL'
WITH race_rows AS (
    SELECT
        re.race_code,
        SUBSTRING(re.race_code, 9, 3) AS place_code,
        re.lane_number::int AS lane,
        rrd.rank,
        COALESCE(
            CASE WHEN rrd.entry_course::text ~ '^[1-6]$' THEN rrd.entry_course::int ELSE NULL END,
            CASE WHEN el.entry_course::text ~ '^[1-6]$' THEN el.entry_course::int ELSE NULL END,
            CASE WHEN re.lane_number::text ~ '^[1-6]$' THEN re.lane_number::int ELSE NULL END
        ) AS actual_course
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
    WHERE rm.race_date >= ?::date
      AND (
            rm.race_date < ?::date
            OR (rm.race_date = ?::date AND re.race_code < ?)
          )
),
eligible AS (
    SELECT
        race_code,
        place_code,
        COUNT(*) AS row_n,
        COUNT(DISTINCT lane) AS lane_n,
        MIN(lane) AS min_lane,
        MAX(lane) AS max_lane,
        COUNT(*) FILTER (WHERE rank = '1') AS rank1_n,
        COUNT(*) FILTER (WHERE rank = '2') AS rank2_n,
        MIN(lane) FILTER (WHERE rank = '1') AS winner_lane,
        MIN(actual_course) FILTER (WHERE rank = '2') AS second_course,
        COUNT(actual_course) AS course_n,
        COUNT(DISTINCT actual_course) AS course_distinct_n,
        MIN(actual_course) AS min_course,
        MAX(actual_course) AS max_course
    FROM race_rows
    GROUP BY race_code, place_code
)
SELECT place_code, second_course, COUNT(*) AS race_n
FROM eligible
WHERE row_n = 6
  AND lane_n = 6
  AND min_lane = 1
  AND max_lane = 6
  AND rank1_n = 1
  AND rank2_n = 1
  AND winner_lane = 1
  AND course_n = 6
  AND course_distinct_n = 6
  AND min_course = 1
  AND max_course = 6
  AND second_course BETWEEN 1 AND 6
GROUP BY place_code, second_course
ORDER BY place_code, second_course
SQL;

$newPriorSql = <<<'SQL'
SELECT
    place_code,
    c2 AS second_course,
    COUNT(*) AS race_n
FROM boat_race.race_history_fact
WHERE head1_prior_valid
  AND race_date >= ?::date
  AND (
        race_date < ?::date
        OR (race_date = ?::date AND race_code < ?)
      )
GROUP BY place_code, c2
ORDER BY place_code, c2
SQL;

$targetSql = <<<'SQL'
SELECT
    re.lane_number::int AS lane,
    re.player_id::text AS player_id,
    COALESCE(
        CASE WHEN el.entry_course::text ~ '^[1-6]$' THEN el.entry_course::int ELSE NULL END,
        re.lane_number::int
    ) AS target_course
FROM boat_race.race_entry re
LEFT JOIN LATERAL (
    SELECT x.entry_course
    FROM boat_race.exhibition_live x
    WHERE x.race_code = re.race_code
      AND x.player_id = re.player_id
    LIMIT 1
) el ON TRUE
WHERE re.race_code = ?
ORDER BY re.lane_number
SQL;
$stmt = $pdo->prepare($targetSql);
$stmt->execute([$raceCode]);
$boats = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (count($boats) !== 6) {
    fwrite(STDERR, "対象レースが6艇ではありません。\n");
    exit(1);
}

$oldRecentSql = <<<'SQL'
WITH recent AS (
    SELECT
        rm.race_date,
        re.race_code,
        re.lane_number::int AS lane,
        rrd.rank,
        COALESCE(
            CASE WHEN rrd.entry_course::text ~ '^[1-6]$' THEN rrd.entry_course::int ELSE NULL END,
            CASE WHEN el.entry_course::text ~ '^[1-6]$' THEN el.entry_course::int ELSE NULL END,
            CASE WHEN re.lane_number::text ~ '^[1-6]$' THEN re.lane_number::int ELSE NULL END
        ) AS actual_course
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
      AND rm.race_date >= ?::date
      AND (
            rm.race_date < ?::date
            OR (rm.race_date = ?::date AND re.race_code < ?)
          )
    ORDER BY rm.race_date DESC, re.race_code DESC
    LIMIT 100
)
SELECT
    r.race_code,
    r.rank,
    r.actual_course,
    stats.row_n,
    stats.lane_n,
    stats.min_lane,
    stats.max_lane,
    stats.rank1_n,
    stats.rank2_n,
    stats.winner_lane
FROM recent r
LEFT JOIN LATERAL (
    SELECT
        COUNT(*) AS row_n,
        COUNT(DISTINCT re2.lane_number) AS lane_n,
        MIN(re2.lane_number::int) AS min_lane,
        MAX(re2.lane_number::int) AS max_lane,
        COUNT(*) FILTER (WHERE rrd2.rank = '1') AS rank1_n,
        COUNT(*) FILTER (WHERE rrd2.rank = '2') AS rank2_n,
        MIN(re2.lane_number::int) FILTER (WHERE rrd2.rank = '1') AS winner_lane
    FROM boat_race.race_entry re2
    LEFT JOIN boat_race.race_result_detail rrd2
      ON rrd2.race_code = re2.race_code
     AND rrd2.player_id = re2.player_id
    WHERE re2.race_code = r.race_code
) stats ON TRUE
ORDER BY r.race_date DESC, r.race_code DESC
SQL;

$newRecentSql = <<<'SQL'
WITH recent AS (
    SELECT
        rm.race_date,
        re.race_code,
        rrd.rank,
        COALESCE(
            CASE WHEN rrd.entry_course::text ~ '^[1-6]$' THEN rrd.entry_course::int ELSE NULL END,
            CASE WHEN el.entry_course::text ~ '^[1-6]$' THEN el.entry_course::int ELSE NULL END,
            CASE WHEN re.lane_number::text ~ '^[1-6]$' THEN re.lane_number::int ELSE NULL END
        ) AS actual_course,
        COALESCE(hf.head1_player_eligible, false) AS head1_player_eligible
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
    LEFT JOIN boat_race.race_history_fact hf
      ON hf.race_code = re.race_code
    WHERE re.player_id::text = ?
      AND rm.race_date >= ?::date
      AND (
            rm.race_date < ?::date
            OR (rm.race_date = ?::date AND re.race_code < ?)
          )
    ORDER BY rm.race_date DESC, re.race_code DESC
    LIMIT 100
)
SELECT race_code, rank, actual_course, head1_player_eligible
FROM recent
ORDER BY race_date DESC, race_code DESC
SQL;

function oldCounts(array $rows, int $targetCourse): array
{
    $n = 0;
    $w = 0;
    foreach ($rows as $row) {
        $course = validCourse($row['actual_course'] ?? null);
        if ($course === null) continue;
        $eligible = (
            (int)($row['row_n'] ?? 0) === 6
            && (int)($row['lane_n'] ?? 0) === 6
            && (int)($row['min_lane'] ?? 0) === 1
            && (int)($row['max_lane'] ?? 0) === 6
            && (int)($row['rank1_n'] ?? 0) === 1
            && (int)($row['rank2_n'] ?? 0) === 1
            && (int)($row['winner_lane'] ?? 0) === 1
        );
        if (!$eligible || $course !== $targetCourse) continue;
        $n++;
        if ((string)($row['rank'] ?? '') === '2') $w++;
    }
    return [$n, $w];
}

function newCounts(array $rows, int $targetCourse): array
{
    $n = 0;
    $w = 0;
    foreach ($rows as $row) {
        $course = validCourse($row['actual_course'] ?? null);
        if ($course === null || empty($row['head1_player_eligible']) || $course !== $targetCourse) continue;
        $n++;
        if ((string)($row['rank'] ?? '') === '2') $w++;
    }
    return [$n, $w];
}

echo str_repeat('=', 92) . PHP_EOL;
echo "1号艇1着時2着率 Fact 速度・一致比較\n";
echo str_repeat('=', 92) . PHP_EOL;
echo "race_code   : {$raceCode}\n";
echo "target      : {$targetDate} / {$placeCode}\n";
echo "history     : {$historyStart} ～ target直前\n\n";

$params = [$historyStart, $targetDate, $targetDate, $raceCode];
[$oldPriorRows, $oldPriorMs] = timedQuery($pdo, $oldPriorSql, $params);
[$newPriorRows, $newPriorMs] = timedQuery($pdo, $newPriorSql, $params);
$priorSame = priorCanonical($oldPriorRows) === priorCanonical($newPriorRows);

printf("prior OLD   : %8.1f ms / rows=%d\n", $oldPriorMs, count($oldPriorRows));
printf("prior FACT  : %8.1f ms / rows=%d / %.2fx\n", $newPriorMs, count($newPriorRows), $newPriorMs > 0 ? $oldPriorMs / $newPriorMs : 0.0);
echo "prior一致   : " . ($priorSame ? 'YES' : 'NO') . "\n\n";

$allPlayersSame = true;
$oldPlayerMs = 0.0;
$newPlayerMs = 0.0;
echo "【選手×今回コース 直近100走】\n";
echo "艇  C   OLD n/w     FACT n/w    一致    OLDms   FACTms\n";
echo str_repeat('-', 72) . PHP_EOL;
foreach ($boats as $boatRow) {
    $lane = (int)$boatRow['lane'];
    if ($lane === 1) continue;
    $playerId = (string)$boatRow['player_id'];
    $targetCourse = validCourse($boatRow['target_course'] ?? $lane) ?? $lane;
    $p = [$playerId, $historyStart, $targetDate, $targetDate, $raceCode];
    [$oldRows, $oms] = timedQuery($pdo, $oldRecentSql, $p);
    [$newRows, $nms] = timedQuery($pdo, $newRecentSql, $p);
    [$on, $ow] = oldCounts($oldRows, $targetCourse);
    [$nn, $nw] = newCounts($newRows, $targetCourse);
    $same = ($on === $nn && $ow === $nw);
    $allPlayersSame = $allPlayersSame && $same;
    $oldPlayerMs += $oms;
    $newPlayerMs += $nms;
    printf("%d  %dC  %3d/%-3d     %3d/%-3d     %-4s  %7.1f  %7.1f\n",
        $lane, $targetCourse, $on, $ow, $nn, $nw, $same ? 'YES' : 'NO', $oms, $nms
    );
}

printf("\nplayer合計  : OLD %.1f ms / FACT %.1f ms / %.2fx\n",
    $oldPlayerMs,
    $newPlayerMs,
    $newPlayerMs > 0 ? $oldPlayerMs / $newPlayerMs : 0.0
);
echo "選手一致    : " . ($allPlayersSame ? 'YES' : 'NO') . "\n";
echo "総合一致    : " . (($priorSame && $allPlayersSame) ? 'YES' : 'NO') . "\n";
echo str_repeat('=', 92) . PHP_EOL;
