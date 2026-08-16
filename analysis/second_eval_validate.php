<?php
declare(strict_types=1);

/**
 * BoatRaceLogic - 二次評価 検証プログラム
 *
 * 現在の本番 tenji_api.php の二次評価ロジックを再現し、
 * 過去レースの実着順と比較する。
 *
 * 展示STは検証済みの ST_BAND を使用する。
 *   ST <= 0.00        => 3点
 *   0.00 < ST <= 0.12 => 5点
 *   0.12 < ST <= 0.20 => 3点
 *   0.20 < ST <= 0.30 => 2点
 *   ST > 0.30         => 1点
 *
 * 実行:
 *   php analysis/second_eval_validate.php 2026-06-15 2026-07-14
 *   php analysis/second_eval_validate.php 2026-07-15 2026-08-14
 */

require_once __DIR__ . '/../common/db_connect.php';

const DEFAULT_FROM = '2025-08-01';
const DEFAULT_TO   = '2026-07-31';

$from = $argv[1] ?? DEFAULT_FROM;
$to   = $argv[2] ?? DEFAULT_TO;

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

function calcExhibitionScore(float $diff): float
{
    if ($diff <= -0.10) return 5.0;
    if ($diff <= -0.05) return 4.0;
    if ($diff <=  0.05) return 3.0;
    if ($diff <=  0.10) return 2.0;
    return 1.0;
}

function calcStScore(float $st): float
{
    if ($st <= 0.00) return 3.0;
    if ($st <= 0.12) return 5.0;
    if ($st <= 0.20) return 3.0;
    if ($st <= 0.30) return 2.0;
    return 1.0;
}

function calcLapScore(float $lap, float $avgLap): float
{
    $diff = $lap - $avgLap;
    if ($diff <= -0.30) return 5.0;
    if ($diff <= -0.10) return 4.0;
    if ($diff <=  0.10) return 3.0;
    if ($diff <=  0.30) return 2.0;
    return 1.0;
}

function calcMawariScore(float $mawari, float $avgMawari): float
{
    $diff = $mawari - $avgMawari;
    if ($diff <= -0.20) return 5.0;
    if ($diff <= -0.05) return 4.0;
    if ($diff <=  0.05) return 3.0;
    if ($diff <=  0.20) return 2.0;
    return 1.0;
}

function calcStraightScore(float $straight, float $avgStraight): float
{
    $diff = $straight - $avgStraight;
    if ($diff <= -0.04) return 5.0;
    if ($diff <= -0.01) return 4.0;
    if ($diff <=  0.01) return 3.0;
    if ($diff <=  0.04) return 2.0;
    return 1.0;
}

function calcSecondEval(
    int $lane,
    float $exhibition,
    float $avgExhibition,
    float $st,
    float $lap,
    float $avgLap,
    float $mawari,
    float $avgMawari,
    float $straight,
    float $avgStraight
): array {
    $exScore = calcExhibitionScore($exhibition - $avgExhibition);
    $stScore = calcStScore($st);
    $lapScore = calcLapScore($lap, $avgLap);
    $mawariScore = calcMawariScore($mawari, $avgMawari);
    $straightScore = calcStraightScore($straight, $avgStraight);

    $exTotal = $exScore + $lapScore + $mawariScore + $straightScore;
    $attackPotential = $stScore + $straightScore;
    $stableScore = $lapScore + $mawariScore;
    $exSougou = $exTotal + $attackPotential + $stableScore;
    $typeHosei = 0.0;
    $tenkaiMorai = ($lane === 2 || $lane === 4) ? 1.0 : 0.0;
    $finalSecondScore = $exSougou + $typeHosei + $tenkaiMorai;

    return [
        'ex_score' => $exScore,
        'st_score' => $stScore,
        'lap_score' => $lapScore,
        'mawari_score' => $mawariScore,
        'straight_score' => $straightScore,
        'final_2nd_score' => $finalSecondScore,
    ];
}

function assignCompetitionRanks(array &$boats): void
{
    usort($boats, function (array $a, array $b): int {
        if ((float)$a['final_2nd_score'] === (float)$b['final_2nd_score']) {
            return (int)$a['lane'] <=> (int)$b['lane'];
        }
        return (float)$a['final_2nd_score'] > (float)$b['final_2nd_score'] ? -1 : 1;
    });

    $previousScore = null;
    $rank = 0;
    foreach ($boats as $index => &$boat) {
        $score = (float)$boat['final_2nd_score'];
        $position = $index + 1;
        if ($previousScore === null || $score !== $previousScore) {
            $rank = $position;
            $previousScore = $score;
        }
        $boat['score_rank'] = $rank;
    }
    unset($boat);
}

