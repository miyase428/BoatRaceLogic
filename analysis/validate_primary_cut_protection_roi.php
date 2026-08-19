<?php
declare(strict_types=1);

/**
 * 一次評価による「切る艇」保護の回収率検証。
 *
 * 比較:
 *   CURRENT: 現行kiruのまま
 *   R1: 一次1位なら切らない
 *   R2: 一次2位以内なら切らない
 *   R3_ONLY: 一次3位だけ切らない
 *   R3: 一次3位以内なら切らない
 *
 * 本命頭・final3順位は変更しない。
 * 本命買い目のみを1点100円で比較する。
 * 3連単払戻は boat_race.race_payouts.trifecta_payout を使用。
 *
 * Usage:
 *   php analysis/validate_primary_cut_protection_roi.php \
 *     analysis/output/final_prediction_boats_20260615_20260714.csv \
 *     analysis/output/final_prediction_boats_20260715_20260814.csv
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php analysis/validate_primary_cut_protection_roi.php <boats.csv> [boats2.csv ...]\n");
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
            throw new RuntimeException("必要な列がありません: {$name}");
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

    // 正数: 一次順位1～N位を保護
    // -3: 一次3位だけを保護（R3の効果切り分け用）
    $scenarioDefs = [
        'CURRENT' => 0,
        'R1' => 1,
        'R2' => 2,
        'R3_ONLY' => -3,
        'R3' => 3,
    ];

    $stats = [];
    foreach ($scenarioDefs as $name => $limit) {
        $stats[$name] = makeStats($name, $limit);
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

        $currentBets = null;

        foreach ($scenarioDefs as $name => $limit) {
            $kiru = $currentKiru;

            foreach ($boats as $lane => $b) {
                if (empty($kiru[$lane])) {
                    continue;
                }

                $firstRank = (int)$b['first_rank'];

                if ($limit > 0 && $firstRank >= 1 && $firstRank <= $limit) {
                    $kiru[$lane] = false;
                } elseif ($limit === -3 && $firstRank === 3) {
                    $kiru[$lane] = false;
                }
            }

            $bets = buildBetSet($boats, $head, $kiru);
            if ($name === 'CURRENT') {
                $currentBets = $bets;
            } elseif ($currentBets !== null && $bets !== $currentBets) {
                $stats[$name]['affected_races']++;
            }

            $pointCount = count($bets);
            $stats[$name]['races']++;
            $stats[$name]['points'] += $pointCount;
            $stats[$name]['investment'] += $pointCount * 100;

            if (in_array($actualTrifecta, $bets, true)) {
                $stats[$name]['hits']++;
                $stats[$name]['payout'] += $payout;
                $stats[$name]['hit_payout_sum'] += $payout;
            }
        }
    }

    return [
        'label' => basename($file),
        'base' => $base,
        'stats' => $stats,
    ];
}

function makeStats(string $name, int $limit): array
{
    return [
        'name' => $name,
        'limit' => $limit,
        'races' => 0,
        'affected_races' => 0,
        'points' => 0,
        'investment' => 0,
        'hits' => 0,
        'payout' => 0,
        'hit_payout_sum' => 0,
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
        if ($lane === $head) {
            continue;
        }
        if (!empty($kiru[$lane])) {
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
    return $n > 0 ? ((float)$sum / $n) : 0.0;
}

function recovery(array $s): float
{
    return pct((int)$s['payout'], (int)$s['investment']);
}

function printResult(array $result, bool $pooled = false): void
{
    $base = $result['base'];
    $stats = $result['stats'];
    $current = $stats['CURRENT'];

    echo "\n" . str_repeat('=', 112) . "\n";
    echo ($pooled ? 'POOLED：' : '') . "一次評価『切る艇』保護 回収率検証（本命買い目・1点100円）\n";
    echo str_repeat('=', 112) . "\n";
    echo "対象                  : {$result['label']}\n";
    echo "読込レース            : {$base['read_races']}\n";
    echo "評価可能              : {$base['eval_races']}\n";
    echo "6艇不完備             : {$base['six_missing']}\n";
    echo "実1～3着不足          : {$base['actual_missing']}\n";
    echo "払戻不足              : {$base['payout_missing']}\n\n";

    printf(
        "%-10s %9s %10s %10s %14s %14s %10s %11s %11s\n",
        '方式', '影響R', '平均点数', '的中率', '購入金額', '払戻', '回収率', '回収率差', '点数差'
    );
    echo str_repeat('-', 114) . "\n";

    foreach (['CURRENT','R1','R2','R3_ONLY','R3'] as $name) {
        $s = $stats[$name];
        $hitRate = pct($s['hits'], $s['races']);
        $rec = recovery($s);
        $avgPoints = avg($s['points'], $s['races']);
        $curRec = recovery($current);
        $curAvgPoints = avg($current['points'], $current['races']);

        printf(
            "%-10s %9d %9.2f点 %9.2f%% %12s円 %12s円 %9.2f%% %+10.2fpt %+9.2f点\n",
            $name,
            $s['affected_races'],
            $avgPoints,
            $hitRate,
            number_format($s['investment']),
            number_format($s['payout']),
            $rec,
            $rec - $curRec,
            $avgPoints - $curAvgPoints
        );
    }

    echo "\n【現行比の的中増減】\n";
    foreach (['R1','R2','R3_ONLY','R3'] as $name) {
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

    echo "\n判断方針: R3_ONLYで一次3位単独の寄与を確認し、P1・P2の両方で的中増が再現し、回収率が大きく悪化しない単純ルールだけを採用候補とする。\n";
}

function poolResults(array $results): array
{
    $pooled = [
        'label' => 'POOLED',
        'base' => [
            'read_races' => 0,
            'eval_races' => 0,
            'six_missing' => 0,
            'actual_missing' => 0,
            'payout_missing' => 0,
        ],
        'stats' => [
            'CURRENT' => makeStats('CURRENT', 0),
            'R1' => makeStats('R1', 1),
            'R2' => makeStats('R2', 2),
            'R3_ONLY' => makeStats('R3_ONLY', -3),
            'R3' => makeStats('R3', 3),
        ],
    ];

    foreach ($results as $result) {
        foreach ($pooled['base'] as $key => $_) {
            $pooled['base'][$key] += (int)($result['base'][$key] ?? 0);
        }

        foreach (['CURRENT','R1','R2','R3_ONLY','R3'] as $name) {
            foreach (['races','affected_races','points','investment','hits','payout','hit_payout_sum'] as $key) {
                $pooled['stats'][$name][$key] += (int)($result['stats'][$name][$key] ?? 0);
            }
        }
    }

    return $pooled;
}
