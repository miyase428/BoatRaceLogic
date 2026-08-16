<?php
declare(strict_types=1);

/**
 * SUM理論 場別 features 比較
 *
 * AROUND   : exhibition_time + lap_time + around_time
 * STRAIGHT : exhibition_time + lap_time + straight_time
 *
 * 2方式を完全に同じレース・同じ6艇で比較するため、
 * exhibition/lap/around/straight の4項目が全艇揃うレースだけを使用する。
 * rank NULL/空はプロジェクト共通ルールに合わせて5.5扱い。
 *
 * 実行:
 *   php analysis/sam_feature_compare.php 2026-06-15 2026-07-14
 *   php analysis/sam_feature_compare.php 2026-07-15 2026-08-14
 *
 * 場限定:
 *   php analysis/sam_feature_compare.php 2026-07-15 2026-08-14 OMR
 */

require_once __DIR__ . '/../common/db_connect.php';

$from = $argv[1] ?? '2026-06-15';
$to   = $argv[2] ?? '2026-07-14';
$venueFilter = strtoupper(trim($argv[3] ?? ''));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    fwrite(STDERR, "日付は YYYY-MM-DD 形式で指定してください。\n");
    exit(1);
}
if ($from > $to) {
    fwrite(STDERR, "開始日は終了日以前にしてください。\n");
    exit(1);
}
if ($venueFilter !== '' && !preg_match('/^[A-Z0-9]{3}$/', $venueFilter)) {
    fwrite(STDERR, "場コードは OMR のような3文字で指定してください。\n");
    exit(1);
}

$featuresPath = __DIR__ . '/../theories/new_sam/features.json';
$currentFeatures = json_decode((string)@file_get_contents($featuresPath), true);
if (!is_array($currentFeatures)) {
    fwrite(STDERR, "features.json の読み込みに失敗しました。\n");
    exit(1);
}

try {
    $pdo = getPDO();
} catch (Throwable $e) {
    fwrite(STDERR, "DB接続エラー: {$e->getMessage()}\n");
    exit(1);
}

const INTERVALS = [
    '-0.6未満', '-0.6--0.4', '-0.4--0.2', '-0.2-0.0',
    '0.0-0.2', '0.2-0.4', '0.4-0.6', '0.6以上',
];
const ZONES = ['GOOD', 'NEUTRAL', 'BAD'];
const MODE_FEATURES = [
    'AROUND' => ['exhibition_time', 'lap_time', 'around_time'],
    'STRAIGHT' => ['exhibition_time', 'lap_time', 'straight_time'],
];

function getInterval(float $v): string
{
    if ($v < -0.6) return '-0.6未満';
    if ($v < -0.4) return '-0.6--0.4';
    if ($v < -0.2) return '-0.4--0.2';
    if ($v <  0.0) return '-0.2-0.0';
    if ($v <  0.2) return '0.0-0.2';
    if ($v <  0.4) return '0.2-0.4';
    if ($v <  0.6) return '0.4-0.6';
    return '0.6以上';
}

function getZone(float $v): string
{
    if ($v < -0.2) return 'GOOD';
    if ($v <  0.2) return 'NEUTRAL';
    return 'BAD';
}

function newBucket(): array
{
    return [
        'count' => 0, 'first' => 0, 'top2' => 0, 'top3' => 0,
        'sum_rank' => 0.0, 'mix' => [],
    ];
}

function addBucket(array &$b, string $venue, int $course, float $rank): void
{
    $b['count']++;
    if ($rank === 1.0) $b['first']++;
    if ($rank <= 2.0) $b['top2']++;
    if ($rank <= 3.0) $b['top3']++;
    $b['sum_rank'] += $rank;
    if (!isset($b['mix'][$venue])) $b['mix'][$venue] = [];
    $b['mix'][$venue][$course] = ($b['mix'][$venue][$course] ?? 0) + 1;
}

function newBase(): array
{
    return ['count' => 0, 'first' => 0, 'top2' => 0, 'top3' => 0];
}

function addBase(array &$b, float $rank): void
{
    $b['count']++;
    if ($rank === 1.0) $b['first']++;
    if ($rank <= 2.0) $b['top2']++;
    if ($rank <= 3.0) $b['top3']++;
}

function rate(int $n, int $d): float
{
    return $d > 0 ? $n / $d * 100.0 : 0.0;
}

