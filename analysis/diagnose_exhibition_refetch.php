<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../logic/race_url.php';

date_default_timezone_set('Asia/Tokyo');

/**
 * exhibition_live の既存値と、競艇日和を現在のPlaywrightスクレイパーで
 * 再取得した値を1レース単位で比較する診断。
 * DB更新は一切行わない。
 *
 * Usage:
 *   php analysis/diagnose_exhibition_refetch.php 20260804TKN01
 */

$raceCode = trim((string)($argv[1] ?? ''));
if (!preg_match('/^\d{8}[A-Z]{3}\d{2}$/', $raceCode)) {
    fwrite(STDERR, "Usage: php {$argv[0]} YYYYMMDDXXXRR\n");
    exit(1);
}

function val(mixed $v): string
{
    if ($v === null) return '-';
    $s = trim((string)$v);
    return $s === '' ? '-' : $s;
}

function countNonNull(array $rows, string $key): int
{
    $n = 0;
    foreach ($rows as $row) {
        if (!array_key_exists($key, $row)) continue;
        $v = $row[$key];
        if ($v !== null && trim((string)$v) !== '' && trim((string)$v) !== '-') $n++;
    }
    return $n;
}

$pdo = getPDO();
$stmt = $pdo->prepare(<<<SQL
SELECT
    entry_course, player_id,
    exhibition_time, start_timing, lap_time, around_time, straight_time
FROM boat_race.exhibition_live
WHERE race_code = :race_code
ORDER BY entry_course
SQL);
$stmt->execute([':race_code' => $raceCode]);
$dbRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

try {
    $url = raceCodeToKyoteiBiyoriUrl($raceCode);
} catch (Throwable $e) {
    fwrite(STDERR, "URL生成失敗: {$e->getMessage()}\n");
    exit(1);
}

$cmd = 'HOME=/home/miyazaki PLAYWRIGHT_BROWSERS_PATH=/home/miyazaki/.cache/ms-playwright '
     . '/usr/bin/node /var/www/html/boatrace/playwright/exhibition_live_scraper.js '
     . escapeshellarg($url) . ' 2>&1';

$out = [];
$returnVar = 0;
exec($cmd, $out, $returnVar);

if ($returnVar !== 0) {
    echo "Playwright失敗 code={$returnVar}\n";
    echo implode("\n", $out) . "\n";
    exit(2);
}

$json = implode("\n", $out);
$srcRows = json_decode($json, true);
if (!is_array($srcRows)) {
    echo "JSON解析失敗: " . json_last_error_msg() . "\n";
    echo "RAW:\n{$json}\n";
    exit(2);
}

$dbByCourse = [];
foreach ($dbRows as $r) $dbByCourse[(int)$r['entry_course']] = $r;
$srcByCourse = [];
foreach ($srcRows as $r) {
    $c = (int)($r['entry_course'] ?? 0);
    if ($c >= 1 && $c <= 6) $srcByCourse[$c] = $r;
}

$fields = [
    'exhibition_time' => 'E',
    'start_timing' => 'ST',
    'lap_time' => 'L',
    'around_time' => 'A',
    'straight_time' => 'D',
];

echo str_repeat('=', 150) . "\n";
echo "展示指標 再取得比較診断\n";
echo "race_code : {$raceCode}\n";
echo "URL       : {$url}\n";
echo "DB行数    : " . count($dbRows) . "\n";
echo "再取得行数: " . count($srcRows) . "\n";
echo str_repeat('=', 150) . "\n\n";

printf("%-3s %-8s | %-44s | %-44s\n", 'C', 'player', 'DB  E/ST/L/A/D', 'SOURCE  E/ST/L/A/D');
echo str_repeat('-', 150) . "\n";
for ($c = 1; $c <= 6; $c++) {
    $d = $dbByCourse[$c] ?? [];
    $s = $srcByCourse[$c] ?? [];
    $dbText = implode('/', array_map(fn($k) => val($d[$k] ?? null), array_keys($fields)));
    $srcText = implode('/', array_map(fn($k) => val($s[$k] ?? null), array_keys($fields)));
    $pid = val($s['player_id'] ?? ($d['player_id'] ?? null));
    printf("%-3d %-8s | %-44s | %-44s\n", $c, $pid, $dbText, $srcText);
}

echo "\n【非NULL艇数】\n";
foreach ($fields as $key => $label) {
    printf("%-3s DB=%d / SOURCE=%d\n", $label, countNonNull($dbRows, $key), countNonNull($srcRows, $key));
}

$improved = false;
foreach (array_keys($fields) as $key) {
    if (countNonNull($srcRows, $key) > countNonNull($dbRows, $key)) {
        $improved = true;
    }
}

echo "\n判定: " . ($improved
    ? '再取得側で非NULL項目が増えています → DB補修候補'
    : '再取得しても項目数は増えていません → 取得元側の非提供/当時仕様の可能性が高い') . "\n";
