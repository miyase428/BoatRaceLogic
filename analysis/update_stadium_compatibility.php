<?php

declare(strict_types=1);

/**
 * 場別・R別予想相性のローリング更新。
 *
 * 初回は過去365日を再現して基礎CSVを作成し、2回目以降は直近3日だけを
 * 再現・差し替えする。結果訂正が遅れて入るケースもこの3日で取り込む。
 *
 * Usage:
 *   php analysis/update_stadium_compatibility.php
 *   php analysis/update_stadium_compatibility.php --through 2026-09-21
 */

const COMPATIBILITY_DAYS = 365;
const COMPATIBILITY_REBUILD_DAYS = 3;

function compatibilityUsage(): never
{
    fwrite(STDERR, "Usage: php analysis/update_stadium_compatibility.php [--through YYYY-MM-DD]\n");
    exit(1);
}

function compatibilityOpenCsv(string $path, array $required): array
{
    $fp = fopen($path, 'rb');
    if ($fp === false) throw new RuntimeException("CSVを開けません: {$path}");
    $header = fgetcsv($fp);
    if (!is_array($header)) throw new RuntimeException("CSVヘッダーを読み込めません: {$path}");
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $map = array_flip($header);
    foreach ($required as $column) {
        if (!array_key_exists($column, $map)) throw new RuntimeException("必要な列がありません: {$column} ({$path})");
    }
    return [$fp, $header, $map];
}

function compatibilityNextRow($fp, int $dateIndex, string $startDate, string $endDate): ?array
{
    while (($row = fgetcsv($fp)) !== false) {
        $date = trim((string)($row[$dateIndex] ?? ''));
        if ($date >= $startDate && $date <= $endDate) return $row;
    }
    return null;
}

/** 大きな艇別CSVを丸ごとメモリに載せず、日付・レース順のまま差し替える。 */
function compatibilityStreamMerge(
    string $destination,
    ?string $existingPath,
    string $generatedPath,
    array $required,
    bool $boats,
    string $startDate,
    string $endDate
): void {
    [$generatedFp, $header, $map] = compatibilityOpenCsv($generatedPath, $required);
    $existingFp = null;
    $existingMap = null;
    if ($existingPath !== null && is_file($existingPath)) {
        [$existingFp, $existingHeader, $existingMap] = compatibilityOpenCsv($existingPath, $required);
        if ($existingHeader !== $header) throw new RuntimeException("CSV列が一致しません: {$existingPath}");
    }
    $dateIndex = $map['race_date'];
    $keyOf = static function (array $row) use ($map, $boats): string {
        $key = (string)$row[$map['race_code']];
        return $boats ? $key . ':' . str_pad((string)$row[$map['lane_number']], 2, '0', STR_PAD_LEFT) : $key;
    };
    $tmp = $destination . '.tmp.' . getmypid();
    $out = fopen($tmp, 'wb');
    if ($out === false) throw new RuntimeException("一時CSVを作成できません: {$destination}");
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header);
    $existingRow = $existingFp === null ? null : compatibilityNextRow($existingFp, $existingMap['race_date'], $startDate, $endDate);
    $generatedRow = compatibilityNextRow($generatedFp, $dateIndex, $startDate, $endDate);
    while ($existingRow !== null || $generatedRow !== null) {
        if ($generatedRow === null) {
            fputcsv($out, $existingRow);
            $existingRow = compatibilityNextRow($existingFp, $existingMap['race_date'], $startDate, $endDate);
            continue;
        }
        if ($existingRow === null) {
            fputcsv($out, $generatedRow);
            $generatedRow = compatibilityNextRow($generatedFp, $dateIndex, $startDate, $endDate);
            continue;
        }
        $existingKey = $keyOf($existingRow);
        $generatedKey = $keyOf($generatedRow);
        if ($existingKey < $generatedKey) {
            fputcsv($out, $existingRow);
            $existingRow = compatibilityNextRow($existingFp, $existingMap['race_date'], $startDate, $endDate);
        } elseif ($existingKey > $generatedKey) {
            fputcsv($out, $generatedRow);
            $generatedRow = compatibilityNextRow($generatedFp, $dateIndex, $startDate, $endDate);
        } else {
            // 直近再計算側を優先し、同じrace_codeの古い行を置換する。
            fputcsv($out, $generatedRow);
            $existingRow = compatibilityNextRow($existingFp, $existingMap['race_date'], $startDate, $endDate);
            $generatedRow = compatibilityNextRow($generatedFp, $dateIndex, $startDate, $endDate);
        }
    }
    fclose($out);
    fclose($generatedFp);
    if ($existingFp !== null) fclose($existingFp);
    if (!rename($tmp, $destination)) {
        @unlink($tmp);
        throw new RuntimeException("CSVを更新できません: {$destination}");
    }
}

