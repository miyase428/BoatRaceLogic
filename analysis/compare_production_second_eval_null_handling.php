<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

/**
 * 本番Web（ApiClientProduction）基準で、展示NULL扱いを比較する。
 *
 * CURRENT_PROD:
 *   - public/tenji_api.php と同様に NULL を 0 として各展示スコアを計算
 *   - 周回/周り足/直線平均も6艇固定（NULL=0）
 *   - ApiClientProduction と同様、旧2・4号艇固定+1は final_2nd_score に加算しない
 *
 * NULL_SAFE_PROD:
 *   - 周回/周り足/直線平均は非NULL艇だけで計算
 *   - 個別NULLは中立3点
 *   - 全艇NULLの指標も全艇3点
 *   - 旧2・4号艇固定+1は加算しない
 *
 * DB更新なし。
 *
 * Usage:
 *   php analysis/compare_production_second_eval_null_handling.php 2026-08-01 2026-09-02 [表示上限]
 */

$from = trim((string)($argv[1] ?? ''));
$to = trim((string)($argv[2] ?? ''));
$limit = max(1, (int)($argv[3] ?? 30));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
    fwrite(STDERR, "Usage: php {$argv[0]} YYYY-MM-DD YYYY-MM-DD [表示上限]\n");
    exit(1);
}

function exScoreProd(float $diff): float
{
    if ($diff <= -0.10) return 5.0;
    if ($diff <= -0.05) return 4.0;
    if ($diff <=  0.05) return 3.0;
    if ($diff <=  0.10) return 2.0;
    return 1.0;
}

function stScoreProd(float $st): float
{
    if ($st <= 0.00) return 3.0;
    if ($st <= 0.12) return 5.0;
    if ($st <= 0.20) return 3.0;
    if ($st <= 0.30) return 2.0;
    return 1.0;
}

function lapScoreProd(float $v, float $avg): float
{
    $d = $v - $avg;
    if ($d <= -0.30) return 5.0;
    if ($d <= -0.10) return 4.0;
    if ($d <=  0.10) return 3.0;
    if ($d <=  0.30) return 2.0;
    return 1.0;
}

function aroundScoreProd(float $v, float $avg): float
{
    $d = $v - $avg;
    if ($d <= -0.20) return 5.0;
    if ($d <= -0.05) return 4.0;
    if ($d <=  0.05) return 3.0;
    if ($d <=  0.20) return 2.0;
    return 1.0;
}

function straightScoreProd(float $v, float $avg): float
{
    $d = $v - $avg;
    if ($d <= -0.04) return 5.0;
    if ($d <= -0.01) return 4.0;
    if ($d <=  0.01) return 3.0;
    if ($d <=  0.04) return 2.0;
    return 1.0;
}

function nullableFloatProd(mixed $v): ?float
{
    if ($v === null || $v === '') return null;
    return (float)$v;
}

function avgNonNullProd(array $values): ?float
{
    $valid = array_values(array_filter($values, static fn($v) => $v !== null));
    return $valid ? array_sum($valid) / count($valid) : null;
}

function metricCountsProd(array $rows): array
{
    $keys = ['exhibition_time', 'start_timing', 'lap_time', 'around_time', 'straight_time'];
    $out = [];
    foreach ($keys as $k) {
        $n = 0;
        foreach ($rows as $r) {
            if ($r[$k] !== null) $n++;
        }
        $out[$k] = $n;
    }
    return $out;
}

function signatureProd(array $counts): string
{
    return sprintf(
        'E%d ST%d L%d A%d D%d',
        $counts['exhibition_time'],
        $counts['start_timing'],
        $counts['lap_time'],
        $counts['around_time'],
        $counts['straight_time']
    );
}

function actualRankProd(mixed $raw): ?float
{
    if ($raw === null || $raw === '') return 5.5;
    $s = trim((string)$raw);
    if (in_array($s, ['1','2','3','4','5','6'], true)) return (float)$s;
    return null;
}

function addPerfProd(array &$b, ?float $rank): void
{
    if ($rank === null) return;
    $b['n']++;
    if ($rank === 1.0) $b['first']++;
    if ($rank <= 2.0) $b['top2']++;
    if ($rank <= 3.0) $b['top3']++;
    $b['sum'] += $rank;
}

function pctProd(int $n, int $d): string
{
    return $d > 0 ? number_format($n * 100 / $d, 2) . '%' : '-';
}

function perfLineProd(string $name, array $b): string
{
    $avg = $b['n'] > 0 ? $b['sum'] / $b['n'] : 0.0;
    return sprintf(
        "%-16s N=%4d 1着=%6s 2連=%6s 3連=%6s 平均=%.3f",
        $name,
        $b['n'],
        pctProd($b['first'], $b['n']),
        pctProd($b['top2'], $b['n']),
        pctProd($b['top3'], $b['n']),
        $avg
    );
}

