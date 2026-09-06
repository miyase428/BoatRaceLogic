<?php
/**
 * 穴レース検索の基礎検証。
 *
 * 条件:
 * - 1号艇(1C)の1年逃げ率 <= X
 * - 2〜6号艇の「差し率 + 捲り率」の最大値 >= Y
 *
 * Web本命/AI/展示/場補正は使わず、この2条件だけで
 * 「1号艇が1着を外す率（非1号艇1着率）」がどこまで上がるかを見る。
 *
 * Usage:
 *   php analysis/analyze_upset_escape_search.php \
 *     analysis/output/kimarite_analysis_dataset_20250815_20260814.csv
 *
 * Optional:
 *   2nd arg = minimum sample_n (default 10)
 */

declare(strict_types=1);

function usage(): never
{
    fwrite(STDERR, "使用方法:\n  php analysis/analyze_upset_escape_search.php KIMARITE_DATASET_CSV [MIN_SAMPLE_N]\n");
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

function calcStats(array $rows, ?float $nigeMax = null, ?float $attackMin = null): array
{
    $n = 0;
    $non1 = 0;
    foreach ($rows as $r) {
        if ($nigeMax !== null && $r['nige'] > $nigeMax) continue;
        if ($attackMin !== null && $r['attack_max'] < $attackMin) continue;
        $n++;
        if ($r['actual_1st'] !== 1) $non1++;
    }
    return [
        'n' => $n,
        'non1' => $non1,
        'rate' => pct($non1, $n),
    ];
}

if ($argc < 2 || $argc > 3) usage();

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

$required = ['race_code', 'c1_1y_sample_n', 'c1_1y_nige', 'actual_1st'];
for ($c = 2; $c <= 6; $c++) {
    $required[] = "c{$c}_1y_sample_n";
    $required[] = "c{$c}_1y_sashi";
    $required[] = "c{$c}_1y_makuri";
}
foreach ($required as $col) {
    if (!array_key_exists($col, $idx)) {
        fwrite(STDERR, "必要列がありません: {$col}\n");
        exit(1);
    }
}

$rows = [];
$dateMin = null;
$dateMax = null;
$skip = ['c1_sample' => 0, 'actual' => 0, 'nige' => 0, 'outer_sample' => 0];

while (($data = fgetcsv($fh)) !== false) {
    if (count($data) < count($header)) continue;

    $raceCode = trim((string)($data[$idx['race_code']] ?? ''));
    $c1n = (int)($data[$idx['c1_1y_sample_n']] ?? 0);
    if ($c1n < $minSample) {
        $skip['c1_sample']++;
        continue;
    }

    $nigeRaw = trim((string)($data[$idx['c1_1y_nige']] ?? ''));
    if ($nigeRaw === '' || !is_numeric($nigeRaw)) {
        $skip['nige']++;
        continue;
    }

    $actualRaw = trim((string)($data[$idx['actual_1st']] ?? ''));
    if ($actualRaw === '' || !preg_match('/^[1-6]$/', $actualRaw)) {
        $skip['actual']++;
        continue;
    }

    $attackMax = null;
    $attackCourse = 0;
    for ($c = 2; $c <= 6; $c++) {
        $sn = (int)($data[$idx["c{$c}_1y_sample_n"]] ?? 0);
        if ($sn < $minSample) continue;
        $sRaw = trim((string)($data[$idx["c{$c}_1y_sashi"]] ?? ''));
        $mRaw = trim((string)($data[$idx["c{$c}_1y_makuri"]] ?? ''));
        if ($sRaw === '' || $mRaw === '' || !is_numeric($sRaw) || !is_numeric($mRaw)) continue;
        $attack = (float)$sRaw + (float)$mRaw;
        if ($attackMax === null || $attack > $attackMax) {
            $attackMax = $attack;
            $attackCourse = $c;
        }
    }
    if ($attackMax === null) {
        $skip['outer_sample']++;
        continue;
    }

    $date = substr($raceCode, 0, 8);
    if (preg_match('/^\d{8}$/', $date)) {
        $dateMin = $dateMin === null || $date < $dateMin ? $date : $dateMin;
        $dateMax = $dateMax === null || $date > $dateMax ? $date : $dateMax;
    }

    $rows[] = [
        'race_code' => $raceCode,
        'nige' => (float)$nigeRaw,
        'attack_max' => $attackMax,
        'attack_course' => $attackCourse,
        'actual_1st' => (int)$actualRaw,
    ];
}
fclose($fh);

if (!$rows) {
    fwrite(STDERR, "分析可能な行がありません\n");
    exit(1);
}

$base = calcStats($rows);

// 競艇日和の表示域を含め、1%刻みで広めに総当たり。
$nigeThresholds = range(10, 50);   // 逃げ率 <= X
$attackThresholds = range(20, 50); // 2〜6C最大(差し+捲り) >= Y

// 逃げ率単独の基準を先に計算。
$nigeOnly = [];
foreach ($nigeThresholds as $x) {
    $nigeOnly[$x] = calcStats($rows, (float)$x, null);
}

$grid = [];
foreach ($nigeThresholds as $x) {
    foreach ($attackThresholds as $y) {
        $s = calcStats($rows, (float)$x, (float)$y);
        $solo = $nigeOnly[$x];
        $grid[] = [
            'nige_max' => $x,
            'attack_min' => $y,
            'n' => $s['n'],
            'non1' => $s['non1'],
            'non1_rate' => $s['rate'],
            'base_diff' => $s['rate'] - $base['rate'],
            'nige_only_n' => $solo['n'],
            'nige_only_rate' => $solo['rate'],
            'attack_added_diff' => $s['rate'] - $solo['rate'],
            'retention_pct' => $solo['n'] > 0 ? 100.0 * $s['n'] / $solo['n'] : 0.0,
        ];
    }
}

usort($grid, static function(array $a, array $b): int {
    $cmp = $b['non1_rate'] <=> $a['non1_rate'];
    if ($cmp !== 0) return $cmp;
    return $b['n'] <=> $a['n'];
});

$label = 'joined';
if (preg_match('/kimarite_analysis_dataset_(\d{8})_(\d{8})\.csv$/', basename($csvPath), $m)) {
    $label = $m[1] . '_' . $m[2];
}
$outPath = dirname($csvPath) . "/upset_escape_grid_{$label}.csv";
$out = fopen($outPath, 'wb');
if ($out === false) {
    fwrite(STDERR, "出力CSVを作れません: {$outPath}\n");
    exit(1);
}
fputcsv($out, [
    'nige_max','attack_min','n','non1_wins','non1_rate','base_diff',
    'nige_only_n','nige_only_rate','attack_added_diff','retention_pct'
]);
foreach ($grid as $g) {
    fputcsv($out, [
        $g['nige_max'], $g['attack_min'], $g['n'], $g['non1'],
        number_format($g['non1_rate'], 4, '.', ''),
        number_format($g['base_diff'], 4, '.', ''),
        $g['nige_only_n'], number_format($g['nige_only_rate'], 4, '.', ''),
        number_format($g['attack_added_diff'], 4, '.', ''),
        number_format($g['retention_pct'], 4, '.', ''),
    ]);
}
fclose($out);

function printTop(array $grid, int $minN, int $limit, float $baseRate): void
{
    $rank = 0;
    foreach ($grid as $g) {
        if ($g['n'] < $minN) continue;
        $rank++;
        printf(
            "%2d位 逃げ<=%2d%% × 差捲最大>=%2d%%  N=%6d  非1号艇1着=%6.2f%%  基礎差=%+6.2fpt  差捲上乗せ=%+5.2fpt\n",
            $rank, $g['nige_max'], $g['attack_min'], $g['n'], $g['non1_rate'],
            $g['non1_rate'] - $baseRate, $g['attack_added_diff']
        );
        if ($rank >= $limit) break;
    }
}

$line = str_repeat('=', 132);
echo $line . "\n";
echo "穴レース検索 1%刻み総当たり（1C逃げ率上限 × 2〜6C差し+捲り最大値）\n";
echo "CSV      : " . basename($csvPath) . "\n";
echo "期間     : " . fmtDate($dateMin) . " ～ " . fmtDate($dateMax) . "\n";
echo "分析母体 : " . number_format($base['n']) . "R\n";
printf("基礎非1号艇1着率: %.2f%% (%d/%d)\n", $base['rate'], $base['non1'], $base['n']);
echo "sample_n : 1Cは{$minSample}以上、2〜6Cはsample_n>={$minSample}の艇だけで最大値計算\n";
echo "総当たり : 逃げ<=10～50% × 差捲最大>=20～50% = " . number_format(count($grid)) . "通り\n";
echo "追加条件 : Web本命・AI・展示・場補正なし\n";
echo "出力CSV  : {$outPath}\n";
echo $line . "\n\n";

echo "【勝率上位10（母数制限なし）】\n";
printTop($grid, 0, 10, $base['rate']);

echo "\n【実用上位10（N>=500）】\n";
printTop($grid, 500, 10, $base['rate']);

echo "\n【実用上位10（N>=1000）】\n";
printTop($grid, 1000, 10, $base['rate']);

echo "\n【実用上位10（N>=2000）】\n";
printTop($grid, 2000, 10, $base['rate']);

$added = array_values(array_filter($grid, static fn(array $g): bool => $g['n'] >= 1000));
usort($added, static function(array $a, array $b): int {
    $cmp = $b['attack_added_diff'] <=> $a['attack_added_diff'];
    if ($cmp !== 0) return $cmp;
    return $b['n'] <=> $a['n'];
});
echo "\n【差し+捲り条件を足した上乗せ効果 上位10（N>=1000）】\n";
foreach (array_slice($added, 0, 10) as $i => $g) {
    printf(
        "%2d位 逃げ<=%2d%% × 差捲最大>=%2d%%  N=%6d  非1号艇1着=%6.2f%%  逃げ条件単独=%6.2f%%  上乗せ=%+5.2fpt  母数維持=%5.1f%%\n",
        $i + 1, $g['nige_max'], $g['attack_min'], $g['n'], $g['non1_rate'],
        $g['nige_only_rate'], $g['attack_added_diff'], $g['retention_pct']
    );
}

echo "\n【競艇日和型の代表条件】\n";
foreach ([[50,20],[40,25],[40,30],[30,30],[30,35],[20,35],[20,40],[10,40]] as [$x, $y]) {
    $found = null;
    foreach ($grid as $g) {
        if ($g['nige_max'] === $x && $g['attack_min'] === $y) { $found = $g; break; }
    }
    if ($found === null) continue;
    printf(
        "逃げ<=%2d%% × 差捲最大>=%2d%%  N=%6d  非1号艇1着=%6.2f%%  基礎差=%+6.2fpt\n",
        $x, $y, $found['n'], $found['non1_rate'], $found['base_diff']
    );
}

echo "\n【検索プリセット候補を見る時の基準】\n";
echo "- まず『1号艇が負けるレースを拾えるか』を評価する。ここでは高配当そのものはまだ評価しない。\n";
echo "- 率だけの上位は母数が小さくなりやすいので、N>=500 / 1000 / 2000を比較する。\n";
echo "- attack_added_diff が小さいなら、差し+捲り条件の追加価値は薄い。\n";
echo "- 3候補を選んだ後、2026-08-15以降の未使用期間で閾値を変えず前方検証する。\n";
echo $line . "\n";
