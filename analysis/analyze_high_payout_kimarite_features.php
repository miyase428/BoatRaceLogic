<?php

declare(strict_types=1);

/**
 * STEP10 決まり手・筋舟券を高配当条件へ接続する探索。
 *
 * 重要:
 * - 高配当用ホールドアウト(H1/H2)は消費しない。
 * - このスクリプトは探索期間 2026-06-15 ～ 2026-08-14 のみを対象に固定する。
 * - 閾値はSTEP9/9.5で既に使った固定値だけを使用し、ここでは再チューニングしない。
 *
 * Usage:
 * php analysis/analyze_high_payout_kimarite_features.php \
 *   analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *   analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv \
 *   analysis/output/trifecta_payouts_20250815_20260814.csv
 */

const START_DATE = '2026-06-15';
const END_DATE   = '2026-08-14';
const MANSHU = 10000;
const PAYOUT_30K = 30000;
const PAYOUT_100K = 100000;
const MIN_SINGLE_N = 100;
const MIN_PAIR_N = 50;
const TOP_PAIR_LIMIT = 25;

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

function outerTop3Count(array $boats, string $key): int
{
    $n = 0;
    foreach ([4,5,6] as $lane) if (rankOf($boats, $lane, $key) <= 3) $n++;
    return $n;
}

