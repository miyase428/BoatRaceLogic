<?php
declare(strict_types=1);

/**
 * public/kimarite_api.php の現行2回SQL（12ヶ月 + 6ヶ月）と、
 * 12ヶ月履歴を1回だけ走査して両期間を同時計算する候補を比較する。
 *
 * Usage:
 *   php analysis/kimarite_query_benchmark.php 20260818SME12
 */

require_once __DIR__ . '/../common/db_connect.php';

$raceCode = strtoupper(trim((string)($argv[1] ?? '')));
if (!preg_match('/^\d{8}[A-Z0-9]{3}\d{2}$/', $raceCode)) {
    fwrite(STDERR, "Usage: php analysis/kimarite_query_benchmark.php YYYYMMDDXXXRR\n");
    exit(1);
}

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Webと同じ「艇番 -> 展示進入コース」6桁を作る。
$targetSql = <<<SQL
SELECT
    re.lane_number,
    COALESCE(
        CASE WHEN el.entry_course BETWEEN 1 AND 6 THEN el.entry_course ELSE NULL END,
        re.lane_number
    ) AS entry_course
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
$targetRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (count($targetRows) !== 6) {
    throw new RuntimeException('対象レースの出走艇が6艇ではありません');
}

$inCourse = [];
$used = [];
foreach ($targetRows as $row) {
    $lane = (int)$row['lane_number'];
    $course = (int)$row['entry_course'];
    if ($lane < 1 || $lane > 6 || $course < 1 || $course > 6 || isset($used[$course])) {
        throw new RuntimeException('展示進入が1～6コースで一意ではありません');
    }
    $inCourse[$lane] = $course;
    $used[$course] = true;
}
ksort($inCourse);
$entryOrder = implode('', $inCourse);

$params = [':race_code' => $raceCode];
for ($i = 1; $i <= 6; $i++) {
    $params[":in{$i}"] = $inCourse[$i];
}

function oldSql(int $months): string
{
    return <<<SQL
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
    SELECT re.player_id, tm.today_course
    FROM boat_race.race_entry re
    JOIN tm ON tm.waku = re.lane_number
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
    JOIN boat_race.race_master rm ON rm.race_code = re.race_code
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
        SELECT rrd.player_id, rrd.entry_course, rrd.technique
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
        COUNT(*) FILTER (WHERE tm.today_course = 1 AND p.winner_player_id = tm.player_id) AS nige_cnt,
        COUNT(*) FILTER (WHERE tm.today_course = 1 AND p.winner_player_id <> tm.player_id AND p.winner_technique = '差し') AS sasare_cnt,
        COUNT(*) FILTER (WHERE tm.today_course = 1 AND p.winner_player_id <> tm.player_id AND p.winner_technique = 'まくり') AS makurare_cnt,
        COUNT(*) FILTER (WHERE tm.today_course = 1 AND p.winner_player_id <> tm.player_id AND p.winner_technique = 'まくり差し') AS makurarezashi_cnt,
        COUNT(*) FILTER (WHERE tm.today_course = 2 AND p.winner_player_id <> tm.player_id AND p.winner_course = 1) AS nogashi_cnt,
        COUNT(*) FILTER (WHERE tm.today_course <> 1 AND p.winner_player_id = tm.player_id AND p.winner_technique = '差し') AS sashi_cnt,
        COUNT(*) FILTER (WHERE tm.today_course <> 1 AND p.winner_player_id = tm.player_id AND p.winner_technique = 'まくり') AS makuri_cnt,
        COUNT(*) FILTER (WHERE tm.today_course <> 1 AND p.winner_player_id = tm.player_id AND p.winner_technique = 'まくり差し') AS makurizashi_cnt
    FROM today_members tm
    LEFT JOIN past p
      ON p.player_id = tm.player_id
     AND p.entry_course = tm.today_course::integer
    GROUP BY tm.today_course, tm.player_id
)
SELECT * FROM agg ORDER BY course
SQL;
}

