<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

/**
 * exhibition_live の「6艇構造正常だが指標が一部NULL」の内訳診断。
 *
 * 目的:
 * - どの指標が欠けているのかをシグネチャで集計
 * - 場・日付への偏りを確認
 * - 全艇で特定指標だけ欠ける系と、1艇だけ全指標欠ける系を分離
 * - 直ちにバックフィルすべき取得漏れか、取得元仕様/欠場等の正常欠損かを判断する材料にする
 *
 * Usage:
 *   php analysis/diagnose_exhibition_metric_missing.php 2026-08-01 2026-09-02 [表示上限]
 */

$from = trim((string)($argv[1] ?? ''));
$to = trim((string)($argv[2] ?? ''));
$limit = max(1, (int)($argv[3] ?? 30));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    fwrite(STDERR, "Usage: php {$argv[0]} YYYY-MM-DD YYYY-MM-DD [表示上限]\n");
    exit(1);
}
if ($from > $to) {
    fwrite(STDERR, "開始日は終了日以前にしてください。\n");
    exit(1);
}

$pdo = getPDO();

$sql = <<<SQL
WITH exhibition AS (
    SELECT
        el.race_code,
        COUNT(*)::int AS rows,
        COUNT(DISTINCT el.entry_course)::int AS course_count,
        COUNT(DISTINCT el.player_id)::int AS player_count,
        COUNT(*) FILTER (WHERE el.entry_course::text ~ '^[1-6]$')::int AS valid_course_rows,
        COUNT(*) FILTER (
            WHERE EXISTS (
                SELECT 1
                FROM boat_race.race_entry re2
                WHERE re2.race_code = el.race_code
                  AND re2.player_id::text = el.player_id::text
            )
        )::int AS entry_match_rows,
        COUNT(*) FILTER (WHERE el.exhibition_time IS NOT NULL)::int AS exh_n,
        COUNT(*) FILTER (WHERE el.start_timing IS NOT NULL)::int AS st_n,
        COUNT(*) FILTER (WHERE el.lap_time IS NOT NULL)::int AS lap_n,
        COUNT(*) FILTER (WHERE el.around_time IS NOT NULL)::int AS around_n,
        COUNT(*) FILTER (WHERE el.straight_time IS NOT NULL)::int AS straight_n
    FROM boat_race.exhibition_live el
    WHERE SUBSTRING(el.race_code, 1, 8)
          BETWEEN REPLACE(:from, '-', '') AND REPLACE(:to, '-', '')
    GROUP BY el.race_code
), result_count AS (
    SELECT
        rrd.race_code,
        COUNT(*)::int AS result_rows,
        COUNT(DISTINCT rrd.player_id)::int AS result_players
    FROM boat_race.race_result_detail rrd
    WHERE SUBSTRING(rrd.race_code, 1, 8)
          BETWEEN REPLACE(:from, '-', '') AND REPLACE(:to, '-', '')
    GROUP BY rrd.race_code
)
SELECT
    x.race_code,
    SUBSTRING(x.race_code, 1, 8) AS ymd,
    SUBSTRING(x.race_code, 9, 3) AS place_code,
    RIGHT(x.race_code, 2) AS race_no,
    x.exh_n,
    x.st_n,
    x.lap_n,
    x.around_n,
    x.straight_n,
    COALESCE(r.result_rows, 0) AS result_rows,
    COALESCE(r.result_players, 0) AS result_players
FROM exhibition x
LEFT JOIN result_count r ON r.race_code = x.race_code
WHERE x.rows = 6
  AND x.course_count = 6
  AND x.player_count = 6
  AND x.valid_course_rows = 6
  AND x.entry_match_rows = 6
  AND (
      x.exh_n < 6 OR x.st_n < 6 OR x.lap_n < 6 OR x.around_n < 6 OR x.straight_n < 6
  )
ORDER BY x.race_code
SQL;

