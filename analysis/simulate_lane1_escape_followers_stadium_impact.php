<?php

declare(strict_types=1);

/**
 * 1逃げ時の場別2・3着傾向を、現行Web本命①の相手選びへ加えた場合の影響を検証する。
 *
 * 検証設計
 * - 学習期間: SPLIT_DATE より前の「実際に1コース逃げ」だけで、場別2着/3着コース順位を作る。
 * - 検証期間: SPLIT_DATE 以降の Web本命①レース。
 * - 場別の個別閾値・除外条件は作らない。
 * - 現行final_rank順位と、学習期間の場別コース順位を1:1の順位和で合成する。
 * - 現行honmei_kaiと同じ2着候補数/3着候補数を維持し、点数予算を極力同じにする。
 * - 3着候補は2着候補を必ず含め、現行と同じフォーメーション構造を維持する。
 * - 1点100円でDBの3連単払戻を使い、的中率・平均点数・回収率を比較する。
 *
 * 注意
 * - 場別傾向の記述自体はすでに全期間で確認済みなので、この時系列分割は完全な未観測ホールドアウトではない。
 * - この結果で重み・閾値・場除外を後付け変更しない。候補を固定した後、2026-08-15以降で前方検証する。
 * - 場別分布は「コース」の傾向だが、現行CSVには予想時の全艇進入コース列がないため、
 *   この一次シミュレーションでは艇番=コース（枠なり）として適用する。最終採用前に展示進入対応版で再確認する。
 *
 * Usage:
 * php analysis/simulate_lane1_escape_followers_stadium_impact.php \
 *   analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *   analysis/output/final_prediction_races_fast_cached_20250815_20260814.csv \
 *   analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv
 */

const SPLIT_DATE = '2026-02-15';

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php {$argv[0]} KIMARITE_DATASET_CSV RACES_CSV BOATS_CSV\n");
    exit(1);
}

