<?php

declare(strict_types=1);

/**
 * STEP12 M1高配当シグナル 1年再現性確認。
 *
 * 固定条件:
 *   S516穴筋(CUT維持) × 1号艇一次4位以下
 *
 * 重要:
 * - 条件・閾値は一切変更しない。
 * - STEP10/11で採用したM1が、古い6か月でも万舟率上昇を再現するかだけを見る。
 * - ここでは新しい候補探索をしない。
 *
 * Usage:
 * php analysis/validate_m1_high_payout_1year_reproducibility.php \
 *   analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *   analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv \
 *   analysis/output/trifecta_payouts_20250815_20260814.csv
 */

const MANSHU = 10000;
const PAYOUT_30K = 30000;
const PAYOUT_100K = 100000;

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php {$argv[0]} DATASET_CSV BOATS_CSV PAYOUT_CSV\n");
    exit(1);
}

[$script, $datasetPath, $boatsPath, $payoutPath] = $argv;
foreach ([$datasetPath, $boatsPath, $payoutPath] as $path) {
    if (!is_file($path)) throw new RuntimeException("必要ファイルがありません: {$path}");
}

function readCsvAssoc(string $path): array
{
    $fp = fopen($path, 'rb');
    if ($fp === false) throw new RuntimeException("CSVを開けません: {$path}");
    $header = fgetcsv($fp);
    if ($header === false) { fclose($fp); return []; }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
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

function pct(int $n, int $d): float { return $d > 0 ? 100.0 * $n / $d : 0.0; }
function lift(float $rate, float $base): float { return $base > 0 ? $rate / $base : 0.0; }

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
    foreach ($boats as $lane => $b) $kiru[(int)$lane] = inum($b, 'kiru') === 1;

    $s516 = sampleOk($row, 5)
        && k($row, 5, 'makurizashi') >= 15.0
        && !($kiru[5] ?? true)
        && !($kiru[1] ?? true)
        && !($kiru[6] ?? true);

    $lane1PrimaryWeak = rankOf($boats, 1, 'first_rank') >= 4;
    return $s516 && $lane1PrimaryWeak;
}

function summarize(array $rows): array
{
    $s = [
        'n'=>0,'m'=>0,'p30'=>0,'p100'=>0,'sum'=>0,
        'one1'=>0,'one2'=>0,'one3'=>0,'oneOut'=>0,
    ];
    foreach ($rows as $r) {
        $p = (int)$r['payout'];
        $s['n']++;
        $s['sum'] += $p;
        if ($p >= MANSHU) {
            $s['m']++;
            $a1 = (int)$r['actual1'];
            $a2 = (int)$r['actual2'];
            $a3 = (int)$r['actual3'];
            if ($a1 === 1) $s['one1']++;
            elseif ($a2 === 1) $s['one2']++;
            elseif ($a3 === 1) $s['one3']++;
            else $s['oneOut']++;
        }
        if ($p >= PAYOUT_30K) $s['p30']++;
        if ($p >= PAYOUT_100K) $s['p100']++;
    }
    return $s;
}

$dataset = readCsvAssoc($datasetPath);
$boatRows = readCsvAssoc($boatsPath);
$payoutRows = readCsvAssoc($payoutPath);

$boatsByRace = [];
foreach ($boatRows as $b) {
    $code = trim((string)($b['race_code'] ?? ''));
    $lane = inum($b, 'lane_number');
    if ($code !== '' && $lane >= 1 && $lane <= 6) $boatsByRace[$code][$lane] = $b;
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

    $races[] = [
        'race_code'=>$code,
        'race_date'=>$date,
        'actual1'=>inum($row, 'actual_1st'),
        'actual2'=>inum($row, 'actual_2nd'),
        'actual3'=>inum($row, 'actual_3rd'),
        'payout'=>$payout,
        'm1'=>isM1($row, $boats),
    ];
}

$periodDefs = [
    'OLD1' => ['2025-08-15','2025-11-14'],
    'OLD2' => ['2025-11-15','2026-02-16'],
    'OLD6M' => ['2025-08-15','2026-02-16'],
    'H1' => ['2026-02-17','2026-04-15'],
    'H2' => ['2026-04-16','2026-06-14'],
    'DISCOVERY' => ['2026-06-15','2026-08-14'],
    'ALL1Y' => ['2025-08-15','2026-08-14'],
];

$periods = [];
foreach ($periodDefs as $label => [$start,$end]) {
    $periods[$label] = array_values(array_filter(
        $races,
        static fn(array $r): bool => $r['race_date'] >= $start && $r['race_date'] <= $end
    ));
}

echo str_repeat('=', 168) . PHP_EOL;
echo "STEP12 M1高配当シグナル 1年再現性確認" . PHP_EOL;
echo str_repeat('=', 168) . PHP_EOL;
echo "固定条件: S516穴筋(CUT維持) × 1号艇一次4位以下" . PHP_EOL;
echo "条件・閾値は変更しない。古い6か月で万舟Liftを再確認する。" . PHP_EOL . PHP_EOL;

printf("%-10s %8s %8s %8s %8s %8s %9s %8s %9s %24s\n",
    '期間','BASE N','M1 N','BASE万舟','M1万舟','Lift','3万率','10万率','平均払戻','M1万舟①位置 1/2/3/外');
echo str_repeat('-', 168) . PHP_EOL;

foreach ($periods as $label => $rows) {
    $base = summarize($rows);
    $m1Rows = array_values(array_filter($rows, static fn(array $r): bool => !empty($r['m1'])));
    $m1 = summarize($m1Rows);

    $baseM = pct($base['m'],$base['n']);
    $m1M = pct($m1['m'],$m1['n']);
    $m130 = pct($m1['p30'],$m1['n']);
    $m1100 = pct($m1['p100'],$m1['n']);
    $avg = $m1['n'] > 0 ? $m1['sum'] / $m1['n'] : 0.0;

    printf(
        "%-10s %8d %8d %7.2f%% %7.2f%% %7.2fx %8.2f%% %7.2f%% %8.0f円 %4d/%4d/%4d/%4d\n",
        $label,
        $base['n'],
        $m1['n'],
        $baseM,
        $m1M,
        lift($m1M,$baseM),
        $m130,
        $m1100,
        $avg,
        $m1['one1'],$m1['one2'],$m1['one3'],$m1['oneOut']
    );
}

echo PHP_EOL;
echo "判定:" . PHP_EOL;
echo "1. OLD1/OLD2の両方でM1万舟Lift>1なら1年再現性は強い。" . PHP_EOL;
echo "2. OLD6MでもLift>1なら、STEP10のH1/H2再現と合わせて採用根拠を補強。" . PHP_EOL;
echo "3. ①着外優勢が古い期間でも続くか確認する。" . PHP_EOL;
echo "4. この結果を見ても条件・閾値は変更しない。" . PHP_EOL;