function buildFeatures(array $row, array $boats): array
{
    $honmei = inum($row, 'honmei_head');
    $pTop = topLane($boats, 'first_rank');
    $sTop = topLane($boats, 'second_rank');
    $kiru = [];
    $kiruCount = 0;
    foreach ($boats as $lane => $b) {
        $kiru[(int)$lane] = inum($b, 'kiru') === 1;
        if ($kiru[(int)$lane]) $kiruCount++;
    }

    $s2m = sampleOk($row, 2) && k($row, 2, 'makuri') >= 15.0;
    $s2s = sampleOk($row, 2) && k($row, 2, 'sashi') >= 15.0;
    $s3m = sampleOk($row, 3) && k($row, 3, 'makuri') >= 15.0;
    $s3z = sampleOk($row, 3) && k($row, 3, 'makurizashi') >= 10.0;
    $s4m = sampleOk($row, 4) && k($row, 4, 'makuri') >= 15.0;
    $s4z = sampleOk($row, 4) && k($row, 4, 'makurizashi') >= 10.0;
    $s5z = sampleOk($row, 5) && k($row, 5, 'makurizashi') >= 15.0;
    $sujiCount = (int)$s2m + (int)$s2s + (int)$s3m + (int)$s3z + (int)$s4m + (int)$s4z + (int)$s5z;

    return [
        // 既存Web側の高配当候補
        'WEB_本命非1' => $honmei !== 1,
        'WEB_1号艇一次4位以下' => rankOf($boats, 1, 'first_rank') >= 4,
        'WEB_1号艇二次4位以下' => rankOf($boats, 1, 'second_rank') >= 4,
        'WEB_1号艇最終4位以下' => rankOf($boats, 1, 'final_rank') >= 4,
        'WEB_一次二次TOP不一致' => $pTop > 0 && $sTop > 0 && $pTop !== $sTop,
        'WEB_最終TOP3外枠2艇以上' => outerTop3Count($boats, 'final_rank') >= 2,
        'WEB_切る艇あり' => $kiruCount >= 1,

        // STEP9/9.5で固定済みの決まり手・筋特徴
        'K_2Cまくり15+' => $s2m,
        'K_2C差し15+' => $s2s,
        'K_3Cまくり15+' => $s3m,
        'K_3Cまくり差し10+' => $s3z,
        'K_4Cまくり15+' => $s4m,
        'K_4Cまくり差し10+' => $s4z,
        'K_5Cまくり差し15+' => $s5z,
        'K_3C攻め20+' => sampleOk($row, 3) && attack($row, 3) >= 20.0,
        'K_4C攻め20+' => sampleOk($row, 4) && attack($row, 4) >= 20.0,
        'K_5C攻め20+' => sampleOk($row, 5) && attack($row, 5) >= 20.0,
        'K_筋2個以上同時' => $sujiCount >= 2,

        // STEP9の本命/相手補正コンテキスト
        'K_A3候補' => $honmei === 1 && sampleOk($row, 3) && attack($row, 3) >= 15.0,
        'K_A4候補' => $honmei === 1 && sampleOk($row, 4) && attack($row, 4) >= 20.0,
        'K_H3候補' => $honmei === 3 && sampleOk($row, 3) && attack($row, 3) >= 15.0,

        // 芦屋6R型。CUT維持時だけ成立扱い。
        'K_S516穴筋' => $s5z
            && !($kiru[5] ?? true)
            && !($kiru[1] ?? true)
            && !($kiru[6] ?? true),
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
                        $roughMate = true; break;
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

function selected(array $races, array $features): array
{
    return array_values(array_filter($races, static function(array $r) use ($features): bool {
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

$races = [];
foreach ($dataset as $row) {
    $date = trim((string)($row['race_date'] ?? ''));
    if ($date < START_DATE || $date > END_DATE) continue;
    if (inum($row, 'result_top3_course_complete') !== 1 || inum($row, 'result_boat_match') !== 1) continue;
    $code = trim((string)($row['race_code'] ?? ''));
    $boats = $boatsByRace[$code] ?? [];
    $payout = $payouts[$code] ?? 0;
    if ($code === '' || count($boats) !== 6 || $payout <= 0) continue;
    $races[] = [
        'race_code'=>$code,
        'race_date'=>$date,
        'stadium'=>trim((string)($row['stadium_name'] ?? '')),
        'honmei'=>inum($row, 'honmei_head'),
        'actual1'=>inum($row, 'actual_1st'),
        'actual2'=>inum($row, 'actual_2nd'),
        'actual3'=>inum($row, 'actual_3rd'),
        'payout'=>$payout,
        'boats'=>$boats,
        'features'=>buildFeatures($row, $boats),
    ];
}

if (!$races) throw new RuntimeException('探索期間の正式対象が0件です');
$base = summarize($races);
$baseM = pct($base['m'], $base['n']);
$base30 = pct($base['p30'], $base['n']);
$base100 = pct($base['p100'], $base['n']);

$featureNames = array_keys($races[0]['features']);

printf("%s\nSTEP10 決まり手・筋舟券 × 万舟・高配当 条件探索（探索期間のみ）\n%s\n", str_repeat('=', 170), str_repeat('=', 170));
printf("探索期間 : %s ～ %s\n", START_DATE, END_DATE);
printf("正式対象 : %dR\n", $base['n']);
printf("BASE     : 万舟 %d (%.2f%%) / 3万+ %d (%.2f%%) / 10万+ %d (%.2f%%) / 平均払戻 %.0f円\n",
    $base['m'], $baseM, $base['p30'], $base30, $base['p100'], $base100, $base['sum'] / max(1,$base['n']));
echo "注意     : 高配当用H1/H2ホールドアウトは未使用。ここでは候補探索だけ。\n";

$singles = [];
foreach ($featureNames as $f) {
    $sel = selected($races, [$f]);
    $s = summarize($sel);
    if ($s['n'] < MIN_SINGLE_N) continue;
    $mRate = pct($s['m'], $s['n']);
    $r30 = pct($s['p30'], $s['n']);
    $r100 = pct($s['p100'], $s['n']);
    $singles[] = [
        'name'=>$f,'n'=>$s['n'],'m'=>$s['m'],'mr'=>$mRate,'ml'=>lift($mRate,$baseM),
        'p30'=>$s['p30'],'r30'=>$r30,'l30'=>lift($r30,$base30),
        'p100'=>$s['p100'],'r100'=>$r100,'l100'=>lift($r100,$base100),
        'mate'=>$s['mate'],'head'=>$s['head'],'all'=>$s['all'],
    ];
}
usort($singles, static fn(array $a,array $b): int => $b['ml'] <=> $a['ml']);

echo "\n" . str_repeat('=',170) . "\n単独特徴\n" . str_repeat('=',170) . "\n";
printf("%-30s %7s %8s %8s %8s %8s %8s %8s %8s %16s\n", '条件','N','万舟率','Lift','3万率','Lift30','10万率','Lift100','万舟数','荒れ型 相/頭/全');
echo str_repeat('-',170) . "\n";
foreach ($singles as $x) {
    printf("%-30s %7d %7.2f%% %7.2fx %7.2f%% %7.2fx %7.2f%% %7.2fx %8d %4d/%4d/%4d\n",
        $x['name'],$x['n'],$x['mr'],$x['ml'],$x['r30'],$x['l30'],$x['r100'],$x['l100'],$x['m'],$x['mate'],$x['head'],$x['all']);
}

// 単独で最低限の上振れがある特徴だけをペア候補にする。
$pairPool = array_values(array_map(
    static fn(array $x): string => $x['name'],
    array_filter($singles, static fn(array $x): bool => $x['ml'] >= 1.05)
));
$pairs = [];
for ($i=0; $i<count($pairPool); $i++) {
    for ($j=$i+1; $j<count($pairPool); $j++) {
        $fs = [$pairPool[$i], $pairPool[$j]];
        $s = summarize(selected($races, $fs));
        if ($s['n'] < MIN_PAIR_N || $s['m'] < 5) continue;
        $mr = pct($s['m'],$s['n']);
        $r30 = pct($s['p30'],$s['n']);
        $pairs[] = [
            'name'=>implode(' + ', $fs),'n'=>$s['n'],'m'=>$s['m'],'mr'=>$mr,'ml'=>lift($mr,$baseM),
            'p30'=>$s['p30'],'r30'=>$r30,'l30'=>lift($r30,$base30),
            'p100'=>$s['p100'],'r100'=>pct($s['p100'],$s['n']),
        ];
    }
}
usort($pairs, static function(array $a,array $b): int {
    $cmp = $b['ml'] <=> $a['ml'];
    return $cmp !== 0 ? $cmp : ($b['n'] <=> $a['n']);
});

echo "\n" . str_repeat('=',170) . "\n固定特徴の2条件組合せ TOP" . TOP_PAIR_LIMIT . "（万舟Lift順）\n" . str_repeat('=',170) . "\n";
printf("%-63s %7s %8s %8s %8s %8s %8s\n", '条件','N','万舟率','Lift','3万率','Lift30','10万率');
echo str_repeat('-',170) . "\n";
foreach (array_slice($pairs,0,TOP_PAIR_LIMIT) as $x) {
    printf("%-63s %7d %7.2f%% %7.2fx %7.2f%% %7.2fx %7.2f%%\n",
        $x['name'],$x['n'],$x['mr'],$x['ml'],$x['r30'],$x['l30'],$x['r100']);
}

echo "\n判断:\n";
echo "1. ここでは候補を絞るだけ。H1/H2ホールドアウトはまだ使わない。\n";
echo "2. 単独でNがあり、万舟/3万の両方が上がる特徴を優先。\n";
echo "3. ペアはLiftだけでなくNと3万率も見る。小標本の派手な数字は採用しない。\n";
echo "4. S516は自動購入候補ではなく、高配当シグナル特徴としてのみ評価する。\n";