[$script, $datasetPath, $racesPath, $boatsPath] = $argv;
foreach ([$datasetPath, $racesPath, $boatsPath] as $p) {
    if (!is_file($p)) {
        throw new RuntimeException("必要ファイルがありません: {$p}");
    }
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

function pct(int $n, int $d): float
{
    return $d > 0 ? 100.0 * $n / $d : 0.0;
}

function roi(int $payout, int $investment): float
{
    return $investment > 0 ? 100.0 * $payout / $investment : 0.0;
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

function parseFormation(string $formation): ?array
{
    $parts = explode('-', trim($formation));
    if (count($parts) !== 3) return null;
    $first = array_values(array_unique(array_map('intval', str_split(trim($parts[0])))));
    $second = array_values(array_unique(array_map('intval', str_split(trim($parts[1])))));
    $third = array_values(array_unique(array_map('intval', str_split(trim($parts[2])))));
    $first = array_values(array_filter($first, static fn(int $b): bool => $b >= 1 && $b <= 6));
    $second = array_values(array_filter($second, static fn(int $b): bool => $b >= 1 && $b <= 6));
    $third = array_values(array_filter($third, static fn(int $b): bool => $b >= 1 && $b <= 6));
    if (!$first || !$second || !$third) return null;
    return [$first, $second, $third];
}

function expandFormation(array $first, array $second, array $third): array
{
    $bets = [];
    foreach ($first as $a) {
        foreach ($second as $b) {
            foreach ($third as $c) {
                if ($a === $b || $a === $c || $b === $c) continue;
                $bets[] = "{$a}-{$b}-{$c}";
            }
        }
    }
    $bets = array_values(array_unique($bets));
    sort($bets);
    return $bets;
}

function buildCourseRanks(array $counts): array
{
    $courses = [2,3,4,5,6];
    usort($courses, static function (int $a, int $b) use ($counts): int {
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
        $rows[] = ['lane'=>$lane, 'rank'=>inum($b, 'final_rank', 99)];
    }
    usort($rows, static function(array $a, array $b): int {
        $cmp = $a['rank'] <=> $b['rank'];
        return $cmp !== 0 ? $cmp : ($a['lane'] <=> $b['lane']);
    });
    $rank = [];
    foreach ($rows as $i => $r) $rank[(int)$r['lane']] = $i + 1;
    return $rank;
}

function adjustedCandidates(
    array $boats,
    array $stadiumSecondRank,
    array $stadiumThirdRank,
    int $secondCount,
    int $thirdCount
): array {
    $currentRank = currentFollowerRanks($boats);
    $allowed = [];
    foreach ($boats as $lane => $b) {
        if ($lane === 1) continue;
        if (inum($b, 'kiru') === 1) continue;
        $allowed[] = (int)$lane;
    }

    $secondOrder = $allowed;
    usort($secondOrder, static function(int $a, int $b) use ($currentRank, $stadiumSecondRank): int {
        $sa = ($currentRank[$a] ?? 99) + ($stadiumSecondRank[$a] ?? 99);
        $sb = ($currentRank[$b] ?? 99) + ($stadiumSecondRank[$b] ?? 99);
        if ($sa !== $sb) return $sa <=> $sb;
        $ra = $currentRank[$a] ?? 99;
        $rb = $currentRank[$b] ?? 99;
        return $ra !== $rb ? $ra <=> $rb : $a <=> $b;
    });
    $second = array_slice($secondOrder, 0, min($secondCount, count($secondOrder)));

    $thirdOrder = $allowed;
    usort($thirdOrder, static function(int $a, int $b) use ($currentRank, $stadiumThirdRank): int {
        $sa = ($currentRank[$a] ?? 99) + ($stadiumThirdRank[$a] ?? 99);
        $sb = ($currentRank[$b] ?? 99) + ($stadiumThirdRank[$b] ?? 99);
        if ($sa !== $sb) return $sa <=> $sb;
        $ra = $currentRank[$a] ?? 99;
        $rb = $currentRank[$b] ?? 99;
        return $ra !== $rb ? $ra <=> $rb : $a <=> $b;
    });

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
        'n'=>0, 'changed'=>0,
        'cur_points'=>0, 'new_points'=>0,
        'cur_hit'=>0, 'new_hit'=>0,
        'gained'=>0, 'lost'=>0,
        'cur_invest'=>0, 'new_invest'=>0,
        'cur_payout'=>0, 'new_payout'=>0,
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
        "%-12s N=%5d 変更=%5d | 点数 %5.2f→%5.2f | 的中 %6.2f%%→%6.2f%% (%+d件/%+6.2fpt) | 拾い=%d 落ち=%d | 回収 %6.2f%%→%6.2f%%\n",
        $label,
        $n,
        $s['changed'],
        $curAvg,
        $newAvg,
        pct($s['cur_hit'],$n),
        pct($s['new_hit'],$n),
        $s['new_hit']-$s['cur_hit'],
        pct($s['new_hit'],$n)-pct($s['cur_hit'],$n),
        $s['gained'],
        $s['lost'],
        roi($s['cur_payout'],$s['cur_invest']),
        roi($s['new_payout'],$s['new_invest'])
    );
}

$dataset = readCsvAssoc($datasetPath);
$races = readCsvAssoc($racesPath);
$boatRows = readCsvAssoc($boatsPath);

// 学習期間の場別1逃げフォロワー分布。
$secondCounts = [];
$thirdCounts = [];
$trainN = [];
foreach ($dataset as $row) {
    $date = trim((string)($row['race_date'] ?? ''));
    if ($date === '' || $date >= SPLIT_DATE || !isEscape($row)) continue;
    $stadium = trim((string)($row['stadium_name'] ?? ''));
    if ($stadium === '') continue;
    $c2 = inum($row, 'actual_2nd_course');
    $c3 = inum($row, 'actual_3rd_course');
    if ($c2 >= 2 && $c2 <= 6) $secondCounts[$stadium][$c2] = ($secondCounts[$stadium][$c2] ?? 0) + 1;
    if ($c3 >= 2 && $c3 <= 6) $thirdCounts[$stadium][$c3] = ($thirdCounts[$stadium][$c3] ?? 0) + 1;
    $trainN[$stadium] = ($trainN[$stadium] ?? 0) + 1;
}

$secondRanks = [];
$thirdRanks = [];
foreach ($trainN as $stadium => $n) {
    $secondRanks[$stadium] = buildCourseRanks($secondCounts[$stadium] ?? []);
    $thirdRanks[$stadium] = buildCourseRanks($thirdCounts[$stadium] ?? []);
}

$boatsByRace = [];
foreach ($boatRows as $b) {
    $rc = trim((string)($b['race_code'] ?? ''));
    $lane = inum($b, 'lane_number');
    if ($rc !== '' && $lane >= 1 && $lane <= 6) $boatsByRace[$rc][$lane] = $b;
}

$pdo = getPDO();
$payoutStmt = $pdo->prepare(
    'SELECT trifecta_payout FROM boat_race.race_payouts WHERE race_code = :race_code'
);

$overall = emptyStat();
$byStadium = [];
$validationStart = null;
$validationEnd = null;
$skippedNoTrain = 0;
$skippedStructure = 0;
$skippedPayout = 0;

foreach ($races as $row) {
    $date = trim((string)($row['race_date'] ?? ''));
    if ($date === '' || $date < SPLIT_DATE) continue;
    if (inum($row, 'honmei_head') !== 1) continue;

    $stadium = trim((string)($row['stadium_name'] ?? ''));
    if ($stadium === '' || !isset($secondRanks[$stadium], $thirdRanks[$stadium])) {
        $skippedNoTrain++;
        continue;
    }

    $rc = trim((string)($row['race_code'] ?? ''));
    $actual = trim((string)($row['actual_trifecta'] ?? ''));
    $formation = parseFormation((string)($row['honmei_kai'] ?? ''));
    $boats = $boatsByRace[$rc] ?? [];
    if ($rc === '' || $actual === '' || $formation === null || count($boats) !== 6) {
        $skippedStructure++;
        continue;
    }

    [$first, $curSecond, $curThird] = $formation;
    if ($first !== [1]) {
        $skippedStructure++;
        continue;
    }

    $payoutStmt->execute([':race_code'=>$rc]);
    $payoutRaw = $payoutStmt->fetchColumn();
    if ($payoutRaw === false || $payoutRaw === null) {
        $skippedPayout++;
        continue;
    }
    $payout = (int)$payoutRaw;

    [$newSecond, $newThird] = adjustedCandidates(
        $boats,
        $secondRanks[$stadium],
        $thirdRanks[$stadium],
        count($curSecond),
        count($curThird)
    );

    $curBets = expandFormation([1], $curSecond, $curThird);
    $newBets = expandFormation([1], $newSecond, $newThird);
    if (!$curBets || !$newBets) {
        $skippedStructure++;
        continue;
    }

    $curHit = in_array($actual, $curBets, true);
    $newHit = in_array($actual, $newBets, true);
    $changed = $curBets !== $newBets;

    addStat($overall, count($curBets), count($newBets), $curHit, $newHit, $payout, $changed);
    if (!isset($byStadium[$stadium])) $byStadium[$stadium] = emptyStat();
    addStat($byStadium[$stadium], count($curBets), count($newBets), $curHit, $newHit, $payout, $changed);

    if ($validationStart === null || $date < $validationStart) $validationStart = $date;
    if ($validationEnd === null || $date > $validationEnd) $validationEnd = $date;
}

uasort($byStadium, static fn(array $a, array $b): int => $b['n'] <=> $a['n']);
ksort($trainN);

$trainTotal = array_sum($trainN);
echo str_repeat('=', 170) . "\n";
echo "1逃げ時の場別フォロワー傾向 適用前後シミュレーション（時系列分割）\n";
echo str_repeat('=', 170) . "\n";
echo "学習期間 : データ先頭 ～ " . date('Y-m-d', strtotime(SPLIT_DATE . ' -1 day')) . "\n";
echo "検証期間 : " . ($validationStart ?? '-') . " ～ " . ($validationEnd ?? '-') . "\n";
echo "学習1逃げ: {$trainTotal}R / " . count($trainN) . "場\n";
echo "固定方式 : final_rank順位 + 場別1逃げフォロワー順位（1:1順位和）\n";
echo "点数構造 : 現行honmei_kaiの2着/3着候補数を維持\n";
echo "重要制約 : 艇番=コース（枠なり）仮定。最終採用前に展示進入対応版で再確認する。\n";
echo "注意     : 場別記述は全期間で既に確認済みのため、これは完全未観測ホールドアウトではない。\n";
echo "           この結果を見て重み・閾値・場除外を追加しない。\n\n";

printStat('全24場', $overall);

echo "\n【場別参考】\n";
foreach ($byStadium as $stadium => $s) {
    printStat($stadium, $s);
}

echo "\nスキップ: 学習分布なし={$skippedNoTrain} / 構造不備={$skippedStructure} / 払戻なし={$skippedPayout}\n";
echo "\n判断ポイント:\n";
echo "1. 最優先は全24場で、平均点数を増やさず的中率が改善するか。\n";
echo "2. 回収率も同方向か確認する。拾い的中と落とした的中の件数も見る。\n";
echo "3. 場別結果は参考表示のみ。結果を見て場除外を後付けしない。\n";
echo "4. 改善しても即採用せず、固定したまま2026-08-15以降の前方期間で再検証する。\n";
echo str_repeat('=', 170) . "\n";
