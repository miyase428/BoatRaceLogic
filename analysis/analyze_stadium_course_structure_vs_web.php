<?php

declare(strict_types=1);

/**
 * 場ごとの実コース特性と、現行Web買い目の相性を比較する。
 *
 * 目的:
 * - 「現行Webはインが強い場に強いだけなのか」を確認する。
 * - 各場の1〜6コース1着率、現行Web本命1号艇率、
 *   本命1号艇時/非1号艇時の買い目的中率を並べる。
 * - 24場横断で1C1着率とWeb買い目的中率の相関も出す。
 *
 * 現行Webは以下を再現する:
 * - ⑤⑥本命 kimarite 頭補正
 * - A3 / A4 / H3 相手補正
 *
 * Usage:
 * php analysis/analyze_stadium_course_structure_vs_web.php \
 *   analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *   analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv
 */

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php {$argv[0]} DATASET_CSV BOATS_CSV\n");
    exit(1);
}

[$script, $datasetPath, $boatsPath] = $argv;
$modelPath = dirname(__DIR__) . '/config/kimarite_head_model.php';
foreach ([$datasetPath, $boatsPath, $modelPath] as $path) {
    if (!is_file($path)) throw new RuntimeException("必要ファイルがありません: {$path}");
}
$model = require $modelPath;
if (!is_array($model) || empty($model['courses'])) {
    throw new RuntimeException("kimarite頭補正モデルの形式が不正です: {$modelPath}");
}

