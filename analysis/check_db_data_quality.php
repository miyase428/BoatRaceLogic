<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

/**
 * 競艇DBの期間データ品質チェック。
 *
 * race_entry を母集団にして、分析CSV生成前に以下を確認する。
 * - 出走表: 6行 / lane 1～6 が6種
 * - race_master: 1行
 * - race_result_detail: 行数そのものではなく、1～3着が各1行・実進入1～6・race_entryと選手対応
 * - race_payouts: 1行かつ3連単払戻 > 0
 * - exhibition_live: 参考件数のみ（未取得でも分析利用不可にはしない）
 *
 * 重要:
 * race_result_detail は時期・取得元により3～6行保存が混在するため、
 * 「4行なら正常」のような総行数固定判定は行わない。
 * 分析で必要なTop3が一意かつ対応可能かを品質基準とする。
 *
 * Usage:
 *   php analysis/check_db_data_quality.php 2026-08-01 2026-09-02 [表示上限]
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
        race_code,
        COUNT(*)::int AS entry_rows,
        COUNT(DISTINCT lane_number)::int AS lane_count
    FROM boat_race.race_entry
    WHERE SUBSTRING(race_code, 1, 8) BETWEEN REPLACE(:from, '-', '') AND REPLACE(:to, '-', '')
    GROUP BY race_code
), master AS (
    SELECT race_code, COUNT(*)::int AS master_rows
    FROM boat_race.race_master
    WHERE race_date BETWEEN :from::date AND :to::date
    GROUP BY race_code
), result AS (
    SELECT
        rrd.race_code,
        COUNT(*)::int AS result_rows,
        COUNT(*) FILTER (WHERE TRIM(rrd.rank) = '1')::int AS rank1_rows,
        COUNT(*) FILTER (WHERE TRIM(rrd.rank) = '2')::int AS rank2_rows,
        COUNT(*) FILTER (WHERE TRIM(rrd.rank) = '3')::int AS rank3_rows,
        COUNT(*) FILTER (
            WHERE TRIM(rrd.rank) IN ('1', '2', '3')
              AND rrd.entry_course::text ~ '^[1-6]$'
        )::int AS top3_course_rows,
        COUNT(*) FILTER (
            WHERE TRIM(rrd.rank) IN ('1', '2', '3')
              AND EXISTS (
                  SELECT 1
                  FROM boat_race.race_entry re2
                  WHERE re2.race_code = rrd.race_code
                    AND re2.player_id = rrd.player_id
              )
        )::int AS top3_entry_match_rows,
        COUNT(DISTINCT rrd.player_id) FILTER (
            WHERE TRIM(rrd.rank) IN ('1', '2', '3')
        )::int AS top3_player_count
    FROM boat_race.race_result_detail rrd
    WHERE SUBSTRING(rrd.race_code, 1, 8) BETWEEN REPLACE(:from, '-', '') AND REPLACE(:to, '-', '')
    GROUP BY rrd.race_code
), payout AS (
    SELECT
        race_code,
        COUNT(*)::int AS payout_rows,
        MAX(COALESCE(trifecta_payout, 0))::numeric AS trifecta_payout
    FROM boat_race.race_payouts
    WHERE SUBSTRING(race_code, 1, 8) BETWEEN REPLACE(:from, '-', '') AND REPLACE(:to, '-', '')
    GROUP BY race_code
), exhibition AS (
    SELECT race_code, COUNT(*)::int AS exhibition_rows
    FROM boat_race.exhibition_live
    WHERE SUBSTRING(race_code, 1, 8) BETWEEN REPLACE(:from, '-', '') AND REPLACE(:to, '-', '')
    GROUP BY race_code
)
SELECT
    e.race_code,
    SUBSTRING(e.race_code, 1, 8) AS race_ymd,
    e.entry_rows,
    e.lane_count,
    COALESCE(m.master_rows, 0) AS master_rows,
    COALESCE(r.result_rows, 0) AS result_rows,
    COALESCE(r.rank1_rows, 0) AS rank1_rows,
    COALESCE(r.rank2_rows, 0) AS rank2_rows,
    COALESCE(r.rank3_rows, 0) AS rank3_rows,
    COALESCE(r.top3_course_rows, 0) AS top3_course_rows,
    COALESCE(r.top3_entry_match_rows, 0) AS top3_entry_match_rows,
    COALESCE(r.top3_player_count, 0) AS top3_player_count,
    COALESCE(p.payout_rows, 0) AS payout_rows,
    COALESCE(p.trifecta_payout, 0) AS trifecta_payout,
    COALESCE(x.exhibition_rows, 0) AS exhibition_rows
FROM entry e
LEFT JOIN master m ON m.race_code = e.race_code
LEFT JOIN result r ON r.race_code = e.race_code
LEFT JOIN payout p ON p.race_code = e.race_code
LEFT JOIN exhibition x ON x.race_code = e.race_code
ORDER BY e.race_code
SQL;

$stmt = $pdo->prepare($sql);
$stmt->execute([':from' => $from, ':to' => $to]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = count($rows);
$stats = [
    'entry_bad' => 0,
    'master_bad' => 0,
    'top3_bad' => 0,
    'payout_unavailable' => 0,
    'suspicious_result_missing' => 0,
    'payout_only_issue' => 0,
    'nonformed_candidate' => 0,
    'exhibition_none' => 0,
    'analysis_ready' => 0,
];
$examples = [
    'entry_bad' => [],
    'master_bad' => [],
    'top3_bad' => [],
    'payout_unavailable' => [],
    'suspicious_result_missing' => [],
    'payout_only_issue' => [],
    'nonformed_candidate' => [],
    'exhibition_none' => [],
];
$daily = [];

foreach ($rows as $row) {
    $code = (string)$row['race_code'];
    $ymd = (string)$row['race_ymd'];
    $date = strlen($ymd) === 8
        ? substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2)
        : $ymd;

    $entryOk = (int)$row['entry_rows'] === 6 && (int)$row['lane_count'] === 6;
    $masterOk = (int)$row['master_rows'] === 1;
    $top3Ok = (int)$row['rank1_rows'] === 1
        && (int)$row['rank2_rows'] === 1
        && (int)$row['rank3_rows'] === 1
        && (int)$row['top3_course_rows'] === 3
        && (int)$row['top3_entry_match_rows'] === 3
        && (int)$row['top3_player_count'] === 3;
    $payoutOk = (int)$row['payout_rows'] === 1 && (float)$row['trifecta_payout'] > 0.0;
    $exhibitionOk = (int)$row['exhibition_rows'] > 0;
    $analysisReady = $entryOk && $masterOk && $top3Ok && $payoutOk;

    // 払戻が成立しているのにTop3が欠ける場合は、取得漏れ・不整合の疑いが強い。
    $suspiciousResultMissing = !$top3Ok && $payoutOk;
    // Top3は揃うのに払戻が無い場合も、払戻取得漏れの疑いがある。
    $payoutOnlyIssue = $top3Ok && !$payoutOk;
    // Top3も3連単払戻も無い場合は、中止・不成立レースの可能性が高い。
    $nonformedCandidate = !$top3Ok && !$payoutOk;

    if (!isset($daily[$date])) {
        $daily[$date] = [
            'n' => 0,
            'ready' => 0,
            'entry_bad' => 0,
            'master_bad' => 0,
            'top3_bad' => 0,
            'payout_unavailable' => 0,
            'suspicious_result_missing' => 0,
        ];
    }
    $daily[$date]['n']++;
    if ($analysisReady) $daily[$date]['ready']++;

    $detail = sprintf(
        '%s | entry=%d/lane=%d master=%d result=%d rank=%d/%d/%d course=%d match=%d players=%d payout=%d/3T=%s exhibition=%d',
        $code,
        (int)$row['entry_rows'],
        (int)$row['lane_count'],
        (int)$row['master_rows'],
        (int)$row['result_rows'],
        (int)$row['rank1_rows'],
        (int)$row['rank2_rows'],
        (int)$row['rank3_rows'],
        (int)$row['top3_course_rows'],
        (int)$row['top3_entry_match_rows'],
        (int)$row['top3_player_count'],
        (int)$row['payout_rows'],
        number_format((float)$row['trifecta_payout'], 0, '.', ''),
        (int)$row['exhibition_rows']
    );

    $badFlags = [
        'entry_bad' => !$entryOk,
        'master_bad' => !$masterOk,
        'top3_bad' => !$top3Ok,
        'payout_unavailable' => !$payoutOk,
        'suspicious_result_missing' => $suspiciousResultMissing,
        'payout_only_issue' => $payoutOnlyIssue,
        'nonformed_candidate' => $nonformedCandidate,
        'exhibition_none' => !$exhibitionOk,
    ];

    foreach ($badFlags as $key => $bad) {
        if (!$bad) continue;
        $stats[$key]++;
        if (count($examples[$key]) < $limit) $examples[$key][] = $detail;
    }

    if (!$entryOk) $daily[$date]['entry_bad']++;
    if (!$masterOk) $daily[$date]['master_bad']++;
    if (!$top3Ok) $daily[$date]['top3_bad']++;
    if (!$payoutOk) $daily[$date]['payout_unavailable']++;
    if ($suspiciousResultMissing) $daily[$date]['suspicious_result_missing']++;
    if ($analysisReady) $stats['analysis_ready']++;
}

function pct(int $n, int $d): string
{
    return $d > 0 ? number_format($n * 100 / $d, 2) . '%' : '-';
}

function printExamples(string $title, array $items, int $count, int $limit): void
{
    echo "\n【{$title}】 件数={$count}\n";
    if (!$items) {
        echo "なし\n";
        return;
    }
    foreach ($items as $line) echo $line . "\n";
    if ($count > $limit) echo "... 他 " . ($count - $limit) . "件\n";
}

echo str_repeat('=', 132) . "\n";
echo "競艇DB データ品質チェック（Top3完備基準）\n";
echo "期間: {$from} ～ {$to}\n";
echo "母集団: race_entryに存在するレース / {$total}R\n";
echo str_repeat('=', 132) . "\n\n";

printf("分析利用可能               : %6d / %6d = %s\n", $stats['analysis_ready'], $total, pct($stats['analysis_ready'], $total));
printf("出走表6艇不備             : %6d / %6d = %s\n", $stats['entry_bad'], $total, pct($stats['entry_bad'], $total));
printf("race_master不備           : %6d / %6d = %s\n", $stats['master_bad'], $total, pct($stats['master_bad'], $total));
printf("Top3結果不備              : %6d / %6d = %s\n", $stats['top3_bad'], $total, pct($stats['top3_bad'], $total));
printf("3連単払戻なし/0           : %6d / %6d = %s\n", $stats['payout_unavailable'], $total, pct($stats['payout_unavailable'], $total));
printf("払戻あり＋Top3不備        : %6d / %6d = %s\n", $stats['suspicious_result_missing'], $total, pct($stats['suspicious_result_missing'], $total));
printf("Top3完備＋払戻なし        : %6d / %6d = %s\n", $stats['payout_only_issue'], $total, pct($stats['payout_only_issue'], $total));
printf("Top3なし＋払戻なし候補    : %6d / %6d = %s\n", $stats['nonformed_candidate'], $total, pct($stats['nonformed_candidate'], $total));
printf("展示なし（参考）          : %6d / %6d = %s\n", $stats['exhibition_none'], $total, pct($stats['exhibition_none'], $total));

echo "\n【日別品質】\n";
foreach ($daily as $date => $s) {
    printf(
        "%s | N=%3d READY=%3d(%6s) | entry=%2d master=%2d top3=%2d payout=%2d suspicious=%2d\n",
        $date,
        $s['n'],
        $s['ready'],
        pct($s['ready'], $s['n']),
        $s['entry_bad'],
        $s['master_bad'],
        $s['top3_bad'],
        $s['payout_unavailable'],
        $s['suspicious_result_missing']
    );
}

printExamples('出走表6艇不備', $examples['entry_bad'], $stats['entry_bad'], $limit);
printExamples('race_master不備', $examples['master_bad'], $stats['master_bad'], $limit);
printExamples('Top3結果不備', $examples['top3_bad'], $stats['top3_bad'], $limit);
printExamples('3連単払戻なし/0', $examples['payout_unavailable'], $stats['payout_unavailable'], $limit);
printExamples('払戻あり＋Top3不備（取得漏れ疑い）', $examples['suspicious_result_missing'], $stats['suspicious_result_missing'], $limit);
printExamples('Top3完備＋払戻なし（払戻取得漏れ疑い）', $examples['payout_only_issue'], $stats['payout_only_issue'], $limit);
printExamples('Top3なし＋払戻なし（中止・不成立候補）', $examples['nonformed_candidate'], $stats['nonformed_candidate'], $limit);
printExamples('展示なし（参考）', $examples['exhibition_none'], $stats['exhibition_none'], $limit);

echo "\n【判定メモ】\n";
echo "- race_result_detailの総行数は3～6行が混在するため、行数固定では判定しない。\n";
echo "- 長期分析の主要基準は、1～3着が各1行・実進入1～6・race_entryとの選手対応が成立すること。\n";
echo "- 『払戻あり＋Top3不備』は、成立レースの結果取得漏れ・DB不整合として最優先で確認する。\n";
echo "- 『Top3なし＋払戻なし』は中止・不成立の可能性が高く、直ちに取得漏れとは扱わない。\n";
echo "- exhibition_liveは未取得レースがあり得るため、このチェックでは分析利用可能判定に含めない。\n";
