<?php

declare(strict_types=1);

/**
 * 現行Web × 24場 相性ランキング
 *
 * 現在本番のロジックを再現:
 *   - ⑤⑥本命 kimarite 頭補正
 *   - A3 / A4 / H3 相手補正
 *
 * 相性順位は「本命買い目的中率」を主指標にする。
 * ROIは参考値として表示するが、順位付けには使わない。
 *
 * 1年を OLD6M / RECENT6M に分け、両期間で全場平均以上かも確認する。
 *
 * Usage:
 * php analysis/analyze_current_web_stadium_compatibility.php \
 *   analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *   analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv \
 *   analysis/output/trifecta_payouts_20250815_20260814.csv
 */

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php {$argv[0]} DATASET_CSV BOATS_CSV PAYOUT_CSV\n");
    exit(1);
}

[$script, $datasetPath, $boatsPath, $payoutPath] = $argv;
$modelPath = dirname(__DIR__) . '/config/kimarite_head_model.php';

foreach ([$datasetPath, $boatsPath, $payoutPath, $modelPath] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException("必要ファイルがありません: {$path}");
    }
}

$model = require $modelPath;
if (!is_array($model) || empty($model['courses'])) {
    throw new RuntimeException("kimarite頭補正モデルの形式が不正です: {$modelPath}");
}

const OLD_START = '2025-08-15';
const OLD_END = '2026-02-14';
const RECENT_START = '2026-02-15';
const RECENT_END = '2026-08-14';
const ALL_START = '2025-08-15';
const ALL_END = '2026-08-14';

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

function sampleOk(array $row, int $course): bool
{
    return inum($row, "c{$course}_6m_sample_n") >= 10;
}

function attack(array $row, int $course): float
{
    return fnum($row, "c{$course}_6m_makuri")
        + fnum($row, "c{$course}_6m_makurizashi");
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
    return is_array($br) && array_key_exists('p', $br)
        ? (float)$br['p']
        : $base;
}

