<?php

declare(strict_types=1);

/**
 * 1号艇一次1位レスキューの前方回収率検証。
 *
 * 固定候補:
 *   現行Web頭 != 1 × 1号艇 first_rank == 1 -> 頭を1号艇へ戻す
 *
 * 比較:
 *   CURRENT : 現行production再構成の本命買い目
 *   RESCUE  : 上記固定条件だけを追加した本命買い目
 *
 * 1点100円、3連単払戻は boat_race.race_payouts.trifecta_payout を使用。
 * 場名・閾値・追加条件は使わない。
 *
 * Usage:
 *   php analysis/validate_lane1_primary1_global_forward_roi.php \
 *     DATASET_CSV BOATS_CSV [DATASET_CSV2 BOATS_CSV2 ...]
 */

if ($argc < 3 || (($argc - 1) % 2) !== 0) {
    fwrite(STDERR, "Usage: php {$argv[0]} DATASET_CSV BOATS_CSV [DATASET_CSV2 BOATS_CSV2 ...]\n");
    exit(1);
}

require_once __DIR__ . '/../common/db_connect.php';

$modelPath = dirname(__DIR__) . '/config/kimarite_head_model.php';
if (!is_file($modelPath)) {
    throw new RuntimeException("kimarite頭補正モデルがありません: {$modelPath}");
}
$model = require $modelPath;
if (!is_array($model) || empty($model['courses'])) {
    throw new RuntimeException("kimarite頭補正モデルの形式が不正です: {$modelPath}");
}

$pdo = getPDO();
$args = array_slice($argv, 1);
$results = [];

for ($i = 0; $i < count($args); $i += 2) {
    $results[] = validatePeriod($pdo, $args[$i], $args[$i + 1], $model);
}

foreach ($results as $result) {
    printResult($result);
}

if (count($results) >= 2) {
    printResult(poolResults($results), true);
}

