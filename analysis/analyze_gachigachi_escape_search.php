<?php
/**
 * ガチガチレース検索の基礎検証。
 *
 * 条件:
 *   1C・1年逃げ率 >= X (50～80%, 1%刻み)
 *   2C・1年逃し率 >= Y (30～70%, 1%刻み)
 *
 * Web本命 / AI / 展示 / 場補正は加えない。
 * 31 × 41 = 1,271通りを全件検証し、CSVへ保存する。
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

// 0～100% の整数バケット。
$countGrid = array_fill(0, 101, array_fill(0, 101, 0));
$winGrid = array_fill(0, 101, array_fill(0, 101, 0));

$totalN = 0;
$totalWin1 = 0;
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

    $raceCode = trim((string)($data[$idx['race_code']] ?? ''));
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

    $nige = max(0.0, min(100.0, (float)$nigeRaw));
    $nogashi = max(0.0, min(100.0, (float)$nogashiRaw));

    // 整数閾値 >= X の判定には floor(value) のバケットで十分。
    $nigeBucket = (int)floor($nige + 1e-9);
    $nogashiBucket = (int)floor($nogashi + 1e-9);
    $win1 = ((int)$actualRaw === 1) ? 1 : 0;

    $countGrid[$nigeBucket][$nogashiBucket]++;
    $winGrid[$nigeBucket][$nogashiBucket] += $win1;
    $totalN++;
    $totalWin1 += $win1;

    $date = substr($raceCode, 0, 8);
    if (preg_match('/^\d{8}$/', $date)) {
        $dateMin = $dateMin === null || $date < $dateMin ? $date : $dateMin;
        $dateMax = $dateMax === null || $date > $dateMax ? $date : $dateMax;
    }
}
fclose($fh);

if ($totalN === 0) {
    fwrite(STDERR, "分析可能な行がありません\n");
    exit(1);
}

function pct(int $num, int $den): float
{
    return $den > 0 ? 100.0 * $num / $den : 0.0;
}

function fmtDate(?string $ymd): string
{
    if ($ymd === null || strlen($ymd) !== 8) return '-';
    return substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2);
}

/** 2次元の suffix sum: [x][y] = nige>=x AND nogashi>=y */
function buildSuffix(array $grid): array
{
    $suffix = array_fill(0, 102, array_fill(0, 102, 0));
    for ($x = 100; $x >= 0; $x--) {
        for ($y = 100; $y >= 0; $y--) {
            $suffix[$x][$y] = $grid[$x][$y]
                + $suffix[$x + 1][$y]
                + $suffix[$x][$y + 1]
                - $suffix[$x + 1][$y + 1];
        }
    }
    return $suffix;
}

function outputPath(string $csvPath): string
{
    $dir = dirname($csvPath);
    $base = basename($csvPath);
    if (preg_match('/kimarite_analysis_dataset_(\d{8}_\d{8})\.csv$/', $base, $m)) {
        return $dir . '/gachigachi_escape_grid_' . $m[1] . '.csv';
    }
    return $dir . '/gachigachi_escape_grid.csv';
}

function sortByRate(array &$rows): void
{
    usort($rows, static function (array $a, array $b): int {
        $rateCmp = $b['win_rate'] <=> $a['win_rate'];
        if ($rateCmp !== 0) return $rateCmp;
        $nCmp = $b['n'] <=> $a['n'];
        if ($nCmp !== 0) return $nCmp;
        $xCmp = $b['nige_min'] <=> $a['nige_min'];
        if ($xCmp !== 0) return $xCmp;
        return $b['nogashi_min'] <=> $a['nogashi_min'];
    });
}

function printTop(string $title, array $all, int $minN, int $limit = 10): void
{
    $filtered = array_values(array_filter($all, static fn(array $r): bool => $r['n'] >= $minN));
    sortByRate($filtered);

    echo "\n【{$title}】\n";
    if (!$filtered) {
        echo "該当なし\n";
        return;
    }

    foreach (array_slice($filtered, 0, $limit) as $i => $r) {
        printf(
            "%2d位 逃げ>=%2d%% × 逃し>=%2d%%  N=%6d  1号艇1着=%6.2f%%  基礎差=%+6.2fpt  逃し上乗せ=%+5.2fpt\n",
            $i + 1,
            $r['nige_min'],
            $r['nogashi_min'],
            $r['n'],
            $r['win_rate'],
            $r['base_diff'],
            $r['nogashi_added_diff']
        );
    }
}

$countSuffix = buildSuffix($countGrid);
$winSuffix = buildSuffix($winGrid);
$baseRate = pct($totalWin1, $totalN);

// 1C逃げ率だけの基準値（nogashi>=0 と等価）
$nigeOnly = [];
for ($x = 0; $x <= 100; $x++) {
    $n = $countSuffix[$x][0];
    $w = $winSuffix[$x][0];
    $nigeOnly[$x] = [
        'n' => $n,
        'win1' => $w,
        'rate' => pct($w, $n),
    ];
}

