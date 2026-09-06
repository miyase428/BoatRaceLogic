<?php
/**
 * ガチガチレース検索の基礎検証。
 *
 * 条件は「1号艇(1C)の1年逃げ率 >= X」AND「2号艇(2C)の1年逃し率 >= Y」だけ。
 * Web本命/AI/展示/場補正などは一切加えず、実際の1号艇1着率がどこまで上がるかを見る。
 *
 * Usage:
 *   php analysis/analyze_gachigachi_escape_search.php \
 *     analysis/output/kimarite_analysis_dataset_20250815_20260814.csv
 *
 * Optional:
 *   2nd arg = minimum sample_n (default 10)
 */

declare(strict_types=1);

function usage(): never
{
    fwrite(STDERR, "使用方法:\n  php analysis/analyze_gachigachi_escape_search.php KIMARITE_DATASET_CSV [MIN_SAMPLE_N]\n");
    exit(1);
}

if ($argc < 2 || $argc > 3) {
    usage();
}

$csvPath = $argv[1];
$minSample = isset($argv[2]) ? max(0, (int)$argv[2]) : 10;

if (!is_file($csvPath)) {
    fwrite(STDERR, "CSVがありません: {$csvPath}\n");
    exit(1);
}

$fh = fopen($csvPath, 'rb');
if ($fh === false) {
    fwrite(STDERR, "CSVを開けません: {$csvPath}\n");
    exit(1);
}

$header = fgetcsv($fh);
if ($header === false) {
    fwrite(STDERR, "CSVヘッダを読めません\n");
    exit(1);
}

$idx = array_flip($header);
$required = [
    'race_code',
    'c1_1y_sample_n',
    'c1_1y_nige',
    'c2_1y_sample_n',
    'c2_1y_nogashi',
    'actual_1st',
];
foreach ($required as $col) {
    if (!array_key_exists($col, $idx)) {
        fwrite(STDERR, "必要列がありません: {$col}\n");
        exit(1);
    }
}

$rows = [];
$dateMin = null;
$dateMax = null;
$skips = [
    'sample' => 0,
    'actual' => 0,
    'rate' => 0,
];

while (($data = fgetcsv($fh)) !== false) {
    if (count($data) < count($header)) {
        continue;
    }

    $raceCode = trim((string)$data[$idx['race_code']]);
    $n1 = (int)($data[$idx['c1_1y_sample_n']] ?? 0);
    $n2 = (int)($data[$idx['c2_1y_sample_n']] ?? 0);
    if ($n1 < $minSample || $n2 < $minSample) {
        $skips['sample']++;
        continue;
    }

    $nigeRaw = trim((string)($data[$idx['c1_1y_nige']] ?? ''));
    $nogashiRaw = trim((string)($data[$idx['c2_1y_nogashi']] ?? ''));
    if ($nigeRaw === '' || $nogashiRaw === '' || !is_numeric($nigeRaw) || !is_numeric($nogashiRaw)) {
        $skips['rate']++;
        continue;
    }

    $actualRaw = trim((string)($data[$idx['actual_1st']] ?? ''));
    if ($actualRaw === '' || !preg_match('/^[1-6]$/', $actualRaw)) {
        $skips['actual']++;
        continue;
    }

    $raceNo = 0;
    if (preg_match('/(0[1-9]|1[0-2])$/', $raceCode, $m)) {
        $raceNo = (int)$m[1];
    }

    $date = substr($raceCode, 0, 8);
    if (preg_match('/^\d{8}$/', $date)) {
        $dateMin = $dateMin === null || $date < $dateMin ? $date : $dateMin;
        $dateMax = $dateMax === null || $date > $dateMax ? $date : $dateMax;
    }

    $rows[] = [
        'race_code' => $raceCode,
        'race_no' => $raceNo,
        'nige' => (float)$nigeRaw,
        'nogashi' => (float)$nogashiRaw,
        'actual_1st' => (int)$actualRaw,
    ];
}
fclose($fh);

if (!$rows) {
    fwrite(STDERR, "分析可能な行がありません\n");
    exit(1);
}

function pct(int $num, int $den): float
{
    return $den > 0 ? 100.0 * $num / $den : 0.0;
}

function stat(array $rows, ?float $nigeMin = null, ?float $nogashiMin = null, ?array $raceRange = null): array
{
    $n = 0;
    $win1 = 0;
    $actualEscape = 0;

    foreach ($rows as $r) {
        if ($nigeMin !== null && $r['nige'] < $nigeMin) continue;
        if ($nogashiMin !== null && $r['nogashi'] < $nogashiMin) continue;
        if ($raceRange !== null) {
            [$from, $to] = $raceRange;
            if ($r['race_no'] < $from || $r['race_no'] > $to) continue;
        }
        $n++;
        if ($r['actual_1st'] === 1) {
            $win1++;
            $actualEscape++;
        }
    }

    return [
        'n' => $n,
        'win1' => $win1,
        'rate' => pct($win1, $n),
        'actual_escape' => $actualEscape,
    ];
}