function readCsvAssoc(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("CSVが見つかりません: {$path}");
    }

    $fp = fopen($path, 'rb');
    if ($fp === false) {
        throw new RuntimeException("CSVを開けません: {$path}");
    }

    $header = fgetcsv($fp);
    if ($header === false) {
        fclose($fp);
        return [];
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);

    $rows = [];
    while (($cols = fgetcsv($fp)) !== false) {
        if (count($cols) !== count($header)) {
            continue;
        }
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

function fnum(array $row, string $key, float $default = 0.0): float
{
    $v = $row[$key] ?? null;
    return is_numeric($v) ? (float)$v : $default;
}

function formal(array $row): bool
{
    return inum($row, 'result_top3_course_complete') === 1
        && inum($row, 'result_boat_match') === 1;
}

function sampleOk(array $row, int $course): bool
{
    return inum($row, "c{$course}_6m_sample_n") >= 10;
}

function attack(array $row, int $course): float
{
    return fnum($row, "c{$course}_6m_makuri") + fnum($row, "c{$course}_6m_makurizashi");
}

function headFeature(array $row, int $course): float
{
    return $course === 2 ? fnum($row, 'c2_6m_sashi') : attack($row, $course);
}

function featureBand(float $v): string
{
    if ($v < 5.0) return '0-5';
    if ($v < 10.0) return '5-10';
    if ($v < 15.0) return '10-15';
    if ($v < 20.0) return '15-20';
    if ($v < 25.0) return '20-25';
    return '25+';
}

function modelScore(array $row, int $course, array $model): float
{
    $cm = $model['courses'][$course] ?? [];
    $base = (float)($cm['base_p'] ?? 0.0);
    $minSample = (int)($model['min_sample'] ?? 10);
    if (inum($row, "c{$course}_6m_sample_n") < $minSample) {
        return $base;
    }
    $band = featureBand(headFeature($row, $course));
    $br = $cm['bands'][$band] ?? null;
    return is_array($br) && array_key_exists('p', $br) ? (float)$br['p'] : $base;
}

function chooseHead(array $row, array $model): int
{
    $best = 2;
    $bestScore = -INF;
    foreach ([2, 3, 4] as $course) {
        $score = modelScore($row, $course, $model);
        if ($score > $bestScore || ($score === $bestScore && $course < $best)) {
            $best = $course;
            $bestScore = $score;
        }
    }
    return $best;
}

function rankAndKiru(array $boats): ?array
{
    if (count($boats) !== 6) {
        return null;
    }

    usort($boats, static function (array $a, array $b): int {
        $ra = inum($a, 'final_rank', 99);
        $rb = inum($b, 'final_rank', 99);
        return $ra === $rb
            ? inum($a, 'lane_number', 99) <=> inum($b, 'lane_number', 99)
            : $ra <=> $rb;
    });

    $rank = [];
    $kiru = [];
    $byLane = [];
    foreach ($boats as $b) {
        $lane = inum($b, 'lane_number');
        if ($lane < 1 || $lane > 6) {
            return null;
        }
        $rank[] = $lane;
        $kiru[$lane] = inum($b, 'kiru') === 1;
        $byLane[$lane] = $b;
    }

    return [$rank, $kiru, $byLane];
}

function moveToFront(array $rank, int $target): array
{
    $rank = array_values(array_filter($rank, static fn (int $b): bool => $b !== $target));
    array_unshift($rank, $target);
    return $rank;
}

function promoteToSecond(array $rank, int $head, int $target): array
{
    if ($head === $target) {
        return $rank;
    }
    $pos = array_search($target, $rank, true);
    if ($pos === false) {
        return $rank;
    }
    array_splice($rank, (int)$pos, 1);
    $headPos = array_search($head, $rank, true);
    if ($headPos === false) {
        return $rank;
    }
    array_splice($rank, (int)$headPos + 1, 0, [$target]);
    return array_values($rank);
}

function buildBet(array $rank, array $kiru, int $head): array
{
    $aite = [];
    $third = [];

    foreach ($rank as $boat) {
        if ($boat === $head || ($kiru[$boat] ?? false) === true) {
            continue;
        }
        $third[] = $boat;
        if (count($aite) < 3) {
            $aite[] = $boat;
        }
    }

    $bets = [];
    foreach ($aite as $second) {
        foreach ($third as $thirdBoat) {
            if ($second === $thirdBoat) {
                continue;
            }
            $bets[] = "{$head}-{$second}-{$thirdBoat}";
        }
    }
    sort($bets);
    return array_values(array_unique($bets));
}

function currentPrediction(array $row, array $baseRank, array $kiru, array $model): array
{
    $originalHead = inum($row, 'honmei_head');
    $head = $originalHead;
    $rank = $baseRank;

    // 現行productionの⑤⑥頭補正。
    if ($originalHead === 5 || $originalHead === 6) {
        $head = chooseHead($row, $model);
        $rank = moveToFront($rank, $head);
    }

    // 現行productionのA3/A4/H3（相手順位のみ）。
    if ($originalHead === 1) {
        $a3 = sampleOk($row, 3) && attack($row, 3) >= 15.0;
        $a4 = sampleOk($row, 4) && attack($row, 4) >= 20.0;
        if ($a4) $rank = promoteToSecond($rank, $head, 4);
        if ($a3) $rank = promoteToSecond($rank, $head, 3);
    } elseif ($originalHead === 3) {
        $h3 = sampleOk($row, 3) && attack($row, 3) >= 15.0;
        if ($h3) $rank = promoteToSecond($rank, $head, 1);
    }

    return [$head, $rank, buildBet($rank, $kiru, $head)];
}

function makeStats(): array
{
    return [
        'races' => 0,
        'points' => 0,
        'investment' => 0,
        'hits' => 0,
        'payout' => 0,
    ];
}

function addBetStat(array &$stats, array $bets, string $actualTrifecta, int $payout): void
{
    $stats['races']++;
    $stats['points'] += count($bets);
    $stats['investment'] += count($bets) * 100;
    if (in_array($actualTrifecta, $bets, true)) {
        $stats['hits']++;
        $stats['payout'] += $payout;
    }
}

function validatePeriod(PDO $pdo, string $datasetPath, string $boatsPath, array $model): array
{
    $dataset = readCsvAssoc($datasetPath);
    $boatRows = readCsvAssoc($boatsPath);

    $boatsByRace = [];
    foreach ($boatRows as $b) {
        $raceCode = trim((string)($b['race_code'] ?? ''));
        if ($raceCode !== '') {
            $boatsByRace[$raceCode][] = $b;
        }
    }

    $payoutStmt = $pdo->prepare(
        'SELECT trifecta_payout FROM boat_race.race_payouts WHERE race_code = :race_code LIMIT 1'
    );

    $currentAll = makeStats();
    $rescueAll = makeStats();
    $currentTrigger = makeStats();
    $rescueTrigger = makeStats();

    $formalN = 0;
    $reconstructN = 0;
    $payoutMissing = 0;
    $triggerN = 0;
    $dates = [];

    foreach ($dataset as $row) {
        if (!formal($row)) {
            continue;
        }
        $formalN++;

        $raceCode = trim((string)($row['race_code'] ?? ''));
        $rk = rankAndKiru($boatsByRace[$raceCode] ?? []);
        if ($rk === null) {
            continue;
        }
        [$baseRank, $kiru, $byLane] = $rk;

        $originalHead = inum($row, 'honmei_head');
        if (($baseRank[0] ?? 0) !== $originalHead) {
            continue;
        }

        $actual1 = inum($row, 'actual_1st');
        $actual2 = inum($row, 'actual_2nd');
        $actual3 = inum($row, 'actual_3rd');
        if ($actual1 < 1 || $actual1 > 6 || $actual2 < 1 || $actual2 > 6 || $actual3 < 1 || $actual3 > 6) {
            continue;
        }

        $payoutStmt->execute([':race_code' => $raceCode]);
        $payoutRaw = $payoutStmt->fetchColumn();
        if ($payoutRaw === false || $payoutRaw === null || !is_numeric($payoutRaw)) {
            $payoutMissing++;
            continue;
        }
        $payout = (int)$payoutRaw;

        $reconstructN++;
        $date = trim((string)($row['race_date'] ?? ''));
        if ($date !== '') {
            $dates[] = $date;
        }

        [$curHead, $curRank, $curBet] = currentPrediction($row, $baseRank, $kiru, $model);
        $rescueHead = $curHead;
        $rescueRank = $curRank;
        $rescueBet = $curBet;

        $triggered = false;
        $lane1 = $byLane[1] ?? null;
        if (is_array($lane1) && $curHead !== 1 && inum($lane1, 'first_rank', 99) === 1) {
            $triggered = true;
            $triggerN++;
            $rescueHead = 1;
            $rescueRank = moveToFront($curRank, 1);
            $rescueBet = buildBet($rescueRank, $kiru, $rescueHead);
        }

        $actualTrifecta = "{$actual1}-{$actual2}-{$actual3}";
        addBetStat($currentAll, $curBet, $actualTrifecta, $payout);
        addBetStat($rescueAll, $rescueBet, $actualTrifecta, $payout);

        if ($triggered) {
            addBetStat($currentTrigger, $curBet, $actualTrifecta, $payout);
            addBetStat($rescueTrigger, $rescueBet, $actualTrifecta, $payout);
        }
    }

    sort($dates);

    return [
        'label' => basename($datasetPath),
        'start' => $dates[0] ?? '-',
        'end' => $dates ? $dates[count($dates) - 1] : '-',
        'formal_n' => $formalN,
        'eval_n' => $reconstructN,
        'payout_missing' => $payoutMissing,
        'trigger_n' => $triggerN,
        'current_all' => $currentAll,
        'rescue_all' => $rescueAll,
        'current_trigger' => $currentTrigger,
        'rescue_trigger' => $rescueTrigger,
    ];
}

function pct(int|float $num, int|float $den): float
{
    return $den > 0 ? ((float)$num * 100.0 / (float)$den) : 0.0;
}

function avg(int|float $sum, int $n): float
{
    return $n > 0 ? ((float)$sum / $n) : 0.0;
}

function recovery(array $s): float
{
    return pct($s['payout'], $s['investment']);
}

function printPair(string $label, array $current, array $rescue): void
{
    $curHit = pct($current['hits'], $current['races']);
    $newHit = pct($rescue['hits'], $rescue['races']);
    $curRoi = recovery($current);
    $newRoi = recovery($rescue);
    $curPts = avg($current['points'], $current['races']);
    $newPts = avg($rescue['points'], $rescue['races']);

    echo "\n【{$label}】\n";
    printf(
        "CURRENT N=%4d 平均=%5.2f点 的中=%6.2f%% 投資=%10s円 払戻=%10s円 ROI=%6.2f%%\n",
        $current['races'], $curPts, $curHit,
        number_format($current['investment']), number_format($current['payout']), $curRoi
    );
    printf(
        "RESCUE  N=%4d 平均=%5.2f点 的中=%6.2f%% 投資=%10s円 払戻=%10s円 ROI=%6.2f%%\n",
        $rescue['races'], $newPts, $newHit,
        number_format($rescue['investment']), number_format($rescue['payout']), $newRoi
    );
    printf(
        "差              点数=%+5.2f点 的中=%+6.2fpt 投資=%+10s円 払戻=%+10s円 ROI=%+6.2fpt\n",
        $newPts - $curPts,
        $newHit - $curHit,
        number_format($rescue['investment'] - $current['investment']),
        number_format($rescue['payout'] - $current['payout']),
        $newRoi - $curRoi
    );
}

function printResult(array $r, bool $pooled = false): void
{
    echo "\n" . str_repeat('=', 126) . "\n";
    echo ($pooled ? 'POOLED：' : '') . "1号艇一次1位レスキュー 前方回収率検証（本命買い目・1点100円）\n";
    echo str_repeat('=', 126) . "\n";
    echo "対象       : {$r['label']}\n";
    echo "期間       : {$r['start']} ～ {$r['end']}\n";
    echo "正式対象   : {$r['formal_n']}R\n";
    echo "評価可能   : {$r['eval_n']}R\n";
    echo "払戻不足   : {$r['payout_missing']}R\n";
    echo "トリガー   : {$r['trigger_n']}R\n";
    echo "固定条件   : 現行Web頭≠1 × 1号艇一次1位 → 1号艇へ戻す\n";

    printPair('トリガー部分', $r['current_trigger'], $r['rescue_trigger']);
    printPair('全評価レース', $r['current_all'], $r['rescue_all']);

    echo "\n判断方針:\n";
    echo "1. P1/P2の両方でトリガー部分の的中率改善が再現すること。\n";
    echo "2. 全評価レースでも的中率改善を維持すること。\n";
    echo "3. ROIが大きく悪化するなら本番接続しない。結果を見て場・閾値・別条件を追加しない。\n";
}

function poolStats(array $a, array $b): array
{
    foreach (['races', 'points', 'investment', 'hits', 'payout'] as $key) {
        $a[$key] += $b[$key];
    }
    return $a;
}

function poolResults(array $results): array
{
    $out = [
        'label' => 'POOLED',
        'start' => '-',
        'end' => '-',
        'formal_n' => 0,
        'eval_n' => 0,
        'payout_missing' => 0,
        'trigger_n' => 0,
        'current_all' => makeStats(),
        'rescue_all' => makeStats(),
        'current_trigger' => makeStats(),
        'rescue_trigger' => makeStats(),
    ];

    $starts = [];
    $ends = [];
    foreach ($results as $r) {
        if ($r['start'] !== '-') $starts[] = $r['start'];
        if ($r['end'] !== '-') $ends[] = $r['end'];
        foreach (['formal_n', 'eval_n', 'payout_missing', 'trigger_n'] as $key) {
            $out[$key] += (int)$r[$key];
        }
        foreach (['current_all', 'rescue_all', 'current_trigger', 'rescue_trigger'] as $key) {
            $out[$key] = poolStats($out[$key], $r[$key]);
        }
    }

    sort($starts);
    sort($ends);
    $out['start'] = $starts[0] ?? '-';
    $out['end'] = $ends ? $ends[count($ends) - 1] : '-';
    return $out;
}
