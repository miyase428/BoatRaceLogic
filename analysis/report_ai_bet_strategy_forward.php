<?php

declare(strict_types=1);

/**
 * AI買い方タイプv1の締切前・前方成績を集計する。
 *
 * Usage: php analysis/report_ai_bet_strategy_forward.php
 */

require_once __DIR__ . '/../common/db_connect.php';

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $pdo->query(<<<'SQL'
SELECT race_code, race_date, payload, grade
FROM boat_race.prediction_forward_snapshots
WHERE stage = 'exhibition'
  AND component = 'trifecta_odds'
  AND validation_mode = 'strict'
  AND payload->>'source' = 'ai_bet_modes_v1'
  AND graded_at IS NOT NULL
ORDER BY race_date, race_code, captured_at
SQL);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$names = ['CURRENT_COMBINED', 'HIT_FOCUS', 'BALANCE', 'ONE_SHOT', 'SELECTIVE'];
$totals = [];
foreach ($names as $name) {
    $totals[$name] = [
        'all_races' => 0,
        'bet_races' => 0,
        'points' => 0,
        'hits' => 0,
        'manshu_hits' => 0,
        'investment' => 0,
        'return' => 0,
    ];
}

foreach ($rows as $row) {
    $grade = json_decode((string)$row['grade'], true);
    if (!is_array($grade)) continue;
    $metrics = [
        'CURRENT_COMBINED' => $grade['selection_metrics']['combined'] ?? null,
        'HIT_FOCUS' => $grade['ai_bet_modes']['modes']['HIT_FOCUS'] ?? null,
        'BALANCE' => $grade['ai_bet_modes']['modes']['BALANCE'] ?? null,
        'ONE_SHOT' => $grade['ai_bet_modes']['modes']['ONE_SHOT'] ?? null,
        'SELECTIVE' => $grade['ai_bet_modes']['modes']['SELECTIVE'] ?? null,
    ];
    foreach ($metrics as $name => $metric) {
        if (!is_array($metric)) continue;
        $points = (int)($metric['points'] ?? 0);
        $hit = !empty($metric['hit']);
        $totals[$name]['all_races']++;
        if ($points <= 0) continue;
        $totals[$name]['bet_races']++;
        $totals[$name]['points'] += $points;
        $totals[$name]['hits'] += (int)$hit;
        $totals[$name]['manshu_hits'] += (int)(!empty($metric['manshu_hit']) || ($hit && (int)($metric['return'] ?? 0) >= 10000));
        $totals[$name]['investment'] += (int)($metric['investment'] ?? 0);
        $totals[$name]['return'] += (int)($metric['return'] ?? 0);
    }
}

echo "AI買い方タイプ v1 前方評価（締切前オッズ）\n";
printf("採点済み: %dR / 中間確認まで残り: %dR / 採用判断まで残り: %dR\n\n",
    count($rows), max(0, 1000 - count($rows)), max(0, 2000 - count($rows)));
echo "方式                 購入R/全R     購入率   平均点数    的中率   万舟   回収率\n";
echo str_repeat('-', 88) . "\n";
foreach ($names as $name) {
    $t = $totals[$name];
    $betRate = $t['all_races'] > 0 ? 100.0 * $t['bet_races'] / $t['all_races'] : 0.0;
    $avgPoints = $t['bet_races'] > 0 ? $t['points'] / $t['bet_races'] : 0.0;
    $hitRate = $t['bet_races'] > 0 ? 100.0 * $t['hits'] / $t['bet_races'] : 0.0;
    $roi = $t['investment'] > 0 ? 100.0 * $t['return'] / $t['investment'] : 0.0;
    printf(
        "%-20s %4d/%-4d   %7.2f%%   %7.2f   %7.2f%%   %4d   %7.2f%%\n",
        $name,
        $t['bet_races'],
        $t['all_races'],
        $betRate,
        $avgPoints,
        $hitRate,
        $t['manshu_hits'],
        $roi
    );
}

echo "\n※100円/点。公式締切前オッズと同時保存した予想だけを集計します。\n";
