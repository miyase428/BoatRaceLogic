<?php

declare(strict_types=1);

/**
 * 1逃げ時の場別フォロワー傾向を、展示進入コースへ対応させて前方検証する。
 *
 * 方針
 * - 学習期間は過去データの「実際に1コース逃げ」で場別2着/3着コース順位を固定する。
 * - 前方期間は Web本命① の正式対象レースを評価する。
 * - 現行final_rank順位 + 場別フォロワー順位を1:1の順位和で合成する。
 * - 場別順位を艇へ割り当てるときは艇番ではなく exhibition_live.entry_course を使う。
 * - 1号艇が展示1コースのときだけ補正を適用し、それ以外は現行買い目を維持する。
 * - 現行honmei_kaiの2着/3着候補数を維持し、点数予算を増やさない。
 * - この結果を見て重み・閾値・場除外を後付けしない。
 *
 * Usage:
 * php analysis/validate_lane1_escape_followers_stadium_forward_exhibition.php \
 *   TRAIN_KIMARITE_DATASET_CSV \
 *   FORWARD_KIMARITE_DATASET_CSV \
 *   FORWARD_RACES_CSV \
 *   FORWARD_BOATS_CSV
 */

if ($argc !== 5) {
    fwrite(STDERR, "Usage: php {$argv[0]} TRAIN_DATASET FORWARD_DATASET FORWARD_RACES FORWARD_BOATS\n");
    exit(1);
}

[, $trainDatasetPath, $forwardDatasetPath, $racesPath, $boatsPath] = $argv;
foreach ([$trainDatasetPath, $forwardDatasetPath, $racesPath, $boatsPath] as $p) {
    if (!is_file($p)) throw new RuntimeException("必要ファイルがありません: {$p}");
}

require_once __DIR__ . '/../common/db_connect.php';

function readCsvAssoc2(string $path): array
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

function inum2(array $row, string $key, int $default = 0): int
{
    $v = $row[$key] ?? null;
    return is_numeric($v) ? (int)$v : $default;
}

function pct2(int $n, int $d): float { return $d > 0 ? 100.0 * $n / $d : 0.0; }
function roi2(int $payout, int $investment): float { return $investment > 0 ? 100.0 * $payout / $investment : 0.0; }

function formal2(array $row): bool
{
    return inum2($row, 'result_top3_course_complete') === 1
        && inum2($row, 'result_boat_match') === 1;
}

function isEscape2(array $row): bool
{
    return formal2($row)
        && inum2($row, 'actual_1st_course') === 1
        && trim((string)($row['winner_technique'] ?? '')) === '逃げ';
}

function parseFormation2(string $formation): ?array
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

function expandFormation2(array $first, array $second, array $third): array
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

function buildCourseRanks2(array $counts): array
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

function currentFollowerRanks2(array $boats): array
{
    $rows = [];
    foreach ($boats as $lane => $b) {
        if ((int)$lane === 1) continue;
        $rows[] = ['lane'=>(int)$lane, 'rank'=>inum2($b, 'final_rank', 99)];
    }
    usort($rows, static function(array $a, array $b): int {
        $c = $a['rank'] <=> $b['rank'];
        return $c !== 0 ? $c : ($a['lane'] <=> $b['lane']);
    });
    $rank = [];
    foreach ($rows as $i => $r) $rank[$r['lane']] = $i + 1;
    return $rank;
}

