<?php

declare(strict_types=1);

/**
 * 本命＋対抗の3連単を、記録済み合成オッズで分けて実績比較する。
 * 前方記録を最優先し、ない過去レースは official_historical_final を補助値として使う。
 *
 * Usage: php analysis/report_trifecta_odds_gate.php [--threshold 2.3]
 */

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../web/logic/PredictionForwardSnapshotStore.php';

$threshold = 2.3;
if (($argv[1] ?? '') !== '') {
    if (($argv[1] ?? '') !== '--threshold' || !isset($argv[2]) || !is_numeric($argv[2]) || (float)$argv[2] <= 0.0) {
        fwrite(STDERR, "Usage: php analysis/report_trifecta_odds_gate.php [--threshold 2.3]\n");
        exit(2);
    }
    $threshold = (float)$argv[2];
}

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sql = <<<'SQL'
SELECT DISTINCT ON (o.race_code)
    o.race_code, o.race_date, o.payload AS odds_payload,
    p.payload AS prediction_payload, p.actual_result, p.trifecta_payout,
    o.captured_at
FROM boat_race.prediction_forward_snapshots o
JOIN LATERAL (
    SELECT payload, actual_result, trifecta_payout
    FROM boat_race.prediction_forward_snapshots p
    WHERE p.race_code = o.race_code
      AND p.stage = 'exhibition'
      AND p.component = 'prediction'
      AND p.actual_result IS NOT NULL
      AND p.trifecta_payout IS NOT NULL
    ORDER BY (p.validation_mode = 'strict') DESC, p.captured_at DESC
    LIMIT 1
) p ON true
WHERE o.stage = 'exhibition'
  AND o.component = 'trifecta_odds'
ORDER BY o.race_code,
    ((o.payload->>'odds_kind') = 'official_pre_deadline') DESC,
    o.captured_at DESC
SQL;
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$buckets = [
    'all' => ['races' => 0, 'hits' => 0, 'points' => 0, 'investment' => 0, 'payout' => 0],
    'skip' => ['races' => 0, 'hits' => 0, 'points' => 0, 'investment' => 0, 'payout' => 0],
    'buy' => ['races' => 0, 'hits' => 0, 'points' => 0, 'investment' => 0, 'payout' => 0],
];
$preDeadline = 0;
$historicalFinal = 0;

foreach ($rows as $row) {
    $oddsPayload = json_decode((string)$row['odds_payload'], true);
    $predictionPayload = json_decode((string)$row['prediction_payload'], true);
    if (!is_array($oddsPayload) || !is_array($predictionPayload)) {
        continue;
    }
    $odds = (array)($oddsPayload['odds'] ?? []);
    $bets = array_values(array_unique(array_merge(
        PredictionForwardSnapshotStore::expandTrifecta((string)($predictionPayload['honmei_kai'] ?? '')),
        PredictionForwardSnapshotStore::expandTrifecta((string)($predictionPayload['taikou_kai'] ?? ''))
    )));
    if ($bets === []) {
        continue;
    }
    $inverse = 0.0;
    $ready = true;
    foreach ($bets as $bet) {
        $value = (float)($odds[$bet] ?? 0.0);
        if ($value <= 0.0) {
            $ready = false;
            break;
        }
        $inverse += 1.0 / $value;
    }
    if (!$ready || $inverse <= 0.0) {
        continue;
    }
    $combinedOdds = 1.0 / $inverse;
    $hit = in_array((string)$row['actual_result'], $bets, true);
    $bucketNames = ['all', $combinedOdds < $threshold ? 'skip' : 'buy'];
    foreach ($bucketNames as $name) {
        $buckets[$name]['races']++;
        $buckets[$name]['hits'] += (int)$hit;
        $buckets[$name]['points'] += count($bets);
        $buckets[$name]['investment'] += count($bets) * 100;
        $buckets[$name]['payout'] += $hit ? (int)$row['trifecta_payout'] : 0;
    }
    (($oddsPayload['odds_kind'] ?? '') === 'official_pre_deadline') ? $preDeadline++ : $historicalFinal++;
}

echo "3連単 本命＋対抗 合成オッズ・ゲート検証\n";
printf("閾値: %.2f倍未満 = 見推奨\n", $threshold);
printf("使用内訳: 締切前記録=%dR / 過去確定オッズ=%dR\n\n", $preDeadline, $historicalFinal);
foreach ([
    'all' => '全件',
    'skip' => sprintf('見推奨（%.2f倍未満）', $threshold),
    'buy' => sprintf('買い候補（%.2f倍以上）', $threshold),
] as $key => $label) {
    $s = $buckets[$key];
    $hitRate = $s['races'] > 0 ? 100.0 * $s['hits'] / $s['races'] : 0.0;
    $roi = $s['investment'] > 0 ? 100.0 * $s['payout'] / $s['investment'] : 0.0;
    printf("%-28s N=%4d / 的中=%3d (%5.1f%%) / 投資=%8d円 / 払戻=%8d円 / 回収率=%6.1f%%\n",
        $label, $s['races'], $s['hits'], $hitRate, $s['investment'], $s['payout'], $roi);
}
echo "\n※過去確定オッズは締切前の画面値ではないため、前方記録と区別して判断してください。\n";
