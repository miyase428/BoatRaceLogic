<?php

declare(strict_types=1);

/**
 * 場別60Rの過去展示再現を、1回あたり4Rずつ進める低負荷ランナー。
 * 完了済み場は飛ばし、全場60Rが揃えば何もしない。
 */

date_default_timezone_set('Asia/Tokyo');

$root = dirname(__DIR__);
$from = '2025-09-23';
$to = '2026-09-22';
$places = ['KRY','TDA','EDG','HWJ','TMG','HMN','GMG','TKN','TSU','MKN','BWK','SME','AMG','NRT','MRG','KJM','MYJ','TKY','SMS','WKM','ASY','FKO','KRT','OMR'];
$lock = fopen(sys_get_temp_dir() . '/boatrace_honmei_taikou_batch.lock', 'c');
if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);

foreach ($places as $place) {
    $path = $root . '/analysis/output/honmei_taikou_probability_backtest_' . strtolower($place) . '.json';
    $saved = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
    $count = is_array($saved) ? (int)($saved['evaluated_races'] ?? 0) : 0;
    if ($count >= 60) continue;

    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/analysis/backtest_honmei_taikou_probability.php')
        . ' ' . escapeshellarg($from) . ' ' . escapeshellarg($to)
        . ' --place=' . escapeshellarg($place) . ' --limit=60 --resume --max-new=4';
    passthru($command, $status);
    exit($status);
}

echo "全場の直近60R再現は完了しています。\n";
