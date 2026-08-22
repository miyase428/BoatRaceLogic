<?php
declare(strict_types=1);

/**
 * 現行Web本命⑤/⑥の過大評価補正について、kimarite自体の追加価値を切り分ける。
 *
 * 後半6か月を評価期間とし、以下を比較する。
 *   - 現行Web本命
 *   - 現行Web順位で2/3/4の最上位
 *   - 2固定 / 3固定 / 4固定
 *   - 前半6か月の基礎1着率だけで選ぶ静的2/3/4
 *   - 前半6か月で学習したkimarite帯別1着率で毎レース2/3/4を選ぶ
 *
 * 特徴:
 *   2C = 6month point-in-time 差し率
 *   3C/4C = 6month point-in-time 攻め率（まくり+まくり差し）
 *   sample_n >= 10
 *   帯 = 0-5 / 5-10 / 10-15 / 15-20 / 20-25 / 25+
 *   帯採用最低N = 100
 *
 * 目的:
 *   ⑤⑥を内側へ戻すだけで改善するのか、それともkimariteで2/3/4を
 *   選び分けることに追加価値があるのかを確認する。
 *
 * Usage:
 *   php analysis/compare_honmei56_kimarite_vs_simple_demotion.php \
 *     analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *     analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv
 */

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php {$argv[0]} DATASET_CSV BOATS_CSV\n");
    exit(1);
}

$datasetPath = $argv[1];
$boatsPath = $argv[2];

if (!is_file($datasetPath)) {
    throw new RuntimeException("dataset CSVがありません: {$datasetPath}");
}
if (!is_file($boatsPath)) {
    throw new RuntimeException("boats CSVがありません: {$boatsPath}");
}

const SPLIT_DATE = '2026-02-15';
const MIN_SAMPLE = 10;
const MIN_BAND_N = 100;
const CANDIDATES = [2, 3, 4];
const BAND_ORDER = ['0-5', '5-10', '10-15', '15-20', '20-25', '25+'];

function readCsvAssoc(string $path): array
{
    $fp = fopen($path, 'rb');
    if ($fp === false) {
        throw new RuntimeException("CSVを開けません: {$path}");
    }

    $header = fgetcsv($fp);
    if ($header === false) {
        fclose($fp);
        return [];
    }
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    }

    $rows = [];
    while (($cols = fgetcsv($fp)) !== false) {
        if (count($cols) !== count($header)) {
            continue;
        }
        $rows[] = array_combine($header, $cols);
    }
    fclose($fp);
    return $rows;
}

function inum(array $row, string $key): int
{
    $v = $row[$key] ?? '';
    return is_numeric($v) ? (int)$v : 0;
}