function compatibilityCsvRange(string $path): array
{
    if (!is_file($path)) return [null, null];
    [$fp, , $map] = compatibilityOpenCsv($path, ['race_date']);
    $start = null;
    $end = null;
    while (($row = fgetcsv($fp)) !== false) {
        $date = trim((string)($row[$map['race_date']] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) continue;
        $start = $start === null || $date < $start ? $date : $start;
        $end = $end === null || $date > $end ? $date : $end;
    }
    fclose($fp);
    return [$start, $end];
}

/**
 * 過去に出力済みのCSVがあれば初回の土台に使う。
 * 完全再構築は重いため、年次CSV + 直近の差分CSVが残っている通常環境では
 * 不足分だけを再現する。ファイルがなければ従来どおり365日を作成する。
 */
function compatibilitySeedFromExistingExports(
    string $outputDir,
    string $rawRaces,
    string $rawBoats,
    string $startDate,
    string $endDate
): void {
    if (is_file($rawRaces) || is_file($rawBoats)) return;
    $sources = [];
    foreach (glob($outputDir . '/final_prediction_races_fast_cached_*.csv') ?: [] as $racePath) {
        if (preg_match('/_(\d{8})_(\d{8})\.csv$/', basename($racePath), $m) !== 1) continue;
        $sources[] = [
            'race_path' => $racePath,
            'boat_path' => str_replace('/final_prediction_races_', '/final_prediction_boats_', $racePath),
            'start' => substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2),
            'end' => substr($m[2], 0, 4) . '-' . substr($m[2], 4, 2) . '-' . substr($m[2], 6, 2),
        ];
    }
    $eligible = array_values(array_filter($sources, static fn(array $s): bool => is_file($s['boat_path']) && $s['start'] <= $startDate));
    usort($eligible, static fn(array $a, array $b): int => (strtotime($b['end']) - strtotime($b['start'])) <=> (strtotime($a['end']) - strtotime($a['start'])));
    $base = $eligible[0] ?? null;
    if ($base === null) return;
    compatibilityStreamMerge($rawRaces, null, $base['race_path'], ['race_code', 'race_date'], false, $startDate, $endDate);
    compatibilityStreamMerge($rawBoats, null, $base['boat_path'], ['race_code', 'race_date', 'lane_number'], true, $startDate, $endDate);
    $latestEnd = $base['end'];
    $byStart = [];
    foreach ($sources as $source) {
        if (!isset($byStart[$source['start']]) || $source['end'] > $byStart[$source['start']]['end']) {
            $byStart[$source['start']] = $source;
        }
    }
    $sources = array_values($byStart);
    usort($sources, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);
    foreach ($sources as $source) {
        $racePath = $source['race_path'];
        $boatPath = $source['boat_path'];
        if ($source['start'] <= $latestEnd || $source['start'] > $endDate || !is_file($boatPath)) continue;
        compatibilityStreamMerge($rawRaces, $rawRaces, $racePath, ['race_code', 'race_date'], false, $startDate, $endDate);
        compatibilityStreamMerge($rawBoats, $rawBoats, $boatPath, ['race_code', 'race_date', 'lane_number'], true, $startDate, $endDate);
        $latestEnd = max($latestEnd, $source['end']);
    }
}

$through = (new DateTimeImmutable('yesterday'))->format('Y-m-d');
if ($argc === 3 && $argv[1] === '--through' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $argv[2]) === 1) {
    $through = $argv[2];
} elseif ($argc !== 1) {
    compatibilityUsage();
}

$end = new DateTimeImmutable($through);
$windowStart = $end->modify('-' . (COMPATIBILITY_DAYS - 1) . ' days')->format('Y-m-d');
$root = dirname(__DIR__);
$outputDir = $root . '/analysis/output';
$rawRaces = $outputDir . '/stadium_compatibility_races.csv';
$rawBoats = $outputDir . '/stadium_compatibility_boats.csv';
compatibilitySeedFromExistingExports($outputDir, $rawRaces, $rawBoats, $windowStart, $through);
[$existingStart, $existingEnd] = compatibilityCsvRange($rawRaces);
$isInitial = $existingStart === null || $existingStart > $windowStart || $existingEnd === null;
$exportStart = $isInitial
    ? $windowStart
    : max($windowStart, (new DateTimeImmutable($existingEnd))->modify('-' . (COMPATIBILITY_REBUILD_DAYS - 1) . ' days')->format('Y-m-d'));

echo "場別・R別相性を更新します: {$exportStart} ～ {$through}" . ($isInitial ? "（初回の365日作成）\n" : "（直近3日を再計算）\n");
$command = escapeshellarg(PHP_BINARY) . ' '
    . escapeshellarg($root . '/analysis/export_final_prediction_fast_cached.php') . ' '
    . escapeshellarg($exportStart) . ' ' . escapeshellarg($through) . ' 4';
passthru($command, $exitCode);
if ($exitCode !== 0) throw new RuntimeException("予想CSVの生成に失敗しました (exit={$exitCode})");

$labelStart = str_replace('-', '', $exportStart);
$labelEnd = str_replace('-', '', $through);
$generatedRaces = "{$outputDir}/final_prediction_races_fast_cached_{$labelStart}_{$labelEnd}.csv";
$generatedBoats = "{$outputDir}/final_prediction_boats_fast_cached_{$labelStart}_{$labelEnd}.csv";
compatibilityStreamMerge($rawRaces, $rawRaces, $generatedRaces, ['race_code', 'race_date'], false, $windowStart, $through);
compatibilityStreamMerge($rawBoats, $rawBoats, $generatedBoats, ['race_code', 'race_date', 'lane_number'], true, $windowStart, $through);

$commands = [
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/analysis/export_stadium_affinity_json.php') . ' ' . escapeshellarg($rawRaces),
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/analysis/export_stadium_race_number_compatibility_json.php') . ' ' . escapeshellarg($rawRaces) . ' ' . escapeshellarg($rawBoats),
];
foreach ($commands as $refreshCommand) {
    passthru($refreshCommand, $exitCode);
    if ($exitCode !== 0) throw new RuntimeException("相性JSONの生成に失敗しました (exit={$exitCode})");
}
echo "場別・R別相性の更新が完了しました。対象最終日: {$through}\n";
