<?php
declare(strict_types=1);

/**
 * 展示5項目 個別評価・閾値検証（艇番別付き）
 *
 * 目的:
 *   現行二次評価で使用している
 *   - 展示タイム
 *   - 展示ST
 *   - 周回
 *   - 周り足
 *   - 直線
 *   の 1～5点判定（STは現行3点なし）が、実着順と整合しているか確認する。
 *
 * 出力:
 *   1) 現行スコア別成績
 *   2) 1～6号艇別 × 現行スコア別成績
 *   3) 元値/平均との差を細分化した区間別成績
 *
 * 実行:
 *   php analysis/exhibition_item_validate.php 2026-06-15 2026-07-14
 *   php analysis/exhibition_item_validate.php 2026-07-15 2026-08-14
 *
 * 艇番を限定する場合:
 *   php analysis/exhibition_item_validate.php 2026-07-15 2026-08-14 1
 */

require_once __DIR__ . '/../common/db_connect.php';

$from = $argv[1] ?? '2026-06-15';
$to   = $argv[2] ?? '2026-07-14';
$laneFilter = $argv[3] ?? 'all';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    fwrite(STDERR, "日付は YYYY-MM-DD 形式で指定してください。\n");
    exit(1);
}
if ($from > $to) {
    fwrite(STDERR, "開始日は終了日以前にしてください。\n");
    exit(1);
}
if ($laneFilter !== 'all' && (!ctype_digit((string)$laneFilter) || (int)$laneFilter < 1 || (int)$laneFilter > 6)) {
    fwrite(STDERR, "艇番指定は 1～6 または省略してください。\n");
    exit(1);
}
$laneFilter = $laneFilter === 'all' ? null : (int)$laneFilter;

try {
    $pdo = getPDO();
} catch (Throwable $e) {
    fwrite(STDERR, "DB接続エラー: {$e->getMessage()}\n");
    exit(1);
}

// ============================================================
// 現行スコア判定（second_eval_validate.php / tenji_api.php と同じ）
// ============================================================
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
    if ($st <= -0.05) return 1.0;
    if ($st < 0)      return 2.0;
    if ($st <= 0.05)  return 5.0;
    if ($st <= 0.12)  return 4.0;
    if ($st <= 0.20)  return 2.0;
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

// ============================================================
// 集計ヘルパー
// ============================================================
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

function addBucket(array &$bucket, float $rank): void
{
    $bucket['count']++;
    if ($rank === 1.0) $bucket['first']++;
    if ($rank >= 1.0 && $rank <= 2.0) $bucket['top2']++;
    if ($rank >= 1.0 && $rank <= 3.0) $bucket['top3']++;
    $bucket['sum_rank'] += $rank;
}

function pct(int $n, int $d): string
{
    return $d > 0 ? number_format($n / $d * 100, 2) . '%' : '-';
}

function avgRank(array $bucket): string
{
    return $bucket['count'] > 0
        ? number_format($bucket['sum_rank'] / $bucket['count'], 3)
        : '-';
}

function formatNumber(float $value, int $precision): string
{
    // -0.000 のような表示を避ける
    if (abs($value) < pow(10, -$precision) / 2) {
        $value = 0.0;
    }
    return number_format($value, $precision, '.', '');
}

function buildBinLabels(array $bounds, int $precision): array
{
    $labels = [];
    $prev = null;
    foreach ($bounds as $bound) {
        $b = formatNumber((float)$bound, $precision);
        if ($prev === null) {
            $labels[] = "<= {$b}";
        } else {
            $p = formatNumber((float)$prev, $precision);
            $labels[] = "({$p}, {$b}]";
        }
        $prev = $bound;
    }
    $last = formatNumber((float)end($bounds), $precision);
    $labels[] = "> {$last}";
    return $labels;
}

function findBinLabel(float $value, array $bounds, int $precision): string
{
    $prev = null;
    foreach ($bounds as $bound) {
        if ($value <= (float)$bound) {
            $b = formatNumber((float)$bound, $precision);
            if ($prev === null) {
                return "<= {$b}";
            }
            $p = formatNumber((float)$prev, $precision);
            return "({$p}, {$b}]";
        }
        $prev = $bound;
    }
    $last = formatNumber((float)end($bounds), $precision);
    return "> {$last}";
}

