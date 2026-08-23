<?php

declare(strict_types=1);

/**
 * 場別 1号艇 一次順位×二次順位 実勝率マトリクス
 *
 * 目的:
 * - 大村/津/宮島の「1号艇を外しすぎ」
 * - 戸田/江戸川の「1号艇判断が不安定」
 *
 * が、一次評価と二次評価の組み合わせで説明できるか確認する。
 * ここでは補正・閾値は作らず、実勝率の構造だけを見る。
 *
 * Usage:
 * php analysis/analyze_stadium_lane1_rank_matrix.php \
 *   analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *   analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv
 */

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php {$argv[0]} DATASET_CSV BOATS_CSV\n");
    exit(1);
}

[$script, $datasetPath, $boatsPath] = $argv;
foreach ([$datasetPath, $boatsPath] as $p) {
    if (!is_file($p)) throw new RuntimeException("必要ファイルがありません: {$p}");
}

const OLD_END = '2026-02-14';
const RECENT_START = '2026-02-15';
const TARGETS = ['大村', '津', '宮島', '戸田', '江戸川'];

function readCsvAssoc(string $path): array
{
    $fp = fopen($path, 'rb');
    if ($fp === false) throw new RuntimeException("CSVを開けません: {$path}");
    $header = fgetcsv($fp);
    if ($header === false) { fclose($fp); return []; }
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    }
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

function bucketRank(int $rank): string
{
    if ($rank <= 1) return '1';
    if ($rank === 2) return '2';
    if ($rank === 3) return '3';
    return '4+';
}

function emptyStat(): array
{
    return ['n'=>0, 'win'=>0, 'first_score_sum'=>0.0, 'second_score_sum'=>0.0];
}

function addStat(array &$s, array $boat1, bool $win): void
{
    $s['n']++;
    $s['win'] += (int)$win;
    $s['first_score_sum'] += fnum($boat1, 'first_total_score');
    $s['second_score_sum'] += fnum($boat1, 'second_score');
}

function printSummary(string $label, array $stats): void
{
    echo "\n  {$label}\n";
    echo "  " . str_repeat('-', 88) . "\n";
    echo "  二次順位帯      N     1艇勝率   一次score   二次score\n";
    echo "  " . str_repeat('-', 88) . "\n";
    foreach (['1','2','3','4+'] as $sb) {
        $s = $stats[$sb] ?? emptyStat();
        $n = $s['n'];
        $fs = $n > 0 ? $s['first_score_sum'] / $n : 0.0;
        $ss = $n > 0 ? $s['second_score_sum'] / $n : 0.0;
        printf("  %-10s %6d   %7.2f%%    %7.2f     %7.2f\n",
            $sb, $n, pct($s['win'], $n), $fs, $ss);
    }
}

function printMatrix(string $label, array $matrix): void
{
    echo "\n  {$label}\n";
    echo "  セル = N / 1艇勝率\n\n";
    printf("  %-10s", '一次\\二次');
    foreach (['1','2','3','4+'] as $sb) printf(" %16s", $sb);
    echo "\n  " . str_repeat('-', 78) . "\n";
    foreach (['1','2','3','4+'] as $fb) {
        printf("  %-10s", $fb);
        foreach (['1','2','3','4+'] as $sb) {
            $s = $matrix[$fb][$sb] ?? emptyStat();
            $text = $s['n'] > 0
                ? sprintf('%d / %.1f%%', $s['n'], pct($s['win'], $s['n']))
                : '-';
            printf(" %16s", $text);
        }
        echo "\n";
    }
}

$datasetRows = readCsvAssoc($datasetPath);
$boatRows = readCsvAssoc($boatsPath);

$boat1ByRace = [];
foreach ($boatRows as $b) {
    if (inum($b, 'lane_number') !== 1) continue;
    $rc = trim((string)($b['race_code'] ?? ''));
    if ($rc !== '') $boat1ByRace[$rc] = $b;
}

