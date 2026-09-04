<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

/**
 * exhibition_live の期間カバレッジ／欠損原因診断。
 *
 * race_entry を母集団にして、展示データを以下に分類する。
 * - 完全: 6行・進入1～6が6種・選手6人・race_entryとの選手対応6行
 * - 部分欠損: 1～5行
 * - 展示なし: 0行
 * - 構造異常: 6行以上あるが進入/選手対応が不完全、または7行以上
 *
 * 展示なし／部分欠損について、3連単払戻とTop3結果を併用して
 * 「成立レースなのに展示欠損」と「不成立候補」を分けて表示する。
 *
 * Usage:
 *   php analysis/diagnose_exhibition_coverage.php 2026-08-01 2026-09-02 [表示上限]
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
WITH entry AS (
    SELECT
        re.race_code,
        COUNT(*)::int AS entry_rows,
        COUNT(DISTINCT re.lane_number)::int AS lane_count
    FROM boat_race.race_entry re
    WHERE SUBSTRING(re.race_code, 1, 8)
          BETWEEN REPLACE(:from, '-', '') AND REPLACE(:to, '-', '')
    GROUP BY re.race_code
), exhibition AS (
    SELECT
        el.race_code,
        COUNT(*)::int AS exhibition_rows,
        COUNT(DISTINCT el.entry_course)::int AS course_count,
        COUNT(DISTINCT el.player_id)::int AS player_count,
        COUNT(*) FILTER (
            WHERE el.entry_course::text ~ '^[1-6]$'
        )::int AS valid_course_rows,
        COUNT(*) FILTER (
            WHERE EXISTS (
                SELECT 1
                FROM boat_race.race_entry re2
                WHERE re2.race_code = el.race_code
                  AND re2.player_id::text = el.player_id::text
            )
        )::int AS entry_match_rows,
        COUNT(*) FILTER (WHERE el.exhibition_time IS NOT NULL)::int AS exhibition_time_rows,
        COUNT(*) FILTER (WHERE el.start_timing IS NOT NULL)::int AS st_rows,
        COUNT(*) FILTER (WHERE el.lap_time IS NOT NULL)::int AS lap_rows,
        COUNT(*) FILTER (WHERE el.around_time IS NOT NULL)::int AS around_rows,
        COUNT(*) FILTER (WHERE el.straight_time IS NOT NULL)::int AS straight_rows
    FROM boat_race.exhibition_live el
    WHERE SUBSTRING(el.race_code, 1, 8)
          BETWEEN REPLACE(:from, '-', '') AND REPLACE(:to, '-', '')
    GROUP BY el.race_code
), result AS (
    SELECT
        rrd.race_code,
        COUNT(*) FILTER (WHERE TRIM(rrd.rank) = '1')::int AS r1,
        COUNT(*) FILTER (WHERE TRIM(rrd.rank) = '2')::int AS r2,
        COUNT(*) FILTER (WHERE TRIM(rrd.rank) = '3')::int AS r3
    FROM boat_race.race_result_detail rrd
    WHERE SUBSTRING(rrd.race_code, 1, 8)
          BETWEEN REPLACE(:from, '-', '') AND REPLACE(:to, '-', '')
    GROUP BY rrd.race_code
), payout AS (
    SELECT
        rp.race_code,
        MAX(COALESCE(rp.trifecta_payout, 0))::numeric AS trifecta_payout
    FROM boat_race.race_payouts rp
    WHERE SUBSTRING(rp.race_code, 1, 8)
          BETWEEN REPLACE(:from, '-', '') AND REPLACE(:to, '-', '')
    GROUP BY rp.race_code
)
SELECT
    e.race_code,
    SUBSTRING(e.race_code, 1, 8) AS ymd,
    SUBSTRING(e.race_code, 9, 3) AS place_code,
    RIGHT(e.race_code, 2) AS race_no,
    e.entry_rows,
    e.lane_count,
    COALESCE(x.exhibition_rows, 0) AS exhibition_rows,
    COALESCE(x.course_count, 0) AS course_count,
    COALESCE(x.player_count, 0) AS player_count,
    COALESCE(x.valid_course_rows, 0) AS valid_course_rows,
    COALESCE(x.entry_match_rows, 0) AS entry_match_rows,
    COALESCE(x.exhibition_time_rows, 0) AS exhibition_time_rows,
    COALESCE(x.st_rows, 0) AS st_rows,
    COALESCE(x.lap_rows, 0) AS lap_rows,
    COALESCE(x.around_rows, 0) AS around_rows,
    COALESCE(x.straight_rows, 0) AS straight_rows,
    COALESCE(r.r1, 0) AS r1,
    COALESCE(r.r2, 0) AS r2,
    COALESCE(r.r3, 0) AS r3,
    COALESCE(p.trifecta_payout, 0) AS trifecta_payout
FROM entry e
LEFT JOIN exhibition x ON x.race_code = e.race_code
LEFT JOIN result r ON r.race_code = e.race_code
LEFT JOIN payout p ON p.race_code = e.race_code
ORDER BY e.race_code
SQL;

$stmt = $pdo->prepare($sql);
$stmt->execute([':from' => $from, ':to' => $to]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

function pct(int $n, int $d): string
{
    return $d > 0 ? number_format($n * 100 / $d, 2) . '%' : '-';
}

function addTop(array &$counter, string $key): void
{
    $counter[$key] = ($counter[$key] ?? 0) + 1;
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
    foreach ($counter as $key => $n) {
        printf("%-16s %5dR\n", $key, $n);
        if (++$i >= $limit) break;
    }
}

$total = count($rows);
$stats = [
    'complete' => 0,
    'none' => 0,
    'partial' => 0,
    'structural_bad' => 0,
    'none_established' => 0,
    'partial_established' => 0,
    'none_nonformed' => 0,
    'none_unclear' => 0,
    'metric_partial' => 0,
];

$examples = [
    'none_established' => [],
    'partial_established' => [],
    'none_nonformed' => [],
    'none_unclear' => [],
    'structural_bad' => [],
    'metric_partial' => [],
];

$missingByDate = [];
$missingByPlace = [];
$missingByRaceNo = [];
$establishedMissingByDate = [];
$establishedMissingByPlace = [];

foreach ($rows as $row) {
    $code = (string)$row['race_code'];
    $ymd = (string)$row['ymd'];
    $date = substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2);
    $place = (string)$row['place_code'];
    $raceNo = (string)$row['race_no'];

    $exRows = (int)$row['exhibition_rows'];
    $courseCount = (int)$row['course_count'];
    $playerCount = (int)$row['player_count'];
    $validCourseRows = (int)$row['valid_course_rows'];
    $entryMatchRows = (int)$row['entry_match_rows'];

    $top3Ok = (int)$row['r1'] === 1 && (int)$row['r2'] === 1 && (int)$row['r3'] === 1;
    $payoutOk = (float)$row['trifecta_payout'] > 0.0;
    $established = $top3Ok || $payoutOk;
    $nonformedCandidate = !$top3Ok && !$payoutOk;

    $complete = $exRows === 6
        && $courseCount === 6
        && $playerCount === 6
        && $validCourseRows === 6
        && $entryMatchRows === 6;
    $none = $exRows === 0;
    $partial = $exRows >= 1 && $exRows <= 5;
    $structuralBad = !$none && !$partial && !$complete;

    if ($complete) $stats['complete']++;
    if ($none) $stats['none']++;
    if ($partial) $stats['partial']++;
    if ($structuralBad) $stats['structural_bad']++;

    if ($none || $partial || $structuralBad) {
        addTop($missingByDate, $date);
        addTop($missingByPlace, $place);
        addTop($missingByRaceNo, $raceNo . 'R');
    }

    $detail = sprintf(
        '%s | exh=%d course=%d player=%d validCourse=%d entryMatch=%d | metric=%d/%d/%d/%d/%d | top3=%d/%d/%d payout=%s',
        $code,
        $exRows,
        $courseCount,
        $playerCount,
        $validCourseRows,
        $entryMatchRows,
        (int)$row['exhibition_time_rows'],
        (int)$row['st_rows'],
        (int)$row['lap_rows'],
        (int)$row['around_rows'],
        (int)$row['straight_rows'],
        (int)$row['r1'],
        (int)$row['r2'],
        (int)$row['r3'],
        number_format((float)$row['trifecta_payout'], 0, '.', '')
    );

    if ($none && $established) {
        $stats['none_established']++;
        addTop($establishedMissingByDate, $date);
        addTop($establishedMissingByPlace, $place);
        if (count($examples['none_established']) < $limit) $examples['none_established'][] = $detail;
    } elseif ($partial && $established) {
        $stats['partial_established']++;
        addTop($establishedMissingByDate, $date);
        addTop($establishedMissingByPlace, $place);
        if (count($examples['partial_established']) < $limit) $examples['partial_established'][] = $detail;
    } elseif ($none && $nonformedCandidate) {
        $stats['none_nonformed']++;
        if (count($examples['none_nonformed']) < $limit) $examples['none_nonformed'][] = $detail;
    } elseif ($none) {
        $stats['none_unclear']++;
        if (count($examples['none_unclear']) < $limit) $examples['none_unclear'][] = $detail;
    }

    if ($structuralBad && count($examples['structural_bad']) < $limit) {
        $examples['structural_bad'][] = $detail;
    }

    // 6艇構造は正常でも、展示5指標のどれかが6艇未満なら指標部分欠損として別集計。
    if ($complete) {
        $metricCounts = [
            (int)$row['exhibition_time_rows'],
            (int)$row['st_rows'],
            (int)$row['lap_rows'],
            (int)$row['around_rows'],
            (int)$row['straight_rows'],
        ];
        if (min($metricCounts) < 6) {
            $stats['metric_partial']++;
            if (count($examples['metric_partial']) < $limit) $examples['metric_partial'][] = $detail;
        }
    }
}

function printExamples(string $title, array $items, int $count, int $limit): void
{
    echo "\n【{$title}】 件数={$count}\n";
    if (!$items) {
        echo "なし\n";
        return;
    }
    foreach ($items as $line) echo $line . "\n";
    if ($count > $limit) echo '... 他 ' . ($count - $limit) . "件\n";
}

echo str_repeat('=', 132) . "\n";
echo "展示データ カバレッジ／欠損原因診断\n";
echo "期間: {$from} ～ {$to}\n";
echo "母集団: race_entry に存在するレース / {$total}R\n";
echo str_repeat('=', 132) . "\n\n";

printf("展示6艇・構造正常          : %6d / %6d = %s\n", $stats['complete'], $total, pct($stats['complete'], $total));
printf("展示なし                  : %6d / %6d = %s\n", $stats['none'], $total, pct($stats['none'], $total));
printf("展示1～5艇（部分欠損）    : %6d / %6d = %s\n", $stats['partial'], $total, pct($stats['partial'], $total));
printf("展示構造異常              : %6d / %6d = %s\n", $stats['structural_bad'], $total, pct($stats['structural_bad'], $total));
printf("6艇揃いだが指標部分欠損   : %6d / %6d = %s\n", $stats['metric_partial'], $total, pct($stats['metric_partial'], $total));

echo "\n--- 展示なし／部分欠損の成立状況 ---\n";
printf("成立レース＋展示なし      : %6dR\n", $stats['none_established']);
printf("成立レース＋展示部分欠損  : %6dR\n", $stats['partial_established']);
printf("不成立候補＋展示なし      : %6dR\n", $stats['none_nonformed']);
printf("その他の展示なし          : %6dR\n", $stats['none_unclear']);

printTop('展示不完全レース 日付別 上位20', $missingByDate, 20);
printTop('展示不完全レース 場別', $missingByPlace, 30);
printTop('展示不完全レース R番号別', $missingByRaceNo, 12);
printTop('成立レースの展示欠損 日付別 上位20', $establishedMissingByDate, 20);
printTop('成立レースの展示欠損 場別', $establishedMissingByPlace, 30);

printExamples('成立レースなのに展示0件', $examples['none_established'], $stats['none_established'], $limit);
printExamples('成立レースなのに展示1～5艇', $examples['partial_established'], $stats['partial_established'], $limit);
printExamples('不成立候補＋展示0件', $examples['none_nonformed'], $stats['none_nonformed'], $limit);
printExamples('展示構造異常', $examples['structural_bad'], $stats['structural_bad'], $limit);
printExamples('6艇構造正常だが展示指標が一部NULL', $examples['metric_partial'], $stats['metric_partial'], $limit);

echo "\n【判定の目安】\n";
echo "- 成立レース＋展示0件/部分欠損: スクレイピング・保存漏れの有力候補。\n";
echo "- 不成立候補＋展示0件: 中止・不成立等の可能性があり、即修復対象とはしない。\n";
echo "- 展示構造異常: entry_course重複・選手不一致・7行以上などを個別確認。\n";
echo "- 6艇構造正常だが指標部分欠損: 周回/周り足/直線等が取得元に無いケースもあるため場・日付偏りを見る。\n";