function fnum(array $row, string $key): float
{
    $v = $row[$key] ?? '';
    return is_numeric($v) ? (float)$v : 0.0;
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

function sampleOk(array $row, int $course): bool
{
    return inum($row, "c{$course}_6m_sample_n") >= MIN_SAMPLE;
}

function feature(array $row, int $course): float
{
    if ($course === 2) {
        return fnum($row, 'c2_6m_sashi');
    }
    return fnum($row, "c{$course}_6m_makuri")
        + fnum($row, "c{$course}_6m_makurizashi");
}

function band(float $v): string
{
    if ($v < 5.0) return '0-5';
    if ($v < 10.0) return '5-10';
    if ($v < 15.0) return '10-15';
    if ($v < 20.0) return '15-20';
    if ($v < 25.0) return '20-25';
    return '25+';
}

function trainModel(array $rows): array
{
    $base = [];
    $bands = [];
    foreach (CANDIDATES as $c) {
        $base[$c] = ['hit' => 0, 'n' => 0];
        $bands[$c] = [];
    }

    foreach ($rows as $row) {
        $winner = inum($row, 'actual_1st_course');
        foreach (CANDIDATES as $c) {
            if (!sampleOk($row, $c)) continue;
            $hit = ($winner === $c) ? 1 : 0;
            $base[$c]['hit'] += $hit;
            $base[$c]['n']++;
            $b = band(feature($row, $c));
            if (!isset($bands[$c][$b])) {
                $bands[$c][$b] = ['hit' => 0, 'n' => 0];
            }
            $bands[$c][$b]['hit'] += $hit;
            $bands[$c][$b]['n']++;
        }
    }

    $baseP = [];
    $bandP = [];
    foreach (CANDIDATES as $c) {
        $n = $base[$c]['n'];
        $baseP[$c] = $n > 0 ? $base[$c]['hit'] / $n : 0.0;
        $bandP[$c] = [];
        foreach ($bands[$c] as $b => $s) {
            if ($s['n'] >= MIN_BAND_N) {
                $bandP[$c][$b] = $s['hit'] / $s['n'];
            }
        }
    }

    return [$base, $bands, $baseP, $bandP];
}

function courseScore(array $row, int $course, array $baseP, array $bandP): float
{
    if (!sampleOk($row, $course)) {
        return $baseP[$course] ?? 0.0;
    }
    $b = band(feature($row, $course));
    return $bandP[$course][$b] ?? ($baseP[$course] ?? 0.0);
}

function bestByScore(array $scores): int
{
    $best = null;
    $bestScore = -INF;
    foreach (CANDIDATES as $c) {
        $score = (float)($scores[$c] ?? -INF);
        if ($score > $bestScore || ($score === $bestScore && ($best === null || $c < $best))) {
            $best = $c;
            $bestScore = $score;
        }
    }
    return $best ?? 2;
}

function loadWebBest234(string $path): array
{
    $rows = readCsvAssoc($path);
    $byRace = [];
    foreach ($rows as $row) {
        $rc = trim((string)($row['race_code'] ?? ''));
        $lane = inum($row, 'lane_number');
        if ($rc === '' || !in_array($lane, CANDIDATES, true)) continue;
        $rank = inum($row, 'final_rank');
        if ($rank <= 0) continue;
        if (!isset($byRace[$rc]) || $rank < $byRace[$rc]['rank']) {
            $byRace[$rc] = ['course' => $lane, 'rank' => $rank];
        }
    }

    $out = [];
    foreach ($byRace as $rc => $v) {
        $out[$rc] = (int)$v['course'];
    }
    return $out;
}

function emptyStat(): array
{
    return [
        'n' => 0,
        'hit' => [
            'current' => 0,
            'web234' => 0,
            'fixed2' => 0,
            'fixed3' => 0,
            'fixed4' => 0,
            'static234' => 0,
            'kimarite234' => 0,
        ],
        'kim_pick' => [2 => 0, 3 => 0, 4 => 0],
        'kim_vs_web_gain' => 0,
        'kim_vs_web_loss' => 0,
        'kim_vs_static_gain' => 0,
        'kim_vs_static_loss' => 0,
    ];
}

function addStat(array &$s, int $winner, int $current, int $web234, int $static234, int $kim234): void
{
    $s['n']++;
    $preds = [
        'current' => $current,
        'web234' => $web234,
        'fixed2' => 2,
        'fixed3' => 3,
        'fixed4' => 4,
        'static234' => $static234,
        'kimarite234' => $kim234,
    ];
    foreach ($preds as $k => $p) {
        if ($winner === $p) $s['hit'][$k]++;
    }
    $s['kim_pick'][$kim234]++;

    $kimHit = ($winner === $kim234);
    $webHit = ($winner === $web234);
    $staticHit = ($winner === $static234);
    if ($kimHit && !$webHit) $s['kim_vs_web_gain']++;
    if (!$kimHit && $webHit) $s['kim_vs_web_loss']++;
    if ($kimHit && !$staticHit) $s['kim_vs_static_gain']++;
    if (!$kimHit && $staticHit) $s['kim_vs_static_loss']++;
}

function printStat(string $label, array $s): void
{
    $n = $s['n'];
    echo "\n" . str_repeat('-', 112) . "\n";
    echo "【{$label}】 N={$n}\n";
    echo str_repeat('-', 112) . "\n";
    if ($n === 0) return;

    $labels = [
        'current' => '現行Web',
        'web234' => 'Web最上位2/3/4',
        'fixed2' => '②固定',
        'fixed3' => '③固定',
        'fixed4' => '④固定',
        'static234' => '学習基礎率2/3/4',
        'kimarite234' => 'kimarite2/3/4',
    ];

    foreach ($labels as $k => $name) {
        $h = $s['hit'][$k];
        echo sprintf("%-18s : %5d / %5d = %6.2f%%\n", $name, $h, $n, pct($h, $n));
    }

    echo sprintf(
        "kimarite vs Web234 : gain=%d loss=%d 純増=%+d\n",
        $s['kim_vs_web_gain'],
        $s['kim_vs_web_loss'],
        $s['kim_vs_web_gain'] - $s['kim_vs_web_loss']
    );
    echo sprintf(
        "kimarite vs 静的234 : gain=%d loss=%d 純増=%+d\n",
        $s['kim_vs_static_gain'],
        $s['kim_vs_static_loss'],
        $s['kim_vs_static_gain'] - $s['kim_vs_static_loss']
    );
    echo sprintf(
        "kimarite選択内訳     : ②=%d (%.1f%%) / ③=%d (%.1f%%) / ④=%d (%.1f%%)\n",
        $s['kim_pick'][2], pct($s['kim_pick'][2], $n),
        $s['kim_pick'][3], pct($s['kim_pick'][3], $n),
        $s['kim_pick'][4], pct($s['kim_pick'][4], $n)
    );
}

$rows = readCsvAssoc($datasetPath);
$formalRows = array_values(array_filter(
    $rows,
    static fn(array $r): bool => formal($r) && inum($r, 'honmei_head') !== 1
));

$trainRows = array_values(array_filter(
    $formalRows,
    static fn(array $r): bool => (string)($r['race_date'] ?? '') < SPLIT_DATE
));
$testRows = array_values(array_filter(
    $formalRows,
    static fn(array $r): bool => (string)($r['race_date'] ?? '') >= SPLIT_DATE
));

[$base, $bands, $baseP, $bandP] = trainModel($trainRows);
$webBest234 = loadWebBest234($boatsPath);
$static234 = bestByScore($baseP);

$stats = [
    '5/6合計' => emptyStat(),
    '現行本命⑤' => emptyStat(),
    '現行本命⑥' => emptyStat(),
];

foreach ($testRows as $row) {
    $current = inum($row, 'honmei_head');
    if ($current !== 5 && $current !== 6) continue;

    $rc = trim((string)($row['race_code'] ?? ''));
    if ($rc === '' || !isset($webBest234[$rc])) continue;

    $winner = inum($row, 'actual_1st_course');
    $scores = [];
    foreach (CANDIDATES as $c) {
        $scores[$c] = courseScore($row, $c, $baseP, $bandP);
    }
    $kim234 = bestByScore($scores);
    $web234 = $webBest234[$rc];

    addStat($stats['5/6合計'], $winner, $current, $web234, $static234, $kim234);
    addStat($stats[$current === 5 ? '現行本命⑤' : '現行本命⑥'], $winner, $current, $web234, $static234, $kim234);
}

echo "\n" . str_repeat('=', 112) . "\n";
echo "現行Web本命⑤⑥：kimarite補正 vs 単純降格 比較\n";
echo str_repeat('=', 112) . "\n";
echo "学習 : 前半6か月 Web本命非1 N=" . count($trainRows) . "\n";
echo "評価 : 後半6か月 Web本命非1 N=" . count($testRows) . "\n";
echo "対象 : 評価期間の現行本命⑤/⑥\n";
echo "比較 : 現行 / Web内2-4最上位 / ②固定 / ③固定 / ④固定 / 学習基礎率 / kimarite帯別\n";
echo "目的 : 改善が『⑤⑥を下げるだけ』なのか『kimariteで2/3/4を選び分ける価値』なのか切り分け\n";

echo "\n【学習側 2/3/4 基礎1着率】\n";
foreach (CANDIDATES as $c) {
    echo sprintf("%dC : %.2f%% (N=%d)\n", $c, pct($base[$c]['hit'], $base[$c]['n']), $base[$c]['n']);
}
echo "静的2/3/4選択 : {$static234}\n";

foreach ($stats as $label => $s) {
    printStat($label, $s);
}

echo "\n" . str_repeat('=', 112) . "\n";
echo "切り分け完了\n";
echo "判定: kimarite2/3/4 が Web最上位2/3/4 や最良固定より上ならkimarite自体に追加価値あり。\n";
echo "      同等以下なら、主因はkimariteより『現行Webの⑤⑥頭過大評価』と考える。\n";
echo str_repeat('=', 112) . "\n";
