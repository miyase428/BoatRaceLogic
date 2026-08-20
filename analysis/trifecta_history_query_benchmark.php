<?php
declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

$raceCode = strtoupper(trim((string)($argv[1] ?? '')));
if (!preg_match('/^(\d{8})([A-Z0-9]{3})(\d{2})$/', $raceCode, $m)) {
    fwrite(STDERR, "Usage: php analysis/trifecta_history_query_benchmark.php YYYYMMDDXXXRR\n");
    exit(1);
}

$targetDate = DateTimeImmutable::createFromFormat('!Ymd', $m[1]);
if (!$targetDate) {
    fwrite(STDERR, "日付解析エラー\n");
    exit(1);
}
$targetDateText = $targetDate->format('Y-m-d');
$placeCode = $m[2];
$pdo = getPDO();

$oldSql = <<<'SQL'
WITH top3_rows AS (
    SELECT
        re.race_code,
        SUBSTRING(re.race_code, 9, 3) AS place_code,
        CASE WHEN rrd.rank::text ~ '^[1-3]$' THEN rrd.rank::int ELSE NULL END AS rank_no,
        COALESCE(
            CASE WHEN rrd.entry_course::text ~ '^[1-6]$' THEN rrd.entry_course::int ELSE NULL END,
            CASE WHEN el.entry_course::text ~ '^[1-6]$' THEN el.entry_course::int ELSE NULL END,
            CASE WHEN re.lane_number::text ~ '^[1-6]$' THEN re.lane_number::int ELSE NULL END
        ) AS actual_course
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
    WHERE (
            rm.race_date < ?::date
            OR (rm.race_date = ?::date AND re.race_code < ?)
          )
      AND rrd.rank::text IN ('1', '2', '3')
),
race_patterns AS (
    SELECT
        race_code,
        place_code,
        COUNT(*) AS row_n,
        COUNT(DISTINCT rank_no) AS rank_n,
        COUNT(DISTINCT actual_course) AS course_n,
        MAX(actual_course) FILTER (WHERE rank_no = 1) AS c1,
        MAX(actual_course) FILTER (WHERE rank_no = 2) AS c2,
        MAX(actual_course) FILTER (WHERE rank_no = 3) AS c3
    FROM top3_rows
    GROUP BY race_code, place_code
)
SELECT place_code, c1, c2, c3, COUNT(*) AS n
FROM race_patterns
WHERE row_n = 3
  AND rank_n = 3
  AND course_n = 3
  AND c1 BETWEEN 1 AND 6
  AND c2 BETWEEN 1 AND 6
  AND c3 BETWEEN 1 AND 6
GROUP BY place_code, c1, c2, c3
ORDER BY place_code, c1, c2, c3
SQL;

$newSql = <<<'SQL'
WITH top3_rows AS (
    SELECT
        rrd.race_code,
        SUBSTRING(rrd.race_code, 9, 3) AS place_code,
        CASE WHEN rrd.rank::text ~ '^[1-3]$' THEN rrd.rank::int ELSE NULL END AS rank_no,
        COALESCE(
            CASE WHEN rrd.entry_course::text ~ '^[1-6]$' THEN rrd.entry_course::int ELSE NULL END,
            CASE WHEN el.entry_course::text ~ '^[1-6]$' THEN el.entry_course::int ELSE NULL END,
            CASE WHEN rrd.lane_number::text ~ '^[1-6]$' THEN rrd.lane_number::int ELSE NULL END
        ) AS actual_course
    FROM boat_race.race_result_detail rrd
    JOIN boat_race.race_master rm
      ON rm.race_code = rrd.race_code
    LEFT JOIN LATERAL (
        SELECT x.entry_course
        FROM boat_race.exhibition_live x
        WHERE x.race_code = rrd.race_code
          AND x.player_id = rrd.player_id
          AND x.entry_course::text ~ '^[1-6]$'
        LIMIT 1
    ) el ON NOT (rrd.entry_course::text ~ '^[1-6]$')
    WHERE (
            rm.race_date < ?::date
            OR (rm.race_date = ?::date AND rrd.race_code < ?)
          )
      AND rrd.rank::text IN ('1', '2', '3')
),
race_patterns AS (
    SELECT
        race_code,
        place_code,
        COUNT(*) AS row_n,
        COUNT(DISTINCT rank_no) AS rank_n,
        COUNT(DISTINCT actual_course) AS course_n,
        MAX(actual_course) FILTER (WHERE rank_no = 1) AS c1,
        MAX(actual_course) FILTER (WHERE rank_no = 2) AS c2,
        MAX(actual_course) FILTER (WHERE rank_no = 3) AS c3
    FROM top3_rows
    GROUP BY race_code, place_code
)
SELECT place_code, c1, c2, c3, COUNT(*) AS n
FROM race_patterns
WHERE row_n = 3
  AND rank_n = 3
  AND course_n = 3
  AND c1 BETWEEN 1 AND 6
  AND c2 BETWEEN 1 AND 6
  AND c3 BETWEEN 1 AND 6
