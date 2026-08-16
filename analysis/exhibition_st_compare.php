<?php
declare(strict_types=1);

/**
 * 展示タイム基準 × 展示STロジック 比較検証
 *
 * 目的:
 *  1. 展示タイム評価を
 *     A) 現行: 場6か月平均との差
 *     B) 候補: 今回レース6艇平均との差
 *     で比較する。
 *  2. 展示STについて
 *     A) 現行ルール
 *     B) 帯域型（0.00～0.12を高評価、早すぎは中立）
 *     C) 減点型（0.20までは中立、遅い時だけ減点）
 *     D) ST不使用
 *     を比較する。
 *  3. 各案を二次評価全体へ組み込んだ時の「評価1位」の実績を比較する。
 *
 * 本番ロジックは変更しない。
 *
 * 実行例:
 *   php analysis/exhibition_st_compare.php 2026-06-15 2026-07-14
 *   php analysis/exhibition_st_compare.php 2026-07-15 2026-08-14
 */

require_once __DIR__ . '/../common/db_connect.php';

$from = $argv[1] ?? '2026-06-15';
$to   = $argv[2] ?? '2026-07-14';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    fwrite(STDERR, "日付は YYYY-MM-DD 形式で指定してください。\n");
    exit(1);
}
if ($from > $to) {
    fwrite(STDERR, "開始日は終了日以前にしてください。\n");
    exit(1);
}

try {
    $pdo = getPDO();
} catch (Throwable $e) {
    fwrite(STDERR, "DB接続エラー: {$e->getMessage()}\n");
    exit(1);
}

// ------------------------------------------------------------
// 現行の基本スコア
// ------------------------------------------------------------
function calcExScore(float $diff): float
{
    if ($diff <= -0.10) return 5.0;
    if ($diff <= -0.05) return 4.0;
    if ($diff <=  0.05) return 3.0;
    if ($diff <=  0.10) return 2.0;
    return 1.0;
}

function calcStCurrent(float $st): float
{
    if ($st <= -0.05) return 1.0;
    if ($st < 0.00)   return 2.0;
    if ($st <= 0.05)  return 5.0;
    if ($st <= 0.12)  return 4.0;
    if ($st <= 0.20)  return 2.0;
    return 1.0;
}

// 仮説B: 0.00～0.12を「良好帯域」として同じ最高評価。
// 展示F側は本番Fではないため、過度に罰せず中立3点。
function calcStBand(float $st): float
{
    if ($st <= 0.00) return 3.0;
    if ($st <= 0.12) return 5.0;
    if ($st <= 0.20) return 3.0;
    if ($st <= 0.30) return 2.0;
    return 1.0;
}

// 仮説C: STは「速いほど加点」には使わず、遅い時だけ減点する。
function calcStPenaltyOnly(float $st): float
{
    if ($st <= 0.20) return 3.0;
    if ($st <= 0.30) return 2.0;
    return 1.0;
}

function calcLapScore(float $diff): float
{
    if ($diff <= -0.30) return 5.0;
    if ($diff <= -0.10) return 4.0;
    if ($diff <=  0.10) return 3.0;
    if ($diff <=  0.30) return 2.0;
    return 1.0;
}

function calcMawariScore(float $diff): float
{
    if ($diff <= -0.20) return 5.0;
    if ($diff <= -0.05) return 4.0;
    if ($diff <=  0.05) return 3.0;
    if ($diff <=  0.20) return 2.0;
    return 1.0;
}

function calcStraightScore(float $diff): float
{
    if ($diff <= -0.04) return 5.0;
    if ($diff <= -0.01) return 4.0;
    if ($diff <=  0.01) return 3.0;
    if ($diff <=  0.04) return 2.0;
    return 1.0;
}

// 現行二次評価の構造:
// ex_total = ex + lap + mawari + straight
// attack   = st + straight
// stable   = lap + mawari
// final    = ex + st + 2*lap + 2*mawari + 2*straight + 展開補正
function buildSecondScore(
    int $lane,
    float $exScore,
    float $stScore,
    float $lapScore,
    float $mawariScore,
    float $straightScore
): float {
    $tenkaiMorai = ($lane === 2 || $lane === 4) ? 1.0 : 0.0;

    return $exScore
        + $stScore
        + 2.0 * $lapScore
        + 2.0 * $mawariScore
        + 2.0 * $straightScore
        + $tenkaiMorai;
}

function newBucket(): array
{
    return [
        'count' => 0,
        'first' => 0,
        'top2' => 0,
        'top3' => 0,
        'sum_rank' => 0.0,
    ];
}