$stmt = $pdo->prepare($sql);
$stmt->execute([':from' => $from, ':to' => $to]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

function pct(int $n, int $d): string
{
    return $d > 0 ? number_format($n * 100 / $d, 2) . '%' : '-';
}

function inc(array &$a, string $k): void
{
    $a[$k] = ($a[$k] ?? 0) + 1;
}

function printTop(string $title, array $counter, int $limit = 20): void
{
    echo "\n【{$title}】\n";
    if (!$counter) {
        echo "なし\n";
        return;
    }
    arsort($counter);
    $i = 0;
    foreach ($counter as $k => $n) {
        printf("%-24s %6dR\n", $k, $n);
        if (++$i >= $limit) break;
    }
}

$total = count($rows);
$signature = [];
$byPlace = [];
$byDate = [];
$class = [
    'straight_only_all6' => 0,
    'one_boat_all_metrics_missing' => 0,
    'same_count_partial' => 0,
    'mixed' => 0,
];
$examples = [
    'straight_only_all6' => [],
    'one_boat_all_metrics_missing' => [],
    'same_count_partial' => [],
    'mixed' => [],
];

foreach ($rows as $row) {
    $code = (string)$row['race_code'];
    $ymd = (string)$row['ymd'];
    $date = substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2);
    $place = (string)$row['place_code'];
    $vals = [
        (int)$row['exh_n'],
        (int)$row['st_n'],
        (int)$row['lap_n'],
        (int)$row['around_n'],
        (int)$row['straight_n'],
    ];

    $sig = sprintf('E%d ST%d L%d A%d D%d', $vals[0], $vals[1], $vals[2], $vals[3], $vals[4]);
    inc($signature, $sig);
    inc($byPlace, $place);
    inc($byDate, $date);

    if ($vals === [6, 6, 6, 6, 0]) {
        $kind = 'straight_only_all6';
    } elseif ($vals === [5, 5, 5, 5, 5]) {
        $kind = 'one_boat_all_metrics_missing';
    } elseif (count(array_unique($vals)) === 1 && $vals[0] >= 1 && $vals[0] <= 5) {
        $kind = 'same_count_partial';
    } else {
        $kind = 'mixed';
    }

    $class[$kind]++;
    if (count($examples[$kind]) < $limit) {
        $examples[$kind][] = sprintf(
            '%s | %s | result_rows=%d result_players=%d',
            $code,
            $sig,
            (int)$row['result_rows'],
            (int)$row['result_players']
        );
    }
}

$labels = [
    'straight_only_all6' => '全艇で直線だけ欠損（E/ST/L/Aは6）',
    'one_boat_all_metrics_missing' => '1艇だけ全指標欠損（5/5/5/5/5）',
    'same_count_partial' => '複数艇が全指標欠損（同数1～4）',
    'mixed' => '指標ごとの欠損数が異なる混合型',
];

echo str_repeat('=', 132) . "\n";
echo "展示指標部分欠損 内訳診断\n";
echo "期間: {$from} ～ {$to}\n";
echo "対象: 6艇構造正常だが、5指標のどれかが6艇未満 / {$total}R\n";
echo "指標順: E=展示タイム ST=スタート L=周回 A=周り足 D=直線\n";
echo str_repeat('=', 132) . "\n\n";

foreach ($class as $k => $n) {
    printf("%-42s : %6dR / %6d = %s\n", $labels[$k], $n, $total, pct($n, $total));
}

printTop('欠損シグネチャ 上位20', $signature, 20);
printTop('場別 部分欠損 上位24', $byPlace, 24);
printTop('日付別 部分欠損 上位20', $byDate, 20);

foreach ($labels as $k => $title) {
    echo "\n【{$title} 例】 件数={$class[$k]}\n";
    if (!$examples[$k]) {
        echo "なし\n";
        continue;
    }
    foreach ($examples[$k] as $line) echo $line . "\n";
    if ($class[$k] > $limit) echo "... 他 " . ($class[$k] - $limit) . "件\n";
}

echo "\n【見方】\n";
echo "- E6 ST6 L6 A6 D0 が特定場・日付に集中: 取得元側で直線タイム非提供の可能性が高い。\n";
echo "- 5/5/5/5/5: 1艇欠場・展示不参加等で1艇ぶん全指標が無い可能性を確認。\n";
echo "- 混合型: スクレイピング位置ずれ・項目単位取得漏れを優先して個別確認。\n";
echo "- result_rows/result_players が5以下なら、欠場・失格等の特殊レースとの整合も確認する。\n";
