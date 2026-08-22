<?php

declare(strict_types=1);

/**
 * 万舟分析用 3連単払戻キャッシュCSV作成
 *
 * race CSV から race_code を集め、race_payouts から trifecta_payout だけを取得してCSV化する。
 * DBアクセスはこのスクリプトだけ。高配当条件分析側はDB不要。
 *
 * Usage:
 *   php analysis/export_trifecta_payout_cache.php \
 *     analysis/output/trifecta_payouts_20260215_20260814.csv \
 *     analysis/output/final_prediction_races_20260215_20260814.csv
 *
 * 複数race CSVも指定可:
 *   php analysis/export_trifecta_payout_cache.php <output.csv> <races1.csv> <races2.csv> ...
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php analysis/export_trifecta_payout_cache.php <output.csv> <races.csv> [races2.csv ...]\n");
    exit(1);
}

$outputPath = $argv[1];
$raceCsvs = array_slice($argv, 2);

require_once __DIR__ . '/../common/db_connect.php';

function readRaceCodes(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("CSVが見つかりません: {$path}");
    }

    $fp = fopen($path, 'rb');
    if ($fp === false) {
        throw new RuntimeException("CSVを開けません: {$path}");
    }

    $header = fgetcsv($fp);
    if ($header === false) {
        fclose($fp);
        throw new RuntimeException("CSVが空です: {$path}");
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $idx = array_search('race_code', $header, true);
    if ($idx === false) {
        fclose($fp);
        throw new RuntimeException("race_code列がありません: {$path}");
    }

    $codes = [];
    while (($row = fgetcsv($fp)) !== false) {
        $code = trim((string)($row[$idx] ?? ''));
        if ($code !== '') {
            $codes[$code] = true;
        }
    }
    fclose($fp);
    return array_keys($codes);
}

try {
    $codeMap = [];
    foreach ($raceCsvs as $path) {
        foreach (readRaceCodes($path) as $code) {
            $codeMap[$code] = true;
        }
    }
    $raceCodes = array_keys($codeMap);
    sort($raceCodes);

    $pdo = getPDO();
    $payouts = [];
    $chunkSize = 500;

    foreach (array_chunk($raceCodes, $chunkSize) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $sql = "SELECT race_code, trifecta_payout FROM boat_race.race_payouts WHERE race_code IN ({$placeholders})";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($chunk);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $code = trim((string)($row['race_code'] ?? ''));
            $payout = $row['trifecta_payout'] ?? null;
            if ($code !== '' && $payout !== null && $payout !== '') {
                $payouts[$code] = (int)$payout;
            }
        }
    }

    $dir = dirname($outputPath);
    if ($dir !== '.' && !is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $out = fopen($outputPath, 'wb');
    if ($out === false) {
        throw new RuntimeException("出力CSVを作成できません: {$outputPath}");
    }

    fputcsv($out, ['race_code', 'trifecta_payout']);
    $found = 0;
    foreach ($raceCodes as $code) {
        if (!array_key_exists($code, $payouts)) {
            continue;
        }
        fputcsv($out, [$code, $payouts[$code]]);
        $found++;
    }
    fclose($out);

    $missing = count($raceCodes) - $found;
    echo "対象race_code : " . count($raceCodes) . PHP_EOL;
    echo "払戻取得      : {$found}" . PHP_EOL;
    echo "払戻なし      : {$missing}" . PHP_EOL;
    echo "出力          : {$outputPath}" . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
