<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

/**
 * race_entry は6艇あるが、展示5指標が1艇だけ全NULLになっている
 * 「実質5艇立て」候補を検索する。
 *
 * 典型例:
 *   E5 ST5 L5 A5 D5
 *
 * Usage:
 *   php analysis/find_effective_five_boat_races.php 2025-09-01 2026-09-04 50
 */

$from = trim((string)($argv[1] ?? ''));
$to = trim((string)($argv[2] ?? ''));
$limit = max(1, min(500, (int)($argv[3] ?? 50)));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)
    || $from > $to) {
    fwrite(STDERR, "Usage: php {$argv[0]} YYYY-MM-DD YYYY-MM-DD [limit]\n");
    exit(1);
}

$pdo = getPDO();

$sql = <<<SQL
WITH ex AS (
    SELECT
        re.race_code,
        COUNT(*) AS entry_rows,
        COUNT(el.player_id) AS joined_ex_rows,
        COUNT(*) FILTER (WHERE el.exhibition_time IS NOT NULL) AS e_cnt,
        COUNT(*) FILTER (WHERE el.start_timing IS NOT NULL) AS st_cnt,
        COUNT(*) FILTER (WHERE el.lap_time IS NOT NULL) AS l_cnt,
        COUNT(*) FILTER (WHERE el.around_time IS NOT NULL) AS a_cnt,
        COUNT(*) FILTER (WHERE el.straight_time IS NOT NULL) AS d_cnt,
        COUNT(*) FILTER (
            WHERE el.exhibition_time IS NULL
              AND el.start_timing IS NULL
              AND el.lap_time IS NULL
              AND el.around_time IS NULL
              AND el.straight_time IS NULL
        ) AS all_null_boats,
        STRING_AGG(
            CASE
                WHEN el.exhibition_time IS NULL
                 AND el.start_timing IS NULL
                 AND el.lap_time IS NULL
                 AND el.around_time IS NULL
                 AND el.straight_time IS NULL
                THEN re.lane_number::text
                ELSE NULL
            END,
            ',' ORDER BY re.lane_number
        ) AS null_lanes
    FROM boat_race.race_entry re
    LEFT JOIN boat_race.exhibition_live el
      ON el.race_code = re.race_code
     AND el.player_id = re.player_id
    WHERE re.race_date BETWEEN :from::date AND :to::date
    GROUP BY re.race_code
),
res AS (
    SELECT
        rrd.race_code,
        COUNT(*) FILTER (WHERE rrd.rank::text = '1') AS r1,
        COUNT(*) FILTER (WHERE rrd.rank::text = '2') AS r2,
        COUNT(*) FILTER (WHERE rrd.rank::text = '3') AS r3
    FROM boat_race.race_result_detail rrd
    GROUP BY rrd.race_code
),
pay AS (
    SELECT
        rp.race_code,
        MAX(COALESCE(rp.trifecta_payout, 0)) AS trifecta_payout
    FROM boat_race.race_payouts rp
    GROUP BY rp.race_code
)
SELECT
    ex.race_code,
    ex.entry_rows,
    ex.joined_ex_rows,
    ex.e_cnt,
    ex.st_cnt,
    ex.l_cnt,
    ex.a_cnt,
    ex.d_cnt,
    ex.all_null_boats,
    ex.null_lanes,
    COALESCE(res.r1, 0) AS r1,
    COALESCE(res.r2, 0) AS r2,
    COALESCE(res.r3, 0) AS r3,
    COALESCE(pay.trifecta_payout, 0) AS trifecta_payout
FROM ex
LEFT JOIN res ON res.race_code = ex.race_code
LEFT JOIN pay ON pay.race_code = ex.race_code
WHERE ex.entry_rows = 6
  AND ex.joined_ex_rows = 6
  AND ex.all_null_boats = 1
  AND ex.e_cnt = 5
  AND ex.st_cnt = 5
  AND ex.l_cnt = 5
  AND ex.a_cnt = 5
  AND ex.d_cnt = 5
ORDER BY ex.race_code DESC
LIMIT :limit
SQL;

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':from', $from);
$stmt->bindValue(':to', $to);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo str_repeat('=', 150) . "\n";
echo "実質5艇立て候補検索（race_entry=6 / 1艇だけ展示5指標すべてNULL）\n";
echo "期間 : {$from} ～ {$to}\n";
echo "件数 : " . count($rows) . "R（最大 {$limit}R）\n";
echo str_repeat('=', 150) . "\n";

if (!$rows) {
    echo "該当レースなし\n";
    exit(0);
}

foreach ($rows as $r) {
    $established = ((int)$r['r1'] === 1 && (int)$r['r2'] === 1 && (int)$r['r3'] === 1 && (int)$r['trifecta_payout'] > 0);
    printf(
        "%s | null艇=%s | E%d ST%d L%d A%d D%d | top3=%d/%d/%d payout=%d | %s\n",
        $r['race_code'],
        $r['null_lanes'] ?: '-',
        (int)$r['e_cnt'],
        (int)$r['st_cnt'],
        (int)$r['l_cnt'],
        (int)$r['a_cnt'],
        (int)$r['d_cnt'],
        (int)$r['r1'],
        (int)$r['r2'],
        (int)$r['r3'],
        (int)$r['trifecta_payout'],
        $established ? '成立 ★通し確認向き' : '要確認'
    );
}