$newSql = <<<SQL
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
    SELECT re.player_id, tm.today_course
    FROM boat_race.race_entry re
    JOIN tm ON tm.waku = re.lane_number
    WHERE re.race_code = :race_code
),
past AS (
    SELECT
        rm.race_date,
        re.player_id,
        COALESCE(rd.entry_course, ex.entry_course)::integer AS entry_course,
        w.player_id AS winner_player_id,
        w.entry_course::integer AS winner_course,
        TRIM(COALESCE(w.technique, '')) AS winner_technique
    FROM boat_race.race_entry re
    JOIN boat_race.race_master rm ON rm.race_code = re.race_code
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
        SELECT rrd.player_id, rrd.entry_course, rrd.technique
        FROM boat_race.race_result_detail rrd
        WHERE rrd.race_code = re.race_code
          AND TRIM(rrd.rank) = '1'
        LIMIT 1
    ) w ON TRUE
    WHERE rm.race_date >= CURRENT_DATE - INTERVAL '12 months'
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
        COUNT(player_id) FILTER (WHERE race_date >= CURRENT_DATE - INTERVAL '6 months') AS total_6,

        COUNT(*) FILTER (WHERE course = 1 AND winner_player_id = target_player_id) AS nige_12,
        COUNT(*) FILTER (WHERE race_date >= CURRENT_DATE - INTERVAL '6 months' AND course = 1 AND winner_player_id = target_player_id) AS nige_6,

        COUNT(*) FILTER (WHERE course = 1 AND winner_player_id <> target_player_id AND winner_technique = '差し') AS sasare_12,
        COUNT(*) FILTER (WHERE race_date >= CURRENT_DATE - INTERVAL '6 months' AND course = 1 AND winner_player_id <> target_player_id AND winner_technique = '差し') AS sasare_6,

        COUNT(*) FILTER (WHERE course = 1 AND winner_player_id <> target_player_id AND winner_technique = 'まくり') AS makurare_12,
        COUNT(*) FILTER (WHERE race_date >= CURRENT_DATE - INTERVAL '6 months' AND course = 1 AND winner_player_id <> target_player_id AND winner_technique = 'まくり') AS makurare_6,

        COUNT(*) FILTER (WHERE course = 1 AND winner_player_id <> target_player_id AND winner_technique = 'まくり差し') AS makurarezashi_12,
        COUNT(*) FILTER (WHERE race_date >= CURRENT_DATE - INTERVAL '6 months' AND course = 1 AND winner_player_id <> target_player_id AND winner_technique = 'まくり差し') AS makurarezashi_6,

        COUNT(*) FILTER (WHERE course = 2 AND winner_player_id <> target_player_id AND winner_course = 1) AS nogashi_12,
        COUNT(*) FILTER (WHERE race_date >= CURRENT_DATE - INTERVAL '6 months' AND course = 2 AND winner_player_id <> target_player_id AND winner_course = 1) AS nogashi_6,

        COUNT(*) FILTER (WHERE course <> 1 AND winner_player_id = target_player_id AND winner_technique = '差し') AS sashi_12,
        COUNT(*) FILTER (WHERE race_date >= CURRENT_DATE - INTERVAL '6 months' AND course <> 1 AND winner_player_id = target_player_id AND winner_technique = '差し') AS sashi_6,

        COUNT(*) FILTER (WHERE course <> 1 AND winner_player_id = target_player_id AND winner_technique = 'まくり') AS makuri_12,
        COUNT(*) FILTER (WHERE race_date >= CURRENT_DATE - INTERVAL '6 months' AND course <> 1 AND winner_player_id = target_player_id AND winner_technique = 'まくり') AS makuri_6,

        COUNT(*) FILTER (WHERE course <> 1 AND winner_player_id = target_player_id AND winner_technique = 'まくり差し') AS makurizashi_12,
        COUNT(*) FILTER (WHERE race_date >= CURRENT_DATE - INTERVAL '6 months' AND course <> 1 AND winner_player_id = target_player_id AND winner_technique = 'まくり差し') AS makurizashi_6
    FROM joined
    GROUP BY course, target_player_id
)
SELECT * FROM agg ORDER BY course
SQL;

function runTimed(PDO $pdo, string $sql, array $params): array
{
    $t0 = hrtime(true);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return [$rows, (hrtime(true) - $t0) / 1_000_000.0];
}

[$old12, $old12ms] = runTimed($pdo, oldSql(12), $params);
[$old6, $old6ms] = runTimed($pdo, oldSql(6), $params);
[$newRows, $newMs] = runTimed($pdo, $newSql, $params);

$keys = ['nige','sashi','makuri','makurizashi','nogashi','sasare','makurare','makurarezashi'];
$allMatch = count($old12) === 6 && count($old6) === 6 && count($newRows) === 6;
$lines = [];

for ($i = 0; $i < 6; $i++) {
    $o12 = $old12[$i] ?? [];
    $o6 = $old6[$i] ?? [];
    $n = $newRows[$i] ?? [];
    $course = (int)($o12['course'] ?? $n['course'] ?? 0);
    $match = ((string)($o12['course'] ?? '') === (string)($n['course'] ?? ''))
        && ((string)($o6['course'] ?? '') === (string)($n['course'] ?? ''))
        && ((int)($o12['total_cnt'] ?? -1) === (int)($n['total_12'] ?? -2))
        && ((int)($o6['total_cnt'] ?? -1) === (int)($n['total_6'] ?? -2));

    foreach ($keys as $key) {
        $match = $match
            && ((int)($o12[$key . '_cnt'] ?? -1) === (int)($n[$key . '_12'] ?? -2))
            && ((int)($o6[$key . '_cnt'] ?? -1) === (int)($n[$key . '_6'] ?? -2));
    }

    $allMatch = $allMatch && $match;
    $lines[] = [
        'course' => $course,
        'n12' => (int)($o12['total_cnt'] ?? 0),
        'n6' => (int)($o6['total_cnt'] ?? 0),
        'match' => $match,
    ];
}

$oldTotal = $old12ms + $old6ms;
$ratio = $newMs > 0.0 ? $oldTotal / $newMs : 0.0;

echo str_repeat('=', 100) . PHP_EOL;
echo "kimarite_api.php SQL OLD/NEW 完全一致・速度比較" . PHP_EOL;
echo str_repeat('=', 100) . PHP_EOL;
echo "race_code : {$raceCode}" . PHP_EOL;
echo "in_course : {$entryOrder}（艇番->コース）" . PHP_EOL;
echo str_repeat('-', 100) . PHP_EOL;
foreach ($lines as $line) {
    printf("%dC  1年N=%4d / 6ヶ月N=%4d / 一致 %s\n",
        $line['course'], $line['n12'], $line['n6'], $line['match'] ? 'YES' : 'NO');
}
echo str_repeat('-', 100) . PHP_EOL;
printf("OLD 12m : %8.1f ms\n", $old12ms);
printf("OLD  6m : %8.1f ms\n", $old6ms);
printf("OLD 合計: %8.1f ms\n", $oldTotal);
printf("NEW 1回 : %8.1f ms\n", $newMs);
printf("速度比  : %8.2fx\n", $ratio);
echo "完全一致: " . ($allMatch ? 'YES' : 'NO') . PHP_EOL;
echo str_repeat('=', 100) . PHP_EOL;

exit($allMatch ? 0 : 2);
