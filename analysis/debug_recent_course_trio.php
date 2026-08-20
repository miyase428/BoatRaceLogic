<?php
/**
 * 最終予想表示候補の「直近6ヶ月/3ヶ月 進入コース別3連対率」診断用。
 *
 * Usage:
 *   php analysis/debug_recent_course_trio.php 20260818TMG05
 *
 * 対象レースの展示進入を今回コースとして、各選手について
 * 直近6ヶ月の「同じ実進入コース」のレースを列挙する。
 *
 * 過去実コースは以下の順で復元する。
 *   1. race_result_detail.entry_course
 *   2. exhibition_live.entry_course
 *   3. race_entry.lane_number
 *
 * 各艇について、
 *  - 対象レース数
 *  - result行あり件数
 *  - 有効着順件数
 *  - 3連対件数
 * を6ヶ月/3ヶ月で比較する。
 */

require_once __DIR__ . '/../common/db_connect.php';

$raceCode = $argv[1] ?? '';
if ($raceCode === '') {
    fwrite(STDERR, "Usage: php analysis/debug_recent_course_trio.php RACE_CODE\n");
    exit(1);
}

$pdo = getPDO();

$targetSql = <<<SQL
SELECT
    rm.race_date,
    re.lane_number,
    re.player_id::text AS player_id,
    re.player_name,
    COALESCE(
        CASE WHEN el.entry_course::text ~ '^[1-6]$' THEN el.entry_course::int ELSE NULL END,
        re.lane_number
    ) AS target_course
FROM boat_race.race_entry re
JOIN boat_race.race_master rm
  ON rm.race_code = re.race_code
LEFT JOIN LATERAL (
    SELECT x.entry_course
    FROM boat_race.exhibition_live x
    WHERE x.race_code = re.race_code
      AND x.player_id = re.player_id
    LIMIT 1
) el ON TRUE
WHERE re.race_code = ?
ORDER BY target_course, re.lane_number
SQL;

$stmt = $pdo->prepare($targetSql);
$stmt->execute([$raceCode]);
$targets = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($targets) !== 6) {
    fwrite(STDERR, "対象レースが6艇ではありません: {$raceCode}\n");
    exit(1);
}

$targetDate = (string)$targets[0]['race_date'];
$entryOrder = '';
foreach ($targets as $row) {
    $entryOrder .= (string)$row['lane_number'];
}

echo str_repeat('=', 124) . "\n";
echo "進入コース別直近3連対率 分母診断\n";
echo "race_code : {$raceCode}\n";
echo "target_date: {$targetDate}\n";
echo "展示進入   : {$entryOrder}\n";
echo str_repeat('=', 124) . "\n\n";

$histSql = <<<SQL
SELECT
    re.race_code,
    rm.race_date,
    re.lane_number AS frame_number,
    COALESCE(
        CASE WHEN rrd.entry_course::text ~ '^[1-6]$' THEN rrd.entry_course::int ELSE NULL END,
        CASE WHEN el.entry_course::text ~ '^[1-6]$' THEN el.entry_course::int ELSE NULL END,
        re.lane_number
    ) AS actual_course,
    CASE WHEN rrd.player_id IS NULL THEN 0 ELSE 1 END AS has_result_row,
    rrd.rank::text AS rank_raw,
    CASE
        WHEN rrd.rank::text ~ '^[1-6]$' THEN rrd.rank::int
        ELSE NULL
    END AS rank_num
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
  AND rm.race_date >= ?::date - INTERVAL '6 months'
  AND COALESCE(
        CASE WHEN rrd.entry_course::text ~ '^[1-6]$' THEN rrd.entry_course::int ELSE NULL END,
        CASE WHEN el.entry_course::text ~ '^[1-6]$' THEN el.entry_course::int ELSE NULL END,
        re.lane_number
      ) = ?
ORDER BY rm.race_date DESC, re.race_code DESC
SQL;

$stmtHist = $pdo->prepare($histSql);

$pct = static function (int $num, int $den): string {
    return $den > 0 ? number_format($num * 100.0 / $den, 1) . '%' : '-';
};

foreach ($targets as $target) {
    $boat = (int)$target['lane_number'];
    $playerId = (string)$target['player_id'];
    $playerName = (string)$target['player_name'];
    $course = (int)$target['target_course'];

    $stmtHist->execute([
        $playerId,
        $targetDate,
        $targetDate,
        $raceCode,
        $targetDate,
        $course,
    ]);
    $rows = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

    $n6Entry = count($rows);
    $n6ResultRow = 0;
    $n6Valid = 0;
    $top36 = 0;
    $n3Entry = 0;
    $n3ResultRow = 0;
    $n3Valid = 0;
    $top33 = 0;

    $threeMonthBoundary = (new DateTimeImmutable($targetDate))->modify('-3 months')->format('Y-m-d');

    foreach ($rows as $row) {
        $hasResult = (int)$row['has_result_row'] === 1;
        $rankNum = $row['rank_num'] !== null ? (int)$row['rank_num'] : null;
        $is3m = (string)$row['race_date'] >= $threeMonthBoundary;

        if ($hasResult) $n6ResultRow++;
        if ($rankNum !== null) {
            $n6Valid++;
            if ($rankNum >= 1 && $rankNum <= 3) $top36++;
        }

        if ($is3m) {
            $n3Entry++;
            if ($hasResult) $n3ResultRow++;
            if ($rankNum !== null) {
                $n3Valid++;
                if ($rankNum >= 1 && $rankNum <= 3) $top33++;
            }
        }
    }

    echo "【{$boat}号艇 / 今回{$course}コース】 {$playerName} (ID={$playerId})\n";
    echo "6ヶ月: candidate={$n6Entry} / result_row={$n6ResultRow} / valid_rank={$n6Valid} / top3={$top36}\n";
    echo "       現行(valid_rank分母)={$pct($top36, $n6Valid)} ({$top36}/{$n6Valid})"
       . " / candidate分母={$pct($top36, $n6Entry)} ({$top36}/{$n6Entry})\n";
    echo "3ヶ月: candidate={$n3Entry} / result_row={$n3ResultRow} / valid_rank={$n3Valid} / top3={$top33}\n";
    echo "       現行(valid_rank分母)={$pct($top33, $n3Valid)} ({$top33}/{$n3Valid})"
       . " / candidate分母={$pct($top33, $n3Entry)} ({$top33}/{$n3Entry})\n";

    echo "-- 6ヶ月同コースレース一覧 --\n";
    echo "date       race_code        frame course result rank\n";
    foreach ($rows as $row) {
        $has = (int)$row['has_result_row'] === 1 ? 'Y' : 'N';
        $rankRaw = $row['rank_raw'];
        $rankText = ($rankRaw === null || $rankRaw === '') ? '-' : $rankRaw;
        echo sprintf(
            "%-10s %-16s  %d     %d      %s      %s\n",
            (string)$row['race_date'],
            (string)$row['race_code'],
            (int)$row['frame_number'],
            (int)$row['actual_course'],
            $has,
            $rankText
        );
    }
    echo "\n";
}
