<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

/**
 * 選手SUM N=0 / 母数不足の原因診断。
 * PlayerSamLogic と同じ主要結合条件を段階別に確認する。
 *
 * Usage:
 *   php analysis/diagnose_player_sam_history.php RACE_CODE BOAT [COURSE]
 *
 * 例:
 *   php analysis/diagnose_player_sam_history.php 20260903OMR05 1 1
 */

$raceCode = trim((string)($argv[1] ?? ''));
$boat = (int)($argv[2] ?? 0);
$courseArg = isset($argv[3]) ? (int)$argv[3] : 0;

if ($raceCode === '' || $boat < 1 || $boat > 6 || ($courseArg !== 0 && ($courseArg < 1 || $courseArg > 6))) {
    fwrite(STDERR, "Usage: php {$argv[0]} RACE_CODE BOAT [COURSE]\n");
    exit(1);
}

$pdo = getPDO();

$targetStmt = $pdo->prepare(<<<SQL
    SELECT lane_number::int AS lane_number,
           player_id::text AS player_id,
           player_name
    FROM boat_race.race_entry
    WHERE race_code = :race_code
      AND lane_number = :boat
    LIMIT 1
SQL);
$targetStmt->execute([':race_code' => $raceCode, ':boat' => $boat]);
$target = $targetStmt->fetch(PDO::FETCH_ASSOC);

if (!$target) {
    fwrite(STDERR, "対象艇が race_entry に見つかりません: {$raceCode} / {$boat}号艇\n");
    exit(2);
}

$playerId = trim((string)$target['player_id']);
$playerName = trim((string)$target['player_name']);
$course = $courseArg > 0 ? $courseArg : $boat;

$countStmt = $pdo->prepare(<<<SQL
WITH player_ex AS (
    SELECT DISTINCT el.race_code
    FROM boat_race.exhibition_live el
    WHERE el.player_id::text = :player_id
      AND el.entry_course = :course
      AND el.race_code < :race_code
), with_sum AS (
    SELECT DISTINCT pe.race_code
    FROM player_ex pe
    JOIN boat_race.sum_history_fact f
      ON f.race_code = pe.race_code
     AND f.course = :course
), with_result AS (
    SELECT DISTINCT ws.race_code
    FROM with_sum ws
    WHERE EXISTS (
        SELECT 1
        FROM boat_race.race_result_detail rrd
        WHERE rrd.race_code = ws.race_code
          AND rrd.entry_course::text ~ '^[1-6]$'
          AND rrd.entry_course::int = :course
          AND rrd.rank::text ~ '^[1-6]$'
    )
)
SELECT
    (SELECT COUNT(*) FROM player_ex)::int AS exhibition_races,
    (SELECT COUNT(*) FROM with_sum)::int AS sum_races,
    (SELECT COUNT(*) FROM with_result)::int AS result_races
SQL);
$countStmt->execute([
    ':player_id' => $playerId,
    ':course' => $course,
    ':race_code' => $raceCode,
]);
$counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$detailStmt = $pdo->prepare(<<<SQL
WITH player_ex AS (
    SELECT DISTINCT ON (el.race_code, el.entry_course)
        el.race_code,
        el.entry_course,
        el.created_date
    FROM boat_race.exhibition_live el
    WHERE el.player_id::text = :player_id
      AND el.entry_course = :course
      AND el.race_code < :race_code
    ORDER BY el.race_code, el.entry_course, el.created_date DESC NULLS LAST
)
SELECT
    pe.race_code,
    SUBSTRING(pe.race_code, 1, 8) AS race_ymd,
    CASE WHEN f.race_code IS NOT NULL THEN 1 ELSE 0 END AS has_sum,
    f.interval_label,
    CASE WHEN rd.race_code IS NOT NULL THEN 1 ELSE 0 END AS has_result,
    rd.rank,
    rd.player_id::text AS result_player_id,
    rd.player_name AS result_player_name
FROM player_ex pe
LEFT JOIN boat_race.sum_history_fact f
  ON f.race_code = pe.race_code
 AND f.course = :course
LEFT JOIN LATERAL (
    SELECT rrd.race_code,
           rrd.rank,
           rrd.player_id,
           rrd.player_name
    FROM boat_race.race_result_detail rrd
    WHERE rrd.race_code = pe.race_code
      AND rrd.entry_course::text ~ '^[1-6]$'
      AND rrd.entry_course::int = :course
    ORDER BY CASE WHEN rrd.rank::text ~ '^[0-9]+$' THEN rrd.rank::int ELSE 99 END
    LIMIT 1
) rd ON TRUE
ORDER BY pe.race_code DESC
LIMIT 40
SQL);
$detailStmt->execute([
    ':player_id' => $playerId,
    ':course' => $course,
    ':race_code' => $raceCode,
]);
$details = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

