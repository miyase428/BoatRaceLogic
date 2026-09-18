<?php

declare(strict_types=1);

/**
 * 既存の締切前固定済み大穴JSONを、共通の自動採点テーブルへ初回移行する。
 * ファイル内の作成時刻が公式締切より前であるレコードだけがStore側で受理される。
 *
 * Usage:
 *   php analysis/import_hole_forward_snapshots.php [YYYY-MM-DD [YYYY-MM-DD]]
 */

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../web/logic/PredictionForwardSnapshotStore.php';

$start = trim((string)($argv[1] ?? date('Y-m-01')));
$end = trim((string)($argv[2] ?? $start));
foreach ([$start, $end] as $value) {
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if ($dt === false || $dt->format('Y-m-d') !== $value) {
        fwrite(STDERR, "Usage: php analysis/import_hole_forward_snapshots.php [START [END]]\n");
        exit(2);
    }
}

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$store = new PredictionForwardSnapshotStore($pdo);
if (!$store->isReady()) {
    fwrite(STDERR, "自動前向き検証テーブルが未作成です。\n");
    exit(1);
}

$before = (int)$pdo->query("SELECT COUNT(*) FROM boat_race.prediction_forward_snapshots WHERE component = 'hole_prediction'")->fetchColumn();
$read = 0;
$invalid = 0;
$cursor = new DateTimeImmutable($start);
$last = new DateTimeImmutable($end);
while ($cursor <= $last) {
    $dir = __DIR__ . '/output/hole_forward/' . $cursor->format('Ymd');
    foreach (glob($dir . '/*.json') ?: [] as $path) {
        $record = json_decode((string)@file_get_contents($path), true);
        if (!is_array($record) || ($record['result_tables_referenced'] ?? null) !== false) {
            $invalid++;
            continue;
        }
        $read++;
        PredictionForwardSnapshotStore::importFrozenHolePrediction($record, $pdo);
    }
    $cursor = $cursor->modify('+1 day');
}

$after = (int)$pdo->query("SELECT COUNT(*) FROM boat_race.prediction_forward_snapshots WHERE component = 'hole_prediction'")->fetchColumn();
echo "大穴前向きJSON移行: 読込={$read}件 / 新規=" . ($after - $before) . "件 / 無効={$invalid}件 / 登録済={$after}件\n";