function chooseHead(array $row, array $model): int
{
    $bestCourse = 2;
    $bestScore = -INF;
    foreach ([2, 3, 4] as $course) {
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

    $rank = [];
    $kiru = [];
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
    $aite = [];
    $third = [];
    foreach ($rank as $boat) {
        if ($boat === $head) continue;
        if (($kiru[$boat] ?? false) === true) continue;
        $third[] = $boat;
        if (count($aite) < 3) $aite[] = $boat;
    }

    $points = 0;
    foreach ($aite as $s) {
        foreach ($third as $t) {
            if ($s !== $t) $points++;
        }
    }

    return [
        'aite' => $aite,
        'third' => $third,
        'points' => $points,
    ];
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

    // 本番済み⑤⑥頭補正。
    if ($originalHead === 5 || $originalHead === 6) {
        $head = chooseHead($row, $model);
        $rank = moveToFront($rank, $head);
    }

    // 本番済みA3/A4/H3。判定は必ず事前Web本命で行う。
    if ($originalHead === 1) {
        $a3 = sampleOk($row, 3) && attack($row, 3) >= 15.0;
        $a4 = sampleOk($row, 4) && attack($row, 4) >= 20.0;
        // A3優先。同時発動なら最後に3を2番手へ。
        if ($a4) $rank = promoteToSecond($rank, $head, 4);
        if ($a3) $rank = promoteToSecond($rank, $head, 3);
    } elseif ($originalHead === 3) {
        $h3 = sampleOk($row, 3) && attack($row, 3) >= 15.0;
        if ($h3) $rank = promoteToSecond($rank, $head, 1);
    }

    return [$head, $rank, buildBet($rank, $kiru, $head)];
}

function emptyStat(): array
{
    return [
        'n'=>0,
        'head1'=>0,
        'head2'=>0,
        'head3'=>0,
        'bet_hit'=>0,
        'points'=>0,
        'payout_n'=>0,
        'payout_points'=>0,
        'payout_return'=>0,
    ];
}

function addStat(array &$s, array $row, int $head, array $bet, ?int $payout): void
{
    $a1 = inum($row, 'actual_1st');
    $a2 = inum($row, 'actual_2nd');
    $a3 = inum($row, 'actual_3rd');
    $hit = betHit($row, $bet, $head);

    $s['n']++;
    $s['head1'] += (int)($a1 === $head);
    $s['head2'] += (int)($a1 === $head || $a2 === $head);
    $s['head3'] += (int)($a1 === $head || $a2 === $head || $a3 === $head);
    $s['bet_hit'] += (int)$hit;
    $s['points'] += (int)$bet['points'];

    if ($payout !== null) {
        $s['payout_n']++;
        $s['payout_points'] += (int)$bet['points'];
        if ($hit) $s['payout_return'] += $payout;
    }
}

function hitRate(array $s): float
{
    return pct((int)$s['bet_hit'], (int)$s['n']);
}

function roi(array $s): ?float
{
    $cost = (int)$s['payout_points'] * 100;
    return $cost > 0 ? 100.0 * (int)$s['payout_return'] / $cost : null;
}

$datasetRows = readCsvAssoc($datasetPath);
$boatRows = readCsvAssoc($boatsPath);
$payoutRows = readCsvAssoc($payoutPath);

$boatsByRace = [];
foreach ($boatRows as $b) {
    $rc = trim((string)($b['race_code'] ?? ''));
    if ($rc !== '') $boatsByRace[$rc][] = $b;
}

$payouts = [];
foreach ($payoutRows as $p) {
    $rc = trim((string)($p['race_code'] ?? ''));
    $pay = inum($p, 'trifecta_payout');
    if ($rc !== '' && $pay > 0) $payouts[$rc] = $pay;
}

$periodDefs = [
    'OLD6M' => [OLD_START, OLD_END],
    'RECENT6M' => [RECENT_START, RECENT_END],
    'ALL1Y' => [ALL_START, ALL_END],
];

$global = [];
$venues = [];
foreach ($periodDefs as $label => $_) $global[$label] = emptyStat();

$formalN = 0;
$reconstructN = 0;
$stadiums = [];

foreach ($datasetRows as $row) {
    if (!formal($row)) continue;
    $date = trim((string)($row['race_date'] ?? ''));
    if ($date < ALL_START || $date > ALL_END) continue;
    $formalN++;

    $rc = trim((string)($row['race_code'] ?? ''));
    $rk = rankAndKiru($boatsByRace[$rc] ?? []);
    if ($rk === null) continue;
    [$baseRank, $kiru] = $rk;

    $originalHead = inum($row, 'honmei_head');
    if (($baseRank[0] ?? 0) !== $originalHead) continue;
    $reconstructN++;

    [$head, $rank, $bet] = currentPrediction($row, $baseRank, $kiru, $model);
    $payout = $payouts[$rc] ?? null;
    $stadium = trim((string)($row['stadium_name'] ?? ''));
    if ($stadium === '') $stadium = '不明';
    $stadiums[$stadium] = true;

    foreach ($periodDefs as $label => [$start, $end]) {
        if ($date < $start || $date > $end) continue;
        if (!isset($venues[$stadium][$label])) $venues[$stadium][$label] = emptyStat();
        addStat($global[$label], $row, $head, $bet, $payout);
        addStat($venues[$stadium][$label], $row, $head, $bet, $payout);
    }
}

foreach (array_keys($stadiums) as $stadium) {
    foreach ($periodDefs as $label => $_) {
        if (!isset($venues[$stadium][$label])) $venues[$stadium][$label] = emptyStat();
    }
}

$allGlobal = $global['ALL1Y'];
$oldGlobal = $global['OLD6M'];
$recentGlobal = $global['RECENT6M'];

printf("%s\n現行Web × 24場 相性ランキング\n%s\n", str_repeat('=', 170), str_repeat('=', 170));
printf("対象期間     : %s ～ %s\n", ALL_START, ALL_END);
printf("正式対象     : %dR / 再構成可能 %dR\n", $formalN, $reconstructN);
printf("確認できた場 : %d場\n", count($stadiums));
printf("現行本命買い目: %d / %d = %.2f%%\n",
    $allGlobal['bet_hit'], $allGlobal['n'], hitRate($allGlobal));
printf("全場平均      : 本命1着 %.2f%% / 3連対 %.2f%% / 平均点 %.2f / ROI %.2f%%\n",
    pct($allGlobal['head1'],$allGlobal['n']),
    pct($allGlobal['head3'],$allGlobal['n']),
    $allGlobal['n'] > 0 ? $allGlobal['points'] / $allGlobal['n'] : 0.0,
    roi($allGlobal) ?? 0.0);
printf("OLD6M平均    : 買い目的中 %.2f%%\n", hitRate($oldGlobal));
printf("RECENT6M平均 : 買い目的中 %.2f%%\n", hitRate($recentGlobal));
echo "順位基準      : ALL1Yの現行本命買い目的中率（ROIは順位に不使用）\n";
echo "両期↑         : OLD6M / RECENT6Mの両方で、それぞれの全場平均以上\n";

$rows = [];
foreach ($venues as $stadium => $periodStats) {
    $all = $periodStats['ALL1Y'];
    $old = $periodStats['OLD6M'];
    $recent = $periodStats['RECENT6M'];
    $allHit = hitRate($all);
    $oldHit = hitRate($old);
    $recentHit = hitRate($recent);
    $rows[] = [
        'stadium'=>$stadium,
        'all'=>$all,
        'old'=>$old,
        'recent'=>$recent,
        'hit'=>$allHit,
        'delta'=>$allHit - hitRate($allGlobal),
        'old_hit'=>$oldHit,
        'recent_hit'=>$recentHit,
        'stable'=>($old['n'] > 0 && $recent['n'] > 0 && $oldHit >= hitRate($oldGlobal) && $recentHit >= hitRate($recentGlobal)),
    ];
}

usort($rows, static function(array $a, array $b): int {
    if ($a['hit'] === $b['hit']) {
        $a3 = pct($a['all']['head3'], $a['all']['n']);
        $b3 = pct($b['all']['head3'], $b['all']['n']);
        return $b3 <=> $a3;
    }
    return $b['hit'] <=> $a['hit'];
});

echo "\n" . str_repeat('=', 170) . "\n24場ランキング\n" . str_repeat('=', 170) . "\n";
printf("%4s %-12s %6s %8s %8s %9s %8s %9s %9s %7s %8s %8s\n",
    '順位','場','N','本命1着','本命3連','買い目的中','全場差','OLD6M','RECENT6M','両期↑','平均点','ROI');
echo str_repeat('-', 170) . "\n";

$rankNo = 0;
foreach ($rows as $r) {
    $rankNo++;
    $all = $r['all'];
    printf("%4d %-12s %6d %7.2f%% %7.2f%% %8.2f%% %+7.2fpt %8.2f%% %8.2f%% %7s %8.2f %7.2f%%\n",
        $rankNo,
        $r['stadium'],
        $all['n'],
        pct($all['head1'],$all['n']),
        pct($all['head3'],$all['n']),
        $r['hit'],
        $r['delta'],
        $r['old_hit'],
        $r['recent_hit'],
        $r['stable'] ? '○' : '-',
        $all['n'] > 0 ? $all['points'] / $all['n'] : 0.0,
        roi($all) ?? 0.0
    );
}

echo "\n判断ポイント:\n";
echo "1. まず買い目的中率の上位/下位を見る。\n";
echo "2. 『両期↑』の場は、1年を前後に割っても相性が安定している候補。\n";
echo "3. ROIは高配当1本で動くため、ここでは相性判定の主指標にしない。\n";
echo "4. 次STEPでは上位場/下位場で、場×コース・決まり手・1逃げ時2/3着・外枠到達・展示/STの効き方を比較する。\n";
