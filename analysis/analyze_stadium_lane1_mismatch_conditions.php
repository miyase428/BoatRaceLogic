<?php

declare(strict_types=1);

/**
 * 場別 1号艇本命判断ズレの条件診断
 *
 * 補正案を作る前に、重点場で
 *   A: 1号艇勝ち × Web本命1（捕捉成功）
 *   B: 1号艇勝ち × Web本命非1（取りこぼし）
 *   C: 1号艇負け × Web本命1（過信）
 *   D: 1号艇負け × Web本命非1（回避成功）
 * を比較する。
 *
 * 比較項目:
 * - 1号艇の一次/二次/最終順位
 * - 1号艇一次スコア
 * - c2逃し率
 * - c3/c4/c5 攻め率（まくり+まくり差し）
 * - 取りこぼし時のWeb本命艇
 * - 過信時の実勝ち艇
 *
 * 現行Webの「頭」は、本番済み⑤⑥頭補正まで再現する。
 * A3/A4/H3は相手補正だけなので頭判定には影響しない。
 *
 * Usage:
 * php analysis/analyze_stadium_lane1_mismatch_conditions.php \
 *   analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *   analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv
 */

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php {$argv[0]} DATASET_CSV BOATS_CSV\n");
    exit(1);
}

[$script, $datasetPath, $boatsPath] = $argv;
$modelPath = dirname(__DIR__) . '/config/kimarite_head_model.php';
foreach ([$datasetPath, $boatsPath, $modelPath] as $p) {
    if (!is_file($p)) throw new RuntimeException("必要ファイルがありません: {$p}");
}
$model = require $modelPath;
if (!is_array($model) || empty($model['courses'])) {
    throw new RuntimeException("kimarite頭補正モデルの形式が不正です: {$modelPath}");
}

const TARGET_VENUES = ['大村','津','宮島','戸田','江戸川'];
const START_DATE = '2025-08-15';
const END_DATE = '2026-08-14';