function baseRate(array $base, string $metric): float
{
    $d = (int)($base['count'] ?? 0);
    return $d > 0 ? (int)($base[$metric] ?? 0) / $d * 100.0 : 0.0;
}

function expectedRate(array $bucket, array $base, string $metric): float
{
    $n = (int)$bucket['count'];
    if ($n === 0) return 0.0;
    $sum = 0.0;
    foreach ($bucket['mix'] as $venue => $courses) {
        foreach ($courses as $course => $count) {
            $b = $base[$venue][(int)$course] ?? null;
            if (!$b || ($b['count'] ?? 0) <= 0) continue;
            $sum += $count * baseRate($b, $metric);
        }
    }
    return $sum / $n;
}

function summarize(array $bucket, array $base): array
{
    $n = (int)$bucket['count'];
    $first = rate((int)$bucket['first'], $n);
    $top2 = rate((int)$bucket['top2'], $n);
    $top3 = rate((int)$bucket['top3'], $n);
    return [
        'n' => $n,
        'first' => $first,
        'top2' => $top2,
        'top3' => $top3,
        'avg' => $n > 0 ? $bucket['sum_rank'] / $n : 0.0,
        'lift_first' => $first - expectedRate($bucket, $base, 'first'),
        'lift_top2' => $top2 - expectedRate($bucket, $base, 'top2'),
        'lift_top3' => $top3 - expectedRate($bucket, $base, 'top3'),
    ];
}

function makeModelStats(): array
{
    $zones = [];
    foreach (ZONES as $zone) $zones[$zone] = newBucket();
    $intervals = [];
    foreach (INTERVALS as $label) $intervals[$label] = newBucket();
    return ['zones' => $zones, 'intervals' => $intervals];
}

function judgement(array $stats, array $base): string
{
    $g = summarize($stats['zones']['GOOD'], $base);
    $n = summarize($stats['zones']['NEUTRAL'], $base);
    $b = summarize($stats['zones']['BAD'], $base);
    if ($g['n'] < 50 || $n['n'] < 50 || $b['n'] < 50) return 'SMALL';
    $firstOrder = $g['lift_first'] > $n['lift_first'] && $n['lift_first'] > $b['lift_first'];
    $top3Order = $g['lift_top3'] > $n['lift_top3'] && $n['lift_top3'] > $b['lift_top3'];
    $signs = $g['lift_top3'] > 0.0 && $b['lift_top3'] < 0.0;
    if ($firstOrder && $top3Order && $signs) return 'OK';
    if (($firstOrder || $top3Order) && $signs) return 'MIXED';
    return 'NG';
}

function monotonicity(array $stats, array $base, string $metric): int
{
    $values = [];
    foreach (INTERVALS as $label) {
        $s = summarize($stats['intervals'][$label], $base);
        $values[] = $metric === 'first' ? $s['lift_first'] : $s['lift_top3'];
    }
    $ok = 0;
    for ($i = 0; $i < count($values) - 1; $i++) {
        if ($values[$i] >= $values[$i + 1]) $ok++;
    }
    return $ok;
}

function judgeRank(string $judge): int
{
    return match ($judge) {
        'OK' => 3,
        'MIXED' => 2,
        'NG' => 1,
        default => 0,
    };
}

function modelSummary(array $stats, array $base): array
{
    $g = summarize($stats['zones']['GOOD'], $base);
    $n = summarize($stats['zones']['NEUTRAL'], $base);
    $b = summarize($stats['zones']['BAD'], $base);
    $judge = judgement($stats, $base);
    return [
        'judge' => $judge,
        'good' => $g,
        'neutral' => $n,
        'bad' => $b,
        'sep_first' => $g['lift_first'] - $b['lift_first'],
        'sep_top3' => $g['lift_top3'] - $b['lift_top3'],
        'mono_first' => monotonicity($stats, $base, 'first'),
        'mono_top3' => monotonicity($stats, $base, 'top3'),
    ];
}

function chooseWinner(array $around, array $straight): string
{
    if ($around['judge'] === 'SMALL' || $straight['judge'] === 'SMALL') return 'HOLD';
    $ar = judgeRank($around['judge']);
    $sr = judgeRank($straight['judge']);
    if ($ar > $sr) return 'AROUND';
    if ($sr > $ar) return 'STRAIGHT';

    $aSep = $around['sep_first'] + $around['sep_top3'];
    $sSep = $straight['sep_first'] + $straight['sep_top3'];
    if (abs($aSep - $sSep) < 1.0) return 'TIE';
    return $aSep > $sSep ? 'AROUND' : 'STRAIGHT';
}

