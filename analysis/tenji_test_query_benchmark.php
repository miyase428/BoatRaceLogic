<?php
declare(strict_types=1);

/**
 * public/tenji_test.php の現行SQLと高速化候補を比較する。
 *
 * 目的:
 * - 現行の recent_6m × recent_3m の二重JOINをやめる
 * - race_result_detail を1回だけ走査して6ヶ月/3ヶ月を条件集計する
 * - 出力値が完全一致することを確認してから本番反映する
 *
 * Usage:
 *   php analysis/tenji_test_query_benchmark.php 20260818SME12
 */

require_once __DIR__ . '/../common/db_connect.php';

$raceCode = strtoupper(trim((string)($argv[1] ?? '')));
if (!preg_match('/^\d{8}[A-Z0-9]{3}\d{2}$/', $raceCode)) {
    fwrite(STDERR, "Usage: php analysis/tenji_test_query_benchmark.php YYYYMMDDXXXRR\n");
    exit(1);
}

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 本番 ApiClientProduction::fetchTenjiTest と同じ course -> boat の逆写像を作る。
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

$courseToBoat = [];
$seenBoats = [];
foreach ($targetRows as $row) {
    $boat = (int)$row['lane_number'];
    $course = (int)$row['entry_course'];
    if ($boat < 1 || $boat > 6 || $course < 1 || $course > 6 || isset($seenBoats[$boat]) || isset($courseToBoat[$course])) {
        throw new RuntimeException('展示進入が1～6コースで一意ではありません');
    }
    $seenBoats[$boat] = true;
    $courseToBoat[$course] = $boat;
}
ksort($courseToBoat);
if (array_keys($courseToBoat) !== [1, 2, 3, 4, 5, 6]) {
    throw new RuntimeException('展示進入1～6コースが揃っていません');
}

$params = [':race_code' => $raceCode];
for ($course = 1; $course <= 6; $course++) {
    $params[":tenji{$course}"] = $courseToBoat[$course];
}

$oldSql = <<<SQL
WITH tenji AS (
    SELECT 1 AS wakuban, :tenji1 AS teiban
    UNION ALL SELECT 2, :tenji2
    UNION ALL SELECT 3, :tenji3
    UNION ALL SELECT 4, :tenji4
    UNION ALL SELECT 5, :tenji5
    UNION ALL SELECT 6, :tenji6
),
entry AS (
    SELECT
        t.wakuban,
        e.player_id
    FROM tenji t
    JOIN boat_race.race_entry e
      ON e.race_code = :race_code
     AND e.lane_number = t.teiban::integer
),
recent_6m AS (
    SELECT
        r.player_id,
        r.rank
    FROM boat_race.race_result_detail r
    JOIN entry e
      ON r.player_id = e.player_id
    WHERE TO_DATE(SUBSTRING(r.race_code, 1, 8), 'YYYYMMDD')
          >= CURRENT_DATE - INTERVAL '6 months'
),
recent_3m AS (
    SELECT
        r.player_id,
        r.rank
    FROM boat_race.race_result_detail r
    JOIN entry e
      ON r.player_id = e.player_id
    WHERE TO_DATE(SUBSTRING(r.race_code, 1, 8), 'YYYYMMDD')
          >= CURRENT_DATE - INTERVAL '3 months'
)
SELECT
    e.wakuban,
    e.player_id,
    COUNT(*) FILTER (WHERE r6.rank IN ('1','2','3'))::float
        / NULLIF(COUNT(r6.rank), 0) AS three_in_rate_6m,
    COUNT(*) FILTER (WHERE r3.rank IN ('1','2','3'))::float
        / NULLIF(COUNT(r3.rank), 0) AS three_in_rate_3m
FROM entry e
LEFT JOIN recent_6m r6 ON e.player_id = r6.player_id
LEFT JOIN recent_3m r3 ON e.player_id = r3.player_id
GROUP BY e.wakuban, e.player_id
ORDER BY e.wakuban
SQL;