const START_DATE = '2025-08-15';
const END_DATE   = '2026-08-14';

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
function formal(array $row): bool
{
    return inum($row, 'result_top3_course_complete') === 1
        && inum($row, 'result_boat_match') === 1;
}
function sampleOk(array $row, int $course): bool
{
    return inum($row, "c{$course}_6m_sample_n") >= 10;
}
function attack(array $row, int $course): float
{
    return fnum($row, "c{$course}_6m_makuri") + fnum($row, "c{$course}_6m_makurizashi");
}
function headFeature(array $row, int $course): float
{
    if ($course === 2) return fnum($row, 'c2_6m_sashi');
    return attack($row, $course);
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
function rankAndKiru(array $boats): ?array
{
    if (count($boats) !== 6) return null;
    usort($boats, static function(array $a, array $b): int {
        $ra = inum($a, 'final_rank', 99);
        $rb = inum($b, 'final_rank', 99);
        return $ra === $rb
            ? inum($a, 'lane_number', 99) <=> inum($b, 'lane_number', 99)
            : $ra <=> $rb;
    });
    $rank = []; $kiru = [];
    foreach ($boats as $b) {
        $lane = inum($b, 'lane_number');
        if ($lane < 1 || $lane > 6) return null;
        $rank[] = $lane;
        $kiru[$lane] = inum($b, 'kiru') === 1;
    }
    return [$rank, $kiru];
}
function moveToFront(array $rank, int $target): array
{
    $rank = array_values(array_filter($rank, static fn(int $b): bool => $b !== $target));
    array_unshift($rank, $target);
    return array_values($rank);
}
function promoteToSecond(array $rank, int $head, int $target): array
{
    if ($head === $target) return $rank;
    $p = array_search($target, $rank, true);
    if ($p === false) return $rank;
    array_splice($rank, (int)$p, 1);
    $hp = array_search($head, $rank, true);
    if ($hp === false) return $rank;
    array_splice($rank, (int)$hp + 1, 0, [$target]);
    return array_values($rank);
}
function buildBet(array $rank, array $kiru, int $head): array
{
    $aite = []; $third = [];
    foreach ($rank as $boat) {
        if ($boat === $head) continue;
        if (($kiru[$boat] ?? false) === true) continue;
        $third[] = $boat;
        if (count($aite) < 3) $aite[] = $boat;
    }
    return ['aite'=>$aite, 'third'=>$third];
}
function betHit(array $row, array $bet, int $head): bool
{
    return inum($row, 'actual_1st') === $head
        && in_array(inum($row, 'actual_2nd'), $bet['aite'], true)
        && in_array(inum($row, 'actual_3rd'), $bet['third'], true);
}
function currentPrediction(array $row, array $baseRank, array $kiru, array $model): array
{
    $originalHead = inum($row, 'honmei_head');
    $head = $originalHead;
    $rank = $baseRank;
    if ($originalHead === 5 || $originalHead === 6) {
        $head = chooseHead($row, $model);
        $rank = moveToFront($rank, $head);
    }
    if ($originalHead === 1) {
        $a3 = sampleOk($row, 3) && attack($row, 3) >= 15.0;
        $a4 = sampleOk($row, 4) && attack($row, 4) >= 20.0;
        if ($a4) $rank = promoteToSecond($rank, $head, 4);
        if ($a3) $rank = promoteToSecond($rank, $head, 3);
    } elseif ($originalHead === 3) {
        $h3 = sampleOk($row, 3) && attack($row, 3) >= 15.0;
        if ($h3) $rank = promoteToSecond($rank, $head, 1);
    }
    return [$head, buildBet($rank, $kiru, $head)];
}
function emptyStat(): array
{
    return [
        'n'=>0, 'hit'=>0,
        'course_win'=>[1=>0,2=>0,3=>0,4=>0,5=>0,6=>0],
        'web_head1_n'=>0, 'web_head1_hit'=>0,
        'web_head_non1_n'=>0, 'web_head_non1_hit'=>0,
    ];
}
function addStat(array &$s, array $row, int $head, array $bet): void
{
    $winnerCourse = inum($row, 'actual_1st_course');
    $hit = betHit($row, $bet, $head);
    $s['n']++;
    $s['hit'] += (int)$hit;
    if ($winnerCourse >= 1 && $winnerCourse <= 6) $s['course_win'][$winnerCourse]++;
    if ($head === 1) {
        $s['web_head1_n']++;
        $s['web_head1_hit'] += (int)$hit;
    } else {
        $s['web_head_non1_n']++;
        $s['web_head_non1_hit'] += (int)$hit;
    }
}
function pearson(array $xs, array $ys): ?float
{
    $n = count($xs);
    if ($n < 2 || count($ys) !== $n) return null;
    $mx = array_sum($xs) / $n;
    $my = array_sum($ys) / $n;
    $num = 0.0; $dx = 0.0; $dy = 0.0;
    for ($i=0; $i<$n; $i++) {
        $a = $xs[$i] - $mx;
        $b = $ys[$i] - $my;
        $num += $a * $b;
        $dx += $a * $a;
        $dy += $b * $b;
    }
    if ($dx <= 0.0 || $dy <= 0.0) return null;
    return $num / sqrt($dx * $dy);
}
function mergeStats(array $stats): array
{
    $out = emptyStat();
    foreach ($stats as $s) {
        $out['n'] += $s['n'];
        $out['hit'] += $s['hit'];
        foreach ([1,2,3,4,5,6] as $c) $out['course_win'][$c] += $s['course_win'][$c];
        $out['web_head1_n'] += $s['web_head1_n'];
        $out['web_head1_hit'] += $s['web_head1_hit'];
        $out['web_head_non1_n'] += $s['web_head_non1_n'];
        $out['web_head_non1_hit'] += $s['web_head_non1_hit'];
    }
    return $out;
}

$datasetRows = readCsvAssoc($datasetPath);
$boatRows = readCsvAssoc($boatsPath);
$boatsByRace = [];
foreach ($boatRows as $b) {
    $rc = trim((string)($b['race_code'] ?? ''));
    if ($rc !== '') $boatsByRace[$rc][] = $b;
}

$venues = [];
$global = emptyStat();
$formalN = 0; $reconstructN = 0;
foreach ($datasetRows as $row) {
    if (!formal($row)) continue;
    $date = trim((string)($row['race_date'] ?? ''));
    if ($date < START_DATE || $date > END_DATE) continue;
    $formalN++;

    $rc = trim((string)($row['race_code'] ?? ''));
    $rk = rankAndKiru($boatsByRace[$rc] ?? []);
    if ($rk === null) continue;
    [$baseRank, $kiru] = $rk;
    $originalHead = inum($row, 'honmei_head');
    if (($baseRank[0] ?? 0) !== $originalHead) continue;
    $reconstructN++;

    [$head, $bet] = currentPrediction($row, $baseRank, $kiru, $model);
    $stadium = trim((string)($row['stadium_name'] ?? '')) ?: '不明';
    if (!isset($venues[$stadium])) $venues[$stadium] = emptyStat();
    addStat($venues[$stadium], $row, $head, $bet);
    addStat($global, $row, $head, $bet);
}

$rows = [];
foreach ($venues as $stadium => $s) {
    $n = max(1, (int)$s['n']);
    $courseRates = [];
    foreach ([1,2,3,4,5,6] as $c) $courseRates[$c] = pct($s['course_win'][$c], $s['n']);
    $rows[] = [
        'stadium'=>$stadium,
        'n'=>$s['n'],
        'hit'=>pct($s['hit'],$s['n']),
        'c'=>$courseRates,
        'outer'=>pct($s['course_win'][4]+$s['course_win'][5]+$s['course_win'][6],$s['n']),
        'head1_share'=>pct($s['web_head1_n'],$s['n']),
        'head1_hit'=>pct($s['web_head1_hit'],$s['web_head1_n']),
        'head_non1_hit'=>pct($s['web_head_non1_hit'],$s['web_head_non1_n']),
    ];
}
usort($rows, static fn(array $a,array $b): int => $b['hit'] <=> $a['hit']);

$c1 = []; $outer = []; $head1share = []; $hits = [];
foreach ($rows as $r) {
    $c1[] = $r['c'][1];
    $outer[] = $r['outer'];
    $head1share[] = $r['head1_share'];
    $hits[] = $r['hit'];
}
$rC1 = pearson($c1,$hits);
$rOuter = pearson($outer,$hits);
$rHead1 = pearson($head1share,$hits);

printf("%s\n場のコース特性 × 現行Web相性\n%s\n", str_repeat('=', 184), str_repeat('=', 184));
printf("対象期間     : %s ～ %s\n", START_DATE, END_DATE);
printf("正式対象     : %dR / 再構成可能 %dR / %d場\n", $formalN, $reconstructN, count($rows));
printf("全場Web的中  : %.2f%%\n", pct($global['hit'],$global['n']));
printf("全場1C1着率  : %.2f%% / 外(4-6C)頭率 %.2f%%\n",
    pct($global['course_win'][1],$global['n']),
    pct($global['course_win'][4]+$global['course_win'][5]+$global['course_win'][6],$global['n']));
printf("相関係数     : 1C1着率×Web的中 r=%+.3f / 外頭率×Web的中 r=%+.3f / Web本命①率×Web的中 r=%+.3f\n",
    $rC1 ?? 0.0, $rOuter ?? 0.0, $rHead1 ?? 0.0);

echo "\n" . str_repeat('=',184) . "\n24場（Web的中率順）\n" . str_repeat('=',184) . "\n";
printf("%3s %-10s %6s %8s %7s %7s %7s %7s %7s %7s %8s %9s %10s\n",
    '順','場','N','Web的中','1C勝','2C勝','3C勝','4C勝','5C勝','6C勝','外頭率','Web本命①','①時/非①時');
echo str_repeat('-',184) . "\n";
foreach ($rows as $i => $r) {
    printf("%3d %-10s %6d %7.2f%% %6.2f%% %6.2f%% %6.2f%% %6.2f%% %6.2f%% %6.2f%% %7.2f%% %8.2f%% %5.2f/%5.2f\n",
        $i+1,$r['stadium'],$r['n'],$r['hit'],
        $r['c'][1],$r['c'][2],$r['c'][3],$r['c'][4],$r['c'][5],$r['c'][6],
        $r['outer'],$r['head1_share'],$r['head1_hit'],$r['head_non1_hit']);
}

if (count($rows) >= 24) {
    $top = mergeStats(array_map(static fn(array $r): array => $venues[$r['stadium']], array_slice($rows,0,8)));
    $mid = mergeStats(array_map(static fn(array $r): array => $venues[$r['stadium']], array_slice($rows,8,8)));
    $low = mergeStats(array_map(static fn(array $r): array => $venues[$r['stadium']], array_slice($rows,16,8)));
    echo "\n" . str_repeat('=',120) . "\n上位8 / 中位8 / 下位8の比較\n" . str_repeat('=',120) . "\n";
    foreach (['上位8'=>$top,'中位8'=>$mid,'下位8'=>$low] as $label=>$s) {
        printf("%-8s N=%5d Web的中=%6.2f%% 1C勝=%6.2f%% 外頭=%6.2f%% Web本命①=%6.2f%% ①時=%6.2f%% 非①時=%6.2f%%\n",
            $label,$s['n'],pct($s['hit'],$s['n']),pct($s['course_win'][1],$s['n']),
            pct($s['course_win'][4]+$s['course_win'][5]+$s['course_win'][6],$s['n']),
            pct($s['web_head1_n'],$s['n']),pct($s['web_head1_hit'],$s['web_head1_n']),
            pct($s['web_head_non1_hit'],$s['web_head_non1_n']));
    }
}

echo "\n判断ポイント:\n";
echo "1. 1C1着率とWeb的中の相関が強ければ、現行Webはイン強場に寄っている可能性が高い。\n";
echo "2. 上位場でも非①本命時の的中率が高ければ、単なるイン依存ではない。\n";
echo "3. 下位場で外頭率が高く、非①本命時も弱いなら、外頭の見極めが次の改善点。\n";
echo "4. 次はこの結果を踏まえて、場×決まり手と1逃げ時2・3着分布へ進む。\n";