function printBucketRow(string $label, array $b): void
{
    printf(
        "%-18s N=%6d 1着=%6s 2連=%6s 3連=%6s 平均=%s\n",
        $label,
        $b['count'],
        pct($b['first'], $b['count']),
        pct($b['top2'], $b['count']),
        pct($b['top3'], $b['count']),
        avgRank($b)
    );
}

// ============================================================
// 細分化区間
// 「現在の閾値の前後」が見えるよう、閾値より細かく刻む。
// ============================================================
$items = [
    'exhibition' => [
        'name' => '展示タイム',
        'value_label' => '場6か月平均との差',
        'precision' => 3,
        'bounds' => [-0.20, -0.15, -0.10, -0.075, -0.05, -0.025, 0.00, 0.025, 0.05, 0.075, 0.10, 0.15, 0.20],
        'threshold' => '5:<=-0.10 / 4:<=-0.05 / 3:<=0.05 / 2:<=0.10 / 1:>0.10',
    ],
    'st' => [
        'name' => '展示ST',
        'value_label' => 'ST実測値',
        'precision' => 2,
        'bounds' => [-0.10, -0.05, 0.00, 0.03, 0.05, 0.08, 0.12, 0.16, 0.20, 0.25, 0.30],
        'threshold' => '1:<=-0.05 / 2:(-0.05,0) / 5:0～0.05 / 4:0.05～0.12 / 2:0.12～0.20 / 1:>0.20（3点なし）',
    ],
    'lap' => [
        'name' => '周回',
        'value_label' => 'レース6艇平均との差',
        'precision' => 2,
        'bounds' => [-0.50, -0.40, -0.30, -0.20, -0.10, 0.00, 0.10, 0.20, 0.30, 0.40, 0.50],
        'threshold' => '5:<=-0.30 / 4:<=-0.10 / 3:<=0.10 / 2:<=0.30 / 1:>0.30',
    ],
    'mawari' => [
        'name' => '周り足',
        'value_label' => 'レース6艇平均との差',
        'precision' => 2,
        'bounds' => [-0.30, -0.20, -0.15, -0.10, -0.05, 0.00, 0.05, 0.10, 0.15, 0.20, 0.30],
        'threshold' => '5:<=-0.20 / 4:<=-0.05 / 3:<=0.05 / 2:<=0.20 / 1:>0.20',
    ],
    'straight' => [
        'name' => '直線',
        'value_label' => 'レース6艇平均との差',
        'precision' => 2,
        'bounds' => [-0.08, -0.06, -0.04, -0.03, -0.02, -0.01, 0.00, 0.01, 0.02, 0.03, 0.04, 0.06, 0.08],
        'threshold' => '5:<=-0.04 / 4:<=-0.01 / 3:<=0.01 / 2:<=0.04 / 1:>0.04',
    ],
];

$byScore = [];
$byBin = [];
$byLaneScore = [];
foreach ($items as $key => $cfg) {
    $byScore[$key] = [];
    for ($score = 5; $score >= 1; $score--) {
        $byScore[$key][$score] = newBucket();
    }
    $byBin[$key] = [];
    $byLaneScore[$key] = [];
    foreach (range(1, 6) as $lane) {
        $byLaneScore[$key][$lane] = [];
        for ($score = 5; $score >= 1; $score--) {
            $byLaneScore[$key][$lane][$score] = newBucket();
        }
    }
    foreach (buildBinLabels($cfg['bounds'], $cfg['precision']) as $label) {
        $byBin[$key][$label] = newBucket();
    }
}

// ============================================================
// SQL準備
// ============================================================
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

$sqlExhibition = <<<SQL
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
$stmtExhibition = $pdo->prepare($sqlExhibition);

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

$totalRaces = count($races);
$processed = 0;
$skipped = 0;
$evaluatedBoats = 0;
$skipReasons = [
    'not_6_boats' => 0,
    'missing_exhibition' => 0,
    'missing_average' => 0,
    'invalid_rank' => 0,
];