$newSql = <<<SQL
WITH tenji AS (
    SELECT 1 AS wakuban, :tenji1 AS teiban
    UNION ALL SELECT 2, :tenji2
    UNION ALL SELECT 3, :tenji3
    UNION ALL SELECT 4, :tenji4
    UNION ALL SELECT 5, :tenji5
    UNION ALL SELECT 6, :tenji6
),
entry AS (
    SELECT
        t.wakuban,
        e.player_id
    FROM tenji t
    JOIN boat_race.race_entry e
      ON e.race_code = :race_code
     AND e.lane_number = t.teiban::integer
)
SELECT
    e.wakuban,
    e.player_id,
    COUNT(*) FILTER (
        WHERE r.rank IN ('1','2','3')
          AND r.race_code >= TO_CHAR(CURRENT_DATE - INTERVAL '6 months', 'YYYYMMDD')
    )::float
        / NULLIF(
            COUNT(r.rank) FILTER (
                WHERE r.race_code >= TO_CHAR(CURRENT_DATE - INTERVAL '6 months', 'YYYYMMDD')
            ),
            0
        ) AS three_in_rate_6m,
    COUNT(*) FILTER (
        WHERE r.rank IN ('1','2','3')
          AND r.race_code >= TO_CHAR(CURRENT_DATE - INTERVAL '3 months', 'YYYYMMDD')
    )::float
        / NULLIF(
            COUNT(r.rank) FILTER (
                WHERE r.race_code >= TO_CHAR(CURRENT_DATE - INTERVAL '3 months', 'YYYYMMDD')
            ),
            0
        ) AS three_in_rate_3m
FROM entry e
LEFT JOIN boat_race.race_result_detail r
  ON r.player_id = e.player_id
 AND r.race_code >= TO_CHAR(CURRENT_DATE - INTERVAL '6 months', 'YYYYMMDD')
GROUP BY e.wakuban, e.player_id
ORDER BY e.wakuban
SQL;

function runTimed(PDO $pdo, string $sql, array $params): array
{
    $t0 = hrtime(true);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ms = (hrtime(true) - $t0) / 1_000_000.0;
    return [$rows, $ms];
}

function normRate(mixed $v): ?float
{
    if ($v === null || $v === '') {
        return null;
    }
    return (float)$v;
}

function sameRate(?float $a, ?float $b): bool
{
    if ($a === null || $b === null) {
        return $a === $b;
    }
    return abs($a - $b) <= 1e-12;
}

[$oldRows, $oldMs] = runTimed($pdo, $oldSql, $params);
[$newRows, $newMs] = runTimed($pdo, $newSql, $params);

$allMatch = count($oldRows) === 6 && count($newRows) === 6;
$lines = [];
for ($i = 0; $i < max(count($oldRows), count($newRows)); $i++) {
    $o = $oldRows[$i] ?? [];
    $n = $newRows[$i] ?? [];
    $o6 = normRate($o['three_in_rate_6m'] ?? null);
    $n6 = normRate($n['three_in_rate_6m'] ?? null);
    $o3 = normRate($o['three_in_rate_3m'] ?? null);
    $n3 = normRate($n['three_in_rate_3m'] ?? null);
    $match = ((string)($o['wakuban'] ?? '') === (string)($n['wakuban'] ?? ''))
        && ((string)($o['player_id'] ?? '') === (string)($n['player_id'] ?? ''))
        && sameRate($o6, $n6)
        && sameRate($o3, $n3);
    $allMatch = $allMatch && $match;
    $lines[] = [
        'wakuban' => (int)($o['wakuban'] ?? $n['wakuban'] ?? 0),
        'player' => (string)($o['player_id'] ?? $n['player_id'] ?? ''),
        'old6' => $o6,
        'new6' => $n6,
        'old3' => $o3,
        'new3' => $n3,
        'match' => $match,
    ];
}

$ratio = $newMs > 0 ? $oldMs / $newMs : 0.0;
$entryOrder = implode('', array_values($courseToBoat));

echo str_repeat('=', 100) . PHP_EOL;
echo "tenji_test.php SQL OLD/NEW 完全一致・速度比較" . PHP_EOL;
echo str_repeat('=', 100) . PHP_EOL;
echo "race_code : {$raceCode}" . PHP_EOL;
echo "tenji1-6 : {$entryOrder}（コース->艇番）" . PHP_EOL;
echo str_repeat('-', 100) . PHP_EOL;
foreach ($lines as $line) {
    $fmt = static fn(?float $v): string => $v === null ? 'NULL' : number_format($v * 100.0, 6) . '%';
    printf(
        "%dC player=%-5s  6m OLD=%-12s NEW=%-12s / 3m OLD=%-12s NEW=%-12s / 一致 %s\n",
        $line['wakuban'],
        $line['player'],
        $fmt($line['old6']),
        $fmt($line['new6']),
        $fmt($line['old3']),
        $fmt($line['new3']),
        $line['match'] ? 'YES' : 'NO'
    );
}
echo str_repeat('-', 100) . PHP_EOL;
printf("OLD : %8.1f ms\n", $oldMs);
printf("NEW : %8.1f ms\n", $newMs);
printf("速度比: %8.2fx\n", $ratio);
echo "完全一致: " . ($allMatch ? 'YES' : 'NO') . PHP_EOL;
echo str_repeat('=', 100) . PHP_EOL;

exit($allMatch ? 0 : 2);
