<?php

declare(strict_types=1);

/**
 * 場×既存攻め条件 12候補の RECENT6M→前方 ドリフト診断。
 *
 * 条件は固定。閾値探索や候補の選び直しはしない。
 * RECENT6M と forward で、
 * - 同場Web本命①の基礎敗率
 * - シグナル出現率
 * - 条件時①敗率Δ
 * - 候補コース1着率Δ
 * を比較し、前方失速が単なる少数ブレか構造変化かを見る。
 *
 * Usage:
 * php analysis/analyze_stadium_attack_signal_forward_drift.php \
 *   analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *   analysis/output/kimarite_analysis_dataset_20260815_20260822.csv
 */

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php {$argv[0]} HIST_CSV FORWARD_CSV\n");
    exit(1);
}

[$script, $histPath, $forwardPath] = $argv;
foreach ([$histPath, $forwardPath] as $p) {
    if (!is_file($p)) throw new RuntimeException("CSVがありません: {$p}");
}

const RECENT_START = '2026-02-15';
const RECENT_END = '2026-08-14';
const MIN_SAMPLE = 10;

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

function pct(int $n, int $d): float
{
    return $d > 0 ? 100.0 * $n / $d : 0.0;
}

function formal(array $row): bool
{
    return inum($row, 'result_top3_course_complete') === 1
        && inum($row, 'result_boat_match') === 1;
}

function attack(array $row, int $course): float
{
    return fnum($row, "c{$course}_6m_makuri") + fnum($row, "c{$course}_6m_makurizashi");
}

function signalOn(array $row, int $course): bool
{
    if (inum($row, "c{$course}_6m_sample_n") < MIN_SAMPLE) return false;
    $a = attack($row, $course);
    return $course === 3 ? $a >= 15.0 : $a >= 20.0;
}

function emptyStat(): array
{
    return [
        'base_n'=>0,
        'base_loss'=>0,
        'base_head'=>0,
        'sig_n'=>0,
        'sig_loss'=>0,
        'sig_head'=>0,
    ];
}

function addBase(array &$s, array $row, int $course): void
{
    $s['base_n']++;
    if (inum($row, 'actual_1st') !== 1) $s['base_loss']++;
    if (inum($row, 'actual_1st_course') === $course) $s['base_head']++;
}

function addSignal(array &$s, array $row, int $course): void
{
    $s['sig_n']++;
    if (inum($row, 'actual_1st') !== 1) $s['sig_loss']++;
    if (inum($row, 'actual_1st_course') === $course) $s['sig_head']++;
}

function summarize(array $s): array
{
    $baseLoss = pct($s['base_loss'], $s['base_n']);
    $sigLoss = pct($s['sig_loss'], $s['sig_n']);
    $baseHead = pct($s['base_head'], $s['base_n']);
    $sigHead = pct($s['sig_head'], $s['sig_n']);
    return [
        'base_n'=>$s['base_n'],
        'sig_n'=>$s['sig_n'],
        'sig_rate'=>pct($s['sig_n'], $s['base_n']),
        'base_loss'=>$baseLoss,
        'sig_loss'=>$sigLoss,
        'loss_delta'=>$sigLoss - $baseLoss,
        'base_head'=>$baseHead,
        'sig_head'=>$sigHead,
        'head_delta'=>$sigHead - $baseHead,
    ];
}

$candidates = [
    ['venue'=>'児島','course'=>3],
    ['venue'=>'大村','course'=>3],
    ['venue'=>'尼崎','course'=>3],
    ['venue'=>'常滑','course'=>3],
    ['venue'=>'桐生','course'=>3],
    ['venue'=>'芦屋','course'=>3],
    ['venue'=>'若松','course'=>3],
    ['venue'=>'丸亀','course'=>4],
    ['venue'=>'唐津','course'=>4],
    ['venue'=>'多摩川','course'=>4],
    ['venue'=>'徳山','course'=>4],
    ['venue'=>'若松','course'=>4],
];

$hist = readCsvAssoc($histPath);
$forward = readCsvAssoc($forwardPath);

function collect(array $rows, array $candidates, bool $recentOnly): array
{
    $stats = [];
    foreach ($candidates as $c) {
        $key = $c['venue'] . '_A' . $c['course'];
        $stats[$key] = emptyStat();
    }

    foreach ($rows as $row) {
        if (!formal($row)) continue;
        $date = trim((string)($row['race_date'] ?? ''));
        if ($recentOnly && ($date < RECENT_START || $date > RECENT_END)) continue;
        if (inum($row, 'honmei_head') !== 1) continue;

        $venue = trim((string)($row['stadium_name'] ?? ''));
        foreach ($candidates as $c) {
            if ($venue !== $c['venue']) continue;
            $course = (int)$c['course'];
            $key = $venue . '_A' . $course;
            addBase($stats[$key], $row, $course);
            if (signalOn($row, $course)) addSignal($stats[$key], $row, $course);
        }
    }
    return $stats;
}

