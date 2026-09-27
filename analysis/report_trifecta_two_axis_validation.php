<?php

declare(strict_types=1);

/**
 * 本命＋対抗3連単を、確率合計と均等配分のモデル期待回収率で検証する。
 * 合成オッズは購入条件に使わない。
 *
 * Usage: php analysis/report_trifecta_two_axis_validation.php
 */

require_once __DIR__ . '/../common/db_connect.php';

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sql = <<<'SQL'
SELECT DISTINCT ON (o.race_code)
    o.race_code, o.race_date, o.payload AS odds_payload,
    p.actual_result, p.trifecta_payout
FROM boat_race.prediction_forward_snapshots o
JOIN LATERAL (
    SELECT actual_result, trifecta_payout
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
  AND o.validation_mode = 'strict'
  AND COALESCE((o.payload->'selection_metrics'->'combined'->>'points')::int, 0) > 0
ORDER BY o.race_code, o.captured_at DESC
SQL;
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

/** @return array{races:int,hits:int,investment:int,payout:int} */
function blankBucket(): array
{
    return ['races' => 0, 'hits' => 0, 'investment' => 0, 'payout' => 0];
}

function addResult(array &$bucket, int $points, bool $hit, int $payout): void
{
    $bucket['races']++;
    $bucket['hits'] += (int)$hit;
    $bucket['investment'] += $points * 100;
    $bucket['payout'] += $hit ? $payout : 0;
}

function probabilityBand(float $probability): string
{
    // 購入ルールではなく、傾向を見るための固定集計帯。
    if ($probability < 0.25) return 'P < 25%';
    if ($probability < 0.40) return '25% ≤ P < 40%';
    return 'P ≥ 40%';
}

function expectedRoiBand(float $roi): string
{
    // こちらも購入閾値ではない。1.00（100%）周辺を確認するための帯。
    if ($roi < 0.80) return '期待ROI < 80%';
    if ($roi < 1.00) return '80% ≤ 期待ROI < 100%';
    return '期待ROI ≥ 100%';
}

$all = blankBucket();
$probabilityBuckets = [];
$expectedRoiBuckets = [];
$crossBuckets = [];
$usable = 0;
foreach ($rows as $row) {
    $payload = json_decode((string)$row['odds_payload'], true);
    $metric = is_array($payload) ? ($payload['selection_metrics']['combined'] ?? null) : null;
    if (!is_array($metric)) continue;
    $points = (int)($metric['points'] ?? 0);
    $probability = (float)($metric['probability_sum'] ?? -1.0);
    $expectedRoi = $metric['equal_stake_model_expected_roi'] ?? null;
    if ($points <= 0 || $probability < 0.0 || !is_numeric($expectedRoi)) continue;

    $hit = in_array((string)$row['actual_result'], (array)($metric['bets'] ?? []), true);
    $payout = (int)$row['trifecta_payout'];
    $probBand = probabilityBand($probability);
    $roiBand = expectedRoiBand((float)$expectedRoi);
    $crossKey = $probBand . ' / ' . $roiBand;
    $probabilityBuckets[$probBand] ??= blankBucket();
    $expectedRoiBuckets[$roiBand] ??= blankBucket();
    $crossBuckets[$crossKey] ??= blankBucket();
    addResult($all, $points, $hit, $payout);
    addResult($probabilityBuckets[$probBand], $points, $hit, $payout);
    addResult($expectedRoiBuckets[$roiBand], $points, $hit, $payout);
    addResult($crossBuckets[$crossKey], $points, $hit, $payout);
    $usable++;
}

function printBuckets(string $title, array $buckets): void
{
    echo "\n{$title}\n";
    foreach ($buckets as $label => $bucket) {
        $hitRate = $bucket['races'] > 0 ? $bucket['hits'] / $bucket['races'] * 100.0 : 0.0;
        $roi = $bucket['investment'] > 0 ? $bucket['payout'] / $bucket['investment'] * 100.0 : 0.0;
        printf("%-32s N=%4d / 的中=%3d (%5.1f%%) / 回収率=%6.1f%%\n",
            $label, $bucket['races'], $bucket['hits'], $hitRate, $roi);
    }
}

echo "3連単 本命＋対抗・2軸検証\n";
echo "軸: 買い目の確率合計 / 均等配分のモデル期待回収率\n";
echo "合成オッズは参考表示のみで、集計条件には使いません。\n";
printf("採点済み対象: %dR\n", $usable);
printBuckets('全体', ['全件' => $all]);
printBuckets('確率合計別（観察用）', $probabilityBuckets);
printBuckets('モデル期待回収率別（観察用）', $expectedRoiBuckets);
printBuckets('2軸クロス（観察用）', $crossBuckets);
echo "\n※各帯は傾向観察用であり、購入・見送りの閾値は前方データが溜まってから決めます。\n";
