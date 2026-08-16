<?php
declare(strict_types=1);

/**
 * 現行二次評価 vs ST_BAND版 最終比較
 *
 * 目的:
 *   展示タイム・周回・周り足・直線は現行のまま維持し、
 *   展示STだけを現行ルールから ST_BAND へ変更した場合に、
 *   二次評価順位1～6の実績がどう変わるか比較する。
 *
 * ST_BAND:
 *   ST <= 0.00       => 3点
 *   0.00 < ST <= .12 => 5点
 *   .12  < ST <= .20 => 3点
 *   .20  < ST <= .30 => 2点
 *   ST > .30         => 1点
 *
 * 比較方法:
 *   各レースで必ず1～6位を1艇ずつ割り当てる。
 *   同点時は艇番昇順でタイブレーク。
 *   これによりCURRENT/ST_BANDとも各順位N=処理レース数となる。
 *
 * 本番ロジックは変更しない。
 *
 * 実行:
 *   php analysis/second_eval_st_band_compare.php 2026-06-15 2026-07-14
 *   php analysis/second_eval_st_band_compare.php 2026-07-15 2026-08-14
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

function calcStBand(float $st): float
{
    if ($st <= 0.00) return 3.0;
    if ($st <= 0.12) return 5.0;
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

function buildSecondScore(
    int $lane,
    float $exScore,
    float $stScore,
    float $lapScore,
    float $mawariScore,
    float $straightScore
): float {
    $tenkaiMorai = ($lane === 2 || $lane === 4) ? 1.0 : 0.0;

    // 現行 tenji_api.php の構造を展開したもの
    // ex_total = ex + lap + mawari + straight
    // attack   = st + straight
    // stable   = lap + mawari
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

function rate(int $n, int $d): float
{
    return $d > 0 ? $n / $d * 100.0 : 0.0;
}

function avgRankValue(array $b): float
{
    return $b['count'] > 0 ? $b['sum_rank'] / $b['count'] : 0.0;
}

function sortBoatsByScore(array $boats, string $scoreKey): array
{
    usort($boats, function (array $a, array $b) use ($scoreKey): int {
        $sa = (float)$a[$scoreKey];
        $sb = (float)$b[$scoreKey];

        if ($sa === $sb) {
            return (int)$a['lane'] <=> (int)$b['lane'];
        }
        return $sa > $sb ? -1 : 1;
    });
    return $boats;
}

function countTies(array $boats, string $scoreKey): int
{
    $counts = [];
    foreach ($boats as $boat) {
        $key = number_format((float)$boat[$scoreKey], 6, '.', '');
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    foreach ($counts as $count) {
        if ($count >= 2) return 1;
    }
    return 0;
}

$currentByRank = [];
$bandByRank = [];
for ($rank = 1; $rank <= 6; $rank++) {
    $currentByRank[$rank] = newBucket();
    $bandByRank[$rank] = newBucket();
}

$changedTop = [
    'count' => 0,
    'band_better' => 0,
    'current_better' => 0,
    'same_actual_rank' => 0,
    'gain_first' => 0,
    'lose_first' => 0,
];

$positionChanges = 0;
$currentTieRaces = 0;
$bandTieRaces = 0;

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
    $avgEx = (float)$stmtAvg->fetchColumn();
    if ($avgEx <= 0) {
        $skipped++;
        $skipReasons['missing_average']++;
        continue;
    }

    $actual = [];
    foreach ($entries as $entry) {
        $lane = (int)$entry['lane'];
        $raw = $entry['rank'];

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

    $lapVals = [];
    $mawariVals = [];
    $straightVals = [];
    foreach ($exRows as $row) {
        $lapVals[] = (float)$row['lap_time'];
        $mawariVals[] = (float)$row['around_time'];
        $straightVals[] = (float)$row['straight_time'];
    }

    $avgLap = array_sum($lapVals) / 6.0;
    $avgMawari = array_sum($mawariVals) / 6.0;
    $avgStraight = array_sum($straightVals) / 6.0;

    $boats = [];
    foreach ($exRows as $row) {
        $lane = (int)$row['lane'];
        $exScore = calcExScore((float)$row['exhibition_time'] - $avgEx);
        $st = (float)$row['start_timing'];
        $lapScore = calcLapScore((float)$row['lap_time'] - $avgLap);
        $mawariScore = calcMawariScore((float)$row['around_time'] - $avgMawari);
        $straightScore = calcStraightScore((float)$row['straight_time'] - $avgStraight);

        $boats[] = [
            'lane' => $lane,
            'actual_rank' => $actual[$lane],
            'current_score' => buildSecondScore(
                $lane,
                $exScore,
                calcStCurrent($st),
                $lapScore,
                $mawariScore,
                $straightScore
            ),
            'band_score' => buildSecondScore(
                $lane,
                $exScore,
                calcStBand($st),
                $lapScore,
                $mawariScore,
                $straightScore
            ),
        ];
    }

    $currentTieRaces += countTies($boats, 'current_score');
    $bandTieRaces += countTies($boats, 'band_score');

    $currentSorted = sortBoatsByScore($boats, 'current_score');
    $bandSorted = sortBoatsByScore($boats, 'band_score');

    $currentPosByLane = [];
    $bandPosByLane = [];

    foreach ($currentSorted as $idx => $boat) {
        $rank = $idx + 1;
        addBucket($currentByRank[$rank], (float)$boat['actual_rank']);
        $currentPosByLane[(int)$boat['lane']] = $rank;
    }

    foreach ($bandSorted as $idx => $boat) {
        $rank = $idx + 1;
        addBucket($bandByRank[$rank], (float)$boat['actual_rank']);
        $bandPosByLane[(int)$boat['lane']] = $rank;
    }

    foreach (range(1, 6) as $lane) {
        if ($currentPosByLane[$lane] !== $bandPosByLane[$lane]) {
            $positionChanges++;
        }
    }

    $currentTop = $currentSorted[0];
    $bandTop = $bandSorted[0];

    if ((int)$currentTop['lane'] !== (int)$bandTop['lane']) {
        $changedTop['count']++;

        $currentActual = (float)$currentTop['actual_rank'];
        $bandActual = (float)$bandTop['actual_rank'];

        if ($bandActual < $currentActual) $changedTop['band_better']++;
        elseif ($bandActual > $currentActual) $changedTop['current_better']++;
        else $changedTop['same_actual_rank']++;

        if ($bandActual === 1.0 && $currentActual !== 1.0) $changedTop['gain_first']++;
        if ($bandActual !== 1.0 && $currentActual === 1.0) $changedTop['lose_first']++;
    }

    $processed++;
}

echo "========================================\n";
echo "現行二次評価 vs ST_BAND 最終比較\n";
echo "========================================\n";
echo "期間       : {$from} ～ {$to}\n";
echo "対象レース : {$total}\n";
echo "処理レース : {$processed}\n";
echo "スキップ   : {$skipped}\n";
echo "順位方式   : 各レース1～6位を1艇ずつ（同点は艇番昇順）\n";
echo "\n【スキップ理由】\n";
foreach ($skipReasons as $k => $v) {
    printf("%-24s : %d\n", $k, $v);
}

echo "\n========================================\n";
echo "【順位別 成績比較】\n";
echo "========================================\n";

for ($rank = 1; $rank <= 6; $rank++) {
    $c = $currentByRank[$rank];
    $b = $bandByRank[$rank];

    $c1 = rate($c['first'], $c['count']);
    $b1 = rate($b['first'], $b['count']);
    $c2 = rate($c['top2'], $c['count']);
    $b2 = rate($b['top2'], $b['count']);
    $c3 = rate($c['top3'], $c['count']);
    $b3 = rate($b['top3'], $b['count']);
    $ca = avgRankValue($c);
    $ba = avgRankValue($b);

    echo "\n順位 {$rank}\n";
    printf("  CURRENT : N=%d 1着=%6.2f%% 2連=%6.2f%% 3連=%6.2f%% 平均=%.3f\n", $c['count'], $c1, $c2, $c3, $ca);
    printf("  ST_BAND : N=%d 1着=%6.2f%% 2連=%6.2f%% 3連=%6.2f%% 平均=%.3f\n", $b['count'], $b1, $b2, $b3, $ba);
    printf("  差      :       1着=%+6.2fpt 2連=%+6.2fpt 3連=%+6.2fpt 平均=%+.3f\n", $b1 - $c1, $b2 - $c2, $b3 - $c3, $ba - $ca);
}

echo "\n========================================\n";
echo "【1位艇が変わったレース】\n";
echo "========================================\n";
printf("1位変更レース       : %d\n", $changedTop['count']);
printf("ST_BANDの実着順良い : %d\n", $changedTop['band_better']);
printf("CURRENTの実着順良い : %d\n", $changedTop['current_better']);
printf("同着順               : %d\n", $changedTop['same_actual_rank']);
printf("1着を新たに拾った    : %d\n", $changedTop['gain_first']);
printf("現行1着を失った      : %d\n", $changedTop['lose_first']);
printf("1着純増              : %+d\n", $changedTop['gain_first'] - $changedTop['lose_first']);

echo "\n========================================\n";
echo "【順位変動・同点参考】\n";
echo "========================================\n";
printf("全艇順位が変わった延べ数 : %d\n", $positionChanges);
printf("CURRENT 同点ありレース   : %d\n", $currentTieRaces);
printf("ST_BAND 同点ありレース   : %d\n", $bandTieRaces);

echo "\n========================================\n";
echo "判定目安\n";
echo "========================================\n";
echo "・順位1でST_BANDが2期間とも改善\n";
echo "・順位1→6の成績勾配が崩れない\n";
echo "・1位変更レースで1着純増が2期間ともプラス\n";
echo "上記が揃えばST_BANDを本番採用候補とする。\n";

echo "\n========================================\n";
echo "検証終了\n";
echo "========================================\n";
