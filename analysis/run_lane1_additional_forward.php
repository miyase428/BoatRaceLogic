<?php

declare(strict_types=1);

/**
 * 1号艇関連の横待ち2候補を、指定した未使用前方期間だけでまとめて再検証するラッパー。
 *
 * 条件そのものは既存スクリプトへ委譲し、このファイルでは変更しない。
 * - 現行Web頭!=1 × 1号艇一次1位 -> 1号艇へ戻す
 * - Web本命1 × 1号艇一次4位以下 × 1号艇二次1位 -> 1号艇1着危険
 *
 * Usage:
 * php analysis/run_lane1_additional_forward.php \
 *   analysis/output/kimarite_analysis_dataset_20260823_20260831.csv \
 *   analysis/output/final_prediction_boats_fast_cached_20260823_20260831.csv \
 *   2026-08-29 2026-08-31
 */

if ($argc !== 5) {
    fwrite(STDERR, "Usage: php {$argv[0]} DATASET_CSV BOATS_CSV START_DATE END_DATE\n");
    exit(1);
}

[$script, $datasetPath, $boatsPath, $startDate, $endDate] = $argv;

foreach ([$datasetPath, $boatsPath] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException("必要ファイルがありません: {$path}");
    }
}

foreach ([$startDate, $endDate] as $date) {
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException("日付形式が不正です: {$date}");
    }
}
if ($startDate > $endDate) {
    throw new InvalidArgumentException('START_DATE は END_DATE 以下にしてください。');
}

function readCsv(string $path): array
{
    $fp = fopen($path, 'rb');
    if ($fp === false) throw new RuntimeException("CSVを開けません: {$path}");
    $header = fgetcsv($fp);
    if ($header === false) {
        fclose($fp);
        throw new RuntimeException("CSVヘッダを読めません: {$path}");
    }
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    }
    $rows = [];
    while (($cols = fgetcsv($fp)) !== false) {
        if (count($cols) !== count($header)) continue;
        $rows[] = array_combine($header, $cols);
    }
    fclose($fp);
    return [$header, $rows];
}

function writeCsv(string $path, array $header, array $rows): void
{
    $fp = fopen($path, 'wb');
    if ($fp === false) throw new RuntimeException("一時CSVを書けません: {$path}");
    fputcsv($fp, $header);
    foreach ($rows as $row) {
        $cols = [];
        foreach ($header as $name) $cols[] = $row[$name] ?? '';
        fputcsv($fp, $cols);
    }
    fclose($fp);
}

[$datasetHeader, $datasetRows] = readCsv($datasetPath);
if (!in_array('race_code', $datasetHeader, true) || !in_array('race_date', $datasetHeader, true)) {
    throw new RuntimeException('DATASET_CSV に race_code / race_date がありません。');
}

$filteredDataset = [];
$raceCodes = [];
foreach ($datasetRows as $row) {
    $date = trim((string)($row['race_date'] ?? ''));
    if ($date < $startDate || $date > $endDate) continue;
    $code = trim((string)($row['race_code'] ?? ''));
    if ($code === '') continue;
    $filteredDataset[] = $row;
    $raceCodes[$code] = true;
}

if (!$filteredDataset) {
    throw new RuntimeException("指定期間 {$startDate} ～ {$endDate} のDATASETレースが0件です。");
}

[$boatsHeader, $boatRows] = readCsv($boatsPath);
if (!in_array('race_code', $boatsHeader, true)) {
    throw new RuntimeException('BOATS_CSV に race_code がありません。');
}

$filteredBoats = [];
foreach ($boatRows as $row) {
    $code = trim((string)($row['race_code'] ?? ''));
    if (isset($raceCodes[$code])) $filteredBoats[] = $row;
}

$tmpDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR . 'boatrace_lane1_additional_forward_' . getmypid();
if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
    throw new RuntimeException("一時ディレクトリを作れません: {$tmpDir}");
}

$tmpDataset = $tmpDir . DIRECTORY_SEPARATOR . 'dataset.csv';
$tmpBoats = $tmpDir . DIRECTORY_SEPARATOR . 'boats.csv';
writeCsv($tmpDataset, $datasetHeader, $filteredDataset);
writeCsv($tmpBoats, $boatsHeader, $filteredBoats);

$commands = [
    [
        'title' => '候補A: Web頭!=1 × 1号艇一次1位 -> 1号艇へ戻す',
        'script' => __DIR__ . '/validate_lane1_primary1_global_forward_holdout.php',
    ],
    [
        'title' => '候補B: Web本命1 × 1号艇一次4位以下 × 1号艇二次1位 -> 1号艇1着危険',
        'script' => __DIR__ . '/validate_web1_primary4_secondary1_risk_forward.php',
    ],
];

echo str_repeat('=', 178) . PHP_EOL;
echo "1号艇横待ち2候補 追加前方検証" . PHP_EOL;
echo "期間: {$startDate} ～ {$endDate}" . PHP_EOL;
echo "対象DATASET: " . count($filteredDataset) . "R / BOATS行: " . count($filteredBoats) . PHP_EOL;
echo "条件変更: なし（既存の固定検証スクリプトをそのまま実行）" . PHP_EOL;
echo str_repeat('=', 178) . PHP_EOL;

$exitCode = 0;
foreach ($commands as $item) {
    echo PHP_EOL . str_repeat('#', 178) . PHP_EOL;
    echo $item['title'] . PHP_EOL;
    echo str_repeat('#', 178) . PHP_EOL;

    $cmd = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($item['script'])
        . ' ' . escapeshellarg($tmpDataset)
        . ' ' . escapeshellarg($tmpBoats);
    passthru($cmd, $code);
    if ($code !== 0) $exitCode = $code;
}

@unlink($tmpDataset);
@unlink($tmpBoats);
@rmdir($tmpDir);

exit($exitCode);