// ============================================================
// レースループ
// ============================================================
foreach ($races as $race) {
    $raceCode = (string)$race['race_code'];

    $stmtEntry->execute([':race_code' => $raceCode]);
    $entries = $stmtEntry->fetchAll(PDO::FETCH_ASSOC);
    if (count($entries) !== 6) {
        $skipped++;
        $skipReasons['not_6_boats']++;
        continue;
    }

    $stmtExhibition->execute([':race_code' => $raceCode]);
    $exhibitions = $stmtExhibition->fetchAll(PDO::FETCH_ASSOC);
    if (count($exhibitions) !== 6) {
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

    $lapValues = [];
    $mawariValues = [];
    $straightValues = [];
    foreach ($exhibitions as $row) {
        $lapValues[] = (float)$row['lap_time'];
        $mawariValues[] = (float)$row['around_time'];
        $straightValues[] = (float)$row['straight_time'];
    }
    $avgLap = array_sum($lapValues) / 6;
    $avgMawari = array_sum($mawariValues) / 6;
    $avgStraight = array_sum($straightValues) / 6;

    $actualRanks = [];
    foreach ($entries as $entry) {
        $lane = (int)$entry['lane'];
        $raw = $entry['rank'];
        if ($raw === null || $raw === '') {
            $actualRanks[$lane] = 5.5;
        } elseif (in_array((string)$raw, ['1','2','3','4','5','6'], true)) {
            $actualRanks[$lane] = (float)$raw;
        } else {
            $actualRanks[$lane] = null;
        }
    }

    $invalidRank = false;
    foreach (range(1, 6) as $lane) {
        if (!array_key_exists($lane, $actualRanks) || $actualRanks[$lane] === null) {
            $invalidRank = true;
            break;
        }
    }
    if ($invalidRank) {
        $skipped++;
        $skipReasons['invalid_rank']++;
        continue;
    }

    $processed++;

    foreach ($exhibitions as $row) {
        $lane = (int)$row['lane'];
        if ($laneFilter !== null && $lane !== $laneFilter) {
            continue;
        }

        $rank = (float)$actualRanks[$lane];
        $values = [
            'exhibition' => (float)$row['exhibition_time'] - $avgExhibition,
            'st' => (float)$row['start_timing'],
            'lap' => (float)$row['lap_time'] - $avgLap,
            'mawari' => (float)$row['around_time'] - $avgMawari,
            'straight' => (float)$row['straight_time'] - $avgStraight,
        ];
        $scores = [
            'exhibition' => (int)calcExhibitionScore($values['exhibition']),
            'st' => (int)calcStScore($values['st']),
            'lap' => (int)calcLapScore($values['lap']),
            'mawari' => (int)calcMawariScore($values['mawari']),
            'straight' => (int)calcStraightScore($values['straight']),
        ];

        foreach ($items as $key => $cfg) {
            addBucket($byScore[$key][$scores[$key]], $rank);
            addBucket($byLaneScore[$key][$lane][$scores[$key]], $rank);
            $binLabel = findBinLabel($values[$key], $cfg['bounds'], $cfg['precision']);
            addBucket($byBin[$key][$binLabel], $rank);
        }
        $evaluatedBoats++;
    }
}

// ============================================================
// 出力
// ============================================================
echo "========================================\n";
echo "展示5項目 個別評価・閾値検証（艇番別付き）\n";
echo "========================================\n";
echo "期間       : {$from} ～ {$to}\n";
echo "対象レース : {$totalRaces}\n";
echo "処理レース : {$processed}\n";
echo "スキップ   : {$skipped}\n";
echo "評価艇数   : {$evaluatedBoats}\n";
echo "艇番条件   : " . ($laneFilter === null ? '全艇' : $laneFilter . '号艇のみ') . "\n";
echo "\n【スキップ理由】\n";
foreach ($skipReasons as $reason => $count) {
    printf("%-24s : %d\n", $reason, $count);
}

foreach ($items as $key => $cfg) {
    echo "\n========================================\n";
    echo "【{$cfg['name']}】\n";
    echo "========================================\n";
    echo "現行判定 : {$cfg['threshold']}\n";
    echo "元データ : {$cfg['value_label']}\n";

    echo "\n--- 現行スコア別 成績 ---\n";
    for ($score = 5; $score >= 1; $score--) {
        printBucketRow($score . '点', $byScore[$key][$score]);
    }

    echo "\n--- 艇番別 × 現行スコア別 成績 ---\n";
    foreach (range(1, 6) as $lane) {
        echo "\n[{$lane}号艇]\n";
        for ($score = 5; $score >= 1; $score--) {
            printBucketRow($score . '点', $byLaneScore[$key][$lane][$score]);
        }
    }

    echo "\n--- 細分化区間別 成績 ---\n";
    foreach ($byBin[$key] as $label => $bucket) {
        printBucketRow($label, $bucket);
    }
}

echo "\n========================================\n";
echo "検証終了\n";
echo "========================================\n";