$periodDefs = [
    'ALL1Y' => static fn(string $date): bool => true,
    'OLD6M' => static fn(string $date): bool => $date <= OLD_END,
    'RECENT6M' => static fn(string $date): bool => $date >= RECENT_START,
];

$summary = [];
$matrix = [];
$counts = [];

foreach ($datasetRows as $row) {
    if (!formal($row)) continue;
    $stadium = trim((string)($row['stadium_name'] ?? ''));
    if (!in_array($stadium, TARGETS, true)) continue;

    $rc = trim((string)($row['race_code'] ?? ''));
    $boat1 = $boat1ByRace[$rc] ?? null;
    if (!is_array($boat1)) continue;

    $firstRank = inum($boat1, 'first_rank', 99);
    $secondRank = inum($boat1, 'second_rank', 99);
    if ($firstRank < 1 || $secondRank < 1) continue;

    $fb = bucketRank($firstRank);
    $sb = bucketRank($secondRank);
    $win = inum($row, 'actual_1st') === 1;
    $date = trim((string)($row['race_date'] ?? ''));

    foreach ($periodDefs as $period => $accept) {
        if (!$accept($date)) continue;
        if (!isset($summary[$stadium][$period][$sb])) $summary[$stadium][$period][$sb] = emptyStat();
        if (!isset($matrix[$stadium][$period][$fb][$sb])) $matrix[$stadium][$period][$fb][$sb] = emptyStat();
        addStat($summary[$stadium][$period][$sb], $boat1, $win);
        addStat($matrix[$stadium][$period][$fb][$sb], $boat1, $win);
        $counts[$stadium][$period] = ($counts[$stadium][$period] ?? 0) + 1;
    }
}

echo str_repeat('=', 170) . "\n";
echo "場別 1号艇 一次順位×二次順位 実勝率マトリクス\n";
echo str_repeat('=', 170) . "\n";
echo "対象場: " . implode(',', TARGETS) . "\n";
echo "目的: 場ごとに二次評価順位をどこまで1号艇の頭判断に信頼できるか診断する。ここでは補正しない。\n";

foreach (TARGETS as $stadium) {
    echo "\n" . str_repeat('=', 120) . "\n";
    printf("【%s】 ALL1Y=%d / OLD6M=%d / RECENT6M=%d\n",
        $stadium,
        $counts[$stadium]['ALL1Y'] ?? 0,
        $counts[$stadium]['OLD6M'] ?? 0,
        $counts[$stadium]['RECENT6M'] ?? 0
    );
    echo str_repeat('=', 120) . "\n";

    printSummary('ALL1Y: 二次順位別', $summary[$stadium]['ALL1Y'] ?? []);
    printMatrix('ALL1Y: 一次×二次', $matrix[$stadium]['ALL1Y'] ?? []);

    echo "\n  期間再現性（各二次順位帯の1艇勝率）\n";
    echo "  " . str_repeat('-', 88) . "\n";
    echo "  二次順位帯      OLD6M N/勝率       RECENT6M N/勝率\n";
    echo "  " . str_repeat('-', 88) . "\n";
    foreach (['1','2','3','4+'] as $sb) {
        $o = $summary[$stadium]['OLD6M'][$sb] ?? emptyStat();
        $r = $summary[$stadium]['RECENT6M'][$sb] ?? emptyStat();
        printf("  %-10s %6d / %6.2f%%     %6d / %6.2f%%\n",
            $sb,
            $o['n'], pct($o['win'], $o['n']),
            $r['n'], pct($r['win'], $r['n'])
        );
    }
}

echo "\n判断ポイント:\n";
echo "1. 大村/津/宮島で二次2～4位でも1艇勝率が高ければ、二次評価で1号艇を落としすぎている可能性。\n";
echo "2. 戸田で二次1位でも1艇勝率が低ければ、二次1位を頭へ直結させすぎている可能性。\n";
echo "3. 江戸川で順位帯ごとの勝率差が小さければ、今の一次/二次軸だけでは場特性を分離しにくい。\n";
echo "4. OLD6M/RECENT6Mで方向が揃うかを確認してから、場補正候補を考える。\n";
