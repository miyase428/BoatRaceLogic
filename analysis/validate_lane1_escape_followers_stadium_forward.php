<?php

declare(strict_types=1);

/**
 * 1逃げ時の場別フォロワー傾向を、完全前方期間で固定検証する。
 *
 * 学習:
 * - TRAIN_DATASET に含まれる正式対象の「実1コース逃げ」だけで場別2着/3着コース順位を作る。
 * - 重み・閾値・場除外は一切追加しない。
 *
 * 前方検証:
 * - FORWARD_DATASET の正式対象 race_code のうち、FORWARD_RACES で Web本命①のレース。
 * - 現行 final_rank と場別フォロワー順位を 1:1 の順位和で合成。
 * - 現行 honmei_kai の2着/3着候補数を維持する。
 * - 1点100円で的中率・平均点数・回収率・拾い/落ちを比較する。
 *
 * 重要:
 * - 場別分布はコース傾向だが、現CSVには予想時の全艇進入コースがないため艇番=コース（枠なり）仮定。
 * - この前方結果を見て重み・閾値・場除外を後付けしない。
 *
 * Usage:
 * php analysis/validate_lane1_escape_followers_stadium_forward.php \
 *   analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *   analysis/output/kimarite_analysis_dataset_20260823_20260831.csv \
 *   analysis/output/final_prediction_races_fast_cached_20260823_20260831.csv \
 *   analysis/output/final_prediction_boats_fast_cached_20260823_20260831.csv
 */

if ($argc !== 5) {
    fwrite(STDERR, "Usage: php {$argv[0]} TRAIN_DATASET FORWARD_DATASET FORWARD_RACES FORWARD_BOATS\n");
    exit(1);
}

[$script, $trainDatasetPath, $forwardDatasetPath, $racesPath, $boatsPath] = $argv;
foreach ([$trainDatasetPath, $forwardDatasetPath, $racesPath, $boatsPath] as $p) {
    if (!is_file($p)) throw new RuntimeException("必要ファイルがありません: {$p}");
}

require_once __DIR__ . '/../common/db_connect.php';

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

function formal(array $row): bool
{
    return inum($row, 'result_top3_course_complete') === 1
        && inum($row, 'result_boat_match') === 1;
}

function isEscape(array $row): bool
{
    return formal($row)
        && inum($row, 'actual_1st_course') === 1
        && trim((string)($row['winner_technique'] ?? '')) === '逃げ';
}

function pct(int $n, int $d): float
{
    return $d > 0 ? 100.0 * $n / $d : 0.0;
}

function roi(int $payout, int $investment): float
{
    return $investment > 0 ? 100.0 * $payout / $investment : 0.0;
}

function parseFormation(string $formation): ?array
{
    $parts = explode('-', trim($formation));
    if (count($parts) !== 3) return null;
    $out = [];
    foreach ($parts as $part) {
        $a = array_values(array_unique(array_map('intval', str_split(trim($part)))));
        $a = array_values(array_filter($a, static fn(int $b): bool => $b >= 1 && $b <= 6));
        if (!$a) return null;
        $out[] = $a;
    }
    return $out;
}

function expandFormation(array $first, array $second, array $third): array
{
    $bets = [];
    foreach ($first as $a) foreach ($second as $b) foreach ($third as $c) {
        if ($a === $b || $a === $c || $b === $c) continue;
        $bets[] = "{$a}-{$b}-{$c}";
    }
    $bets = array_values(array_unique($bets));
    sort($bets);
    return $bets;
}

function buildCourseRanks(array $counts): array
{
    $courses = [2,3,4,5,6];
    usort($courses, static function(int $a, int $b) use ($counts): int {
        $ca = (int)($counts[$a] ?? 0);
        $cb = (int)($counts[$b] ?? 0);
        if ($ca !== $cb) return $cb <=> $ca;
        return $a <=> $b;
    });
    $rank = [];
    foreach ($courses as $i => $c) $rank[$c] = $i + 1;
    return $rank;
}

function currentFollowerRanks(array $boats): array
{
    $rows = [];
    foreach ($boats as $lane => $b) {
        if ($lane === 1) continue;
        $rows[] = ['lane'=>(int)$lane, 'rank'=>inum($b, 'final_rank', 99)];
    }
    usort($rows, static function(array $a, array $b): int {
        $cmp = $a['rank'] <=> $b['rank'];
        return $cmp !== 0 ? $cmp : ($a['lane'] <=> $b['lane']);
    });
    $rank = [];
    foreach ($rows as $i => $r) $rank[$r['lane']] = $i + 1;
    return $rank;
}

