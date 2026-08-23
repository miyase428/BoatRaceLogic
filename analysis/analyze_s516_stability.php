<?php

declare(strict_types=1);

/**
 * STEP9.5 S516安定性診断
 *
 * 固定ルール:
 *   5C 6m sample_n >= 10
 *   5C 6m まくり差し率 >= 15%
 *   穴買い目 = 5-1-6
 *   現行kiruを維持（5/1/6のいずれかがkiruなら買わない）
 *
 * 閾値や条件はここでは一切チューニングしない。
 * 月別ROI、的中払戻の偏り、最大配当を除いた感応度だけを見る。
 *
 * Usage:
 * php analysis/analyze_s516_stability.php \
 *   analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *   analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv \
 *   analysis/output/trifecta_payouts_20250815_20260814.csv
 */

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php {$argv[0]} DATASET_CSV BOATS_CSV PAYOUT_CSV\n");
    exit(1);
}

[$script, $datasetPath, $boatsPath, $payoutPath] = $argv;
foreach ([$datasetPath, $boatsPath, $payoutPath] as $p) {
    if (!is_file($p)) {
        throw new RuntimeException("必要ファイルがありません: {$p}");
    }
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

function pct(float $n, float $d): float
{
    return $d > 0 ? 100.0 * $n / $d : 0.0;
}

$boatRows = readCsvAssoc($boatsPath);
$kiruByRace = [];
foreach ($boatRows as $r) {
    $rc = trim((string)($r['race_code'] ?? ''));
    $lane = inum($r, 'lane_number');
    if ($rc === '' || $lane < 1 || $lane > 6) continue;
    $kiruByRace[$rc][$lane] = inum($r, 'kiru') === 1;
}

$payoutRows = readCsvAssoc($payoutPath);
$payoutByRace = [];
foreach ($payoutRows as $r) {
    $rc = trim((string)($r['race_code'] ?? ''));
    if ($rc === '') continue;
    $payoutByRace[$rc] = inum($r, 'trifecta_payout');
}

$dataset = readCsvAssoc($datasetPath);
$monthly = [];
$hits = [];
$total = ['trigger'=>0, 'buy'=>0, 'hit'=>0, 'stake'=>0, 'return'=>0];

foreach ($dataset as $r) {
    if (inum($r, 'result_top3_course_complete') !== 1 || inum($r, 'result_boat_match') !== 1) continue;
    if (inum($r, 'c5_6m_sample_n') < 10) continue;
    if (fnum($r, 'c5_6m_makurizashi') < 15.0) continue;

    $rc = trim((string)($r['race_code'] ?? ''));
    $date = trim((string)($r['race_date'] ?? ''));
    if ($rc === '' || strlen($date) < 7) continue;
    $month = substr($date, 0, 7);

    $total['trigger']++;
    if (!isset($monthly[$month])) {
        $monthly[$month] = ['trigger'=>0, 'buy'=>0, 'hit'=>0, 'stake'=>0, 'return'=>0];
    }
    $monthly[$month]['trigger']++;

    $kiru = $kiruByRace[$rc] ?? [];
    $cut = (($kiru[5] ?? false) || ($kiru[1] ?? false) || ($kiru[6] ?? false));
    if ($cut) continue;

    // 払戻がないレースはROI母体に混ぜない。
    if (!array_key_exists($rc, $payoutByRace)) continue;

    $total['buy']++;
    $total['stake'] += 100;
    $monthly[$month]['buy']++;
    $monthly[$month]['stake'] += 100;

    $isHit = inum($r, 'actual_1st') === 5
        && inum($r, 'actual_2nd') === 1
        && inum($r, 'actual_3rd') === 6;

    if ($isHit) {
        $pay = $payoutByRace[$rc];
        $total['hit']++;
        $total['return'] += $pay;
        $monthly[$month]['hit']++;
        $monthly[$month]['return'] += $pay;
        $hits[] = [
            'race_date' => $date,
            'stadium_name' => (string)($r['stadium_name'] ?? ''),
            'race_number' => (string)($r['race_number'] ?? ''),
            'race_code' => $rc,
            'rate' => fnum($r, 'c5_6m_makurizashi'),
            'sample' => inum($r, 'c5_6m_sample_n'),
            'payout' => $pay,
        ];
    }
}

ksort($monthly);
usort($hits, static fn(array $a, array $b): int => $b['payout'] <=> $a['payout']);

echo str_repeat('=', 142) . PHP_EOL;
echo "STEP9.5 S516 安定性・外れ値依存診断（CUT維持）" . PHP_EOL;
echo str_repeat('=', 142) . PHP_EOL;
echo "固定条件 : 5Cまくり差し>=15% / sample>=10 / 5-1-6 1点 / kiru維持" . PHP_EOL;
echo "注意     : 条件・閾値の再探索は行わない" . PHP_EOL;
echo PHP_EOL;

echo sprintf("%-8s %8s %8s %7s %8s %12s %12s %10s\n",
    '月', '条件R', '購入R', '的中', '的中率', '購入額', '払戻', 'ROI');
echo str_repeat('-', 100) . PHP_EOL;
foreach ($monthly as $month => $s) {
    $hitRate = pct($s['hit'], $s['buy']);
    $roi = pct($s['return'], $s['stake']);
    echo sprintf("%-8s %8d %8d %7d %7.3f%% %11d円 %11d円 %9.2f%%\n",
        $month, $s['trigger'], $s['buy'], $s['hit'], $hitRate,
        $s['stake'], $s['return'], $roi);
}

echo str_repeat('-', 100) . PHP_EOL;
echo sprintf("%-8s %8d %8d %7d %7.3f%% %11d円 %11d円 %9.2f%%\n",
    'TOTAL', $total['trigger'], $total['buy'], $total['hit'],
    pct($total['hit'], $total['buy']), $total['stake'], $total['return'],
    pct($total['return'], $total['stake']));

echo PHP_EOL . str_repeat('=', 142) . PHP_EOL;
echo "高配当依存チェック" . PHP_EOL;
echo str_repeat('=', 142) . PHP_EOL;
$baseRoi = pct($total['return'], $total['stake']);
echo sprintf("通常ROI                  : %.2f%%\n", $baseRoi);
$removedReturn = $total['return'];
for ($n = 1; $n <= 3; $n++) {
    if (!isset($hits[$n - 1])) break;
    $removedReturn -= $hits[$n - 1]['payout'];
    echo sprintf("上位%d本の払戻を0円扱い   : %.2f%%  （除外累計払戻 %d円）\n",
        $n, pct($removedReturn, $total['stake']), $total['return'] - $removedReturn);
}

if ($total['return'] > 0 && !empty($hits)) {
    echo sprintf("最大1本の総払戻寄与率     : %.2f%%\n", pct($hits[0]['payout'], $total['return']));
}

echo PHP_EOL . str_repeat('=', 142) . PHP_EOL;
echo "S516 的中一覧（払戻順）" . PHP_EOL;
echo str_repeat('=', 142) . PHP_EOL;
foreach ($hits as $h) {
    $rn = rtrim($h['race_number'], 'R');
    echo sprintf("%s %s %sR %s  5Cまくり差し=%.1f%% N=%d  %d円\n",
        $h['race_date'], $h['stadium_name'], $rn, $h['race_code'],
        $h['rate'], $h['sample'], $h['payout']);
}

echo PHP_EOL . "判断: 月別の偏りと上位1～3本除外時ROIを確認し、S516を『穴筋候補』として固定できるか判断する。" . PHP_EOL;
