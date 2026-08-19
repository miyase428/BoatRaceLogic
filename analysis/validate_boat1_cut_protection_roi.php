<?php
declare(strict_types=1);

/**
 * 1号艇「切る艇」保護の回収率検証。
 *
 * 比較（本命買い目のみ・1点100円）:
 *   CURRENT : 旧kiruのまま
 *   R3_ONLY : 一次評価3位だけ切り解除
 *   B1_ONLY : 1号艇だけ切り解除
 *   R3_B1   : 一次評価3位 + 1号艇を切り解除
 *
 * 現在の採用候補は本命限定R3_ONLYなので、最重要比較は
 *   R3_ONLY → R3_B1
 * の増分。
 *
 * Usage:
 *   php analysis/validate_boat1_cut_protection_roi.php \
 *     analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
 *     analysis/output/final_prediction_boats_20260715_20260814_OLD.csv
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php analysis/validate_boat1_cut_protection_roi.php <boats.csv> [boats2.csv ...]\n");
    exit(1);
}

require_once __DIR__ . '/../common/db_connect.php';
$pdo = getPDO();

$files = array_slice($argv, 1);
$results = [];

foreach ($files as $file) {
    $result = validateFile($pdo, $file);
    printResult($result);
    $results[] = $result;
}

if (count($results) >= 2) {
    $pooled = poolResults($results);
    printResult($pooled, true);
}

function validateFile(PDO $pdo, string $file): array
{
    if (!is_file($file)) {
        throw new RuntimeException("CSVが見つかりません: {$file}");
    }

    $fp = fopen($file, 'rb');
    if ($fp === false) {
        throw new RuntimeException("CSVを開けません: {$file}");
    }

    $header = fgetcsv($fp);
    if ($header === false) {
        fclose($fp);
        throw new RuntimeException("CSVが空です: {$file}");
    }

    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);

    $map = [];
    foreach ($header as $i => $name) {
        $map[trim((string)$name)] = $i;
    }

    $required = [
        'race_code', 'lane_number', 'first_rank',
        'final_rank', 'kiru', 'actual_rank'
    ];

    foreach ($required as $name) {
        if (!array_key_exists($name, $map)) {
            fclose($fp);
            throw new RuntimeException("必要な列がありません: {$name} ({$file})");
        }
    }

    $races = [];

    while (($row = fgetcsv($fp)) !== false) {
        if (count($row) !== count($header)) {
            continue;
        }

        $raceCode = trim((string)$row[$map['race_code']]);
        $lane = (int)$row[$map['lane_number']];

        if ($raceCode === '' || $lane < 1 || $lane > 6) {
            continue;
        }

        $actualRaw = trim((string)$row[$map['actual_rank']]);

        $races[$raceCode][$lane] = [
            'lane' => $lane,
            'first_rank' => (int)$row[$map['first_rank']],
            'final_rank' => (int)$row[$map['final_rank']],
            'kiru' => (int)$row[$map['kiru']],
            'actual_rank' => $actualRaw === '' ? 0 : (int)$actualRaw,
        ];
    }

    fclose($fp);

    $scenarioNames = ['CURRENT', 'R3_ONLY', 'B1_ONLY', 'R3_B1'];
    $stats = [];

    foreach ($scenarioNames as $name) {
        $stats[$name] = makeStats($name);
    }

    $payoutStmt = $pdo->prepare(
        'SELECT trifecta_payout
           FROM boat_race.race_payouts
          WHERE race_code = :race_code
          LIMIT 1'
    );

    $base = [
        'read_races' => count($races),
        'eval_races' => 0,
        'six_missing' => 0,
        'actual_missing' => 0,
        'payout_missing' => 0,
        'boat1_cut_races' => 0,
        'boat1_cut_and_r3' => 0,
    ];

    foreach ($races as $raceCode => $boats) {
        if (count($boats) !== 6) {
            $base['six_missing']++;
            continue;
        }

        ksort($boats);

        $head = findByRank($boats, 'final_rank', 1);
        $actual1 = findByRank($boats, 'actual_rank', 1);
        $actual2 = findByRank($boats, 'actual_rank', 2);
        $actual3 = findByRank($boats, 'actual_rank', 3);

        if ($head === null || $actual1 === null || $actual2 === null || $actual3 === null) {
            $base['actual_missing']++;
            continue;
        }

        $payoutStmt->execute([':race_code' => $raceCode]);
        $payoutRaw = $payoutStmt->fetchColumn();

        if ($payoutRaw === false || $payoutRaw === null || !is_numeric($payoutRaw)) {
            $base['payout_missing']++;
            continue;
        }

        $payout = (int)$payoutRaw;
        $actualTrifecta = "{$actual1}-{$actual2}-{$actual3}";
        $base['eval_races']++;

        $currentKiru = [];
        foreach ($boats as $lane => $b) {
            $currentKiru[$lane] = ((int)$b['kiru'] === 1);
        }

        if (!empty($currentKiru[1])) {
            $base['boat1_cut_races']++;
            if ((int)$boats[1]['first_rank'] === 3) {
                $base['boat1_cut_and_r3']++;
            }
        }

        $betSets = [];

        foreach ($scenarioNames as $name) {
            $kiru = $currentKiru;

            foreach ($boats as $lane => $b) {
                if (empty($kiru[$lane])) {
                    continue;
                }

                $isR3 = ((int)$b['first_rank'] === 3);
                $isBoat1 = ((int)$lane === 1);

                $protect = match ($name) {
                    'R3_ONLY' => $isR3,
                    'B1_ONLY' => $isBoat1,
                    'R3_B1' => $isR3 || $isBoat1,
                    default => false,
                };

                if ($protect) {
                    $kiru[$lane] = false;
                }
            }

            $bets = buildBetSet($boats, $head, $kiru);
            $betSets[$name] = $bets;

            $pointCount = count($bets);
            $stats[$name]['races']++;
            $stats[$name]['points'] += $pointCount;
            $stats[$name]['investment'] += $pointCount * 100;

            if (in_array($actualTrifecta, $bets, true)) {
                $stats[$name]['hits']++;
                $stats[$name]['payout'] += $payout;
            }
        }

        foreach (['R3_ONLY', 'B1_ONLY', 'R3_B1'] as $name) {
            if ($betSets[$name] !== $betSets['CURRENT']) {
                $stats[$name]['affected_vs_current']++;
            }
        }

        if ($betSets['R3_B1'] !== $betSets['R3_ONLY']) {
            $stats['R3_B1']['affected_vs_r3']++;
        }
    }

    return [
        'label' => basename($file),
        'base' => $base,
        'stats' => $stats,
    ];
}

function makeStats(string $name): array
{
    return [
        'name' => $name,
        'races' => 0,
        'affected_vs_current' => 0,
        'affected_vs_r3' => 0,
        'points' => 0,
        'investment' => 0,
        'hits' => 0,
        'payout' => 0,
    ];
}

function findByRank(array $boats, string $key, int $target): ?int
{
    foreach ($boats as $lane => $b) {
        if ((int)($b[$key] ?? 0) === $target) {
            return (int)$lane;
        }
    }

    return null;
}

function buildBetSet(array $boats, int $head, array $kiru): array
{
    uasort($boats, static function(array $a, array $b): int {
        $ra = (int)($a['final_rank'] ?? 999);
        $rb = (int)($b['final_rank'] ?? 999);

        if ($ra === $rb) {
            return (int)$a['lane'] <=> (int)$b['lane'];
        }

        return $ra <=> $rb;
    });

    $aite = [];
    $third = [];

    foreach ($boats as $b) {
        $lane = (int)$b['lane'];

        if ($lane === $head || !empty($kiru[$lane])) {
            continue;
        }

        $third[] = $lane;

        if (count($aite) < 3) {
            $aite[] = $lane;
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

function pct(int|float $num, int|float $den): float
{
    return $den > 0 ? ((float)$num * 100.0 / (float)$den) : 0.0;
}

function avg(int|float $sum, int $n): float
{
    return $n > 0 ? ((float)$sum / (float)$n) : 0.0;
}

function recovery(array $s): float
{
    return pct((int)$s['payout'], (int)$s['investment']);
}

function marginalRecovery(array $from, array $to): ?float
{
    $extraInvestment = (int)$to['investment'] - (int)$from['investment'];
    $extraPayout = (int)$to['payout'] - (int)$from['payout'];

    if ($extraInvestment <= 0) {
        return null;
    }

    return pct($extraPayout, $extraInvestment);
}

function printResult(array $result, bool $pooled = false): void
{
    $base = $result['base'];
    $stats = $result['stats'];
    $current = $stats['CURRENT'];
    $r3 = $stats['R3_ONLY'];

    echo "\n" . str_repeat('=', 118) . "\n";
    echo ($pooled ? 'POOLED：' : '') . "1号艇『切る艇』保護 回収率検証（本命買い目・1点100円）\n";
    echo str_repeat('=', 118) . "\n";
    echo "対象                  : {$result['label']}\n";
    echo "読込レース            : {$base['read_races']}\n";
    echo "評価可能              : {$base['eval_races']}\n";
    echo "6艇不完備             : {$base['six_missing']}\n";
    echo "実1～3着不足          : {$base['actual_missing']}\n";
    echo "払戻不足              : {$base['payout_missing']}\n";
    echo "1号艇が切られた対象   : {$base['boat1_cut_races']}\n";
    echo "うち一次3位と重複     : {$base['boat1_cut_and_r3']}\n\n";

    printf(
        "%-10s %10s %10s %10s %14s %14s %10s %11s %11s\n",
        '方式', '影響R', '平均点数', '的中率', '購入金額', '払戻', '回収率', 'CURRENT差', '点数差'
    );
    echo str_repeat('-', 118) . "\n";

    foreach (['CURRENT', 'R3_ONLY', 'B1_ONLY', 'R3_B1'] as $name) {
        $s = $stats[$name];
        $avgPoints = avg($s['points'], $s['races']);
        $hitRate = pct($s['hits'], $s['races']);
        $rec = recovery($s);
        $curRec = recovery($current);
        $curAvg = avg($current['points'], $current['races']);

        printf(
            "%-10s %10d %9.2f点 %9.2f%% %12s円 %12s円 %9.2f%% %+10.2fpt %+9.2f点\n",
            $name,
            $s['affected_vs_current'],
            $avgPoints,
            $hitRate,
            number_format($s['investment']),
            number_format($s['payout']),
            $rec,
            $rec - $curRec,
            $avgPoints - $curAvg
        );
    }

    echo "\n【CURRENT比】\n";
    foreach (['R3_ONLY', 'B1_ONLY', 'R3_B1'] as $name) {
        $s = $stats[$name];
        printf(
            "%s : 的中 %d → %d (%+d件) / 投資 %+s円 / 払戻 %+s円\n",
            $name,
            $current['hits'],
            $s['hits'],
            $s['hits'] - $current['hits'],
            number_format($s['investment'] - $current['investment']),
            number_format($s['payout'] - $current['payout'])
        );
    }

    $r3b1 = $stats['R3_B1'];
    $extraInvestment = $r3b1['investment'] - $r3['investment'];
    $extraPayout = $r3b1['payout'] - $r3['payout'];
    $extraHits = $r3b1['hits'] - $r3['hits'];
    $marginal = marginalRecovery($r3, $r3b1);

    echo "\n【最重要: R3_ONLY → R3_B1 の追加効果】\n";
    echo "影響レース            : {$r3b1['affected_vs_r3']}\n";
    echo "的中増減              : " . sprintf('%+d', $extraHits) . "件\n";
    echo "追加投資              : " . number_format($extraInvestment) . "円\n";
    echo "追加払戻              : " . number_format($extraPayout) . "円\n";
    echo "全体回収率            : " . number_format(recovery($r3), 2) . "% → " . number_format(recovery($r3b1), 2) . "% (" . sprintf('%+.2fpt', recovery($r3b1) - recovery($r3)) . ")\n";
    echo "追加分の限界回収率    : " . ($marginal === null ? '-' : number_format($marginal, 2) . '%') . "\n";

    echo "\n判断方針: P1・P2の両方でR3_ONLY→R3_B1が悪化しにくく、POOLEDでも回収率改善かつ追加分の限界回収率が十分なら1号艇保護を採用候補とする。\n";
}

function poolResults(array $results): array
{
    $scenarioNames = ['CURRENT', 'R3_ONLY', 'B1_ONLY', 'R3_B1'];

    $pooled = [
        'label' => 'POOLED',
        'base' => [
            'read_races' => 0,
            'eval_races' => 0,
            'six_missing' => 0,
            'actual_missing' => 0,
            'payout_missing' => 0,
            'boat1_cut_races' => 0,
            'boat1_cut_and_r3' => 0,
        ],
        'stats' => [],
    ];

    foreach ($scenarioNames as $name) {
        $pooled['stats'][$name] = makeStats($name);
    }

    foreach ($results as $result) {
        foreach ($pooled['base'] as $key => $_) {
            $pooled['base'][$key] += (int)($result['base'][$key] ?? 0);
        }

        foreach ($scenarioNames as $name) {
            foreach ([
                'races', 'affected_vs_current', 'affected_vs_r3',
                'points', 'investment', 'hits', 'payout'
            ] as $key) {
                $pooled['stats'][$name][$key] += (int)($result['stats'][$name][$key] ?? 0);
            }
        }
    }

    return $pooled;
}