function adjustedCandidates(array $boats, array $secondRank, array $thirdRank, int $secondCount, int $thirdCount): array
{
    $currentRank = currentFollowerRanks($boats);
    $allowed = [];
    foreach ($boats as $lane => $b) {
        $lane = (int)$lane;
        if ($lane === 1 || inum($b, 'kiru') === 1) continue;
        $allowed[] = $lane;
    }

    $sortBy = static function(array $source, array $stadiumRank) use ($currentRank): array {
        usort($source, static function(int $a, int $b) use ($currentRank, $stadiumRank): int {
            $sa = ($currentRank[$a] ?? 99) + ($stadiumRank[$a] ?? 99);
            $sb = ($currentRank[$b] ?? 99) + ($stadiumRank[$b] ?? 99);
            if ($sa !== $sb) return $sa <=> $sb;
            $ra = $currentRank[$a] ?? 99;
            $rb = $currentRank[$b] ?? 99;
            return $ra !== $rb ? $ra <=> $rb : $a <=> $b;
        });
        return $source;
    };

    $secondOrder = $sortBy($allowed, $secondRank);
    $second = array_slice($secondOrder, 0, min($secondCount, count($secondOrder)));

    $thirdOrder = $sortBy($allowed, $thirdRank);
    $third = $second;
    foreach ($thirdOrder as $lane) {
        if (count($third) >= $thirdCount) break;
        if (!in_array($lane, $third, true)) $third[] = $lane;
    }
    return [$second, $third];
}

function emptyStat(): array
{
    return [
        'n'=>0,'changed'=>0,'cur_points'=>0,'new_points'=>0,
        'cur_hit'=>0,'new_hit'=>0,'gained'=>0,'lost'=>0,
        'cur_invest'=>0,'new_invest'=>0,'cur_payout'=>0,'new_payout'=>0,
    ];
}

function addStat(array &$s, int $curPoints, int $newPoints, bool $curHit, bool $newHit, int $payout, bool $changed): void
{
    $s['n']++;
    $s['changed'] += (int)$changed;
    $s['cur_points'] += $curPoints;
    $s['new_points'] += $newPoints;
    $s['cur_hit'] += (int)$curHit;
    $s['new_hit'] += (int)$newHit;
    $s['gained'] += (int)(!$curHit && $newHit);
    $s['lost'] += (int)($curHit && !$newHit);
    $s['cur_invest'] += $curPoints * 100;
    $s['new_invest'] += $newPoints * 100;
    if ($curHit) $s['cur_payout'] += $payout;
    if ($newHit) $s['new_payout'] += $payout;
}

function printStat(string $label, array $s): void
{
    $n = $s['n'];
    $curAvg = $n > 0 ? $s['cur_points'] / $n : 0.0;
    $newAvg = $n > 0 ? $s['new_points'] / $n : 0.0;
    printf(
        "%-12s N=%5d 変更=%4d | 点数 %5.2f→%5.2f | 的中 %6.2f%%→%6.2f%% (%+d件/%+6.2fpt) | 拾い=%d 落ち=%d | 回収 %6.2f%%→%6.2f%%\n",
        $label,$n,$s['changed'],$curAvg,$newAvg,
        pct($s['cur_hit'],$n),pct($s['new_hit'],$n),
        $s['new_hit']-$s['cur_hit'],pct($s['new_hit'],$n)-pct($s['cur_hit'],$n),
        $s['gained'],$s['lost'],
        roi($s['cur_payout'],$s['cur_invest']),roi($s['new_payout'],$s['new_invest'])
    );
}

$train = readCsvAssoc($trainDatasetPath);
$forwardDataset = readCsvAssoc($forwardDatasetPath);
$races = readCsvAssoc($racesPath);
$boatRows = readCsvAssoc($boatsPath);

$secondCounts = [];
$thirdCounts = [];
$trainN = [];
$trainDates = [];
foreach ($train as $row) {
    if (!isEscape($row)) continue;
    $stadium = trim((string)($row['stadium_name'] ?? ''));
    if ($stadium === '') continue;
    $c2 = inum($row, 'actual_2nd_course');
    $c3 = inum($row, 'actual_3rd_course');
    if ($c2 >= 2 && $c2 <= 6) $secondCounts[$stadium][$c2] = ($secondCounts[$stadium][$c2] ?? 0) + 1;
    if ($c3 >= 2 && $c3 <= 6) $thirdCounts[$stadium][$c3] = ($thirdCounts[$stadium][$c3] ?? 0) + 1;
    $trainN[$stadium] = ($trainN[$stadium] ?? 0) + 1;
    $d = trim((string)($row['race_date'] ?? ''));
    if ($d !== '') $trainDates[] = $d;
}
$secondRanks = [];
$thirdRanks = [];
foreach ($trainN as $stadium => $n) {
    $secondRanks[$stadium] = buildCourseRanks($secondCounts[$stadium] ?? []);
    $thirdRanks[$stadium] = buildCourseRanks($thirdCounts[$stadium] ?? []);
}

