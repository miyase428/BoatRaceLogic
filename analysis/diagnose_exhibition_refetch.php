<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../logic/race_url.php';

date_default_timezone_set('Asia/Tokyo');

/**
 * exhibition_live の既存値と、競艇日和を現在のPlaywrightスクレイパーで
 * 再取得した値を比較する診断。
 * DB更新は一切行わない。
 *
 * 複数race_code指定時は、サイト負荷を抑えるため各レース間を10～13秒待機する。
 *
 * Usage:
 *   php analysis/diagnose_exhibition_refetch.php 20260804TKN01
 *   php analysis/diagnose_exhibition_refetch.php 20260804TKN01 20260807TSU04 20260804SME10
 */

$raceCodes = array_values(array_filter(array_map(
    static fn($v) => trim((string)$v),
    array_slice($argv, 1)
)));

if (!$raceCodes) {
    fwrite(STDERR, "Usage: php {$argv[0]} YYYYMMDDXXXRR [YYYYMMDDXXXRR ...]\n");
    exit(1);
}

foreach ($raceCodes as $code) {
    if (!preg_match('/^\d{8}[A-Z]{3}\d{2}$/', $code)) {
        fwrite(STDERR, "race_code形式不正: {$code}\n");
        exit(1);
    }
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

function signature(array $rows): string
{
    $map = [
        'exhibition_time' => 'E',
        'start_timing' => 'ST',
        'lap_time' => 'L',
        'around_time' => 'A',
        'straight_time' => 'D',
    ];
    $parts = [];
    foreach ($map as $key => $label) {
        $parts[] = $label . countNonNull($rows, $key);
    }
    return implode(' ', $parts);
}

function normalWait(): void
{
    $seconds = random_int(1000, 1300) / 100;
    printf("次レースまで待機 %.2f 秒\n", $seconds);
    usleep((int)round($seconds * 1_000_000));
}

function diagnoseRace(PDO $pdo, string $raceCode): array
{
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
        echo "URL生成失敗: {$e->getMessage()}\n";
        return ['race_code' => $raceCode, 'status' => 'url_error'];
    }

    $cmd = 'HOME=/home/miyazaki PLAYWRIGHT_BROWSERS_PATH=/home/miyazaki/.cache/ms-playwright '
         . '/usr/bin/node /var/www/html/boatrace/playwright/exhibition_live_scraper.js '
         . escapeshellarg($url) . ' 2>&1';

    $out = [];
    $returnVar = 0;
    exec($cmd, $out, $returnVar);

    if ($returnVar !== 0) {
        echo str_repeat('=', 150) . "\n";
        echo "展示指標 再取得比較診断\n";
        echo "race_code : {$raceCode}\n";
        echo "Playwright失敗 code={$returnVar}\n";
        echo implode("\n", $out) . "\n";
        return ['race_code' => $raceCode, 'status' => 'playwright_error'];
    }

    $json = implode("\n", $out);
    $srcRows = json_decode($json, true);
    if (!is_array($srcRows)) {
        echo str_repeat('=', 150) . "\n";
        echo "展示指標 再取得比較診断\n";
        echo "race_code : {$raceCode}\n";
        echo "JSON解析失敗: " . json_last_error_msg() . "\n";
        echo "RAW:\n{$json}\n";
        return ['race_code' => $raceCode, 'status' => 'json_error'];
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
    echo "DB署名    : " . signature($dbRows) . "\n";
    echo "SOURCE署名: " . signature($srcRows) . "\n";
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
    $improved = false;
    foreach ($fields as $key => $label) {
        $dbN = countNonNull($dbRows, $key);
        $srcN = countNonNull($srcRows, $key);
        printf("%-3s DB=%d / SOURCE=%d\n", $label, $dbN, $srcN);
        if ($srcN > $dbN) $improved = true;
    }

    echo "\n判定: " . ($improved
        ? '再取得側で非NULL項目が増えています → DB補修候補'
        : '再取得しても項目数は増えていません → 取得元側の非提供/当時仕様の可能性が高い') . "\n";

    return [
        'race_code' => $raceCode,
        'status' => 'ok',
        'db_signature' => signature($dbRows),
        'source_signature' => signature($srcRows),
        'improved' => $improved,
    ];
}

$pdo = getPDO();
$summary = [];
$total = count($raceCodes);

foreach ($raceCodes as $i => $raceCode) {
    $summary[] = diagnoseRace($pdo, $raceCode);
    if ($i < $total - 1) {
        echo "\n";
        normalWait();
        echo "\n";
    }
}

if ($total > 1) {
    echo "\n" . str_repeat('=', 120) . "\n";
    echo "一括診断サマリ\n";
    echo str_repeat('=', 120) . "\n";
    foreach ($summary as $row) {
        if (($row['status'] ?? '') !== 'ok') {
            printf("%s | %s\n", $row['race_code'], $row['status']);
            continue;
        }
        printf(
            "%s | DB %-24s | SRC %-24s | %s\n",
            $row['race_code'],
            $row['db_signature'],
            $row['source_signature'],
            $row['improved'] ? '補修候補' : '取得元同一'
        );
    }
}
