<?php
declare(strict_types=1);

/**
 * 一次評価を「切る艇」保護へ使う価値があるかを検証する。
 *
 * 対象ルール（固定3案）:
 *   R1: 一次1位なら切らない
 *   R2: 一次2位以内なら切らない
 *   R3: 一次3位以内なら切らない
 *
 * 本命頭・final3順位は変更しない。
 * 現行のkiru=1だけを条件付きで0へ戻し、
 * その後の相手候補/3着候補を現行buildSummary相当で再構成する。
 *
 * Usage:
 *   php analysis/validate_primary_cut_protection.php \
 *     analysis/output/final_prediction_boats_20260615_20260714.csv \
 *     analysis/output/final_prediction_boats_20260715_20260814.csv
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php analysis/validate_primary_cut_protection.php <boats.csv> [boats2.csv ...]\n");
    exit(1);
}

$files = array_slice($argv, 1);
$allResults = [];

foreach ($files as $file) {
    $result = validateFile($file);
    printResult($result);
    $allResults[] = $result;
}

if (count($allResults) >= 2) {
    $pooled = poolResults($allResults);
    printResult($pooled, true);
}

function validateFile(string $file): array
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
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    $map = [];
    foreach ($header as $i => $name) {
        $map[trim((string)$name)] = $i;
    }

    $required = [
        'race_code','lane_number','first_rank','first_total_score',
        'final_rank','kiru','actual_rank'
    ];
    foreach ($required as $name) {
        if (!array_key_exists($name, $map)) {
            throw new RuntimeException("必要な列がありません: {$name}");
        }
    }

    $races = [];
    while (($row = fgetcsv($fp)) !== false) {
        if (count($row) !== count($header)) continue;
        $race = trim((string)$row[$map['race_code']]);
        if ($race === '') continue;

        $lane = (int)$row[$map['lane_number']];
        if ($lane < 1 || $lane > 6) continue;

        $races[$race][$lane] = [
            'lane' => $lane,
            'first_rank' => (int)$row[$map['first_rank']],
            'first_total_score' => (float)$row[$map['first_total_score']],
            'final_rank' => (int)$row[$map['final_rank']],
            'kiru' => (int)$row[$map['kiru']],
            'actual_rank' => ($row[$map['actual_rank']] === '' ? 0 : (int)$row[$map['actual_rank']]),
        ];
    }
    fclose($fp);

    $rules = [
        1 => makeRuleStats('一次1位なら切らない'),
        2 => makeRuleStats('一次2位以内なら切らない'),
        3 => makeRuleStats('一次3位以内なら切らない'),
    ];

    $base = [
        'read_races' => count($races),
        'eval_races' => 0,
        'six_missing' => 0,
        'actual_missing' => 0,
        'head_actual1' => 0,
        'current_second_hit' => 0,
        'current_third_hit' => 0,
        'current_bet_hit' => 0,
        'current_points_sum' => 0,
        'current_points_n' => 0,
        'cut_boats' => 0,
        'cut_top3' => 0,
        'cut_by_first_rank' => array_fill(1, 6, ['n'=>0,'top3'=>0,'rank_sum'=>0.0,'rank_n'=>0]),
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

        $base['eval_races']++;

        $currentKiru = [];
        foreach ($boats as $lane => $b) {
            $currentKiru[$lane] = ((int)$b['kiru'] === 1);
            if ($currentKiru[$lane]) {
                $base['cut_boats']++;
                $fr = (int)$b['first_rank'];
                if ($fr >= 1 && $fr <= 6) {
                    $base['cut_by_first_rank'][$fr]['n']++;
                    if ((int)$b['actual_rank'] >= 1 && (int)$b['actual_rank'] <= 3) {
                        $base['cut_by_first_rank'][$fr]['top3']++;
                        $base['cut_top3']++;
                    }
                    if ((int)$b['actual_rank'] > 0) {
                        $base['cut_by_first_rank'][$fr]['rank_sum'] += (int)$b['actual_rank'];
                        $base['cut_by_first_rank'][$fr]['rank_n']++;
                    }
                }
            }
        }

        [$curAite, $curThird] = buildCandidates($boats, $head, $currentKiru);
        $curPoints = countBetPoints($curAite, $curThird);
        $base['current_points_sum'] += $curPoints;
        $base['current_points_n']++;

        if ($head === $actual1) {
            $base['head_actual1']++;
            $secondHit = in_array($actual2, $curAite, true);
            $thirdHit = in_array($actual3, $curThird, true);
            if ($secondHit) $base['current_second_hit']++;
            if ($thirdHit) $base['current_third_hit']++;
            if ($secondHit && $thirdHit) $base['current_bet_hit']++;
        }

        foreach ($rules as $limit => &$stats) {
            $newKiru = $currentKiru;
            $rescued = [];

            foreach ($boats as $lane => $b) {
                if ($newKiru[$lane] && (int)$b['first_rank'] >= 1 && (int)$b['first_rank'] <= $limit) {
                    $newKiru[$lane] = false;
                    $rescued[] = $lane;
                    $stats['rescued_boats']++;
                    if ((int)$b['actual_rank'] >= 1 && (int)$b['actual_rank'] <= 3) {
                        $stats['rescued_top3']++;
                    }
                    if ((int)$b['actual_rank'] === 2) $stats['rescued_actual2']++;
                    if ((int)$b['actual_rank'] === 3) $stats['rescued_actual3']++;
                }
            }

            if (!empty($rescued)) {
                $stats['affected_races']++;
            }

            [$newAite, $newThird] = buildCandidates($boats, $head, $newKiru);
            $newPoints = countBetPoints($newAite, $newThird);
            $stats['points_sum'] += $newPoints;
            $stats['points_n']++;

            if ($head === $actual1) {
                $stats['head_actual1']++;
                $curSecond = in_array($actual2, $curAite, true);
                $curThirdHit = in_array($actual3, $curThird, true);
                $curBet = $curSecond && $curThirdHit;

                $newSecond = in_array($actual2, $newAite, true);
                $newThirdHit = in_array($actual3, $newThird, true);
                $newBet = $newSecond && $newThirdHit;

                if ($newSecond) $stats['second_hit']++;
                if ($newThirdHit) $stats['third_hit']++;
                if ($newBet) $stats['bet_hit']++;

                if (!$curBet && $newBet) $stats['miss_to_hit']++;
                if ($curBet && !$newBet) $stats['hit_to_miss']++;
            }
        }
        unset($stats);
    }

    return [
        'label' => basename($file),
        'base' => $base,
        'rules' => $rules,
    ];
}

function makeRuleStats(string $label): array
{
    return [
        'label' => $label,
        'affected_races' => 0,
        'rescued_boats' => 0,
        'rescued_top3' => 0,
        'rescued_actual2' => 0,
        'rescued_actual3' => 0,
        'head_actual1' => 0,
        'second_hit' => 0,
        'third_hit' => 0,
        'bet_hit' => 0,
        'miss_to_hit' => 0,
        'hit_to_miss' => 0,
        'points_sum' => 0,
        'points_n' => 0,
    ];
}

function findByRank(array $boats, string $key, int $target): ?int
{
    foreach ($boats as $lane => $b) {
        if ((int)($b[$key] ?? 0) === $target) return (int)$lane;
    }
    return null;
}

function buildCandidates(array $boats, int $head, array $kiru): array
{
    uasort($boats, static function(array $a, array $b): int {
        $ra = (int)($a['final_rank'] ?? 999);
        $rb = (int)($b['final_rank'] ?? 999);
        if ($ra === $rb) return (int)$a['lane'] <=> (int)$b['lane'];
        return $ra <=> $rb;
    });

    $aite = [];
    $third = [];
    foreach ($boats as $b) {
        $lane = (int)$b['lane'];
        if ($lane === $head) continue;
        if (!empty($kiru[$lane])) continue;
        $third[] = $lane;
        if (count($aite) < 3) $aite[] = $lane;
    }

    sort($aite);
    sort($third);
    return [$aite, $third];
}

function countBetPoints(array $aite, array $third): int
{
    $n = 0;
    foreach ($aite as $second) {
        foreach ($third as $thirdBoat) {
            if ($second !== $thirdBoat) $n++;
        }
    }
    return $n;
}

function pct(int $num, int $den): float
{
    return $den > 0 ? $num * 100.0 / $den : 0.0;
}

function avg(float|int $sum, int $n): float
{
    return $n > 0 ? (float)$sum / $n : 0.0;
}

function printResult(array $result, bool $pooled = false): void
{
    $base = $result['base'];
    echo "\n" . str_repeat('=', 106) . "\n";
    echo ($pooled ? 'POOLED：' : '') . "一次評価による『切る艇』保護 検証\n";
    echo str_repeat('=', 106) . "\n";
    echo "対象                  : {$result['label']}\n";
    echo "読込レース            : {$base['read_races']}\n";
    echo "評価可能              : {$base['eval_races']}\n";
    echo "現行の切る艇数        : {$base['cut_boats']}\n\n";

    echo "【現行で切られた艇：一次順位別】\n";
    echo "一次順位   件数      実3連対      3連対率      平均着順\n";
    for ($r = 1; $r <= 6; $r++) {
        $x = $base['cut_by_first_rank'][$r];
        printf(
            "%4d位   %6d   %6d   %8.2f%%   %8.3f\n",
            $r,
            $x['n'],
            $x['top3'],
            pct($x['top3'], $x['n']),
            avg($x['rank_sum'], $x['rank_n'])
        );
    }

    echo "\n【現行：本命頭が実1着のとき】\n";
    printf("対象                  : %d\n", $base['head_actual1']);
    printf("実2着を相手3艇で捕捉  : %d / %d = %.2f%%\n", $base['current_second_hit'], $base['head_actual1'], pct($base['current_second_hit'], $base['head_actual1']));
    printf("実3着を3着候補で捕捉  : %d / %d = %.2f%%\n", $base['current_third_hit'], $base['head_actual1'], pct($base['current_third_hit'], $base['head_actual1']));
    printf("買い目捕捉            : %d / %d = %.2f%%\n", $base['current_bet_hit'], $base['head_actual1'], pct($base['current_bet_hit'], $base['head_actual1']));
    printf("平均買い目点数        : %.2f点\n", avg($base['current_points_sum'], $base['current_points_n']));

    foreach ($result['rules'] as $limit => $s) {
        echo "\n" . str_repeat('-', 86) . "\n";
        echo "【R{$limit}: {$s['label']}】\n";
        echo str_repeat('-', 86) . "\n";
        printf("影響レース            : %d (%.2f%%)\n", $s['affected_races'], pct($s['affected_races'], $base['eval_races']));
        printf("救済する艇            : %d\n", $s['rescued_boats']);
        printf("救済艇の実3連対       : %d / %d = %.2f%%\n", $s['rescued_top3'], $s['rescued_boats'], pct($s['rescued_top3'], $s['rescued_boats']));
        printf("  うち実2着           : %d\n", $s['rescued_actual2']);
        printf("  うち実3着           : %d\n", $s['rescued_actual3']);
        printf("実2着相手捕捉         : %.2f%% → %.2f%%  (%+.2f pt)\n",
            pct($base['current_second_hit'], $base['head_actual1']),
            pct($s['second_hit'], $s['head_actual1']),
            pct($s['second_hit'], $s['head_actual1']) - pct($base['current_second_hit'], $base['head_actual1'])
        );
        printf("実3着候補捕捉         : %.2f%% → %.2f%%  (%+.2f pt)\n",
            pct($base['current_third_hit'], $base['head_actual1']),
            pct($s['third_hit'], $s['head_actual1']),
            pct($s['third_hit'], $s['head_actual1']) - pct($base['current_third_hit'], $base['head_actual1'])
        );
        printf("買い目捕捉            : %.2f%% → %.2f%%  (%+.2f pt)\n",
            pct($base['current_bet_hit'], $base['head_actual1']),
            pct($s['bet_hit'], $s['head_actual1']),
            pct($s['bet_hit'], $s['head_actual1']) - pct($base['current_bet_hit'], $base['head_actual1'])
        );
        printf("外れ→的中 / 的中→外れ: %d / %d\n", $s['miss_to_hit'], $s['hit_to_miss']);
        printf("平均買い目点数        : %.2f → %.2f点  (%+.2f点)\n",
            avg($base['current_points_sum'], $base['current_points_n']),
            avg($s['points_sum'], $s['points_n']),
            avg($s['points_sum'], $s['points_n']) - avg($base['current_points_sum'], $base['current_points_n'])
        );
    }

    echo "\n【除外】\n";
    echo "6艇不完備             : {$base['six_missing']}\n";
    echo "実1～3着/本命不足     : {$base['actual_missing']}\n";
    echo "\n判断方針: P1・P2の両方で買い目捕捉が改善し、点数増加との釣り合いが取れる単純ルールだけを採用候補とする。\n";
}

function poolResults(array $results): array
{
    $pooled = [
        'label' => 'POOLED',
        'base' => [
            'read_races'=>0,'eval_races'=>0,'six_missing'=>0,'actual_missing'=>0,
            'head_actual1'=>0,'current_second_hit'=>0,'current_third_hit'=>0,'current_bet_hit'=>0,
            'current_points_sum'=>0,'current_points_n'=>0,'cut_boats'=>0,'cut_top3'=>0,
            'cut_by_first_rank'=>array_fill(1,6,['n'=>0,'top3'=>0,'rank_sum'=>0.0,'rank_n'=>0]),
        ],
        'rules' => [
            1 => makeRuleStats('一次1位なら切らない'),
            2 => makeRuleStats('一次2位以内なら切らない'),
            3 => makeRuleStats('一次3位以内なら切らない'),
        ],
    ];

    foreach ($results as $res) {
        foreach (['read_races','eval_races','six_missing','actual_missing','head_actual1','current_second_hit','current_third_hit','current_bet_hit','current_points_sum','current_points_n','cut_boats','cut_top3'] as $k) {
            $pooled['base'][$k] += $res['base'][$k];
        }
        for ($r=1;$r<=6;$r++) {
            foreach (['n','top3','rank_sum','rank_n'] as $k) {
                $pooled['base']['cut_by_first_rank'][$r][$k] += $res['base']['cut_by_first_rank'][$r][$k];
            }
        }
        foreach ([1,2,3] as $limit) {
            foreach (['affected_races','rescued_boats','rescued_top3','rescued_actual2','rescued_actual3','head_actual1','second_hit','third_hit','bet_hit','miss_to_hit','hit_to_miss','points_sum','points_n'] as $k) {
                $pooled['rules'][$limit][$k] += $res['rules'][$limit][$k];
            }
        }
    }

    return $pooled;
}