function adjustedCandidates2(
    array $boats,
    array $entryCourseByLane,
    array $stadiumSecondRank,
    array $stadiumThirdRank,
    int $secondCount,
    int $thirdCount
): array {
    $currentRank = currentFollowerRanks2($boats);
    $allowed = [];
    foreach ($boats as $lane => $b) {
        $lane = (int)$lane;
        if ($lane === 1 || inum2($b, 'kiru') === 1) continue;
        if (!isset($entryCourseByLane[$lane])) continue;
        $allowed[] = $lane;
    }

    $secondOrder = $allowed;
    usort($secondOrder, static function(int $a, int $b) use ($currentRank, $entryCourseByLane, $stadiumSecondRank): int {
        $ca = (int)$entryCourseByLane[$a];
        $cb = (int)$entryCourseByLane[$b];
        $sa = ($currentRank[$a] ?? 99) + ($stadiumSecondRank[$ca] ?? 99);
        $sb = ($currentRank[$b] ?? 99) + ($stadiumSecondRank[$cb] ?? 99);
        if ($sa !== $sb) return $sa <=> $sb;
        $ra = $currentRank[$a] ?? 99;
        $rb = $currentRank[$b] ?? 99;
        return $ra !== $rb ? $ra <=> $rb : $a <=> $b;
    });
    $second = array_slice($secondOrder, 0, min($secondCount, count($secondOrder)));

    $thirdOrder = $allowed;
    usort($thirdOrder, static function(int $a, int $b) use ($currentRank, $entryCourseByLane, $stadiumThirdRank): int {
        $ca = (int)$entryCourseByLane[$a];
        $cb = (int)$entryCourseByLane[$b];
        $sa = ($currentRank[$a] ?? 99) + ($stadiumThirdRank[$ca] ?? 99);
        $sb = ($currentRank[$b] ?? 99) + ($stadiumThirdRank[$cb] ?? 99);
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

function emptyStat2(): array
{
    return [
        'n'=>0,'eligible'=>0,'changed'=>0,
        'cur_points'=>0,'new_points'=>0,
        'cur_hit'=>0,'new_hit'=>0,'gained'=>0,'lost'=>0,
        'cur_invest'=>0,'new_invest'=>0,'cur_payout'=>0,'new_payout'=>0,
    ];
}

function addStat2(array &$s, int $curPoints, int $newPoints, bool $curHit, bool $newHit, int $payout, bool $eligible, bool $changed): void
{
    $s['n']++;
    $s['eligible'] += (int)$eligible;
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

function printStat2(string $label, array $s): void
{
    $n = $s['n'];
    $curAvg = $n > 0 ? $s['cur_points'] / $n : 0.0;
    $newAvg = $n > 0 ? $s['new_points'] / $n : 0.0;
    printf(
        "%-12s N=%5d 対象=%4d 変更=%4d | 点数 %5.2f→%5.2f | 的中 %6.2f%%→%6.2f%% (%+d件/%+6.2fpt) | 拾い=%d 落ち=%d | 回収 %6.2f%%→%6.2f%%\n",
        $label, $n, $s['eligible'], $s['changed'], $curAvg, $newAvg,
        pct2($s['cur_hit'],$n), pct2($s['new_hit'],$n),
        $s['new_hit']-$s['cur_hit'], pct2($s['new_hit'],$n)-pct2($s['cur_hit'],$n),
        $s['gained'], $s['lost'], roi2($s['cur_payout'],$s['cur_invest']), roi2($s['new_payout'],$s['new_invest'])
    );
}

$train = readCsvAssoc2($trainDatasetPath);
$forward = readCsvAssoc2($forwardDatasetPath);
$races = readCsvAssoc2($racesPath);
$boatRows = readCsvAssoc2($boatsPath);

$secondCounts = [];
$thirdCounts = [];
$trainN = [];
$trainStart = null;
$trainEnd = null;
foreach ($train as $row) {
    $date = trim((string)($row['race_date'] ?? ''));
    if ($date !== '') {
        $trainStart = $trainStart === null || $date < $trainStart ? $date : $trainStart;
        $trainEnd = $trainEnd === null || $date > $trainEnd ? $date : $trainEnd;
    }
    if (!isEscape2($row)) continue;
    $stadium = trim((string)($row['stadium_name'] ?? ''));
    if ($stadium === '') continue;
    $c2 = inum2($row, 'actual_2nd_course');
    $c3 = inum2($row, 'actual_3rd_course');
    if ($c2 >= 2 && $c2 <= 6) $secondCounts[$stadium][$c2] = ($secondCounts[$stadium][$c2] ?? 0) + 1;
    if ($c3 >= 2 && $c3 <= 6) $thirdCounts[$stadium][$c3] = ($thirdCounts[$stadium][$c3] ?? 0) + 1;
    $trainN[$stadium] = ($trainN[$stadium] ?? 0) + 1;
}

$secondRanks = [];
$thirdRanks = [];
foreach ($trainN as $stadium => $_) {
    $secondRanks[$stadium] = buildCourseRanks2($secondCounts[$stadium] ?? []);
    $thirdRanks[$stadium] = buildCourseRanks2($thirdCounts[$stadium] ?? []);
}

$formalForward = [];
$forwardStart = null;
$forwardEnd = null;
foreach ($forward as $row) {
    $rc = trim((string)($row['race_code'] ?? ''));
    $date = trim((string)($row['race_date'] ?? ''));
    if ($date !== '') {
        $forwardStart = $forwardStart === null || $date < $forwardStart ? $date : $forwardStart;
        $forwardEnd = $forwardEnd === null || $date > $forwardEnd ? $date : $forwardEnd;
    }
    if ($rc !== '' && formal2($row)) $formalForward[$rc] = true;
}

$boatsByRace = [];
foreach ($boatRows as $b) {
    $rc = trim((string)($b['race_code'] ?? ''));
    $lane = inum2($b, 'lane_number');
    if ($rc !== '' && $lane >= 1 && $lane <= 6) $boatsByRace[$rc][$lane] = $b;
}

$pdo = getPDO();
$payoutStmt = $pdo->prepare('SELECT trifecta_payout FROM boat_race.race_payouts WHERE race_code = :race_code');
$exStmt = $pdo->prepare('SELECT entry_course, player_id FROM boat_race.exhibition_live WHERE race_code = :race_code ORDER BY entry_course');

// race_entry の列名は環境差を吸収して自動判定する。
$colStmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema='boat_race' AND table_name='race_entry'");
$cols = $colStmt ? $colStmt->fetchAll(PDO::FETCH_COLUMN) : [];
$laneCol = null;
foreach (['lane_number','boat_number','lane'] as $c) if (in_array($c, $cols, true)) { $laneCol = $c; break; }
$playerCol = null;
foreach (['player_id','racer_id','registration_number'] as $c) if (in_array($c, $cols, true)) { $playerCol = $c; break; }
if ($laneCol === null || $playerCol === null) {
    throw new RuntimeException('race_entry の艇番/選手ID列を判定できません。columns=' . implode(',', $cols));
}
$entryStmt = $pdo->prepare("SELECT {$laneCol} AS lane_number, {$playerCol} AS player_id FROM boat_race.race_entry WHERE race_code = :race_code");

$overall = emptyStat2();
$byStadium = [];
$skipFormal = 0;
$skipTrain = 0;
$skipStructure = 0;
$skipExhibition = 0;
$skipPayout = 0;

foreach ($races as $row) {
    $rc = trim((string)($row['race_code'] ?? ''));
    if ($rc === '' || !isset($formalForward[$rc])) { $skipFormal++; continue; }
    if (inum2($row, 'honmei_head') !== 1) continue;

    $stadium = trim((string)($row['stadium_name'] ?? ''));
    if (!isset($secondRanks[$stadium], $thirdRanks[$stadium])) { $skipTrain++; continue; }
    if (!isset($boatsByRace[$rc]) || count($boatsByRace[$rc]) < 6) { $skipStructure++; continue; }
    $boats = $boatsByRace[$rc];

    $parsed = parseFormation2((string)($row['honmei_kai'] ?? ''));
    if ($parsed === null || $parsed[0] !== [1]) { $skipStructure++; continue; }
    [$first, $curSecond, $curThird] = $parsed;
    $curBets = expandFormation2($first, $curSecond, $curThird);
    if (!$curBets) { $skipStructure++; continue; }

    // 展示進入: exhibition_live の player_id と race_entry の player_id を突合して艇番へ戻す。
    $exStmt->execute([':race_code'=>$rc]);
    $exRows = $exStmt->fetchAll(PDO::FETCH_ASSOC);
    $entryStmt->execute([':race_code'=>$rc]);
    $entryRows = $entryStmt->fetchAll(PDO::FETCH_ASSOC);
    $laneByPlayer = [];
    foreach ($entryRows as $er) {
        $lane = (int)($er['lane_number'] ?? 0);
        $pid = trim((string)($er['player_id'] ?? ''));
        if ($lane >= 1 && $lane <= 6 && $pid !== '') $laneByPlayer[$pid] = $lane;
    }
    $entryCourseByLane = [];
    foreach ($exRows as $er) {
        $course = (int)($er['entry_course'] ?? 0);
        $pid = trim((string)($er['player_id'] ?? ''));
        $lane = $laneByPlayer[$pid] ?? 0;
        if ($course >= 1 && $course <= 6 && $lane >= 1 && $lane <= 6) $entryCourseByLane[$lane] = $course;
    }
    if (count($entryCourseByLane) < 6 || count(array_unique($entryCourseByLane)) < 6) { $skipExhibition++; continue; }

    $eligible = (($entryCourseByLane[1] ?? 0) === 1);
    $newBets = $curBets;
    if ($eligible) {
        [$newSecond, $newThird] = adjustedCandidates2(
            $boats, $entryCourseByLane,
            $secondRanks[$stadium], $thirdRanks[$stadium],
            count($curSecond), count($curThird)
        );
        $candidate = expandFormation2([1], $newSecond, $newThird);
        if ($candidate) $newBets = $candidate;
    }

    $actual = trim((string)($row['actual_trifecta'] ?? ''));
    if ($actual === '') { $skipStructure++; continue; }

    $payoutStmt->execute([':race_code'=>$rc]);
    $pv = $payoutStmt->fetchColumn();
    if ($pv === false || !is_numeric($pv)) { $skipPayout++; continue; }
    $payout = (int)$pv;

    $curHit = in_array($actual, $curBets, true);
    $newHit = in_array($actual, $newBets, true);
    $changed = ($curBets !== $newBets);

    addStat2($overall, count($curBets), count($newBets), $curHit, $newHit, $payout, $eligible, $changed);
    if (!isset($byStadium[$stadium])) $byStadium[$stadium] = emptyStat2();
    addStat2($byStadium[$stadium], count($curBets), count($newBets), $curHit, $newHit, $payout, $eligible, $changed);
}

ksort($byStadium, SORT_NATURAL);
$totalTrainEscape = array_sum($trainN);

echo str_repeat('=', 174) . "\n";
echo "1逃げ時の場別フォロワー傾向 展示進入対応・完全前方検証\n";
echo str_repeat('=', 174) . "\n";
echo "学習期間 : " . ($trainStart ?? '-') . " ～ " . ($trainEnd ?? '-') . "\n";
echo "前方期間 : " . ($forwardStart ?? '-') . " ～ " . ($forwardEnd ?? '-') . "\n";
echo "学習1逃げ: {$totalTrainEscape}R / " . count($trainN) . "場\n";
echo "固定方式 : final_rank順位 + 場別1逃げフォロワー順位（1:1順位和）\n";
echo "進入対応 : exhibition_live.entry_course を player_id で race_entry の艇番へ対応\n";
echo "適用条件 : Web本命①かつ1号艇の展示進入が1コース。その他は現行買い目維持\n";
echo "点数構造 : 現行honmei_kaiの2着/3着候補数を維持\n";
echo "禁止     : この結果を見て重み・閾値・場除外を後付けしない\n\n";
printStat2('全24場', $overall);
echo "\n【場別参考】\n";
foreach ($byStadium as $stadium => $s) printStat2($stadium, $s);
echo "\nスキップ: 正式対象外={$skipFormal} / 学習分布なし={$skipTrain} / 構造不備={$skipStructure} / 展示進入6艇不完備={$skipExhibition} / 払戻なし={$skipPayout}\n\n";
echo "判断ポイント:\n";
echo "1. 枠なり仮定版と比べ、展示進入対応でも的中率改善が維持されるか。\n";
echo "2. 平均点数が増えず、拾い > 落ちが維持されるか。\n";
echo "3. 回収率の方向も確認する。\n";
echo "4. 場別は参考のみ。全24場共通ルールとして判断する。\n";
echo str_repeat('=', 174) . "\n";
