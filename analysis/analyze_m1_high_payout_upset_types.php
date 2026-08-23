<?php

declare(strict_types=1);

/**
 * STEP11 M1高配当シグナルの荒れ方を分類する。
 *
 * M1はSTEP10で固定済み:
 *   S516穴筋(CUT維持) × 1号艇一次4位以下
 *
 * ここではM1の条件・閾値は一切変更せず、M1成立時の万舟が
 * 「1逃げ」「外頭-1が2着」「外頭-1が3着」「1着外」のどれに寄るか、
 * また実際の頭コース・5-1-* / 5-1-6 がどれだけ含まれるかを確認する。
 *
 * Usage:
 * php analysis/analyze_m1_high_payout_upset_types.php \
 *   analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *   analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv \
 *   analysis/output/trifecta_payouts_20250815_20260814.csv
 */

const MANSHU = 10000;

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php {$argv[0]} DATASET_CSV BOATS_CSV PAYOUT_CSV\n");
    exit(1);
}

[$script, $datasetPath, $boatsPath, $payoutPath] = $argv;
foreach ([$datasetPath, $boatsPath, $payoutPath] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException("必要ファイルがありません: {$path}");
    }
}

function readCsvAssoc(string $path): array
{
    $fp = fopen($path, 'rb');
    if ($fp === false) throw new RuntimeException("CSVを開けません: {$path}");
    $header = fgetcsv($fp);
    if ($header === false) { fclose($fp); return []; }
    $header[0] = preg_replace('/^\\xEF\\xBB\\xBF/', '', (string)$header[0]);
    $rows = [];
    while (($cols = fgetcsv($fp)) !== false) {
        if (count($cols) !== count($header)) continue;
        $rows[] = array_combine($header, $cols);
    }
    fclose($fp);
    return $rows;
}

function inum(array $row, string $key, int $default = 0): int
{
    $v = $row[$key] ?? null;
    return is_numeric($v) ? (int)$v : $default;
}

function fnum(array $row, string $key, float $default = 0.0): float
{
    $v = $row[$key] ?? null;
    return is_numeric($v) ? (float)$v : $default;
}

function pct(int $n, int $d): float
{
    return $d > 0 ? 100.0 * $n / $d : 0.0;
}

function sampleOk(array $row, int $course): bool
{
    return inum($row, "c{$course}_6m_sample_n") >= 10;
}

function k(array $row, int $course, string $kind): float
{
    return fnum($row, "c{$course}_6m_{$kind}");
}

function rankOf(array $boats, int $lane, string $key): int
{
    return isset($boats[$lane]) ? inum($boats[$lane], $key, 99) : 99;
}

function isM1(array $row, array $boats): bool
{
    $kiru = [];
    foreach ($boats as $lane => $b) {
        $kiru[(int)$lane] = inum($b, 'kiru') === 1;
    }

    $s516 = sampleOk($row, 5)
        && k($row, 5, 'makurizashi') >= 15.0
        && !($kiru[5] ?? true)
        && !($kiru[1] ?? true)
        && !($kiru[6] ?? true);

    $lane1PrimaryWeak = rankOf($boats, 1, 'first_rank') >= 4;
    return $s516 && $lane1PrimaryWeak;
}

function onePositionType(int $a1, int $a2, int $a3): string
{
    if ($a1 === 1) return '1逃げ';
    if ($a2 === 1) return '外頭-1が2着';
    if ($a3 === 1) return '外頭-1が3着';
    return '1着外';
}

$dataset = readCsvAssoc($datasetPath);
$boatRows = readCsvAssoc($boatsPath);
$payoutRows = readCsvAssoc($payoutPath);

$boatsByRace = [];
foreach ($boatRows as $b) {
    $code = trim((string)($b['race_code'] ?? ''));
    $lane = inum($b, 'lane_number');
    if ($code !== '' && $lane >= 1 && $lane <= 6) {
        $boatsByRace[$code][$lane] = $b;
    }
}

$payouts = [];
foreach ($payoutRows as $p) {
    $code = trim((string)($p['race_code'] ?? ''));
    $pay = inum($p, 'trifecta_payout');
    if ($code !== '' && $pay > 0) $payouts[$code] = $pay;
}

$races = [];
foreach ($dataset as $row) {
    if (inum($row, 'result_top3_course_complete') !== 1 || inum($row, 'result_boat_match') !== 1) continue;

    $code = trim((string)($row['race_code'] ?? ''));
    $date = trim((string)($row['race_date'] ?? ''));
    $boats = $boatsByRace[$code] ?? [];
    $payout = $payouts[$code] ?? 0;
    if ($code === '' || $date === '' || count($boats) !== 6 || $payout <= 0) continue;
    if (!isM1($row, $boats)) continue;

    $a1 = inum($row, 'actual_1st');
    $a2 = inum($row, 'actual_2nd');
    $a3 = inum($row, 'actual_3rd');
    if ($a1 < 1 || $a1 > 6 || $a2 < 1 || $a2 > 6 || $a3 < 1 || $a3 > 6) continue;

    $races[] = [
        'race_code' => $code,
        'race_date' => $date,
        'stadium' => trim((string)($row['stadium_name'] ?? '')),
        'payout' => $payout,
        'a1' => $a1,
        'a2' => $a2,
        'a3' => $a3,
        'trifecta' => "{$a1}-{$a2}-{$a3}",
    ];
}

