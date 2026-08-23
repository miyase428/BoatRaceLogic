<?php

declare(strict_types=1);

/**
 * 場別R1補正候補の前方ホールドアウト検証。
 *
 * 固定条件（1年分析後は変更しない）:
 *   R1: 大村・津・宮島 × 現行Web頭 != 1 × 1号艇一次1位
 *       -> 頭を1号艇へ戻す
 *
 * 2026-08-15以降など、候補発見に使っていない前方期間CSVを渡して検証する。
 * 閾値・対象場・条件はこの結果を見て変更しない。
 *
 * Usage:
 * php analysis/validate_stadium_lane1_r1_forward_holdout.php \
 *   analysis/output/kimarite_analysis_dataset_20260815_20260822.csv \
 *   analysis/output/final_prediction_boats_fast_cached_20260815_20260822.csv
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
    $best = 2;
    $bestScore = -INF;
    foreach ([2,3,4] as $course) {
        $s = modelScore($row, $course, $model);
        if ($s > $bestScore || ($s === $bestScore && $course < $best)) {
            $best = $course;
            $bestScore = $s;
        }
    }
    return $best;
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
    $byLane = [];
    foreach ($boats as $b) {
        $lane = inum($b, 'lane_number');
        if ($lane < 1 || $lane > 6) return null;
        $rank[] = $lane;
        $kiru[$lane] = inum($b, 'kiru') === 1;
        $byLane[$lane] = $b;
    }
    return [$rank, $kiru, $byLane];
}

function moveToFront(array $rank, int $target): array
{
    $rank = array_values(array_filter($rank, static fn(int $b): bool => $b !== $target));
    array_unshift($rank, $target);
    return $rank;
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

    // 現行本番の⑤⑥頭補正。
    if ($originalHead === 5 || $originalHead === 6) {
        $head = chooseHead($row, $model);
        $rank = moveToFront($rank, $head);
    }

    // 現行本番のA3/A4/H3（相手順位のみ）。
    if ($originalHead === 1) {
        $a3 = sampleOk($row, 3) && attack($row, 3) >= 15.0;
        $a4 = sampleOk($row, 4) && attack($row, 4) >= 20.0;
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
    return ['n'=>0,'cur_head'=>0,'new_head'=>0,'cur_bet'=>0,'new_bet'=>0];
}

function addStat(array &$s, bool $curHeadHit, bool $newHeadHit, bool $curBetHit, bool $newBetHit): void
{
    $s['n']++;
    $s['cur_head'] += (int)$curHeadHit;
    $s['new_head'] += (int)$newHeadHit;
    $s['cur_bet'] += (int)$curBetHit;
    $s['new_bet'] += (int)$newBetHit;
}

function printStat(string $label, array $s): void
{
    $n = $s['n'];
    printf(
        "%-8s N=%3d | 頭 CURRENT=%6.2f%% NEW=%6.2f%% 差=%+3d件/%+6.2fpt | 買い目 CURRENT=%6.2f%% NEW=%6.2f%% 差=%+3d件/%+6.2fpt\n",
        $label,
        $n,
        pct($s['cur_head'],$n), pct($s['new_head'],$n),
        $s['new_head']-$s['cur_head'], pct($s['new_head'],$n)-pct($s['cur_head'],$n),
        pct($s['cur_bet'],$n), pct($s['new_bet'],$n),
        $s['new_bet']-$s['cur_bet'], pct($s['new_bet'],$n)-pct($s['cur_bet'],$n)
    );
}

$dataset = readCsvAssoc($datasetPath);
$boatRows = readCsvAssoc($boatsPath);
$boatsByRace = [];
foreach ($boatRows as $b) {
    $rc = trim((string)($b['race_code'] ?? ''));
    if ($rc !== '') $boatsByRace[$rc][] = $b;
}

$venues = ['大村','津','宮島'];
$stats = [];
foreach ($venues as $v) $stats[$v] = emptyStat();
$combined = emptyStat();

$formalN = 0;
$reconstructN = 0;
$dates = [];

foreach ($dataset as $row) {
    if (!formal($row)) continue;
    $formalN++;

    $date = trim((string)($row['race_date'] ?? ''));
    if ($date !== '') $dates[] = $date;

    $venue = trim((string)($row['stadium_name'] ?? ''));
    if (!in_array($venue, $venues, true)) continue;

    $rc = trim((string)($row['race_code'] ?? ''));
    $rk = rankAndKiru($boatsByRace[$rc] ?? []);
    if ($rk === null) continue;
    [$baseRank, $kiru, $byLane] = $rk;

    $originalHead = inum($row, 'honmei_head');
    if (($baseRank[0] ?? 0) !== $originalHead) continue;
    $reconstructN++;

    [$curHead, $curRank, $curBet] = currentPrediction($row, $baseRank, $kiru, $model);

    $lane1 = $byLane[1] ?? null;
    if (!is_array($lane1)) continue;
    $firstRank1 = inum($lane1, 'first_rank', 99);

    // 凍結R1条件。
    if ($curHead === 1 || $firstRank1 !== 1) continue;

    $newHead = 1;
    $newRank = moveToFront($curRank, 1);
    $newBet = buildBet($newRank, $kiru, $newHead);

    $actual1 = inum($row, 'actual_1st');
    $curHeadHit = $actual1 === $curHead;
    $newHeadHit = $actual1 === $newHead;
    $curBetHit = betHit($row, $curBet, $curHead);
    $newBetHit = betHit($row, $newBet, $newHead);

    addStat($stats[$venue], $curHeadHit, $newHeadHit, $curBetHit, $newBetHit);
    addStat($combined, $curHeadHit, $newHeadHit, $curBetHit, $newBetHit);
}

sort($dates);
$start = $dates[0] ?? '-';
$end = $dates ? $dates[count($dates)-1] : '-';

echo str_repeat('=', 170) . "\n";
echo "R1 場別1号艇補正候補 前方ホールドアウト検証\n";
echo str_repeat('=', 170) . "\n";
echo "期間: {$start} ～ {$end}\n";
echo "正式対象: {$formalN}R / 重点3場で現行再構成可能: {$reconstructN}R\n";
echo "固定条件: 大村/津/宮島 × 現行Web頭≠1 × 1号艇一次1位 → 1号艇へ戻す\n";
echo "※ この前方結果を見て条件・対象場・閾値は変更しない。\n\n";

foreach ($venues as $v) printStat($v, $stats[$v]);
echo str_repeat('-', 170) . "\n";
printStat('3場合計', $combined);

echo "\n判定目安:\n";
echo "1. 各場はNが少ない可能性があるため、まず3場合計の方向を見る。\n";
echo "2. 頭・買い目ともCURRENTより改善なら、R1の前方再現性を支持。\n";
echo "3. 1週間程度なので、通過しても即本番化せず追加期間を蓄積して最終判断する。\n";
