<?php
/**
 * 穴検索3プリセットの前方検証。
 *
 * 2025-08-15〜2026-08-14 の1%刻み総当たりで選んだ3条件を凍結し、
 * 2026-08-15以降の未使用期間で再現性だけを確認する。
 *
 * 条件:
 * - 1Cの1年逃げ率 <= X
 * - 2〜6Cのうち sample_n>=10 の艇について、1年差し率+捲り率の最大値 >= Y
 *
 * Usage:
 *   php analysis/validate_upset_presets_forward.php \
 *     analysis/output/kimarite_analysis_dataset_20260815_20260822.csv \
 *     analysis/output/kimarite_analysis_dataset_20260823_20260831.csv
 */

declare(strict_types=1);

const HOLDOUT_START = '20260815';
const MIN_SAMPLE_N = 10;

$presets = [
    '精度優先' => [
        'nige_max' => 20.0,
        'attack_min' => 20.0,
        'train_n' => 505,
        'train_rate' => 83.17,
    ],
    'バランス' => [
        'nige_max' => 28.0,
        'attack_min' => 20.0,
        'train_n' => 1021,
        'train_rate' => 78.06,
    ],
    '件数優先' => [
        'nige_max' => 37.0,
        'attack_min' => 20.0,
        'train_n' => 2084,
        'train_rate' => 72.98,
    ],
];

function usage(): never
{
    fwrite(STDERR, "使用方法:\n  php analysis/validate_upset_presets_forward.php KIMARITE_CSV [KIMARITE_CSV ...]\n");
    exit(1);
}

if ($argc < 2) {
    usage();
}

function pct(int $num, int $den): float
{
    return $den > 0 ? 100.0 * $num / $den : 0.0;
}

function fmtDate(?string $ymd): string
{
    if ($ymd === null || !preg_match('/^\d{8}$/', $ymd)) return '-';
    return substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2);
}

/** Wilson score 95% CI */
function wilson95(int $wins, int $n): array
{
    if ($n <= 0) return [0.0, 0.0];
    $z = 1.959963984540054;
    $p = $wins / $n;
    $z2 = $z * $z;
    $den = 1.0 + $z2 / $n;
    $center = ($p + $z2 / (2.0 * $n)) / $den;
    $half = ($z / $den) * sqrt(($p * (1.0 - $p) / $n) + ($z2 / (4.0 * $n * $n)));
    return [100.0 * max(0.0, $center - $half), 100.0 * min(1.0, $center + $half)];
}