GROUP BY place_code, c1, c2, c3
ORDER BY place_code, c1, c2, c3
SQL;

function runQuery(PDO $pdo, string $sql, array $params): array
{
    $t0 = hrtime(true);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ms = (hrtime(true) - $t0) / 1_000_000.0;
    return [$rows, $ms];
}

function canonical(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $key = implode('-', [
            (string)($row['place_code'] ?? ''),
            (int)($row['c1'] ?? 0),
            (int)($row['c2'] ?? 0),
            (int)($row['c3'] ?? 0),
        ]);
        $out[$key] = (int)($row['n'] ?? 0);
    }
    ksort($out);
    return $out;
}

function totalN(array $rows): int
{
    return array_sum(array_map(static fn(array $r): int => (int)($r['n'] ?? 0), $rows));
}

$params = [$targetDateText, $targetDateText, $raceCode];

echo str_repeat('=', 78) . "\n";
echo "出目確率 履歴SQL 速度・一致比較\n";
echo str_repeat('=', 78) . "\n";
echo "race_code : {$raceCode}\n";
echo "target    : {$targetDateText} / {$placeCode}\n\n";

// キャッシュ影響を見るため旧→新→新→旧の順で実行する。
[$old1, $oldMs1] = runQuery($pdo, $oldSql, $params);
[$new1, $newMs1] = runQuery($pdo, $newSql, $params);
[$new2, $newMs2] = runQuery($pdo, $newSql, $params);
[$old2, $oldMs2] = runQuery($pdo, $oldSql, $params);

$oldCanon = canonical($old1);
$newCanon = canonical($new1);
$same = ($oldCanon === $newCanon);

printf("OLD 1回目 : %9.1f ms / grouped=%d / races=%d\n", $oldMs1, count($old1), totalN($old1));
printf("NEW 1回目 : %9.1f ms / grouped=%d / races=%d\n", $newMs1, count($new1), totalN($new1));
printf("NEW 2回目 : %9.1f ms\n", $newMs2);
printf("OLD 2回目 : %9.1f ms\n", $oldMs2);

$oldAvg = ($oldMs1 + $oldMs2) / 2.0;
$newAvg = ($newMs1 + $newMs2) / 2.0;
printf("\n平均       : OLD %.1f ms / NEW %.1f ms / %.2fx\n", $oldAvg, $newAvg, $newAvg > 0 ? $oldAvg / $newAvg : 0.0);
echo "集計一致   : " . ($same ? 'YES' : 'NO') . "\n";

if (!$same) {
    $keys = array_unique(array_merge(array_keys($oldCanon), array_keys($newCanon)));
    sort($keys);
    $diffs = [];
    foreach ($keys as $key) {
        $a = $oldCanon[$key] ?? 0;
        $b = $newCanon[$key] ?? 0;
        if ($a !== $b) {
            $diffs[] = [$key, $a, $b, $b - $a];
        }
    }
    echo "差分件数   : " . count($diffs) . "\n";
    echo "先頭20差分 :\n";
    foreach (array_slice($diffs, 0, 20) as [$key, $a, $b, $d]) {
        printf("  %-18s OLD=%5d NEW=%5d diff=%+d\n", $key, $a, $b, $d);
    }
}

echo str_repeat('=', 78) . "\n";