$all = [];
for ($x = 50; $x <= 80; $x++) {
    for ($y = 30; $y <= 70; $y++) {
        $n = $countSuffix[$x][$y];
        $w = $winSuffix[$x][$y];
        $rate = pct($w, $n);
        $nigeOnlyRate = $nigeOnly[$x]['rate'];

        $all[] = [
            'nige_min' => $x,
            'nogashi_min' => $y,
            'n' => $n,
            'win1' => $w,
            'win_rate' => $rate,
            'base_diff' => $rate - $baseRate,
            'nige_only_n' => $nigeOnly[$x]['n'],
            'nige_only_rate' => $nigeOnlyRate,
            'nogashi_added_diff' => $rate - $nigeOnlyRate,
            'retention_vs_nige_only' => $nigeOnly[$x]['n'] > 0 ? 100.0 * $n / $nigeOnly[$x]['n'] : 0.0,
        ];
    }
}

$outPath = outputPath($csvPath);
$out = fopen($outPath, 'wb');
if ($out === false) {
    fwrite(STDERR, "出力CSVを作成できません: {$outPath}\n");
    exit(1);
}

fputcsv($out, [
    'nige_min',
    'nogashi_min',
    'n',
    'win1',
    'win_rate',
    'base_diff',
    'nige_only_n',
    'nige_only_rate',
    'nogashi_added_diff',
    'retention_vs_nige_only',
]);
foreach ($all as $r) {
    fputcsv($out, [
        $r['nige_min'],
        $r['nogashi_min'],
        $r['n'],
        $r['win1'],
        number_format($r['win_rate'], 4, '.', ''),
        number_format($r['base_diff'], 4, '.', ''),
        $r['nige_only_n'],
        number_format($r['nige_only_rate'], 4, '.', ''),
        number_format($r['nogashi_added_diff'], 4, '.', ''),
        number_format($r['retention_vs_nige_only'], 4, '.', ''),
    ]);
}
fclose($out);

$line = str_repeat('=', 132);
echo $line . "\n";
echo "ガチガチ検索 1%刻み総当たり（1C逃げ率 × 2C逃し率）\n";
echo "CSV      : " . basename($csvPath) . "\n";
echo "期間     : " . fmtDate($dateMin) . " ～ " . fmtDate($dateMax) . "\n";
echo "分析母体 : " . number_format($totalN) . "R\n";
printf("基礎1号艇1着率: %.2f%% (%d/%d)\n", $baseRate, $totalWin1, $totalN);
echo "sample_n : c1/c2とも {$minSample}以上\n";
echo "総当たり : 逃げ50～80% × 逃し30～70% = " . number_format(count($all)) . "通り\n";
echo "追加条件 : Web本命・AI・展示・場補正なし\n";
echo "出力CSV  : {$outPath}\n";
echo $line . "\n";

printTop('勝率上位10（母数制限なし）', $all, 1, 10);
printTop('実用上位10（N>=500）', $all, 500, 10);
printTop('実用上位10（N>=1000）', $all, 1000, 10);
printTop('実用上位10（N>=2000）', $all, 2000, 10);

// 逃し率を足した「上乗せ」自体が大きい条件も確認。
$added = array_values(array_filter($all, static fn(array $r): bool => $r['n'] >= 1000));
usort($added, static function (array $a, array $b): int {
    $cmp = $b['nogashi_added_diff'] <=> $a['nogashi_added_diff'];
    if ($cmp !== 0) return $cmp;
    return $b['n'] <=> $a['n'];
});

echo "\n【逃し率を足した上乗せ効果 上位10（N>=1000）】\n";
foreach (array_slice($added, 0, 10) as $i => $r) {
    printf(
        "%2d位 逃げ>=%2d%% × 逃し>=%2d%%  N=%6d  1号艇1着=%6.2f%%  逃げ単独=%6.2f%%  上乗せ=%+5.2fpt  母数維持=%5.1f%%\n",
        $i + 1,
        $r['nige_min'],
        $r['nogashi_min'],
        $r['n'],
        $r['win_rate'],
        $r['nige_only_rate'],
        $r['nogashi_added_diff'],
        $r['retention_vs_nige_only']
    );
}

echo "\n【検索プリセット候補を見る時の基準】\n";
echo "- 率だけの上位3は母数が小さすぎる可能性があるため、そのまま採用しない。\n";
echo "- N>=500 / 1000 / 2000 の各上位を比較し、精度と出現頻度のバランスで3候補を選ぶ。\n";
echo "- nogashi_added_diff が小さい場合、2C逃し率は検索条件としての追加価値が薄い。\n";
echo "- 3候補を決めた後に別期間で再現確認してからトップ画面へ固定する。\n";
echo $line . "\n";