function loadRows(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("CSVがありません: {$path}");
    }

    $fh = fopen($path, 'rb');
    if ($fh === false) {
        throw new RuntimeException("CSVを開けません: {$path}");
    }

    $header = fgetcsv($fh);
    if ($header === false) {
        fclose($fh);
        throw new RuntimeException("CSVヘッダを読めません: {$path}");
    }

    $idx = array_flip($header);
    $required = ['race_code', 'c1_1y_sample_n', 'c1_1y_nige', 'actual_1st'];
    for ($course = 2; $course <= 6; $course++) {
        $required[] = "c{$course}_1y_sample_n";
        $required[] = "c{$course}_1y_sashi";
        $required[] = "c{$course}_1y_makuri";
    }

    foreach ($required as $col) {
        if (!array_key_exists($col, $idx)) {
            fclose($fh);
            throw new RuntimeException("必要列がありません {$col}: {$path}");
        }
    }

    $rows = [];
    while (($data = fgetcsv($fh)) !== false) {
        if (count($data) < count($header)) continue;

        $raceCode = trim((string)($data[$idx['race_code']] ?? ''));
        if (!preg_match('/^(\d{8})[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $raceCode, $m)) continue;

        $date = $m[1];
        if ($date < HOLDOUT_START) continue;

        $n1 = (int)($data[$idx['c1_1y_sample_n']] ?? 0);
        if ($n1 < MIN_SAMPLE_N) continue;

        $nigeRaw = trim((string)($data[$idx['c1_1y_nige']] ?? ''));
        $actualRaw = trim((string)($data[$idx['actual_1st']] ?? ''));
        if (!is_numeric($nigeRaw)) continue;
        if (!preg_match('/^[1-6]$/', $actualRaw)) continue;

        $attackMax = null;
        $attackCourse = null;
        for ($course = 2; $course <= 6; $course++) {
            $sample = (int)($data[$idx["c{$course}_1y_sample_n"]] ?? 0);
            if ($sample < MIN_SAMPLE_N) continue;

            $sashiRaw = trim((string)($data[$idx["c{$course}_1y_sashi"]] ?? ''));
            $makuriRaw = trim((string)($data[$idx["c{$course}_1y_makuri"]] ?? ''));
            if (!is_numeric($sashiRaw) || !is_numeric($makuriRaw)) continue;

            $attack = (float)$sashiRaw + (float)$makuriRaw;
            if ($attackMax === null || $attack > $attackMax) {
                $attackMax = $attack;
                $attackCourse = $course;
            }
        }

        if ($attackMax === null) continue;

        $rows[$raceCode] = [
            'race_code' => $raceCode,
            'date' => $date,
            'nige' => (float)$nigeRaw,
            'attack_max' => $attackMax,
            'attack_course' => $attackCourse,
            'actual_1st' => (int)$actualRaw,
            'source' => basename($path),
        ];
    }

    fclose($fh);
    return $rows;
}

function calcPreset(array $rows, float $nigeMax, float $attackMin): array
{
    $n = 0;
    $non1Wins = 0;
    $dates = [];

    foreach ($rows as $r) {
        if ($r['nige'] > $nigeMax || $r['attack_max'] < $attackMin) continue;
        $n++;
        if ($r['actual_1st'] !== 1) $non1Wins++;
        $dates[$r['date']] = true;
    }

    [$lo, $hi] = wilson95($non1Wins, $n);
    return [
        'n' => $n,
        'wins' => $non1Wins,
        'rate' => pct($non1Wins, $n),
        'ci_lo' => $lo,
        'ci_hi' => $hi,
        'days' => count($dates),
    ];
}

$allRows = [];
$byFile = [];
foreach (array_slice($argv, 1) as $path) {
    try {
        $rows = loadRows($path);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }

    $byFile[basename($path)] = $rows;
    foreach ($rows as $code => $row) {
        $allRows[$code] = $row; // race_code重複は1件に統合
    }
}

if (!$allRows) {
    fwrite(STDERR, "前方検証できる行がありません\n");
    exit(1);
}

ksort($allRows);
$dateMin = null;
$dateMax = null;
$baseNon1 = 0;
foreach ($allRows as $r) {
    $dateMin = $dateMin === null || $r['date'] < $dateMin ? $r['date'] : $dateMin;
    $dateMax = $dateMax === null || $r['date'] > $dateMax ? $r['date'] : $dateMax;
    if ($r['actual_1st'] !== 1) $baseNon1++;
}
$baseRate = pct($baseNon1, count($allRows));

$line = str_repeat('=', 132);
echo $line . "\n";
echo "穴検索 3プリセット前方検証（条件固定）\n";
echo "期間         : " . fmtDate($dateMin) . " ～ " . fmtDate($dateMax) . "\n";
echo "分析母体     : " . number_format(count($allRows)) . "R\n";
printf("母体非1号艇1着率: %.2f%% (%d/%d)\n", $baseRate, $baseNon1, count($allRows));
echo "条件選定元   : 2025-08-15 ～ 2026-08-14\n";
echo "前方開始     : 2026-08-15（閾値再調整なし）\n";
echo "差捲定義     : 2〜6Cのうち sample_n>=10 の艇の (1年差し率+1年捲り率) 最大値\n";
echo $line . "\n\n";

printf("%-12s %-29s %8s %10s %10s %10s %20s %10s\n",
    'プリセット', '条件', 'N', '学習率', '前方率', '差', '95%CI', '1日平均');
echo str_repeat('-', 132) . "\n";

foreach ($presets as $name => $p) {
    $s = calcPreset($allRows, $p['nige_max'], $p['attack_min']);
    $diff = $s['rate'] - $p['train_rate'];
    $perDay = $s['days'] > 0 ? $s['n'] / $s['days'] : 0.0;

    printf("%-12s 逃げ<=%2.0f%%×差捲最大>=%2.0f%% %8d %9.2f%% %9.2f%% %+9.2fpt %7.2f～%7.2f%% %9.2fR\n",
        $name,
        $p['nige_max'],
        $p['attack_min'],
        $s['n'],
        $p['train_rate'],
        $s['rate'],
        $diff,
        $s['ci_lo'],
        $s['ci_hi'],
        $perDay
    );
}

echo "\n【期間別】\n";
foreach ($byFile as $file => $rows) {
    if (!$rows) continue;

    $dmin = null;
    $dmax = null;
    foreach ($rows as $r) {
        $dmin = $dmin === null || $r['date'] < $dmin ? $r['date'] : $dmin;
        $dmax = $dmax === null || $r['date'] > $dmax ? $r['date'] : $dmax;
    }

    echo "\n{$file}  (" . fmtDate($dmin) . "～" . fmtDate($dmax) . ")\n";
    foreach ($presets as $name => $p) {
        $s = calcPreset($rows, $p['nige_max'], $p['attack_min']);
        printf("  %-12s N=%4d  非1号艇1着=%6.2f%%  学習差=%+6.2fpt\n",
            $name,
            $s['n'],
            $s['rate'],
            $s['rate'] - $p['train_rate']
        );
    }
}

echo "\n【見るポイント】\n";
echo "1. 学習期間の83.17 / 78.06 / 72.98%に近い水準を保てているか。\n";
echo "2. 3条件の順位関係が大崩れしていないか。\n";
echo "3. 精度優先は母数が小さめなので、率だけで切らず95%CIとNも見る。\n";
echo "4. ここでは『1号艇が負ける率』だけを確認し、高配当性は次工程で別検証する。\n";
echo "5. この前方結果を見てから正式プリセット化し、ここでは閾値を再調整しない。\n";
echo $line . "\n";