function fmtDate(?string $ymd): string
{
    if ($ymd === null || strlen($ymd) !== 8) return '-';
    return substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2);
}

$nigeThresholds = [50, 55, 60, 65, 70, 75];
$nogashiThresholds = [35, 40, 45, 50, 55, 60];

$base = stat($rows);

$line = str_repeat('=', 132);
echo $line . "\n";
echo "ガチガチ検索 基礎検証（1C逃げ率 × 2C逃し率だけ）\n";
echo "CSV      : " . basename($csvPath) . "\n";
echo "期間     : " . fmtDate($dateMin) . " ～ " . fmtDate($dateMax) . "\n";
echo "分析母体 : " . number_format($base['n']) . "R\n";
printf("基礎1号艇1着率: %.2f%% (%d/%d)\n", $base['rate'], $base['win1'], $base['n']);
echo "sample_n : c1/c2とも {$minSample}以上\n";
echo "追加条件 : Web本命・AI・展示・場補正なし\n";
echo $line . "\n\n";

echo "【閾値マトリクス：セル = N / 1号艇1着率 / 基礎差】\n";
printf("%-12s", '1C\\2C');
foreach ($nogashiThresholds as $y) {
    printf(" | %-18s", "逃し>={$y}");
}
echo "\n" . str_repeat('-', 132) . "\n";

foreach ($nigeThresholds as $x) {
    printf("%-12s", "逃げ>={$x}");
    foreach ($nogashiThresholds as $y) {
        $s = stat($rows, (float)$x, (float)$y);
        $diff = $s['rate'] - $base['rate'];
        $cell = sprintf("%5d / %5.2f / %+5.2f", $s['n'], $s['rate'], $diff);
        printf(" | %-18s", $cell);
    }
    echo "\n";
}

echo "\n【1C逃げ率だけ】\n";
foreach ($nigeThresholds as $x) {
    $s = stat($rows, (float)$x, null);
    printf("逃げ>=%2d%%  N=%6d  1号艇1着=%6.2f%%  基礎差=%+6.2fpt\n", $x, $s['n'], $s['rate'], $s['rate'] - $base['rate']);
}

echo "\n【2C逃し率だけ】\n";
foreach ($nogashiThresholds as $y) {
    $s = stat($rows, null, (float)$y);
    printf("逃し>=%2d%%  N=%6d  1号艇1着=%6.2f%%  基礎差=%+6.2fpt\n", $y, $s['n'], $s['rate'], $s['rate'] - $base['rate']);
}

echo "\n【競艇日和型の代表条件】\n";
$representatives = [
    [50, 35], [55, 40], [60, 45], [65, 50], [70, 55], [70, 60], [75, 60],
];
foreach ($representatives as [$x, $y]) {
    $s = stat($rows, (float)$x, (float)$y);
    printf("逃げ>=%2d%% × 逃し>=%2d%%  N=%6d  1号艇1着=%6.2f%%  基礎差=%+6.2fpt\n",
        $x, $y, $s['n'], $s['rate'], $s['rate'] - $base['rate']);
}

echo "\n【R帯別：逃げ>=70% × 逃し>=60%】\n";
foreach ([[1,4],[5,8],[9,12]] as [$from, $to]) {
    $s = stat($rows, 70.0, 60.0, [$from, $to]);
    printf("%2d～%2dR  N=%6d  1号艇1着=%6.2f%%  基礎差=%+6.2fpt\n",
        $from, $to, $s['n'], $s['rate'], $s['rate'] - $base['rate']);
}

echo "\n【参考：逃げ率+逃し率 >= 100】\n";
$n = 0;
$w = 0;
foreach ($rows as $r) {
    if (($r['nige'] + $r['nogashi']) < 100.0) continue;
    $n++;
    if ($r['actual_1st'] === 1) $w++;
}
$rate = pct($w, $n);
printf("N=%6d  1号艇1着=%6.2f%%  基礎差=%+6.2fpt\n", $n, $rate, $rate - $base['rate']);

echo "\n判断ポイント:\n";
echo "1. 閾値を厳しくするほど1号艇1着率が素直に上がるか。\n";
echo "2. Nが急減していないか。率だけでなく母数も見る。\n";
echo "3. 1C逃げ率単独と2C逃し率単独より、AND条件に追加価値があるか。\n";
echo "4. まずこの2条件だけで固定候補を探し、AI/展示条件は後段で別検証する。\n";
echo $line . "\n";