$pdo = getPDO();
$sql = <<<SQL
SELECT
    re.race_code,
    re.lane_number AS lane,
    re.player_id::text AS player_id,
    rrd.rank,
    el.exhibition_time,
    el.start_timing,
    el.lap_time,
    el.around_time,
    el.straight_time,
    ea.avg_exhibition_time_6m
FROM boat_race.race_entry re
JOIN boat_race.exhibition_live el
  ON el.race_code = re.race_code
 AND el.player_id = re.player_id
LEFT JOIN boat_race.race_result_detail rrd
  ON rrd.race_code = re.race_code
 AND rrd.player_id = re.player_id
LEFT JOIN boat_race.stadium_master sm
  ON sm.stadium_code = SUBSTRING(re.race_code, 9, 3)
LEFT JOIN boat_race.exhibition_avg_6m ea
  ON ea.stadium_name = sm.stadium_name
WHERE re.race_date BETWEEN :from::date AND :to::date
ORDER BY re.race_code, re.lane_number
SQL;
$stmt = $pdo->prepare($sql);
$stmt->execute([':from' => $from, ':to' => $to]);
$all = $stmt->fetchAll(PDO::FETCH_ASSOC);

$byRace = [];
foreach ($all as $r) {
    $code = (string)$r['race_code'];
    foreach (['exhibition_time','start_timing','lap_time','around_time','straight_time','avg_exhibition_time_6m'] as $k) {
        $r[$k] = nullableFloatProd($r[$k]);
    }
    $byRace[$code][] = $r;
}

$stats = [
    'races6' => 0,
    'metric_partial' => 0,
    'partial_within_metric' => 0,
    'rank_changed' => 0,
    'top_changed' => 0,
    'current_top_missing' => 0,
];
$base = ['n'=>0,'first'=>0,'top2'=>0,'top3'=>0,'sum'=>0.0];
$perfAllCurrent = $base;
$perfAllSafe = $base;
$perfAffectedCurrent = $base;
$perfAffectedSafe = $base;
$signatureCounts = [];
$examples = [];

