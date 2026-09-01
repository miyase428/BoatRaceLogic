<?php
declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

/**
 * 大村・下関の進入コースについて、
 * レース前に取得できる exhibition_live.entry_course と
 * 結果由来の race_result_detail.entry_course の一致率を確認する。
 *
 * Usage:
 *   php analysis/check_omura_shimonoseki_course_source.php [FROM] [TO]
 *
 * 例:
 *   php analysis/check_omura_shimonoseki_course_source.php 2025-08-15 2026-08-14
 */

$from = $argv[1] ?? '2025-08-15';
$to   = $argv[2] ?? '2026-08-14';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
    fwrite(STDERR, "日付は YYYY-MM-DD 形式で、FROM <= TO にしてください。\n");
    exit(1);
}

$pdo = getPDO();

$sql = <<<SQL
SELECT
    rm.stadium_name,
    rm.race_number,
    re.race_code,
    re.lane_number::integer AS lane_number,
    re.player_id::text AS player_id,
    el.entry_course::integer AS exhibition_course,
    rrd.entry_course::integer AS actual_course
FROM boat_race.race_master rm
JOIN boat_race.race_entry re
  ON re.race_code = rm.race_code
LEFT JOIN boat_race.exhibition_live el
  ON el.race_code = re.race_code
 AND el.player_id = re.player_id
LEFT JOIN boat_race.race_result_detail rrd
  ON rrd.race_code = re.race_code
 AND rrd.player_id = re.player_id
WHERE rm.race_date BETWEEN :f AND :t
  AND rm.stadium_name IN ('大村', '下関')
ORDER BY rm.stadium_name, rm.race_code, re.lane_number
SQL;

$stmt = $pdo->prepare($sql);
$stmt->execute([':f' => $from, ':t' => $to]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

function newStat(): array
{
    return [
        'boats' => 0,
        'exhibition' => 0,
        'actual' => 0,
        'both' => 0,
        'match' => 0,
        'mismatch' => 0,
        'outer_both' => 0,
        'outer_match' => 0,
        'outer_mismatch' => 0,
        'late_both' => 0,
        'late_match' => 0,
        'late_mismatch' => 0,
        'late_outer_both' => 0,
        'late_outer_match' => 0,
        'late_outer_mismatch' => 0,
        'examples' => [],
    ];
}

function addStat(array &$s, array $r): void
{
    $s['boats']++;
    $ex = (int)($r['exhibition_course'] ?? 0);
    $ac = (int)($r['actual_course'] ?? 0);
    $raceNo = (int)($r['race_number'] ?? 0);
    $late = ($raceNo >= 7 && $raceNo <= 12);

    if ($ex >= 1 && $ex <= 6) $s['exhibition']++;
    if ($ac >= 1 && $ac <= 6) $s['actual']++;
    if (!($ex >= 1 && $ex <= 6 && $ac >= 1 && $ac <= 6)) return;

    $s['both']++;
    $match = ($ex === $ac);
    if ($match) $s['match']++; else $s['mismatch']++;

    $outerRelated = in_array($ex, [4,5,6], true) || in_array($ac, [4,5,6], true);
    if ($outerRelated) {
        $s['outer_both']++;
        if ($match) $s['outer_match']++; else $s['outer_mismatch']++;
    }

    if ($late) {
        $s['late_both']++;
        if ($match) $s['late_match']++; else $s['late_mismatch']++;
        if ($outerRelated) {
            $s['late_outer_both']++;
            if ($match) $s['late_outer_match']++; else $s['late_outer_mismatch']++;
        }
    }

    if (!$match && count($s['examples']) < 12) {
        $s['examples'][] = sprintf(
            '%s %dR lane%d player%s 展示%dC→実%dC',
            (string)$r['race_code'],
            $raceNo,
            (int)$r['lane_number'],
            (string)$r['player_id'],
            $ex,
            $ac
        );
    }
}

function rate(int $n, int $d): string
{
    return $d > 0 ? number_format($n * 100.0 / $d, 2) . '%' : '-';
}

$stats = ['大村' => newStat(), '下関' => newStat()];
foreach ($rows as $r) {
    $venue = (string)$r['stadium_name'];
    if (!isset($stats[$venue])) continue;
    addStat($stats[$venue], $r);
}

echo str_repeat('=', 88) . "\n";
echo "大村・下関 進入コースソース確認\n";
echo "期間: {$from} ～ {$to}\n";
echo "展示=レース前 exhibition_live.entry_course / 実=結果 race_result_detail.entry_course\n";
echo str_repeat('=', 88) . "\n";

foreach ($stats as $venue => $s) {
    echo "\n【{$venue}】\n";
    echo sprintf("対象艇=%d / 展示あり=%d / 実あり=%d / 両方あり=%d\n", $s['boats'], $s['exhibition'], $s['actual'], $s['both']);
    echo sprintf("全体一致=%s (%d/%d) / 不一致=%s (%d)\n",
        rate($s['match'], $s['both']), $s['match'], $s['both'], rate($s['mismatch'], $s['both']), $s['mismatch']);
    echo sprintf("4～6C関連 一致=%s (%d/%d) / 不一致=%s (%d)\n",
        rate($s['outer_match'], $s['outer_both']), $s['outer_match'], $s['outer_both'], rate($s['outer_mismatch'], $s['outer_both']), $s['outer_mismatch']);
    echo sprintf("後半7～12R 一致=%s (%d/%d) / 不一致=%s (%d)\n",
        rate($s['late_match'], $s['late_both']), $s['late_match'], $s['late_both'], rate($s['late_mismatch'], $s['late_both']), $s['late_mismatch']);
    echo sprintf("後半7～12R×4～6C関連 一致=%s (%d/%d) / 不一致=%s (%d)\n",
        rate($s['late_outer_match'], $s['late_outer_both']), $s['late_outer_match'], $s['late_outer_both'], rate($s['late_outer_mismatch'], $s['late_outer_both']), $s['late_outer_mismatch']);

    if ($s['examples']) {
        echo "不一致例:\n";
        foreach ($s['examples'] as $e) echo "  - {$e}\n";
    }
}

echo "\n" . str_repeat('=', 88) . "\n";
echo "※この確認結果を見てから、事前条件分析のコース判定を展示進入へ統一します。\n";
echo str_repeat('=', 88) . "\n";
