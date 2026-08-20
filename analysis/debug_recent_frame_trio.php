<?php
/**
 * 最終予想表示の「直近6ヶ月/3ヶ月 枠別3連対率」の分母確認用。
 *
 * Usage:
 *   php analysis/debug_recent_frame_trio.php 20260818TMG05
 *
 * 各艇について、対象レース日時点の同枠出走を直近6ヶ月から列挙し、
 *  - race_entry上の出走数
 *  - race_result_detail行の有無
 *  - rankが1～6として有効な件数
 *  - 3連対件数
 * を比較する。
 */

require_once __DIR__ . '/../common/db_connect.php';

$raceCode = $argv[1] ?? '';
if ($raceCode === '') {
    fwrite(STDERR, "Usage: php analysis/debug_recent_frame_trio.php RACE_CODE\n");
    exit(1);
}

$pdo = getPDO();

$targetSql = <<<SQL
SELECT
    rm.race_date,
    re.lane_number,
    re.player_id::text AS player_id,
    re.player_name
FROM boat_race.race_entry re
JOIN boat_race.race_master rm
  ON rm.race_code = re.race_code
WHERE re.race_code = ?
ORDER BY re.lane_number
SQL;

$stmt = $pdo->prepare($targetSql);
$stmt->execute([$raceCode]);
$targets = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($targets) !== 6) {
    fwrite(STDERR, "対象レースが6艇ではありません: {$raceCode}\n");
    exit(1);
}

$targetDate = (string)$targets[0]['race_date'];

echo str_repeat('=', 120) . "\n";
echo "枠別直近3連対率 分母診断\n";
echo "race_code : {$raceCode}\n";
echo "target_date: {$targetDate}\n";
echo str_repeat('=', 120) . "\n\n";

$histSql = <<<SQL
SELECT
    re.race_code,
    rm.race_date,
    re.lane_number AS frame_number,
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
WHERE re.player_id::text = ?
  AND re.lane_number = ?
  AND (
        rm.race_date < ?::date
        OR (rm.race_date = ?::date AND re.race_code < ?)
      )
  AND rm.race_date >= ?::date - INTERVAL '6 months'
ORDER BY rm.race_date DESC, re.race_code DESC
SQL;

$stmtHist = $pdo->prepare($histSql);

foreach ($targets as $target) {
    $boat = (int)$target['lane_number'];
    $playerId = (string)$target['player_id'];
    $playerName = (string)$target['player_name'];

    $stmtHist->execute([
        $playerId,
        $boat,
        $targetDate,
        $targetDate,
        $raceCode,
        $targetDate,
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

    $targetTs = strtotime($targetDate);
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

    $pct = static function (int $num, int $den): string {
        return $den > 0 ? number_format($num * 100.0 / $den, 1) . '%' : '-';
    };

    echo "【{$boat}号艇 / {$boat}枠】 {$playerName} (ID={$playerId})\n";
    echo "6ヶ月: entry={$n6Entry} / result_row={$n6ResultRow} / valid_rank={$n6Valid} / top3={$top36}\n";
    echo "       現行(valid_rank分母)={$pct($top36, $n6Valid)} ({$top36}/{$n6Valid})"
       . " / entry分母={$pct($top36, $n6Entry)} ({$top36}/{$n6Entry})\n";
    echo "3ヶ月: entry={$n3Entry} / result_row={$n3ResultRow} / valid_rank={$n3Valid} / top3={$top33}\n";
    echo "       現行(valid_rank分母)={$pct($top33, $n3Valid)} ({$top33}/{$n3Valid})"
       . " / entry分母={$pct($top33, $n3Entry)} ({$top33}/{$n3Entry})\n";

    echo "-- 6ヶ月同枠レース一覧 --\n";
    echo "date       race_code        result rank\n";
    foreach ($rows as $row) {
        $has = (int)$row['has_result_row'] === 1 ? 'Y' : 'N';
        $rankRaw = $row['rank_raw'];
        $rankText = ($rankRaw === null || $rankRaw === '') ? '-' : $rankRaw;
        echo sprintf(
            "%-10s %-16s  %s      %s\n",
            (string)$row['race_date'],
            (string)$row['race_code'],
            $has,
            $rankText
        );
    }
    echo "\n";
}