function addBucket(array &$b, float $rank): void
{
    $b['count']++;
    if ($rank === 1.0) $b['first']++;
    if ($rank >= 1.0 && $rank <= 2.0) $b['top2']++;
    if ($rank >= 1.0 && $rank <= 3.0) $b['top3']++;
    $b['sum_rank'] += $rank;
}

function pct(int $n, int $d): string
{
    return $d > 0 ? number_format($n / $d * 100, 2) . '%' : '-';
}

function avgRank(array $b): string
{
    return $b['count'] > 0 ? number_format($b['sum_rank'] / $b['count'], 3) : '-';
}

function pickTopLane(array $scores): int
{
    // 本番で同点時に不定にならないよう、比較用では艇番昇順をタイブレークにする。
    uasort($scores, function (float $a, float $b): int {
        if ($a === $b) return 0;
        return $a > $b ? -1 : 1;
    });

    $max = max($scores);
    $candidates = [];
    foreach ($scores as $lane => $score) {
        if ($score === $max) $candidates[] = (int)$lane;
    }
    sort($candidates, SORT_NUMERIC);
    return $candidates[0];
}

function newCompare(): array
{
    return [
        'changed' => 0,
        'new_better' => 0,
        'current_better' => 0,
        'same_rank' => 0,
        'gain_first' => 0,
        'lose_first' => 0,
    ];
}

$variants = [
    'CURRENT' => '現行: 場6か月平均との差 + 現行ST',
    'EX_RACE' => '展示タイムだけ今回6艇平均との差 + 現行ST',
    'ST_BAND' => '場6か月平均との差 + ST帯域型',
    'ST_PENALTY' => '場6か月平均との差 + ST減点型',
    'NO_ST' => '場6か月平均との差 + ST不使用',
    'EX_RACE_ST_BAND' => '今回6艇平均との差 + ST帯域型',
    'EX_RACE_ST_PENALTY' => '今回6艇平均との差 + ST減点型',
    'EX_RACE_NO_ST' => '今回6艇平均との差 + ST不使用',
];

$stats = [];
$compare = [];
foreach ($variants as $key => $_) {
    $stats[$key] = newBucket();
    if ($key !== 'CURRENT') $compare[$key] = newCompare();
}

// ST候補そのものの点数別実績も出す
$stRuleStats = [
    'CURRENT' => [],
    'BAND' => [],
    'PENALTY' => [],
];
foreach ($stRuleStats as $rule => $_) {
    for ($s = 5; $s >= 1; $s--) {
        $stRuleStats[$rule][$s] = newBucket();
    }
}

$sqlRaces = <<<SQL
SELECT DISTINCT re.race_code, re.race_date
FROM boat_race.race_entry re
WHERE re.race_date BETWEEN :from_date AND :to_date
ORDER BY re.race_date, re.race_code
SQL;
$stmtRaces = $pdo->prepare($sqlRaces);
$stmtRaces->execute([':from_date' => $from, ':to_date' => $to]);
$races = $stmtRaces->fetchAll(PDO::FETCH_ASSOC);

$sqlEntry = <<<SQL
SELECT re.lane_number AS lane, re.player_id, rrd.rank
FROM boat_race.race_entry re
LEFT JOIN boat_race.race_result_detail rrd
  ON rrd.race_code = re.race_code
 AND rrd.player_id = re.player_id
WHERE re.race_code = :race_code
ORDER BY re.lane_number
SQL;
$stmtEntry = $pdo->prepare($sqlEntry);

$sqlEx = <<<SQL
SELECT re.lane_number AS lane,
       el.exhibition_time,
       el.start_timing,
       el.lap_time,
       el.around_time,
       el.straight_time
FROM boat_race.exhibition_live el
JOIN boat_race.race_entry re
  ON el.race_code = re.race_code
 AND el.player_id = re.player_id
WHERE el.race_code = :race_code
ORDER BY re.lane_number
SQL;
$stmtEx = $pdo->prepare($sqlEx);

$sqlName = <<<SQL
SELECT stadium_name
FROM boat_race.stadium_master
WHERE stadium_code = :jyo
LIMIT 1
SQL;
$stmtName = $pdo->prepare($sqlName);

$sqlAvg = <<<SQL
SELECT avg_exhibition_time_6m
FROM boat_race.exhibition_avg_6m
WHERE stadium_name = :stadium_name
LIMIT 1
SQL;
$stmtAvg = $pdo->prepare($sqlAvg);