$missingResultStmt = $pdo->prepare(<<<SQL
WITH player_ex AS (
    SELECT DISTINCT el.race_code
    FROM boat_race.exhibition_live el
    WHERE el.player_id::text = :player_id
      AND el.entry_course = :course
      AND el.race_code < :race_code
)
SELECT
    pe.race_code,
    f.interval_label,
    re.lane_number,
    re.player_id::text AS entry_player_id,
    re.player_name AS entry_player_name
FROM player_ex pe
JOIN boat_race.sum_history_fact f
  ON f.race_code = pe.race_code
 AND f.course = :course
LEFT JOIN boat_race.race_entry re
  ON re.race_code = pe.race_code
 AND re.player_id::text = :player_id
WHERE NOT EXISTS (
    SELECT 1
    FROM boat_race.race_result_detail rrd
    WHERE rrd.race_code = pe.race_code
      AND rrd.entry_course::text ~ '^[1-6]$'
      AND rrd.entry_course::int = :course
      AND rrd.rank::text ~ '^[1-6]$'
)
ORDER BY pe.race_code DESC
LIMIT 40
SQL);
$missingResultStmt->execute([
    ':player_id' => $playerId,
    ':course' => $course,
    ':race_code' => $raceCode,
]);
$missingResult = $missingResultStmt->fetchAll(PDO::FETCH_ASSOC);

echo str_repeat('=', 120) . "\n";
echo "選手SUM 履歴結合診断\n";
echo "race_code : {$raceCode}\n";
echo "対象       : {$boat}号艇 / {$course}C / {$playerName} ({$playerId})\n";
echo str_repeat('=', 120) . "\n\n";

printf("exhibition_live 同選手・同コース : %dR\n", (int)($counts['exhibition_races'] ?? 0));
printf("＋ sum_history_fact             : %dR\n", (int)($counts['sum_races'] ?? 0));
printf("＋ race_result_detail 有効着順 : %dR  ← 選手SUMのN相当\n", (int)($counts['result_races'] ?? 0));

echo "\n【直近の履歴（最大40R）】\n";
if (!$details) {
    echo "なし\n";
} else {
    foreach ($details as $r) {
        printf(
            "%s | SUM=%s interval=%s | RESULT=%s rank=%s result_player=%s(%s)\n",
            (string)$r['race_code'],
            (int)$r['has_sum'] === 1 ? 'OK' : 'NG',
            (string)($r['interval_label'] ?? '-'),
            (int)$r['has_result'] === 1 ? 'OK' : 'NG',
            (string)($r['rank'] ?? '-'),
            trim((string)($r['result_player_name'] ?? '-')),
            trim((string)($r['result_player_id'] ?? '-'))
        );
    }
}

echo "\n【SUMはあるが結果着順が無い履歴（最大40R）】\n";
if (!$missingResult) {
    echo "なし\n";
} else {
    foreach ($missingResult as $r) {
        printf(
            "%s | interval=%s | race_entry=%s号艇 %s(%s)\n",
            (string)$r['race_code'],
            (string)($r['interval_label'] ?? '-'),
            (string)($r['lane_number'] ?? '-'),
            trim((string)($r['entry_player_name'] ?? '-')),
            trim((string)($r['entry_player_id'] ?? '-'))
        );
    }
}

echo "\n【見方】\n";
echo "- exhibition=0: 過去展示データ側に同選手・同コース履歴が無い。\n";
echo "- exhibition>0 / SUM=0: sum_history_fact の履歴不足。\n";
echo "- SUM>0 / RESULT=0: race_result_detail の欠損が原因。『々』問題の過去未修復分を疑う。\n";
echo "- 3段階すべて>0なのに画面N=0: PlayerSamLogic側の別条件を再確認する。\n";
