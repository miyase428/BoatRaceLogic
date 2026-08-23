<?php

declare(strict_types=1);

/**
 * 1号艇一次1位・全24場共通の前方ホールドアウト検証。
 *
 * 固定候補:
 *   現行Web頭 != 1 × 1号艇 first_rank == 1 -> 頭を1号艇へ戻す
 *
 * 場名・場1C強さ・追加閾値は使わない。
 * 2026-08-15以降など、候補発見に使っていない前方期間でのみ評価する。
 *
 * Usage:
 * php analysis/validate_lane1_primary1_global_forward_holdout.php \
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
        "%-14s N=%4d | 頭 %6.2f%%→%6.2f%% 差=%+4d/%+6.2fpt | 買い目 %6.2f%%→%6.2f%% 差=%+4d/%+6.2fpt\n",
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

$formalN = 0;
$reconstructN = 0;
$triggerStat = emptyStat();
$overallStat = emptyStat();
$byVenue = [];
$dates = [];

foreach ($dataset as $row) {
    if (!formal($row)) continue;
    $formalN++;

    $date = trim((string)($row['race_date'] ?? ''));
    if ($date !== '') $dates[] = $date;

    $rc = trim((string)($row['race_code'] ?? ''));
    $rk = rankAndKiru($boatsByRace[$rc] ?? []);
    if ($rk === null) continue;
    [$baseRank, $kiru, $byLane] = $rk;

    $originalHead = inum($row, 'honmei_head');
    if (($baseRank[0] ?? 0) !== $originalHead) continue;
    $reconstructN++;

    [$curHead, $curRank, $curBet] = currentPrediction($row, $baseRank, $kiru, $model);
    $newHead = $curHead;
    $newRank = $curRank;
    $newBet = $curBet;

    $lane1 = $byLane[1] ?? null;
    $triggered = false;
    if (is_array($lane1)) {
        $firstRank1 = inum($lane1, 'first_rank', 99);
        if ($curHead !== 1 && $firstRank1 === 1) {
            $triggered = true;
            $newHead = 1;
            $newRank = moveToFront($curRank, 1);
            $newBet = buildBet($newRank, $kiru, $newHead);
        }
    }

    $actual1 = inum($row, 'actual_1st');
    $curHeadHit = $actual1 === $curHead;
    $newHeadHit = $actual1 === $newHead;
    $curBetHit = betHit($row, $curBet, $curHead);
    $newBetHit = betHit($row, $newBet, $newHead);

    addStat($overallStat, $curHeadHit, $newHeadHit, $curBetHit, $newBetHit);

    if ($triggered) {
        addStat($triggerStat, $curHeadHit, $newHeadHit, $curBetHit, $newBetHit);
        $venue = trim((string)($row['stadium_name'] ?? ''));
        if ($venue === '') $venue = '(不明)';
        if (!isset($byVenue[$venue])) $byVenue[$venue] = emptyStat();
        addStat($byVenue[$venue], $curHeadHit, $newHeadHit, $curBetHit, $newBetHit);
    }
}

sort($dates);
$start = $dates[0] ?? '-';
$end = $dates ? $dates[count($dates)-1] : '-';

uasort($byVenue, static fn(array $a, array $b): int => $b['n'] <=> $a['n']);

echo str_repeat('=', 178) . "\n";
echo "全24場 1号艇一次1位レスキュー 前方ホールドアウト\n";
echo str_repeat('=', 178) . "\n";
echo "期間: {$start} ～ {$end}\n";
echo "正式対象: {$formalN}R / 現行再構成可能: {$reconstructN}R\n";
echo "固定条件: 現行Web頭≠1 × 1号艇一次1位 → 1号艇へ戻す（場名・場1C強さ条件なし）\n";
echo "※ この前方結果を見て場・閾値・順位条件を追加しない。\n\n";

printStat('トリガー部分', $triggerStat);
printStat('全再構成レース', $overallStat);

echo "\n【場別トリガー参考】\n";
foreach ($byVenue as $venue => $s) {
    printStat($venue, $s);
}

echo "\n判断ポイント:\n";
echo "1. 最優先はトリガー部分で頭・買い目がCURRENTより改善するか。\n";
echo "2. 次に全再構成レース全体でも的中率が改善するかを見る。\n";
echo "3. 場別Nは小さくなるため、場別結果を見て新しい除外場を作らない。\n";
echo "4. 通過しても前方1週間程度なので即本番化せず、追加期間で同じ固定条件を再検証する。\n";