$total = count($races);
$processed = 0;
$skipped = 0;
$skipReasons = [
    'not_6_boats' => 0,
    'missing_exhibition' => 0,
    'missing_average' => 0,
    'invalid_rank' => 0,
];

foreach ($races as $race) {
    $raceCode = (string)$race['race_code'];

    $stmtEntry->execute([':race_code' => $raceCode]);
    $entries = $stmtEntry->fetchAll(PDO::FETCH_ASSOC);
    if (count($entries) !== 6) {
        $skipped++;
        $skipReasons['not_6_boats']++;
        continue;
    }

    $stmtEx->execute([':race_code' => $raceCode]);
    $exRows = $stmtEx->fetchAll(PDO::FETCH_ASSOC);
    if (count($exRows) !== 6) {
        $skipped++;
        $skipReasons['missing_exhibition']++;
        continue;
    }

    $jyo = substr($raceCode, 8, 3);
    $stmtName->execute([':jyo' => $jyo]);
    $stadiumName = $stmtName->fetchColumn();
    if (!$stadiumName) {
        $skipped++;
        $skipReasons['missing_average']++;
        continue;
    }

    $stmtAvg->execute([':stadium_name' => $stadiumName]);
    $avgEx6m = (float)$stmtAvg->fetchColumn();
    if ($avgEx6m <= 0) {
        $skipped++;
        $skipReasons['missing_average']++;
        continue;
    }

    $actual = [];
    foreach ($entries as $e) {
        $lane = (int)$e['lane'];
        $raw = $e['rank'];
        if ($raw === null || $raw === '') {
            $actual[$lane] = 5.5;
        } elseif (in_array((string)$raw, ['1','2','3','4','5','6'], true)) {
            $actual[$lane] = (float)$raw;
        } else {
            $actual[$lane] = null;
        }
    }

    $invalid = false;
    foreach (range(1, 6) as $lane) {
        if (!array_key_exists($lane, $actual) || $actual[$lane] === null) {
            $invalid = true;
            break;
        }
    }
    if ($invalid) {
        $skipped++;
        $skipReasons['invalid_rank']++;
        continue;
    }

    $exVals = $lapVals = $mawariVals = $straightVals = [];
    foreach ($exRows as $r) {
        $exVals[] = (float)$r['exhibition_time'];
        $lapVals[] = (float)$r['lap_time'];
        $mawariVals[] = (float)$r['around_time'];
        $straightVals[] = (float)$r['straight_time'];
    }

    $avgExRace = array_sum($exVals) / 6.0;
    $avgLap = array_sum($lapVals) / 6.0;
    $avgMawari = array_sum($mawariVals) / 6.0;
    $avgStraight = array_sum($straightVals) / 6.0;

    $scoresByVariant = [];
    foreach ($variants as $key => $_) $scoresByVariant[$key] = [];

    foreach ($exRows as $r) {
        $lane = (int)$r['lane'];
        $exTime = (float)$r['exhibition_time'];
        $st = (float)$r['start_timing'];
        $lap = (float)$r['lap_time'];
        $mawari = (float)$r['around_time'];
        $straight = (float)$r['straight_time'];

        $exCurrent = calcExScore($exTime - $avgEx6m);
        $exRace = calcExScore($exTime - $avgExRace);
        $stCurrent = calcStCurrent($st);
        $stBand = calcStBand($st);
        $stPenalty = calcStPenaltyOnly($st);
        $lapScore = calcLapScore($lap - $avgLap);
        $mawariScore = calcMawariScore($mawari - $avgMawari);
        $straightScore = calcStraightScore($straight - $avgStraight);

        $scoresByVariant['CURRENT'][$lane] = buildSecondScore($lane, $exCurrent, $stCurrent, $lapScore, $mawariScore, $straightScore);
        $scoresByVariant['EX_RACE'][$lane] = buildSecondScore($lane, $exRace, $stCurrent, $lapScore, $mawariScore, $straightScore);
        $scoresByVariant['ST_BAND'][$lane] = buildSecondScore($lane, $exCurrent, $stBand, $lapScore, $mawariScore, $straightScore);
        $scoresByVariant['ST_PENALTY'][$lane] = buildSecondScore($lane, $exCurrent, $stPenalty, $lapScore, $mawariScore, $straightScore);
        $scoresByVariant['NO_ST'][$lane] = buildSecondScore($lane, $exCurrent, 0.0, $lapScore, $mawariScore, $straightScore);
        $scoresByVariant['EX_RACE_ST_BAND'][$lane] = buildSecondScore($lane, $exRace, $stBand, $lapScore, $mawariScore, $straightScore);
        $scoresByVariant['EX_RACE_ST_PENALTY'][$lane] = buildSecondScore($lane, $exRace, $stPenalty, $lapScore, $mawariScore, $straightScore);
        $scoresByVariant['EX_RACE_NO_ST'][$lane] = buildSecondScore($lane, $exRace, 0.0, $lapScore, $mawariScore, $straightScore);

        addBucket($stRuleStats['CURRENT'][(int)$stCurrent], $actual[$lane]);
        addBucket($stRuleStats['BAND'][(int)$stBand], $actual[$lane]);
        addBucket($stRuleStats['PENALTY'][(int)$stPenalty], $actual[$lane]);
    }

    $top = [];
    foreach ($scoresByVariant as $key => $laneScores) {
        $top[$key] = pickTopLane($laneScores);
        addBucket($stats[$key], $actual[$top[$key]]);
    }

    $currentLane = $top['CURRENT'];
    $currentRank = $actual[$currentLane];

    foreach ($compare as $key => &$cmp) {
        $newLane = $top[$key];
        if ($newLane === $currentLane) continue;

        $cmp['changed']++;
        $newRank = $actual[$newLane];

        if ($newRank < $currentRank) $cmp['new_better']++;
        elseif ($newRank > $currentRank) $cmp['current_better']++;
        else $cmp['same_rank']++;

        if ($newRank === 1.0 && $currentRank !== 1.0) $cmp['gain_first']++;
        if ($newRank !== 1.0 && $currentRank === 1.0) $cmp['lose_first']++;
    }
    unset($cmp);

    $processed++;
}

