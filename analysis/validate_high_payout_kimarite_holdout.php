<?php

declare(strict_types=1);

/**
 * STEP10 凍結済み高配当候補を H1/H2 ホールドアウトで検証する。
 *
 * 重要:
 * - 候補・閾値は探索期間 2026-06-15～2026-08-14 で固定済み。
 * - このスクリプトでは条件追加・閾値変更をしない。
 * - H1/H2を初めて開封して再現性を確認する。
 *
 * 凍結候補:
 *   M1: S516穴筋(CUT維持) × 1号艇一次4位以下      -> 万舟候補
 *   B1: A3候補 × 一次二次TOP不一致               -> 万舟+3万候補
 *   P1: 1号艇一次4位以下 × 4Cまくり15%以上      -> 3万候補
 *
 * Usage:
 * php analysis/validate_high_payout_kimarite_holdout.php \
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

function frozenFeatures(array $row, array $boats): array
{
    $honmei = inum($row, 'honmei_head');
    $kiru = [];
    foreach ($boats as $lane => $b) $kiru[(int)$lane] = inum($b, 'kiru') === 1;

    $s516 = sampleOk($row, 5)
        && k($row, 5, 'makurizashi') >= 15.0
        && !($kiru[5] ?? true)
        && !($kiru[1] ?? true)
        && !($kiru[6] ?? true);

    $a3 = $honmei === 1
        && sampleOk($row, 3)
        && attack($row, 3) >= 15.0;

    $topMismatch = topLane($boats, 'first_rank') !== topLane($boats, 'second_rank');
    $lane1PrimaryWeak = rankOf($boats, 1, 'first_rank') >= 4;
    $c4Makuri15 = sampleOk($row, 4) && k($row, 4, 'makuri') >= 15.0;

    return [
        'M1_S516×1一次弱' => $s516 && $lane1PrimaryWeak,
        'B1_A3×一次二次不一致' => $a3 && $topMismatch,
        'P1_1一次弱×4Cまくり' => $lane1PrimaryWeak && $c4Makuri15,
    ];
}

function summarize(array $rows): array
{
    $s = ['n'=>0,'m'=>0,'p30'=>0,'p100'=>0,'sum'=>0,'mate'=>0,'head'=>0,'all'=>0];
    foreach ($rows as $r) {
        $p = (int)$r['payout'];
        $s['n']++; $s['sum'] += $p;
        if ($p >= MANSHU) {
            $s['m']++;
            if ((int)$r['actual1'] === (int)$r['honmei']) {
                $s['mate']++;
            } else {
                $roughMate = false;
                foreach ([(int)$r['actual2'], (int)$r['actual3']] as $lane) {
                    $b = $r['boats'][$lane] ?? [];
                    if (inum($b, 'final_rank', 99) >= 5 || inum($b, 'kiru') === 1) {
                        $roughMate = true;
                        break;
                    }
                }
                if ($roughMate) $s['all']++; else $s['head']++;
            }
        }
        if ($p >= PAYOUT_30K) $s['p30']++;
        if ($p >= PAYOUT_100K) $s['p100']++;
    }
    return $s;
}

function selectCandidate(array $rows, string $candidate): array
{
    return array_values(array_filter($rows, static fn(array $r): bool => !empty($r['features'][$candidate])));
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
        'honmei'=>inum($row, 'honmei_head'),
        'actual1'=>inum($row, 'actual_1st'),
        'actual2'=>inum($row, 'actual_2nd'),
        'actual3'=>inum($row, 'actual_3rd'),
        'payout'=>$payout,
        'boats'=>$boats,
        'features'=>frozenFeatures($row, $boats),
    ];
}

$periodDefs = [
    'H1' => ['2026-02-17','2026-04-15'],
    'H2' => ['2026-04-16','2026-06-14'],
    'HOLDOUT' => ['2026-02-17','2026-06-14'],
    'DISCOVERY' => ['2026-06-15','2026-08-14'],
];

$periods = [];
foreach ($periodDefs as $label => [$start,$end]) {
    $periods[$label] = array_values(array_filter(
        $races,
        static fn(array $r): bool => $r['race_date'] >= $start && $r['race_date'] <= $end
    ));
}

$candidates = [
    'M1_S516×1一次弱',
    'B1_A3×一次二次不一致',
    'P1_1一次弱×4Cまくり',
];

echo str_repeat('=', 164) . PHP_EOL;
echo "STEP10 凍結済み高配当候補 H1/H2ホールドアウト検証" . PHP_EOL;
echo str_repeat('=', 164) . PHP_EOL;
echo "候補は探索期間で固定済み。ここでは条件・閾値を変更しない。" . PHP_EOL;
echo "H1=2026-02-17～2026-04-15 / H2=2026-04-16～2026-06-14" . PHP_EOL . PHP_EOL;

foreach ($periods as $label => $rows) {
    $b = summarize($rows);
    printf(
        "BASE %-9s N=%5d 万舟=%6.2f%% 3万+=%6.2f%% 10万+=%6.2f%% 平均払戻=%7.0f円\n",
        $label,
        $b['n'],
        pct($b['m'],$b['n']),
        pct($b['p30'],$b['n']),
        pct($b['p100'],$b['n']),
        $b['sum']/max(1,$b['n'])
    );
}

echo PHP_EOL;
printf("%-27s %-10s %6s %8s %8s %8s %9s %8s %10s %16s\n",
    '候補','期間','N','万舟率','Lift','3万率','Lift30','10万率','万舟内訳 相/頭/全');
echo str_repeat('-', 164) . PHP_EOL;

foreach ($candidates as $candidate) {
    foreach ($periods as $label => $rows) {
        $base = summarize($rows);
        $sel = selectCandidate($rows, $candidate);
        $s = summarize($sel);
        $mr = pct($s['m'],$s['n']);
        $r30 = pct($s['p30'],$s['n']);
        $r100 = pct($s['p100'],$s['n']);
        $baseM = pct($base['m'],$base['n']);
        $base30 = pct($base['p30'],$base['n']);
        printf(
            "%-27s %-10s %6d %7.2f%% %7.2fx %7.2f%% %8.2fx %7.2f%% %4d/%4d/%4d\n",
            $candidate,
            $label,
            $s['n'],
            $mr,
            lift($mr,$baseM),
            $r30,
            lift($r30,$base30),
            $r100,
            $s['mate'],$s['head'],$s['all']
        );
    }
    echo str_repeat('-', 164) . PHP_EOL;
}

echo PHP_EOL;
echo "判定基準:" . PHP_EOL;
echo "1. M1はH1/H2とも万舟Lift>1を主判定。" . PHP_EOL;
echo "2. B1はH1/H2とも万舟・3万の両方がBASE超を主判定。" . PHP_EOL;
echo "3. P1はH1/H2とも3万Lift>1を主判定。万舟Liftは補助。" . PHP_EOL;
echo "4. 片側だけ極端に良い候補は採用しない。H1/H2を見た後に閾値を変更しない。" . PHP_EOL;