function currentMode(array $featureCols): string
{
    if (in_array('around_time', $featureCols, true)) return 'AROUND';
    if (in_array('straight_time', $featureCols, true)) return 'STRAIGHT';
    return 'OTHER';
}

$sqlRaces = <<<SQL
SELECT DISTINCT re.race_code
FROM boat_race.race_entry re
WHERE re.race_date BETWEEN :from_date AND :to_date
ORDER BY re.race_code
SQL;
$stmtRaces = $pdo->prepare($sqlRaces);
$stmtRaces->execute([':from_date' => $from, ':to_date' => $to]);
$races = $stmtRaces->fetchAll(PDO::FETCH_COLUMN);

$sqlEx = <<<SQL
SELECT entry_course, exhibition_time, lap_time, around_time, straight_time
FROM boat_race.exhibition_live
WHERE race_code = :race_code
ORDER BY entry_course
SQL;
$stmtEx = $pdo->prepare($sqlEx);

$sqlResult = <<<SQL
SELECT entry_course, rank
FROM boat_race.race_result_detail
WHERE race_code = :race_code
ORDER BY entry_course
SQL;
$stmtResult = $pdo->prepare($sqlResult);

$venueStats = [];
$venueBase = [];
$venueCounts = [];
$overall = ['AROUND' => makeModelStats(), 'STRAIGHT' => makeModelStats()];

$totalRaces = count($races);
$processed = 0;
$skip = [
    'venue_not_target' => 0,
    'not_6_exhibition' => 0,
    'missing_any_feature' => 0,
    'missing_result' => 0,
];

foreach ($races as $raceCodeRaw) {
    $raceCode = (string)$raceCodeRaw;
    $venue = substr($raceCode, 8, 3);
    if ($venueFilter !== '' && $venue !== $venueFilter) {
        $skip['venue_not_target']++;
        continue;
    }
    if (!isset($currentFeatures[$venue])) continue;

    $stmtEx->execute([':race_code' => $raceCode]);
    $rows = $stmtEx->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 6) {
        $skip['not_6_exhibition']++;
        continue;
    }

    $byCourse = [];
    $invalidFeature = false;
    foreach ($rows as $row) {
        $course = (int)$row['entry_course'];
        foreach (['exhibition_time','lap_time','around_time','straight_time'] as $col) {
            if (!array_key_exists($col, $row) || $row[$col] === null || $row[$col] === '' || !is_numeric($row[$col])) {
                $invalidFeature = true;
                break 2;
            }
        }
        $byCourse[$course] = [
            'exhibition_time' => (float)$row['exhibition_time'],
            'lap_time' => (float)$row['lap_time'],
            'around_time' => (float)$row['around_time'],
            'straight_time' => (float)$row['straight_time'],
        ];
    }
    if ($invalidFeature || count($byCourse) !== 6) {
        $skip['missing_any_feature']++;
        continue;
    }

    $stmtResult->execute([':race_code' => $raceCode]);
    $resultRows = $stmtResult->fetchAll(PDO::FETCH_ASSOC);
    $rankByCourse = [];
    $invalidRank = false;
    foreach ($resultRows as $row) {
        $course = (int)$row['entry_course'];
        $raw = $row['rank'];
        if ($raw === null || $raw === '') {
            $rankByCourse[$course] = 5.5;
        } elseif (in_array((string)$raw, ['1','2','3','4','5','6'], true)) {
            $rankByCourse[$course] = (float)$raw;
        } else {
            $invalidRank = true;
            break;
        }
    }
    if ($invalidRank || count($rankByCourse) !== 6) {
        $skip['missing_result']++;
        continue;
    }

    if (!isset($venueStats[$venue])) {
        $venueStats[$venue] = ['AROUND' => makeModelStats(), 'STRAIGHT' => makeModelStats()];
        $venueCounts[$venue] = ['races' => 0, 'boats' => 0];
    }

    foreach (range(1, 6) as $course) {
        if (!isset($venueBase[$venue][$course])) $venueBase[$venue][$course] = newBase();
        addBase($venueBase[$venue][$course], $rankByCourse[$course]);
    }

    foreach (MODE_FEATURES as $mode => $cols) {
        $rawSums = [];
        foreach ($byCourse as $course => $values) {
            $rawSums[$course] = $values[$cols[0]] + $values[$cols[1]] + $values[$cols[2]];
        }
        $avg = array_sum($rawSums) / 6.0;
        foreach ($rawSums as $course => $sumRaw) {
            $diff = $sumRaw - $avg;
            $zone = getZone($diff);
            $interval = getInterval($diff);
            $rank = $rankByCourse[$course];
            addBucket($venueStats[$venue][$mode]['zones'][$zone], $venue, $course, $rank);
            addBucket($venueStats[$venue][$mode]['intervals'][$interval], $venue, $course, $rank);
            addBucket($overall[$mode]['zones'][$zone], $venue, $course, $rank);
            addBucket($overall[$mode]['intervals'][$interval], $venue, $course, $rank);
        }
    }

    $venueCounts[$venue]['races']++;
    $venueCounts[$venue]['boats'] += 6;
    $processed++;
}