echo "========================================\n";
echo "展示タイム基準 × 展示ST 比較検証\n";
echo "========================================\n";
echo "期間       : {$from} ～ {$to}\n";
echo "対象レース : {$total}\n";
echo "処理レース : {$processed}\n";
echo "スキップ   : {$skipped}\n";
echo "\n【スキップ理由】\n";
foreach ($skipReasons as $k => $v) {
    printf("%-24s : %d\n", $k, $v);
}

echo "\n========================================\n";
echo "【二次評価1位の実績比較】\n";
echo "========================================\n";
foreach ($variants as $key => $label) {
    $b = $stats[$key];
    echo "\n{$key}\n";
    echo "  {$label}\n";
    printf(
        "  N=%d 1着=%s 2連=%s 3連=%s 平均着順=%s\n",
        $b['count'], pct($b['first'], $b['count']), pct($b['top2'], $b['count']), pct($b['top3'], $b['count']), avgRank($b)
    );
}

echo "\n========================================\n";
echo "【現行から1位艇が変わったレースだけ比較】\n";
echo "========================================\n";
foreach ($compare as $key => $c) {
    $netFirst = $c['gain_first'] - $c['lose_first'];
    echo "\n{$key} : {$variants[$key]}\n";
    printf("  1位変更レース : %d\n", $c['changed']);
    printf("  新案の方が実着順良い : %d\n", $c['new_better']);
    printf("  現行の方が実着順良い : %d\n", $c['current_better']);
    printf("  同着順             : %d\n", $c['same_rank']);
    printf("  1着を新たに拾った   : %d\n", $c['gain_first']);
    printf("  現行1着を失った     : %d\n", $c['lose_first']);
    printf("  1着純増             : %+d\n", $netFirst);
}

echo "\n========================================\n";
echo "【STルール単体：点数別実績】\n";
echo "========================================\n";

$stLabels = [
    'CURRENT' => '現行ST',
    'BAND' => '帯域型: <=0.00=3, 0.00～0.12=5, 0.12～0.20=3, 0.20～0.30=2, >0.30=1',
    'PENALTY' => '減点型: <=0.20=3, 0.20～0.30=2, >0.30=1',
];

foreach ($stLabels as $rule => $label) {
    echo "\n{$rule} : {$label}\n";
    for ($s = 5; $s >= 1; $s--) {
        $b = $stRuleStats[$rule][$s];
        if ($b['count'] === 0) continue;
        printf(
            "  %d点 N=%6d 1着=%6s 2連=%6s 3連=%6s 平均=%s\n",
            $s, $b['count'], pct($b['first'], $b['count']), pct($b['top2'], $b['count']), pct($b['top3'], $b['count']), avgRank($b)
        );
    }
}

echo "\n========================================\n";
echo "検証終了\n";
echo "========================================\n";
