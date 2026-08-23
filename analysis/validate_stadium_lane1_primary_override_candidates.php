<?php

declare(strict_types=1);

/**
 * 場別1号艇補正候補の再現性診断。
 *
 * ここでは本番補正はしない。前STEPで見えた構造を固定して、
 * OLD6M / RECENT6M / ALL1Y で現行Webとの直接差を見る。
 *
 * 固定候補:
 *  R1: 大村・津・宮島 × 現行Web頭!=1 × 1号艇一次1位 -> 1号艇へ戻す
 *  T4: 戸田 × 現行Web頭=1 × 1号艇一次4位以下 -> 現行順位の最上位非1号艇へ変更
 *  E4: 江戸川 × 現行Web頭=1 × 1号艇一次4位以下 -> 同上（診断のみ）
 *
 * Usage:
 * php analysis/validate_stadium_lane1_primary_override_candidates.php \
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

const OLD_START = '2025-08-15';
const OLD_END = '2026-02-14';
const RECENT_START = '2026-02-15';
const RECENT_END = '2026-08-14';

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

    if ($originalHead === 5 || $originalHead === 6) {
        $head = chooseHead($row, $model);
        $rank = moveToFront($rank, $head);
    }

    // 本番済みA3/A4/H3も順位へ反映（頭自体は変えない）。
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

function periodOf(string $date): string
{
    return $date <= OLD_END ? 'OLD6M' : 'RECENT6M';
}

function printStat(string $label, array $s): void
{
    $n = $s['n'];
    printf("%-10s N=%4d | 頭 CURRENT=%6.2f%% NEW=%6.2f%% 差=%+4d件/%+6.2fpt | 買い目 CURRENT=%6.2f%% NEW=%6.2f%% 差=%+4d件/%+6.2fpt\n",
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

$candidates = [
    'R1_大村' => ['venue'=>'大村','type'=>'rescue'],
    'R1_津'   => ['venue'=>'津','type'=>'rescue'],
    'R1_宮島' => ['venue'=>'宮島','type'=>'rescue'],
    'T4_戸田' => ['venue'=>'戸田','type'=>'demote'],
    'E4_江戸川' => ['venue'=>'江戸川','type'=>'demote'],
];
$stats = [];
foreach ($candidates as $key => $_) {
    $stats[$key] = ['OLD6M'=>emptyStat(),'RECENT6M'=>emptyStat(),'ALL1Y'=>emptyStat()];
}

foreach ($dataset as $row) {
    if (!formal($row)) continue;
    $date = trim((string)($row['race_date'] ?? ''));
    if ($date < OLD_START || $date > RECENT_END) continue;
    $venue = trim((string)($row['stadium_name'] ?? ''));
    $rc = trim((string)($row['race_code'] ?? ''));
    $rk = rankAndKiru($boatsByRace[$rc] ?? []);
    if ($rk === null) continue;
    [$baseRank, $kiru, $byLane] = $rk;
    $originalHead = inum($row, 'honmei_head');
    if (($baseRank[0] ?? 0) !== $originalHead) continue;

    [$curHead, $curRank, $curBet] = currentPrediction($row, $baseRank, $kiru, $model);
    $lane1 = $byLane[1] ?? null;
    if (!is_array($lane1)) continue;
    $firstRank1 = inum($lane1, 'first_rank', 99);

    foreach ($candidates as $key => $def) {
        if ($venue !== $def['venue']) continue;

        $newHead = 0;
        $newRank = [];
        if ($def['type'] === 'rescue') {
            if ($curHead === 1 || $firstRank1 !== 1) continue;
            $newHead = 1;
            $newRank = moveToFront($curRank, 1);
        } else {
            if ($curHead !== 1 || $firstRank1 < 4) continue;
            $alt = 0;
            foreach ($curRank as $b) {
                if ($b !== 1) { $alt = $b; break; }
            }
            if ($alt < 2 || $alt > 6) continue;
            $newHead = $alt;
            $newRank = moveToFront($curRank, $alt);
        }

        $newBet = buildBet($newRank, $kiru, $newHead);
        $actual1 = inum($row, 'actual_1st');
        $curHeadHit = $actual1 === $curHead;
        $newHeadHit = $actual1 === $newHead;
        $curBetHit = betHit($row, $curBet, $curHead);
        $newBetHit = betHit($row, $newBet, $newHead);

        $period = periodOf($date);
        addStat($stats[$key][$period], $curHeadHit, $newHeadHit, $curBetHit, $newBetHit);
        addStat($stats[$key]['ALL1Y'], $curHeadHit, $newHeadHit, $curBetHit, $newBetHit);
    }
}

echo str_repeat('=', 170) . "\n";
echo "場別1号艇補正候補 直接比較（再現性診断）\n";
echo str_repeat('=', 170) . "\n";
echo "R1: 大村/津/宮島 × 現行Web頭≠1 × 1号艇一次1位 → 1号艇へ戻す\n";
echo "T4/E4: 戸田/江戸川 × 現行Web頭=1 × 1号艇一次4位以下 → 現行順位の最上位非1へ変更\n";
echo "※ まだ候補検証。ここで本番ルール化しない。\n\n";

foreach ($stats as $key => $periods) {
    echo str_repeat('-', 170) . "\n【{$key}】\n";
    printStat('OLD6M', $periods['OLD6M']);
    printStat('RECENT6M', $periods['RECENT6M']);
    printStat('ALL1Y', $periods['ALL1Y']);
}

echo "\n判断ポイント:\n";
echo "1. R1はOLD6M/RECENT6Mの両方で頭・買い目がCURRENTより改善するか。\n";
echo "2. T4は戸田の過信を減らせるか。NEWが悪ければ『1を弱める』だけでは足りず、代替頭選定が必要。\n";
echo "3. E4は江戸川で一次順位が使えるかの診断。改善しなくても無理に場補正しない。\n";
