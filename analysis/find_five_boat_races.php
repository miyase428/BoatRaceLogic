<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

/**
 * race_entry が5艇だけ存在する「真正の5艇立て」候補を探す。
 *
 * Usage:
 *   php analysis/find_five_boat_races.php 2025-09-01 2026-09-04 50
 *
 * 出力では、展示行数・結果行数・払戻有無も併記し、
 * 予想の通し確認に使いやすい成立済み候補を上位に出す。
 */

$from = trim((string)($argv[1] ?? '2025-09-01'));
$to = trim((string)($argv[2] ?? date('Y-m-d')));
$limit = max(1, min(200, (int)($argv[3] ?? 50)));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)
    || $from > $to) {
    fwrite(STDERR, "Usage: php {$argv[0]} YYYY-MM-DD YYYY-MM-DD [limit]\n");
    exit(1);
}

$pdo = getPDO();

$sql = <<<SQL
WITH five_entry AS (
    SELECT
        re.race_code,
        MIN(re.race_date) AS race_date,
        COUNT(*)::int AS entry_rows,
        COUNT(DISTINCT re.lane_number)::int AS lane_count,
        COUNT(DISTINCT re.player_id)::int AS player_count,
        STRING_AGG(re.lane_number::text, ',' ORDER BY re.lane_number) AS lanes
    FROM boat_race.race_entry re
    WHERE re.race_date BETWEEN :from_date AND :to_date
    GROUP BY re.race_code
    HAVING COUNT(*) = 5
       AND COUNT(DISTINCT re.lane_number) = 5
       AND COUNT(DISTINCT re.player_id) = 5
),
ex AS (
    SELECT
        el.race_code,
        COUNT(*)::int AS exhibition_rows,
        COUNT(DISTINCT el.entry_course)::int AS exhibition_courses,
        COUNT(*) FILTER (WHERE el.player_id IS NOT NULL)::int AS exhibition_players
    FROM boat_race.exhibition_live el
    JOIN five_entry f ON f.race_code = el.race_code
    GROUP BY el.race_code
),
rr AS (
    SELECT
        rrd.race_code,
        COUNT(*)::int AS result_rows,
        COUNT(DISTINCT rrd.player_id)::int AS result_players,
        COUNT(*) FILTER (WHERE rrd.rank::text = '1')::int AS rank1,
        COUNT(*) FILTER (WHERE rrd.rank::text = '2')::int AS rank2,
        COUNT(*) FILTER (WHERE rrd.rank::text = '3')::int AS rank3
    FROM boat_race.race_result_detail rrd
    JOIN five_entry f ON f.race_code = rrd.race_code
    GROUP BY rrd.race_code
),
po AS (
    SELECT
        rp.race_code,
        COUNT(*)::int AS payout_rows,
        MAX(COALESCE(rp.trifecta_payout, 0))::int AS trifecta_payout
    FROM boat_race.race_payouts rp
    JOIN five_entry f ON f.race_code = rp.race_code
    GROUP BY rp.race_code
)
SELECT
    f.race_code,
    f.race_date,
    SUBSTRING(f.race_code, 9, 3) AS place_code,
    RIGHT(f.race_code, 2) AS race_no,
    f.lanes,
    f.entry_rows,
    COALESCE(ex.exhibition_rows, 0) AS exhibition_rows,
    COALESCE(ex.exhibition_courses, 0) AS exhibition_courses,
    COALESCE(ex.exhibition_players, 0) AS exhibition_players,
    COALESCE(rr.result_rows, 0) AS result_rows,
    COALESCE(rr.result_players, 0) AS result_players,
    COALESCE(rr.rank1, 0) AS rank1,
    COALESCE(rr.rank2, 0) AS rank2,
    COALESCE(rr.rank3, 0) AS rank3,
    COALESCE(po.payout_rows, 0) AS payout_rows,
    COALESCE(po.trifecta_payout, 0) AS trifecta_payout
FROM five_entry f
LEFT JOIN ex ON ex.race_code = f.race_code
LEFT JOIN rr ON rr.race_code = f.race_code
LEFT JOIN po ON po.race_code = f.race_code
ORDER BY
    CASE
        WHEN COALESCE(rr.rank1,0) = 1
         AND COALESCE(rr.rank2,0) = 1
         AND COALESCE(rr.rank3,0) = 1
         AND COALESCE(po.trifecta_payout,0) > 0
        THEN 0 ELSE 1
    END,
    f.race_date DESC,
    f.race_code DESC
LIMIT :limit
SQL;

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':from_date', $from);
$stmt->bindValue(':to_date', $to);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo str_repeat('=', 150) . "\n";
echo "真正の5艇立て候補検索\n";
echo "期間 : {$from} ～ {$to}\n";
echo "件数 : " . count($rows) . "R（最大 {$limit}R）\n";
echo str_repeat('=', 150) . "\n";

if (!$rows) {
    echo "該当レースなし\n";
    exit(0);
}

printf(
    "%-15s %-10s %-3s %-3s %-12s | %-5s %-5s %-5s | %-6s %-6s | %-7s %-8s | %s\n",
    'race_code', 'date', '場', 'R', 'lanes',
    'entry', 'exh', 'exC',
    'result', 'resP',
    'top3', 'payout', '判定'
);
echo str_repeat('-', 150) . "\n";

foreach ($rows as $r) {
    $top3ok = ((int)$r['rank1'] === 1 && (int)$r['rank2'] === 1 && (int)$r['rank3'] === 1);
    $payoutOk = (int)$r['trifecta_payout'] > 0;
    $exOk = (int)$r['exhibition_rows'] === 5 && (int)$r['exhibition_courses'] === 5;

    $status = $top3ok && $payoutOk
        ? ($exOk ? '成立＋展示5艇 ★通し確認向き' : '成立（展示要確認）')
        : '特殊/不成立候補';

    printf(
        "%-15s %-10s %-3s %-3s %-12s | %5d %5d %5d | %6d %6d | %s/%s/%s   %8d | %s\n",
        (string)$r['race_code'],
        (string)$r['race_date'],
        (string)$r['place_code'],
        (string)$r['race_no'],
        (string)$r['lanes'],
        (int)$r['entry_rows'],
        (int)$r['exhibition_rows'],
        (int)$r['exhibition_courses'],
        (int)$r['result_rows'],
        (int)$r['result_players'],
        (int)$r['rank1'],
        (int)$r['rank2'],
        (int)$r['rank3'],
        (int)$r['trifecta_payout'],
        $status
    );
}
