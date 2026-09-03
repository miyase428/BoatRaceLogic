<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

$raceCode = trim((string)($argv[1] ?? ''));
if ($raceCode === '') {
    fwrite(STDERR, "Usage: php {$argv[0]} RACE_CODE\n");
    exit(1);
}

$pdo = getPDO();

function printRows(string $title, array $rows): void
{
    echo "\n【{$title}】 " . count($rows) . "行\n";
    if (!$rows) {
        echo "なし\n";
        return;
    }
    foreach ($rows as $row) {
        echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
}

$entryStmt = $pdo->prepare(
    'SELECT lane_number, player_id, player_name
       FROM boat_race.race_entry
      WHERE race_code = :race_code
      ORDER BY lane_number'
);
$entryStmt->execute([':race_code' => $raceCode]);
$entries = $entryStmt->fetchAll(PDO::FETCH_ASSOC);

$resultStmt = $pdo->prepare(
    "SELECT rank, lane_number, player_id, player_name, motor_number, boat_number,
            exhibition_time, entry_course, start_timing, goal_time, technique
       FROM boat_race.race_result_detail
      WHERE race_code = :race_code
      ORDER BY CASE WHEN TRIM(rank) ~ '^[0-9]+$' THEN TRIM(rank)::int ELSE 99 END,
               lane_number"
);
$resultStmt->execute([':race_code' => $raceCode]);
$results = $resultStmt->fetchAll(PDO::FETCH_ASSOC);

$payoutStmt = $pdo->prepare(
    'SELECT trifecta_combination, trifecta_payout
       FROM boat_race.race_payouts
      WHERE race_code = :race_code'
);
$payoutStmt->execute([':race_code' => $raceCode]);
$payouts = $payoutStmt->fetchAll(PDO::FETCH_ASSOC);

echo str_repeat('=', 120) . "\n";
echo "race_result_detail 個別診断\n";
echo "race_code: {$raceCode}\n";
echo str_repeat('=', 120) . "\n";

printRows('race_entry', $entries);
printRows('race_result_detail', $results);
printRows('race_payouts', $payouts);

$rankCounts = [];
$laneCounts = [];
foreach ($results as $row) {
    $rank = trim((string)($row['rank'] ?? ''));
    $lane = (int)($row['lane_number'] ?? 0);
    $rankCounts[$rank] = ($rankCounts[$rank] ?? 0) + 1;
    if ($lane > 0) $laneCounts[$lane] = ($laneCounts[$lane] ?? 0) + 1;
}
ksort($rankCounts);
ksort($laneCounts);

echo "\n【重複サマリ】\n";
echo 'rank counts: ' . json_encode($rankCounts, JSON_UNESCAPED_UNICODE) . "\n";
echo 'lane counts: ' . json_encode($laneCounts, JSON_UNESCAPED_UNICODE) . "\n";

echo "\n【確認ポイント】\n";
echo "- 3連単組合せの3着艇と、rank=3 の lane_number を比較する。\n";
echo "- 同じrankが複数あっても、同じlaneが重複していなければ元データ側で同着・特殊表記の可能性を確認する。\n";
echo "- 同じlaneが重複していればDB登録・バックフィル由来の不整合を疑う。\n";