ksort($venueStats);

echo "========================================\n";
echo "SUM理論 features比較（周り足 vs 直線）\n";
echo "========================================\n";
echo "期間       : {$from} ～ {$to}\n";
echo "対象レース : {$totalRaces}\n";
echo "共通処理R  : {$processed}\n";
echo "場条件     : " . ($venueFilter !== '' ? $venueFilter : '全場') . "\n";
echo "着外処理   : rank NULL/空 = 5.5\n";
echo "比較条件   : 4展示項目が6艇すべて揃う同一レースのみ\n";

echo "\n【除外・スキップ参考】\n";
foreach ($skip as $k => $v) printf("%-24s : %d\n", $k, $v);

echo "\n========================================\n";
echo "【全場 共通サンプル比較】\n";
echo "========================================\n";
foreach (['AROUND','STRAIGHT'] as $mode) {
    $m = modelSummary($overall[$mode], $venueBase);
    printf(
        "%-8s 判定=%-5s | GOOD lift 1着=%+6.2f 3連=%+6.2f | BAD lift 1着=%+6.2f 3連=%+6.2f | 幅 1着=%5.2f 3連=%5.2f | 単調性=%d/7,%d/7\n",
        $mode, $m['judge'],
        $m['good']['lift_first'], $m['good']['lift_top3'],
        $m['bad']['lift_first'], $m['bad']['lift_top3'],
        $m['sep_first'], $m['sep_top3'],
        $m['mono_first'], $m['mono_top3']
    );
}

echo "\n========================================\n";
echo "【場別 AROUND vs STRAIGHT】\n";
echo "========================================\n";
echo "優勢判定: 健康診断ランクを優先し、同ランクならGOOD-BAD lift幅で比較（1pt未満はTIE）\n";

foreach ($venueStats as $venue => $stats) {
    $around = modelSummary($stats['AROUND'], $venueBase);
    $straight = modelSummary($stats['STRAIGHT'], $venueBase);
    $winner = chooseWinner($around, $straight);
    $current = currentMode($currentFeatures[$venue] ?? []);
    $vc = $venueCounts[$venue];

    if ($winner === 'HOLD') $action = '標本不足・保留';
    elseif ($winner === 'TIE') $action = '差小・現行維持寄り';
    elseif ($winner === $current) $action = '現行維持候補';
    else $action = '変更候補';

    echo "\n[{$venue}] current={$current} races={$vc['races']} boats={$vc['boats']} 期間内優勢={$winner} => {$action}\n";
    foreach (['AROUND' => $around, 'STRAIGHT' => $straight] as $mode => $m) {
        printf(
            "  %-8s %-5s | GOOD lift 1着=%+6.2f 3連=%+6.2f | BAD lift 1着=%+6.2f 3連=%+6.2f | 幅 1着=%5.2f 3連=%5.2f | 単調=%d/7,%d/7\n",
            $mode, $m['judge'],
            $m['good']['lift_first'], $m['good']['lift_top3'],
            $m['bad']['lift_first'], $m['bad']['lift_top3'],
            $m['sep_first'], $m['sep_top3'],
            $m['mono_first'], $m['mono_top3']
        );
    }
}

echo "\n========================================\n";
echo "見るポイント\n";
echo "========================================\n";
echo "・2期間とも同じ方式が優勢なら、その場のfeatures変更/維持候補\n";
echo "・期間で勝敗が割れる場合は現行維持を基本とする\n";
echo "・健康診断ランクだけでなくGOOD-BAD lift幅と8区間単調性も確認する\n";
echo "・このスクリプトは features.json を変更しない\n";
echo "========================================\n";