$recentStats = collect($hist, $candidates, true);
$forwardStats = collect($forward, $candidates, false);

$forwardDates = [];
foreach ($forward as $row) {
    if (!formal($row)) continue;
    $d = trim((string)($row['race_date'] ?? ''));
    if ($d !== '') $forwardDates[] = $d;
}
sort($forwardDates);
$forwardStart = $forwardDates[0] ?? '-';
$forwardEnd = $forwardDates ? $forwardDates[count($forwardDates)-1] : '-';

echo str_repeat('=', 198) . "\n";
echo "場×既存攻め条件 12候補 RECENT6M→前方 ドリフト診断\n";
echo str_repeat('=', 198) . "\n";
echo "RECENT6M: " . RECENT_START . " ～ " . RECENT_END . " / FORWARD: {$forwardStart} ～ {$forwardEnd}\n";
echo "固定条件のまま、出現率・①敗Δ・候補頭Δの変化を見る。候補や閾値は変更しない。\n\n";
printf("%-8s %-4s | %-61s | %-61s | %-30s\n",
    '場','条件','RECENT6M','FORWARD','変化');
echo str_repeat('-', 198) . "\n";

$agg = [
    'recent'=>['base_n'=>0,'base_loss'=>0,'base_head'=>0,'sig_n'=>0,'sig_loss'=>0,'sig_head'=>0],
    'forward'=>['base_n'=>0,'base_loss'=>0,'base_head'=>0,'sig_n'=>0,'sig_loss'=>0,'sig_head'=>0],
];

foreach ($candidates as $c) {
    $venue = $c['venue'];
    $course = (int)$c['course'];
    $key = $venue . '_A' . $course;
    $r = summarize($recentStats[$key]);
    $f = summarize($forwardStats[$key]);

    $rateChange = $f['sig_rate'] - $r['sig_rate'];
    $lossChange = $f['loss_delta'] - $r['loss_delta'];
    $headChange = $f['head_delta'] - $r['head_delta'];

    printf(
        "%-8s A%d   | B=%3d S=%3d 出現=%5.1f%% 敗Δ=%+6.1f 頭Δ=%+6.1f | B=%3d S=%3d 出現=%5.1f%% 敗Δ=%+6.1f 頭Δ=%+6.1f | 出現%+6.1f 敗Δ%+6.1f 頭Δ%+6.1f\n",
        $venue, $course,
        $r['base_n'], $r['sig_n'], $r['sig_rate'], $r['loss_delta'], $r['head_delta'],
        $f['base_n'], $f['sig_n'], $f['sig_rate'], $f['loss_delta'], $f['head_delta'],
        $rateChange, $lossChange, $headChange
    );

    foreach (['recent','forward'] as $period) {
        $src = $period === 'recent' ? $recentStats[$key] : $forwardStats[$key];
        foreach ($agg[$period] as $k => $_) $agg[$period][$k] += $src[$k];
    }
}

$rAgg = summarize($agg['recent']);
$fAgg = summarize($agg['forward']);

echo "\n" . str_repeat('-', 198) . "\n";
echo "単純イベント合計（同一レースで複数候補該当時は重複加算）\n";
printf(
    "RECENT6M: BASE=%d SIGNAL=%d 出現=%.1f%% ①敗Δ=%+.1fpt 候補頭Δ=%+.1fpt\n",
    $rAgg['base_n'], $rAgg['sig_n'], $rAgg['sig_rate'], $rAgg['loss_delta'], $rAgg['head_delta']
);
printf(
    "FORWARD : BASE=%d SIGNAL=%d 出現=%.1f%% ①敗Δ=%+.1fpt 候補頭Δ=%+.1fpt\n",
    $fAgg['base_n'], $fAgg['sig_n'], $fAgg['sig_rate'], $fAgg['loss_delta'], $fAgg['head_delta']
);

echo "\n判断ポイント:\n";
echo "1. 出現率が大きく変わっていれば、前方期間の母集団構造がRECENT6Mと違う可能性。\n";
echo "2. 出現率が近いのに①敗Δ・頭Δだけ反転なら、シグナル効果自体が不安定な可能性。\n";
echo "3. 前方Nは小さいので、ここで候補削除・追加・閾値変更はしない。\n";
echo "4. 12候補セットは現時点で本番不採用のまま維持する。\n";