function newBucket(): array
{
    return [
        'count' => 0,
        'first' => 0,
        'second' => 0,
        'third' => 0,
        'top2' => 0,
        'top3' => 0,
        'sum_rank' => 0.0,
    ];
}

function addBucket(array &$bucket, float $actualRank): void
{
    $bucket['count']++;
    if ($actualRank === 1.0) $bucket['first']++;
    if ($actualRank === 2.0) $bucket['second']++;
    if ($actualRank === 3.0) $bucket['third']++;
    if ($actualRank >= 1.0 && $actualRank <= 2.0) $bucket['top2']++;
    if ($actualRank >= 1.0 && $actualRank <= 3.0) $bucket['top3']++;
    $bucket['sum_rank'] += $actualRank;
}

function pct(int $n, int $d): float
{
    return $d > 0 ? $n / $d * 100.0 : 0.0;
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

$byRank = [];
for ($rank = 1; $rank <= 6; $rank++) {
    $byRank[$rank] = newBucket();
}

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
    $avgExhibition = (float)$stmtAvg->fetchColumn();
    if ($avgExhibition <= 0) {
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
        } elseif (in_array((string)$raw, ['1', '2', '3', '4', '5', '6'], true)) {
            $actual[$lane] = (float)$raw;
        } else {
            $actual[$lane] = null;
        }
    }

    $invalidRank = false;
    foreach (range(1, 6) as $lane) {
        if (!array_key_exists($lane, $actual) || $actual[$lane] === null) {
            $invalidRank = true;
            break;
        }
    }
    if ($invalidRank) {
        $skipped++;
        $skipReasons['invalid_rank']++;
        continue;
    }

    $lapValues = array_map(fn(array $r): float => (float)$r['lap_time'], $exRows);
    $mawariValues = array_map(fn(array $r): float => (float)$r['around_time'], $exRows);
    $straightValues = array_map(fn(array $r): float => (float)$r['straight_time'], $exRows);

    $avgLap = array_sum($lapValues) / 6.0;
    $avgMawari = array_sum($mawariValues) / 6.0;
    $avgStraight = array_sum($straightValues) / 6.0;

    $boats = [];
    foreach ($exRows as $row) {
        $lane = (int)$row['lane'];
        $score = calcSecondEval(
            $lane,
            (float)$row['exhibition_time'],
            $avgExhibition,
            (float)$row['start_timing'],
            (float)$row['lap_time'],
            $avgLap,
            (float)$row['around_time'],
            $avgMawari,
            (float)$row['straight_time'],
            $avgStraight
        );

        $boats[] = array_merge([
            'lane' => $lane,
            'actual_rank' => $actual[$lane],
        ], $score);
    }

    assignCompetitionRanks($boats);

    foreach ($boats as $boat) {
        $rank = (int)$boat['score_rank'];
        if ($rank >= 1 && $rank <= 6) {
            addBucket($byRank[$rank], (float)$boat['actual_rank']);
        }
    }

    $processed++;
}

echo "========================================\n";
echo "二次評価 健康診断（ST_BAND本番ロジック）\n";
echo "========================================\n";
echo "期間       : {$from} ～ {$to}\n";
echo "対象レース : {$total}\n";
echo "処理レース : {$processed}\n";
echo "スキップ   : {$skipped}\n";
echo "順位方式   : 競争順位（同点は同順位）\n";
echo "\n【スキップ理由】\n";
foreach ($skipReasons as $key => $value) {
    printf("%-24s : %d\n", $key, $value);
}

echo "\n========================================\n";
echo "【順位別 成績】\n";
echo "========================================\n";
for ($rank = 1; $rank <= 6; $rank++) {
    $b = $byRank[$rank];
    $n = $b['count'];
    $avg = $n > 0 ? $b['sum_rank'] / $n : 0.0;

    printf(
        "順位%d N=%5d 1着=%6.2f%% 2着=%6.2f%% 3着=%6.2f%% 2連=%6.2f%% 3連=%6.2f%% 平均=%.3f\n",
        $rank,
        $n,
        pct($b['first'], $n),
        pct($b['second'], $n),
        pct($b['third'], $n),
        pct($b['top2'], $n),
        pct($b['top3'], $n),
        $avg
    );
}

echo "\nST判定 : <=0.00=3 / <=0.12=5 / <=0.20=3 / <=0.30=2 / >0.30=1\n";
echo "========================================\n";
echo "検証終了\n";
echo "========================================\n";