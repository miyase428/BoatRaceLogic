<?php

declare(strict_types=1);

/**
 * STEP10 決まり手・筋舟券 高配当候補の探索期間内再現性検証。
 *
 * 高配当用H1/H2ホールドアウトは使わない。
 * 探索期間 2026-06-15～2026-08-14 を
 *   D1: 2026-06-15～2026-07-14
 *   D2: 2026-07-15～2026-08-14
 * に固定分割し、候補の方向が両側で再現するかだけを見る。
 * 閾値の再探索は行わない。
 *
 * Usage:
 * php analysis/validate_high_payout_kimarite_discovery_split.php \
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

function attack(array $row, int $course): float
{
    return k($row, $course, 'makuri') + k($row, $course, 'makurizashi');
}

function rankOf(array $boats, int $lane, string $key): int
{
    return isset($boats[$lane]) ? inum($boats[$lane], $key, 99) : 99;
}

function topLane(array $boats, string $key): int
{
    $bestLane = 0; $bestRank = 999;
    foreach ($boats as $lane => $row) {
        $r = inum($row, $key, 99);
        if ($r < $bestRank) { $bestRank = $r; $bestLane = (int)$lane; }
    }
    return $bestLane;
}

function buildFeatures(array $row, array $boats): array
{
    $honmei = inum($row, 'honmei_head');
    $pTop = topLane($boats, 'first_rank');
    $sTop = topLane($boats, 'second_rank');

    $kiru = [];
    foreach ($boats as $lane => $b) {
        $kiru[(int)$lane] = inum($b, 'kiru') === 1;
    }

    $s5z = sampleOk($row, 5) && k($row, 5, 'makurizashi') >= 15.0;
    $a3 = $honmei === 1 && sampleOk($row, 3) && attack($row, 3) >= 15.0;
    $a4 = $honmei === 1 && sampleOk($row, 4) && attack($row, 4) >= 20.0;
    $c4m15 = sampleOk($row, 4) && k($row, 4, 'makuri') >= 15.0;

    return [
        'WEB_1号艇一次4位以下' => rankOf($boats, 1, 'first_rank') >= 4,
        'WEB_一次二次TOP不一致' => $pTop > 0 && $sTop > 0 && $pTop !== $sTop,
        'K_A3候補' => $a3,
        'K_A4候補' => $a4,
        'K_4Cまくり15+' => $c4m15,
        'K_S516穴筋' => $s5z
            && !($kiru[5] ?? true)
            && !($kiru[1] ?? true)
            && !($kiru[6] ?? true),
    ];
}

function summarize(array $rows): array
{
    $s = ['n'=>0,'m'=>0,'p30'=>0,'p100'=>0,'sum'=>0];
    foreach ($rows as $r) {
        $p = (int)$r['payout'];
        $s['n']++; $s['sum'] += $p;
        if ($p >= MANSHU) $s['m']++;
        if ($p >= PAYOUT_30K) $s['p30']++;
        if ($p >= PAYOUT_100K) $s['p100']++;
    }
    return $s;
}

function selected(array $rows, array $features): array
{
    return array_values(array_filter($rows, static function(array $r) use ($features): bool {
        foreach ($features as $f) if (empty($r['features'][$f])) return false;
        return true;
    }));
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

$periodDefs = [
    'D1' => ['2026-06-15', '2026-07-14'],
    'D2' => ['2026-07-15', '2026-08-14'],
    'ALL'=> ['2026-06-15', '2026-08-14'],
];
$periods = ['D1'=>[], 'D2'=>[], 'ALL'=>[]];

foreach ($dataset as $row) {
    $date = trim((string)($row['race_date'] ?? ''));
    if ($date < '2026-06-15' || $date > '2026-08-14') continue;
    if (inum($row, 'result_top3_course_complete') !== 1 || inum($row, 'result_boat_match') !== 1) continue;
    $code = trim((string)($row['race_code'] ?? ''));
    $boats = $boatsByRace[$code] ?? [];
    $payout = $payouts[$code] ?? 0;
    if ($code === '' || count($boats) !== 6 || $payout <= 0) continue;

    $r = [
        'race_code'=>$code,
        'race_date'=>$date,
        'payout'=>$payout,
        'features'=>buildFeatures($row, $boats),
    ];
    $periods['ALL'][] = $r;
    if ($date <= '2026-07-14') $periods['D1'][] = $r;
    else $periods['D2'][] = $r;
}

$candidates = [
    'M1_S516×1一次弱' => [
        'features'=>['K_S516穴筋','WEB_1号艇一次4位以下'],
        'purpose'=>'万舟',
    ],
    'B1_A3×一次二次不一致' => [
        'features'=>['K_A3候補','WEB_一次二次TOP不一致'],
        'purpose'=>'万舟+3万',
    ],
    'H1_A3×A4' => [
        'features'=>['K_A3候補','K_A4候補'],
        'purpose'=>'3万',
    ],
    'H2_1一次弱×4Cまくり' => [
        'features'=>['WEB_1号艇一次4位以下','K_4Cまくり15+'],
        'purpose'=>'3万',
    ],
    'H3_A3×4Cまくり' => [
        'features'=>['K_A3候補','K_4Cまくり15+'],
        'purpose'=>'3万',
    ],
];

$baseStats = [];
foreach ($periods as $label => $rows) {
    $s = summarize($rows);
    $baseStats[$label] = [
        'n'=>$s['n'],
        'mRate'=>pct($s['m'],$s['n']),
        'r30'=>pct($s['p30'],$s['n']),
        'r100'=>pct($s['p100'],$s['n']),
    ];
}

echo str_repeat('=', 172) . PHP_EOL;
echo "STEP10 高配当候補 探索期間内前後分割再現性" . PHP_EOL;
echo str_repeat('=', 172) . PHP_EOL;
echo "D1: 2026-06-15～2026-07-14 / D2: 2026-07-15～2026-08-14" . PHP_EOL;
echo "注意: H1/H2高配当ホールドアウトは未使用。候補・閾値の再探索もしない。" . PHP_EOL . PHP_EOL;

foreach (['D1','D2','ALL'] as $label) {
    $b = $baseStats[$label];
    printf("BASE %-3s N=%d 万舟=%.2f%% 3万+=%.2f%% 10万+=%.2f%%\n",
        $label, $b['n'], $b['mRate'], $b['r30'], $b['r100']);
}

echo PHP_EOL;
printf("%-24s %-8s %7s %8s %8s %8s %9s %8s %9s\n",
    '候補','期間','N','万舟率','Lift','3万率','Lift30','10万率','Lift100');
echo str_repeat('-', 172) . PHP_EOL;

foreach ($candidates as $name => $def) {
    foreach (['D1','D2','ALL'] as $label) {
        $sel = selected($periods[$label], $def['features']);
        $s = summarize($sel);
        $b = $baseStats[$label];
        $mr = pct($s['m'],$s['n']);
        $r30 = pct($s['p30'],$s['n']);
        $r100 = pct($s['p100'],$s['n']);
        printf("%-24s %-8s %7d %7.2f%% %7.2fx %7.2f%% %8.2fx %7.2f%% %8.2fx\n",
            $name, $label, $s['n'], $mr, lift($mr,$b['mRate']), $r30, lift($r30,$b['r30']), $r100, lift($r100,$b['r100']));
    }
    echo str_repeat('-', 172) . PHP_EOL;
}

echo PHP_EOL;
echo "判断:" . PHP_EOL;
echo "1. D1/D2の両方で目的指標がBASEより上なら凍結候補。" . PHP_EOL;
echo "2. 片側だけ大幅上昇・片側でBASE未満なら見送り。" . PHP_EOL;
echo "3. Nが小さい候補は派手なLiftでも補助候補扱い。" . PHP_EOL;
echo "4. この結果で候補を固定してから、初めてH1/H2へ進む。" . PHP_EOL;