foreach ($byRace as $code => $rows) {
    if (count($rows) !== 6) continue;
    $avgEx = $rows[0]['avg_exhibition_time_6m'] ?? null;
    if ($avgEx === null || $avgEx <= 0) continue;

    $counts = metricCountsProd($rows);
    $sig = signatureProd($counts);
    if (min($counts) < 6) {
        $stats['metric_partial']++;
        $signatureCounts[$sig] = ($signatureCounts[$sig] ?? 0) + 1;
    }

    $within = false;
    foreach ($counts as $n) {
        if ($n >= 1 && $n <= 5) {
            $within = true;
            break;
        }
    }
    if ($within) $stats['partial_within_metric']++;

    $currentAvgLap = array_sum(array_map(static fn($r) => (float)($r['lap_time'] ?? 0.0), $rows)) / 6.0;
    $currentAvgAround = array_sum(array_map(static fn($r) => (float)($r['around_time'] ?? 0.0), $rows)) / 6.0;
    $currentAvgStraight = array_sum(array_map(static fn($r) => (float)($r['straight_time'] ?? 0.0), $rows)) / 6.0;

    $safeAvgLap = avgNonNullProd(array_column($rows, 'lap_time'));
    $safeAvgAround = avgNonNullProd(array_column($rows, 'around_time'));
    $safeAvgStraight = avgNonNullProd(array_column($rows, 'straight_time'));

    $currentBoats = [];
    $safeBoats = [];

    foreach ($rows as $r) {
        $ex = $r['exhibition_time'];
        $st = $r['start_timing'];
        $lap = $r['lap_time'];
        $around = $r['around_time'];
        $straight = $r['straight_time'];

        $cEx = exScoreProd((float)($ex ?? 0.0) - $avgEx);
        $cSt = stScoreProd((float)($st ?? 0.0));
        $cLap = lapScoreProd((float)($lap ?? 0.0), $currentAvgLap);
        $cAround = aroundScoreProd((float)($around ?? 0.0), $currentAvgAround);
        $cStraight = straightScoreProd((float)($straight ?? 0.0), $currentAvgStraight);

        $sEx = $ex === null ? 3.0 : exScoreProd($ex - $avgEx);
        $sSt = $st === null ? 3.0 : stScoreProd($st);
        $sLap = ($lap === null || $safeAvgLap === null) ? 3.0 : lapScoreProd($lap, $safeAvgLap);
        $sAround = ($around === null || $safeAvgAround === null) ? 3.0 : aroundScoreProd($around, $safeAvgAround);
        $sStraight = ($straight === null || $safeAvgStraight === null) ? 3.0 : straightScoreProd($straight, $safeAvgStraight);

        // ApiClientProduction: final_2nd_score = ex_sougou + type_hosei。
        // 現状 type_hosei=0、旧2・4号艇固定+1は加算しない。
        $cScore = ($cEx + $cLap + $cAround + $cStraight)
                + ($cSt + $cStraight)
                + ($cLap + $cAround);
        $sScore = ($sEx + $sLap + $sAround + $sStraight)
                + ($sSt + $sStraight)
                + ($sLap + $sAround);

        $missing = 0;
        foreach (['exhibition_time','start_timing','lap_time','around_time','straight_time'] as $k) {
            if ($r[$k] === null) $missing++;
        }

        $row = [
            'lane'=>(int)$r['lane'],
            'rank'=>actualRankProd($r['rank']),
            'missing'=>$missing,
        ];
        $currentBoats[] = $row + ['score'=>$cScore];
        $safeBoats[] = $row + ['score'=>$sScore];
    }

    $sort = static function(array &$boats): void {
        usort($boats, static function(array $a, array $b): int {
            if ($a['score'] === $b['score']) return $a['lane'] <=> $b['lane'];
            return $a['score'] > $b['score'] ? -1 : 1;
        });
    };
    $sort($currentBoats);
    $sort($safeBoats);

    $stats['races6']++;
    $cTop = $currentBoats[0];
    $sTop = $safeBoats[0];
    addPerfProd($perfAllCurrent, $cTop['rank']);
    addPerfProd($perfAllSafe, $sTop['rank']);

    $orderC = implode(',', array_column($currentBoats, 'lane'));
    $orderS = implode(',', array_column($safeBoats, 'lane'));
    if ($orderC !== $orderS) $stats['rank_changed']++;
    if ($cTop['lane'] !== $sTop['lane']) $stats['top_changed']++;
    if ($cTop['missing'] > 0) $stats['current_top_missing']++;

    if ($within) {
        addPerfProd($perfAffectedCurrent, $cTop['rank']);
        addPerfProd($perfAffectedSafe, $sTop['rank']);
    }

    if (($cTop['lane'] !== $sTop['lane'] || ($within && $cTop['missing'] > 0)) && count($examples) < $limit) {
        $examples[] = sprintf(
            '%s | %s | current=%d(score=%.1f,欠損=%d,実=%s) -> safe=%d(score=%.1f,欠損=%d,実=%s)',
            $code,
            $sig,
            $cTop['lane'], $cTop['score'], $cTop['missing'], $cTop['rank'] === null ? '-' : number_format($cTop['rank'],1),
            $sTop['lane'], $sTop['score'], $sTop['missing'], $sTop['rank'] === null ? '-' : number_format($sTop['rank'],1)
        );
    }
}

arsort($signatureCounts);

echo str_repeat('=', 136) . "\n";
echo "本番Web二次評価 展示NULL扱い比較（CURRENT_PROD vs NULL_SAFE_PROD）\n";
echo "期間: {$from} ～ {$to}\n";
echo "注: ApiClientProduction と同様、旧2・4号艇固定+1は二次スコアへ加算しない\n";
echo str_repeat('=', 136) . "\n\n";
printf("6艇評価可能レース                 : %5dR\n", $stats['races6']);
printf("5指標のどれかが6艇未満            : %5dR\n", $stats['metric_partial']);
printf("1～5艇だけ値ありの指標を含む       : %5dR\n", $stats['partial_within_metric']);
printf("全順位並びが変わる                 : %5dR\n", $stats['rank_changed']);
printf("二次評価1位が変わる                 : %5dR\n", $stats['top_changed']);
printf("CURRENT_PROD二次1位自身に欠損あり   : %5dR\n", $stats['current_top_missing']);

echo "\n【全6艇評価可能レース・二次1位成績】\n";
echo perfLineProd('CURRENT_PROD', $perfAllCurrent) . "\n";
echo perfLineProd('NULL_SAFE_PROD', $perfAllSafe) . "\n";

echo "\n【1～5艇だけ値ありの指標を含むレース・二次1位成績】\n";
echo perfLineProd('CURRENT_PROD', $perfAffectedCurrent) . "\n";
echo perfLineProd('NULL_SAFE_PROD', $perfAffectedSafe) . "\n";

echo "\n【欠損シグネチャ上位15】\n";
$i = 0;
foreach ($signatureCounts as $sig => $n) {
    printf("%-28s %5dR\n", $sig, $n);
    if (++$i >= 15) break;
}

echo "\n【順位影響例】\n";
if (!$examples) {
    echo "なし\n";
} else {
    foreach ($examples as $line) echo $line . "\n";
}

echo "\nこのスクリプトは本番変更前の検証専用です。DB/本番コードは変更しません。\n";