$formalForward = [];
$forwardDates = [];
foreach ($forwardDataset as $row) {
    if (!formal($row)) continue;
    $rc = trim((string)($row['race_code'] ?? ''));
    if ($rc === '') continue;
    $formalForward[$rc] = true;
    $d = trim((string)($row['race_date'] ?? ''));
    if ($d !== '') $forwardDates[] = $d;
}

$boatsByRace = [];
foreach ($boatRows as $b) {
    $rc = trim((string)($b['race_code'] ?? ''));
    $lane = inum($b, 'lane_number');
    if ($rc !== '' && $lane >= 1 && $lane <= 6) $boatsByRace[$rc][$lane] = $b;
}

$pdo = getPDO();
$payoutStmt = $pdo->prepare('SELECT trifecta_payout FROM boat_race.race_payouts WHERE race_code = :race_code');

$overall = emptyStat();
$byStadium = [];
$skippedNotFormal = 0;
$skippedNoTrain = 0;
$skippedStructure = 0;
$skippedPayout = 0;

foreach ($races as $row) {
    $rc = trim((string)($row['race_code'] ?? ''));
    if ($rc === '' || !isset($formalForward[$rc])) { $skippedNotFormal++; continue; }
    if (inum($row, 'honmei_head') !== 1) continue;

    $stadium = trim((string)($row['stadium_name'] ?? ''));
    if ($stadium === '' || !isset($secondRanks[$stadium], $thirdRanks[$stadium])) {
        $skippedNoTrain++; continue;
    }

    $formation = parseFormation((string)($row['honmei_kai'] ?? ''));
    $boats = $boatsByRace[$rc] ?? [];
    if ($formation === null || count($boats) !== 6) { $skippedStructure++; continue; }
    [$first, $currentSecond, $currentThird] = $formation;
    if ($first !== [1]) { $skippedStructure++; continue; }

    $curBets = expandFormation($first, $currentSecond, $currentThird);
    if (!$curBets) { $skippedStructure++; continue; }

    [$newSecond, $newThird] = adjustedCandidates(
        $boats,$secondRanks[$stadium],$thirdRanks[$stadium],count($currentSecond),count($currentThird)
    );
    $newBets = expandFormation([1], $newSecond, $newThird);
    if (!$newBets) { $skippedStructure++; continue; }

    $actual = trim((string)($row['actual_trifecta'] ?? ''));
    if ($actual === '') { $skippedStructure++; continue; }

    $payoutStmt->execute([':race_code'=>$rc]);
    $payout = $payoutStmt->fetchColumn();
    if ($payout === false || $payout === null) { $skippedPayout++; continue; }
    $payout = (int)$payout;

    $curHit = in_array($actual, $curBets, true);
    $newHit = in_array($actual, $newBets, true);
    $changed = $curBets !== $newBets;

    addStat($overall,count($curBets),count($newBets),$curHit,$newHit,$payout,$changed);
    if (!isset($byStadium[$stadium])) $byStadium[$stadium] = emptyStat();
    addStat($byStadium[$stadium],count($curBets),count($newBets),$curHit,$newHit,$payout,$changed);
}

sort($trainDates);
sort($forwardDates);
$trainStart = $trainDates[0] ?? '-';
$trainEnd = $trainDates ? $trainDates[count($trainDates)-1] : '-';
$forwardStart = $forwardDates[0] ?? '-';
$forwardEnd = $forwardDates ? $forwardDates[count($forwardDates)-1] : '-';
ksort($byStadium, SORT_NATURAL);

echo str_repeat('=', 174) . "\n";
echo "1逃げ時の場別フォロワー傾向 完全前方検証\n";
echo str_repeat('=', 174) . "\n";
echo "学習期間 : {$trainStart} ～ {$trainEnd}\n";
echo "前方期間 : {$forwardStart} ～ {$forwardEnd}\n";
echo "学習1逃げ: " . array_sum($trainN) . "R / " . count($trainN) . "場\n";
echo "固定方式 : final_rank順位 + 場別1逃げフォロワー順位（1:1順位和）\n";
echo "点数構造 : 現行honmei_kaiの2着/3着候補数を維持\n";
echo "制約     : 艇番=コース（枠なり）仮定\n";
echo "禁止     : この結果を見て重み・閾値・場除外を後付けしない\n\n";

printStat('全24場', $overall);

echo "\n【場別参考】\n";
foreach ($byStadium as $stadium => $s) printStat($stadium, $s);

echo "\nスキップ: 正式対象外={$skippedNotFormal} / 学習分布なし={$skippedNoTrain} / 構造不備={$skippedStructure} / 払戻なし={$skippedPayout}\n\n";
echo "判断ポイント:\n";
echo "1. 全24場で平均点数を増やさず、的中率改善が再現するか。\n";
echo "2. 拾い > 落ちが維持されるか。\n";
echo "3. 回収率は短期ブレもあるため、方向と落ち幅を確認する。\n";
echo "4. 場別は参考のみ。全24場共通ルールとして判断する。\n";
echo str_repeat('=', 174) . "\n";