$periodDefs = [
    'H1' => ['2026-02-17', '2026-04-15'],
    'H2' => ['2026-04-16', '2026-06-14'],
    'HOLDOUT' => ['2026-02-17', '2026-06-14'],
    'DISCOVERY' => ['2026-06-15', '2026-08-14'],
    'ALL6M' => ['2026-02-17', '2026-08-14'],
];

echo str_repeat('=', 150) . PHP_EOL;
echo "STEP11 M1高配当シグナル 荒れ方タイプ分類" . PHP_EOL;
echo str_repeat('=', 150) . PHP_EOL;
echo "固定条件: S516穴筋(CUT維持) × 1号艇一次4位以下" . PHP_EOL;
echo "条件・閾値は変更せず、万舟時の形だけを分類する。" . PHP_EOL . PHP_EOL;

foreach ($periodDefs as $label => [$start, $end]) {
    $rows = array_values(array_filter(
        $races,
        static fn(array $r): bool => $r['race_date'] >= $start && $r['race_date'] <= $end
    ));
    $manshu = array_values(array_filter($rows, static fn(array $r): bool => $r['payout'] >= MANSHU));

    $typeCounts = ['1逃げ'=>0, '外頭-1が2着'=>0, '外頭-1が3着'=>0, '1着外'=>0];
    $headCounts = array_fill(1, 6, 0);
    $fiveOneAny = 0;
    $fiveOneSix = 0;

    foreach ($manshu as $r) {
        $typeCounts[onePositionType($r['a1'], $r['a2'], $r['a3'])]++;
        $headCounts[$r['a1']]++;
        if ($r['a1'] === 5 && $r['a2'] === 1) $fiveOneAny++;
        if ($r['a1'] === 5 && $r['a2'] === 1 && $r['a3'] === 6) $fiveOneSix++;
    }

    echo str_repeat('-', 150) . PHP_EOL;
    printf("【%s】M1成立=%dR / 万舟=%dR (%.2f%%)\n", $label, count($rows), count($manshu), pct(count($manshu), count($rows)));
    printf(
        "万舟内の①位置: 1逃げ %d (%.1f%%) / 外頭-1が2着 %d (%.1f%%) / 外頭-1が3着 %d (%.1f%%) / 1着外 %d (%.1f%%)\n",
        $typeCounts['1逃げ'], pct($typeCounts['1逃げ'], count($manshu)),
        $typeCounts['外頭-1が2着'], pct($typeCounts['外頭-1が2着'], count($manshu)),
        $typeCounts['外頭-1が3着'], pct($typeCounts['外頭-1が3着'], count($manshu)),
        $typeCounts['1着外'], pct($typeCounts['1着外'], count($manshu))
    );
    printf(
        "万舟の実頭: 1=%d / 2=%d / 3=%d / 4=%d / 5=%d / 6=%d\n",
        $headCounts[1], $headCounts[2], $headCounts[3], $headCounts[4], $headCounts[5], $headCounts[6]
    );
    printf(
        "5-1-*=%dR (万舟中 %.1f%%) / 5-1-6=%dR (万舟中 %.1f%%)\n",
        $fiveOneAny, pct($fiveOneAny, count($manshu)),
        $fiveOneSix, pct($fiveOneSix, count($manshu))
    );
}

$all = array_values(array_filter(
    $races,
    static fn(array $r): bool => $r['race_date'] >= '2026-02-17' && $r['race_date'] <= '2026-08-14' && $r['payout'] >= MANSHU
));
$exactCounts = [];
foreach ($all as $r) {
    $exactCounts[$r['trifecta']] = ($exactCounts[$r['trifecta']] ?? 0) + 1;
}
arsort($exactCounts, SORT_NUMERIC);

echo PHP_EOL . str_repeat('=', 150) . PHP_EOL;
echo "ALL6M M1万舟の上位出目" . PHP_EOL;
echo str_repeat('=', 150) . PHP_EOL;
$i = 0;
foreach ($exactCounts as $tri => $n) {
    printf("%-8s %3dR\n", $tri, $n);
    if (++$i >= 15) break;
}

echo PHP_EOL;
echo "判断ポイント:" . PHP_EOL;
echo "1. M1万舟で『外頭-1が2着/3着』が安定して多いなら、イン飛び警報とは別の『1残り穴目』として扱う。" . PHP_EOL;
echo "2. 5-1-* / 5-1-6がM1万舟の一部に留まるなら、M1そのものを5-1-6専用警報にはしない。" . PHP_EOL;
echo "3. H1/H2/DISCOVERYで荒れ方の方向が大きく変わらないかを確認する。" . PHP_EOL;
