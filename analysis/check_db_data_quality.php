<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

/**
 * 競艇DBの期間データ品質チェック。
 *
 * race_entry を母集団にして、分析CSV生成前に以下を確認する。
 * - 出走表: 6行 / lane 1～6 が6種
 * - race_master: 1行
 * - race_result_detail: 通常4行（1～4着保存仕様）かつ1着が1行
 * - race_payouts: 1行かつ3連単払戻 > 0
 * - exhibition_live: 参考件数のみ（未取得でもDB品質NGにはしない）
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
        race_code,
        COUNT(*)::int AS result_rows,
        COUNT(*) FILTER (WHERE rank = '1')::int AS winner_rows
    FROM boat_race.race_result_detail
    WHERE SUBSTRING(race_code, 1, 8) BETWEEN REPLACE(:from, '-', '') AND REPLACE(:to, '-', '')
    GROUP BY race_code
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
    COALESCE(r.winner_rows, 0) AS winner_rows,
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
    'result_bad' => 0,
    'payout_bad' => 0,
    'exhibition_none' => 0,
    'all_core_ok' => 0,
];
$examples = [
    'entry_bad' => [],
    'master_bad' => [],
    'result_bad' => [],
    'payout_bad' => [],
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
    // 通常レースは1～4着の4行保存。中止・不成立などはここで異常候補として拾う。
    $resultOk = (int)$row['result_rows'] === 4 && (int)$row['winner_rows'] === 1;
    $payoutOk = (int)$row['payout_rows'] === 1 && (float)$row['trifecta_payout'] > 0.0;
    $exhibitionOk = (int)$row['exhibition_rows'] > 0;
    $coreOk = $entryOk && $masterOk && $resultOk && $payoutOk;

    if (!isset($daily[$date])) {
        $daily[$date] = ['n' => 0, 'core_ok' => 0, 'entry_bad' => 0, 'master_bad' => 0, 'result_bad' => 0, 'payout_bad' => 0];
    }
    $daily[$date]['n']++;
    if ($coreOk) $daily[$date]['core_ok']++;

    $detail = sprintf(
        '%s | entry=%d/lane=%d master=%d result=%d/win=%d payout=%d/3T=%s exhibition=%d',
        $code,
        (int)$row['entry_rows'],
        (int)$row['lane_count'],
        (int)$row['master_rows'],
        (int)$row['result_rows'],
        (int)$row['winner_rows'],
        (int)$row['payout_rows'],
        number_format((float)$row['trifecta_payout'], 0, '.', ''),
        (int)$row['exhibition_rows']
    );

    foreach ([
        'entry_bad' => !$entryOk,
        'master_bad' => !$masterOk,
        'result_bad' => !$resultOk,
        'payout_bad' => !$payoutOk,
        'exhibition_none' => !$exhibitionOk,
    ] as $key => $bad) {
        if (!$bad) continue;
        $stats[$key]++;
        if ($key !== 'exhibition_none') $daily[$date][$key]++;
        if (count($examples[$key]) < $limit) $examples[$key][] = $detail;
    }

    if ($coreOk) $stats['all_core_ok']++;
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

echo str_repeat('=', 122) . "\n";
echo "競艇DB データ品質チェック\n";
echo "期間: {$from} ～ {$to}\n";
echo "母集団: race_entryに存在するレース / {$total}R\n";
echo str_repeat('=', 122) . "\n\n";

printf("コア品質OK                 : %6d / %6d = %s\n", $stats['all_core_ok'], $total, pct($stats['all_core_ok'], $total));
printf("出走表6艇不備             : %6d / %6d = %s\n", $stats['entry_bad'], $total, pct($stats['entry_bad'], $total));
printf("race_master不備           : %6d / %6d = %s\n", $stats['master_bad'], $total, pct($stats['master_bad'], $total));
printf("結果4行/1着行不備          : %6d / %6d = %s\n", $stats['result_bad'], $total, pct($stats['result_bad'], $total));
printf("3連単払戻不備             : %6d / %6d = %s\n", $stats['payout_bad'], $total, pct($stats['payout_bad'], $total));
printf("展示なし（参考・NG扱い外）: %6d / %6d = %s\n", $stats['exhibition_none'], $total, pct($stats['exhibition_none'], $total));

echo "\n【日別コア品質】\n";
foreach ($daily as $date => $s) {
    printf(
        "%s | N=%3d OK=%3d(%6s) | entry=%2d master=%2d result=%2d payout=%2d\n",
        $date,
        $s['n'],
        $s['core_ok'],
        pct($s['core_ok'], $s['n']),
        $s['entry_bad'],
        $s['master_bad'],
        $s['result_bad'],
        $s['payout_bad']
    );
}

printExamples('出走表6艇不備', $examples['entry_bad'], $stats['entry_bad'], $limit);
printExamples('race_master不備', $examples['master_bad'], $stats['master_bad'], $limit);
printExamples('結果4行/1着行不備', $examples['result_bad'], $stats['result_bad'], $limit);
printExamples('3連単払戻不備', $examples['payout_bad'], $stats['payout_bad'], $limit);
printExamples('展示なし（参考）', $examples['exhibition_none'], $stats['exhibition_none'], $limit);

echo "\n【判定メモ】\n";
echo "- 過去日でコア品質OKがほぼ100%なら、長期CSV作成のDB土台は良好。\n";
echo "- result/payout不備は中止・不成立レースも含むため、例外か取得漏れかをrace_code単位で確認する。\n";
echo "- race_master不備でも基本1着率はフォールバック可能だが、他分析ではmasterを使うため品質課題として残す。\n";
echo "- exhibition_liveは未取得レースがあり得るので、このチェックではコア品質NGに含めない。\n";