function readCsvAssoc(string $path): array
{
    $fp = fopen($path, 'rb');
    if ($fp === false) throw new RuntimeException("CSVを開けません: {$path}");
    $header = fgetcsv($fp);
    if ($header === false) { fclose($fp); return []; }
    if (isset($header[0])) $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
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

function formal(array $row): bool
{
    return inum($row, 'result_top3_course_complete') === 1
        && inum($row, 'result_boat_match') === 1;
}

function attack(array $row, int $course): float
{
    return fnum($row, "c{$course}_6m_makuri") + fnum($row, "c{$course}_6m_makurizashi");
}

function headFeature(array $row, int $course): float
{
    return $course === 2 ? fnum($row, 'c2_6m_sashi') : attack($row, $course);
}

function featureBand(float $v): string
{
    if ($v < 5.0) return '0-5';
    if ($v < 10.0) return '5-10';
    if ($v < 15.0) return '10-15';
    if ($v < 20.0) return '15-20';
    if ($v < 25.0) return '20-25';
    return '25+';
}

function modelScore(array $row, int $course, array $model): float
{
    $cm = $model['courses'][$course] ?? [];
    $base = (float)($cm['base_p'] ?? 0.0);
    $minSample = (int)($model['min_sample'] ?? 10);
    if (inum($row, "c{$course}_6m_sample_n") < $minSample) return $base;
    $band = featureBand(headFeature($row, $course));
    $br = $cm['bands'][$band] ?? null;
    return is_array($br) && array_key_exists('p', $br) ? (float)$br['p'] : $base;
}

function chooseHead(array $row, array $model): int
{
    $bestCourse = 2;
    $bestScore = -INF;
    foreach ([2,3,4] as $course) {
        $score = modelScore($row, $course, $model);
        if ($score > $bestScore || ($score === $bestScore && $course < $bestCourse)) {
            $bestCourse = $course;
            $bestScore = $score;
        }
    }
    return $bestCourse;
}

function currentHead(array $row, array $model): int
{
    $original = inum($row, 'honmei_head');
    if ($original === 5 || $original === 6) return chooseHead($row, $model);
    return $original;
}

function pct(int $n, int $d): float
{
    return $d > 0 ? 100.0 * $n / $d : 0.0;
}

function avg(array $xs): float
{
    return count($xs) > 0 ? array_sum($xs) / count($xs) : 0.0;
}

function emptyBucket(): array
{
    return [
        'n'=>0,
        'first_rank'=>[], 'second_rank'=>[], 'final_rank'=>[], 'first_score'=>[],
        'c2_nogashi'=>[], 'a3'=>[], 'a4'=>[], 'a5'=>[],
        'head_counts'=>array_fill(1,6,0),
        'winner_counts'=>array_fill(1,6,0),
    ];
}

function addBucket(array &$b, array $row, array $lane1, int $head): void
{
    $b['n']++;
    $b['first_rank'][] = inum($lane1, 'first_rank', 99);
    $b['second_rank'][] = inum($lane1, 'second_rank', 99);
    $b['final_rank'][] = inum($lane1, 'final_rank', 99);
    $b['first_score'][] = fnum($lane1, 'first_total_score');
    $b['c2_nogashi'][] = fnum($row, 'c2_6m_nogashi');
    $b['a3'][] = attack($row, 3);
    $b['a4'][] = attack($row, 4);
    $b['a5'][] = attack($row, 5);
    if ($head >= 1 && $head <= 6) $b['head_counts'][$head]++;
    $winner = inum($row, 'actual_1st');
    if ($winner >= 1 && $winner <= 6) $b['winner_counts'][$winner]++;
}

function rank1Rate(array $xs): float
{
    if (!$xs) return 0.0;
    $n = 0;
    foreach ($xs as $v) if ((int)$v === 1) $n++;
    return pct($n, count($xs));
}

function rank4plusRate(array $xs): float
{
    if (!$xs) return 0.0;
    $n = 0;
    foreach ($xs as $v) if ((int)$v >= 4 && (int)$v <= 6) $n++;
    return pct($n, count($xs));
}

function countsText(array $counts): string
{
    arsort($counts);
    $parts = [];
    foreach ($counts as $k => $v) {
        if ($v <= 0) continue;
        $parts[] = $k . ':' . $v;
        if (count($parts) >= 4) break;
    }
    return $parts ? implode(',', $parts) : '-';
}

function printBucket(string $label, array $b): void
{
    $n = $b['n'];
    if ($n === 0) {
        printf("%-10s N=0\n", $label);
        return;
    }
    printf(
        "%-10s N=%4d | 一次R %.2f (1位%5.1f%%/4位↓%5.1f%%) | 二次R %.2f (1位%5.1f%%/4位↓%5.1f%%) | 最終R %.2f (1位%5.1f%%/4位↓%5.1f%%)\n",
        $label, $n,
        avg($b['first_rank']), rank1Rate($b['first_rank']), rank4plusRate($b['first_rank']),
        avg($b['second_rank']), rank1Rate($b['second_rank']), rank4plusRate($b['second_rank']),
        avg($b['final_rank']), rank1Rate($b['final_rank']), rank4plusRate($b['final_rank'])
    );
    printf(
        "             一次score=%6.2f | c2逃し=%5.2f | 3C攻め=%5.2f | 4C攻め=%5.2f | 5C攻め=%5.2f | Web頭=%s | 実頭=%s\n",
        avg($b['first_score']), avg($b['c2_nogashi']), avg($b['a3']), avg($b['a4']), avg($b['a5']),
        countsText($b['head_counts']), countsText($b['winner_counts'])
    );
}

$dataset = readCsvAssoc($datasetPath);
$boats = readCsvAssoc($boatsPath);
$lane1ByRace = [];
foreach ($boats as $b) {
    if (inum($b, 'lane_number') !== 1) continue;
    $rc = trim((string)($b['race_code'] ?? ''));
    if ($rc !== '') $lane1ByRace[$rc] = $b;
}

$stats = [];
foreach (TARGET_VENUES as $v) {
    $stats[$v] = [
        'A_捕捉'=>emptyBucket(),
        'B_取こぼし'=>emptyBucket(),
        'C_過信'=>emptyBucket(),
        'D_回避'=>emptyBucket(),
    ];
}

$used = 0;
foreach ($dataset as $row) {
    if (!formal($row)) continue;
    $date = trim((string)($row['race_date'] ?? ''));
    if ($date < START_DATE || $date > END_DATE) continue;
    $venue = trim((string)($row['stadium_name'] ?? ''));
    if (!in_array($venue, TARGET_VENUES, true)) continue;
    $rc = trim((string)($row['race_code'] ?? ''));
    $lane1 = $lane1ByRace[$rc] ?? null;
    if (!is_array($lane1)) continue;

    $head = currentHead($row, $model);
    $oneWins = inum($row, 'actual_1st') === 1;
    $headOne = $head === 1;
    $key = $oneWins
        ? ($headOne ? 'A_捕捉' : 'B_取こぼし')
        : ($headOne ? 'C_過信' : 'D_回避');
    addBucket($stats[$venue][$key], $row, $lane1, $head);
    $used++;
}

printf("%s\n場別 1号艇本命判断ズレ 条件診断（1年）\n%s\n", str_repeat('=', 176), str_repeat('=', 176));
printf("対象期間: %s ～ %s / 重点場=%s / 対象=%dR\n", START_DATE, END_DATE, implode(',', TARGET_VENUES), $used);
echo "A=1勝×Web1 / B=1勝×Web非1(取りこぼし) / C=1負×Web1(過信) / D=1負×Web非1\n";
echo "ここでは補正を作らず、BとA・CとDの特徴差だけを見る。\n";

foreach (TARGET_VENUES as $venue) {
    echo "\n" . str_repeat('-', 176) . "\n";
    echo "【{$venue}】\n";
    echo str_repeat('-', 176) . "\n";
    printBucket('A_捕捉', $stats[$venue]['A_捕捉']);
    printBucket('B_取こぼし', $stats[$venue]['B_取こぼし']);
    printBucket('C_過信', $stats[$venue]['C_過信']);
    printBucket('D_回避', $stats[$venue]['D_回避']);
}

echo "\n判断ポイント:\n";
echo "1. 大村/津/宮島は A vs B を比較し、1が勝つのに外される共通条件を探す。\n";
echo "2. 戸田は C vs D を比較し、1を信頼しすぎる条件を探す。\n";
echo "3. 江戸川は BもCも多いので、A/B/C/Dの分離可能性そのものを見る。\n";
echo "4. この結果を見ても、まだ閾値や場補正は決めない。\n";
