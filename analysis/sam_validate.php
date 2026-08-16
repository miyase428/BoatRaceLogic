<?php
declare(strict_types=1);

/**
 * SUM理論 健康診断（現行 features.json 準拠）
 *
 * 現行ロジック:
 *   場ごとに features.json で指定された展示3項目を合計
 *   → レース内平均との差（SUM差分）
 *   → 0.2刻みの8区間へ分類
 *
 * この検証では stats_*.json は使わない。
 * 指定期間の生データからSUM差分を再計算し、
 * 場×コース基準成績との差（lift）で理論単体の方向性を確認する。
 *
 * race_result_detail.rank が NULL / 空の場合は、
 * プロジェクト共通ルールに合わせて着外=5.5として扱う。
 *
 * 実行:
 *   php analysis/sam_validate.php 2026-06-15 2026-07-14
 *   php analysis/sam_validate.php 2026-07-15 2026-08-14
 *
 * 場を限定する場合:
 *   php analysis/sam_validate.php 2026-07-15 2026-08-14 OMR
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
$features = json_decode((string)@file_get_contents($featuresPath), true);
if (!is_array($features)) {
    fwrite(STDERR, "features.json の読み込みに失敗しました。\n");
    exit(1);
}
if ($venueFilter !== '' && !isset($features[$venueFilter])) {
    fwrite(STDERR, "features.json に場コード {$venueFilter} がありません。\n");
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

function safeRate(int $n, int $d): float
{
    return $d > 0 ? $n / $d * 100.0 : 0.0;
}

function baselineRate(array $base, string $metric): float
{
    $n = (int)($base[$metric] ?? 0);
    $d = (int)($base['count'] ?? 0);
    return $d > 0 ? $n / $d * 100.0 : 0.0;
}

function expectedRate(array $bucket, array $venueCourseBase, string $metric): float
{
    $count = (int)$bucket['count'];
    if ($count === 0) return 0.0;
    $sum = 0.0;
    foreach ($bucket['mix'] as $venue => $courses) {
        foreach ($courses as $course => $n) {
            $base = $venueCourseBase[$venue][(int)$course] ?? null;
            if (!$base || ($base['count'] ?? 0) <= 0) continue;
            $sum += $n * baselineRate($base, $metric);
        }
    }
    return $sum / $count;
}

function summarize(array $bucket, array $venueCourseBase): array
{
    $n = (int)$bucket['count'];
    $first = safeRate((int)$bucket['first'], $n);
    $top2 = safeRate((int)$bucket['top2'], $n);
    $top3 = safeRate((int)$bucket['top3'], $n);
    $avg = $n > 0 ? $bucket['sum_rank'] / $n : 0.0;
    $expFirst = expectedRate($bucket, $venueCourseBase, 'first');
    $expTop2 = expectedRate($bucket, $venueCourseBase, 'top2');
    $expTop3 = expectedRate($bucket, $venueCourseBase, 'top3');
    return [
        'n' => $n, 'first' => $first, 'top2' => $top2, 'top3' => $top3, 'avg' => $avg,
        'lift_first' => $first - $expFirst,
        'lift_top2' => $top2 - $expTop2,
        'lift_top3' => $top3 - $expTop3,
    ];
}

function zoneJudgement(array $zoneBuckets, array $base): string
{
    $g = summarize($zoneBuckets['GOOD'], $base);
    $n = summarize($zoneBuckets['NEUTRAL'], $base);
    $b = summarize($zoneBuckets['BAD'], $base);
    if ($g['n'] < 50 || $n['n'] < 50 || $b['n'] < 50) return 'SMALL';
    $firstOrder = $g['lift_first'] > $n['lift_first'] && $n['lift_first'] > $b['lift_first'];
    $top3Order = $g['lift_top3'] > $n['lift_top3'] && $n['lift_top3'] > $b['lift_top3'];
    $signs = $g['lift_top3'] > 0.0 && $b['lift_top3'] < 0.0;
    if ($firstOrder && $top3Order && $signs) return 'OK';
    if (($firstOrder || $top3Order) && $signs) return 'MIXED';
    return 'NG';
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

$intervalBuckets = [];
foreach (INTERVALS as $label) $intervalBuckets[$label] = newBucket();
$zoneBuckets = [];
foreach (ZONES as $zone) $zoneBuckets[$zone] = newBucket();
$venueZones = [];
$venueCourseBase = [];
$venueCounts = [];

$totalRaces = count($races);
$processedRaces = 0;
$validBoats = 0;
$skip = [
    'venue_not_target' => 0,
    'feature_not_found' => 0,
    'not_6_exhibition' => 0,
    'missing_feature_value' => 0,
    'missing_result' => 0,
];

foreach ($races as $raceCodeRaw) {
    $raceCode = (string)$raceCodeRaw;
    $venue = substr($raceCode, 8, 3);

    if ($venueFilter !== '' && $venue !== $venueFilter) {
        $skip['venue_not_target']++;
        continue;
    }

    $featureCols = $features[$venue] ?? null;
    if (!is_array($featureCols) || count($featureCols) !== 3) {
        $skip['feature_not_found']++;
        continue;
    }

    $stmtEx->execute([':race_code' => $raceCode]);
    $exRows = $stmtEx->fetchAll(PDO::FETCH_ASSOC);
    if (count($exRows) !== 6) {
        $skip['not_6_exhibition']++;
        continue;
    }

    $sumRawByCourse = [];
    $invalidFeature = false;
    foreach ($exRows as $row) {
        $course = (int)$row['entry_course'];
        $sumRaw = 0.0;
        foreach ($featureCols as $col) {
            if (!array_key_exists($col, $row) || $row[$col] === null || $row[$col] === '' || !is_numeric($row[$col])) {
                $invalidFeature = true;
                break 2;
            }
            $sumRaw += (float)$row[$col];
        }
        $sumRawByCourse[$course] = $sumRaw;
    }
    if ($invalidFeature || count($sumRawByCourse) !== 6) {
        $skip['missing_feature_value']++;
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

    $avgSum = array_sum($sumRawByCourse) / 6.0;

    if (!isset($venueZones[$venue])) {
        $venueZones[$venue] = [];
        foreach (ZONES as $zone) $venueZones[$venue][$zone] = newBucket();
    }
    if (!isset($venueCounts[$venue])) {
        $venueCounts[$venue] = ['races' => 0, 'boats' => 0];
    }
    $venueCounts[$venue]['races']++;
    $processedRaces++;

    foreach ($sumRawByCourse as $course => $sumRaw) {
        $rank = $rankByCourse[$course];
        $diff = $sumRaw - $avgSum;
        $interval = getInterval($diff);
        $zone = getZone($diff);

        if (!isset($venueCourseBase[$venue][$course])) {
            $venueCourseBase[$venue][$course] = newBase();
        }
        addBase($venueCourseBase[$venue][$course], $rank);
        addBucket($intervalBuckets[$interval], $venue, $course, $rank);
        addBucket($zoneBuckets[$zone], $venue, $course, $rank);
        addBucket($venueZones[$venue][$zone], $venue, $course, $rank);
        $validBoats++;
        $venueCounts[$venue]['boats']++;
    }
}

ksort($venueZones);

echo "========================================\n";
echo "SUM理論 健康診断（現行features）\n";
echo "========================================\n";
echo "期間       : {$from} ～ {$to}\n";
echo "対象レース : {$totalRaces}\n";
echo "処理レース : {$processedRaces}\n";
echo "評価艇数   : {$validBoats}\n";
echo "場条件     : " . ($venueFilter !== '' ? $venueFilter : '全場') . "\n";
echo "着外処理   : rank NULL/空 = 5.5\n";
echo "補正方法   : 同期間の場×コース基準成績との差(lift)\n";

echo "\n【除外・スキップ参考】\n";
foreach ($skip as $k => $v) printf("%-24s : %d\n", $k, $v);

echo "\n========================================\n";
echo "【SUM差分 8区間】\n";
echo "========================================\n";
echo "※ 時間の合計なので、マイナス側ほど展示内容が良い想定\n";
foreach (INTERVALS as $label) {
    $s = summarize($intervalBuckets[$label], $venueCourseBase);
    printf(
        "%-12s N=%5d 1着=%6.2f%% 2連=%6.2f%% 3連=%6.2f%% 平均=%.3f | lift 1着=%+6.2fpt 2連=%+6.2fpt 3連=%+6.2fpt\n",
        $label, $s['n'], $s['first'], $s['top2'], $s['top3'], $s['avg'],
        $s['lift_first'], $s['lift_top2'], $s['lift_top3']
    );
}

echo "\n========================================\n";
echo "【3ゾーン集約】\n";
echo "========================================\n";
$zoneDesc = [
    'GOOD' => 'SUM差 < -0.2',
    'NEUTRAL' => '-0.2 <= SUM差 < 0.2',
    'BAD' => 'SUM差 >= 0.2',
];
foreach (ZONES as $zone) {
    $s = summarize($zoneBuckets[$zone], $venueCourseBase);
    printf(
        "%-7s %-22s N=%5d 1着=%6.2f%% 3連=%6.2f%% | lift 1着=%+6.2fpt 3連=%+6.2fpt\n",
        $zone, $zoneDesc[$zone], $s['n'], $s['first'], $s['top3'], $s['lift_first'], $s['lift_top3']
    );
}

echo "\n========================================\n";
echo "【場別 3ゾーン健康診断】\n";
echo "========================================\n";
echo "判定: OK=方向性良好 / MIXED=一部良好 / NG=方向性弱い / SMALL=標本不足\n";
foreach ($venueZones as $venue => $zones) {
    $featureText = implode('+', $features[$venue] ?? []);
    $judge = zoneJudgement($zones, $venueCourseBase);
    $vc = $venueCounts[$venue] ?? ['races' => 0, 'boats' => 0];
    echo "\n[{$venue}] {$featureText}  races={$vc['races']} boats={$vc['boats']} 判定={$judge}\n";
    foreach (ZONES as $zone) {
        $s = summarize($zones[$zone], $venueCourseBase);
        printf(
            "  %-7s N=%4d 1着=%6.2f%% 3連=%6.2f%% | lift 1着=%+6.2fpt 3連=%+6.2fpt\n",
            $zone, $s['n'], $s['first'], $s['top3'], $s['lift_first'], $s['lift_top3']
        );
    }
}

echo "\n========================================\n";
echo "見るポイント\n";
echo "========================================\n";
echo "・全体8区間でマイナス側→プラス側へ成績/liftが悪化するか\n";
echo "・GOODのliftがプラス、BADのliftがマイナスになるか\n";
echo "・同じ傾向が2つの独立期間で再現するか\n";
echo "・NG/MIXEDの場は features.json の3項目選択を次段階で比較する\n";
echo "========================================\n";