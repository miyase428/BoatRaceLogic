<?php
/**
 * final3 同点時だけ一次評価(first_total_score)をタイブレークに使った場合の
 * 「相手候補（2着候補・最大3艇）」への影響を検証する。
 *
 * 重要:
 * - 本命頭は現行 final_rank 1位を固定する（STEP4の本命昇格をそのまま尊重）
 * - kiru 判定も現行CSVを固定する
 * - 変更するのは「頭以外の final3 同点順」だけ
 * - 候補案: final3 DESC -> first_total_score DESC -> 艇番 ASC
 * - 現行: final_rank 順（同点は現行実装上ほぼ艇番順）
 *
 * Usage:
 *   php analysis/validate_primary_tiebreak_opponent.php <boats.csv> [boats2.csv ...]
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php analysis/validate_primary_tiebreak_opponent.php <boats.csv> [boats2.csv ...]\n");
    exit(1);
}

$files = array_slice($argv, 1);
$allStats = createStats('POOLED');
$validFileCount = 0;

foreach ($files as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "CSVファイルが見つかりません: {$file}\n");
        continue;
    }

    $races = loadRaces($file);
    $stats = evaluateRaces($races, basename($file));
    printStats($stats);
    mergeStats($allStats, $stats);
    $validFileCount++;
}

if ($validFileCount === 0) {
    exit(1);
}

if ($validFileCount >= 2) {
    printStats($allStats, true);
}

function loadRaces(string $file): array
{
    $fp = fopen($file, 'r');
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
        'race_code', 'lane_number', 'first_total_score',
        'final3', 'kiru', 'final_rank', 'actual_rank',
    ];
    foreach ($required as $col) {
        if (!array_key_exists($col, $map)) {
            fclose($fp);
            throw new RuntimeException("必要な列がありません: {$col} ({$file})");
        }
    }

    $races = [];
    while (($row = fgetcsv($fp)) !== false) {
        if (count($row) < count($header)) {
            continue;
        }

        $raceCode = trim((string)$row[$map['race_code']]);
        if ($raceCode === '') {
            continue;
        }

        $lane = (int)$row[$map['lane_number']];
        if ($lane < 1 || $lane > 6) {
            continue;
        }

        $actualRaw = trim((string)$row[$map['actual_rank']]);
        $actualRank = ($actualRaw !== '' && is_numeric($actualRaw)) ? (int)$actualRaw : 0;

        $races[$raceCode][$lane] = [
            'lane' => $lane,
            'first' => (float)$row[$map['first_total_score']],
            'final3' => (float)$row[$map['final3']],
            'kiru' => (int)$row[$map['kiru']],
            'final_rank' => (int)$row[$map['final_rank']],
            'actual_rank' => $actualRank,
        ];
    }
    fclose($fp);

    return $races;
}

function evaluateRaces(array $races, string $label): array
{
    $s = createStats($label);
    $s['loaded_races'] = count($races);

    foreach ($races as $raceCode => $boats) {
        if (count($boats) !== 6) {
            $s['skip_incomplete']++;
            continue;
        }

        ksort($boats);
        $lanes = array_keys($boats);
        if ($lanes !== [1, 2, 3, 4, 5, 6]) {
            $s['skip_incomplete']++;
            continue;
        }

        $currentOrder = array_values($boats);
        usort($currentOrder, static function (array $a, array $b): int {
            $ra = $a['final_rank'];
            $rb = $b['final_rank'];
            if ($ra === $rb) {
                return $a['lane'] <=> $b['lane'];
            }
            return $ra <=> $rb;
        });

        $head = (int)($currentOrder[0]['lane'] ?? 0);
        if ($head < 1 || $head > 6) {
            $s['skip_no_head']++;
            continue;
        }

        $actualByRank = [];
        foreach ($boats as $b) {
            $r = (int)$b['actual_rank'];
            if ($r >= 1 && $r <= 6 && !isset($actualByRank[$r])) {
                $actualByRank[$r] = (int)$b['lane'];
            }
        }

        if (!isset($actualByRank[2])) {
            $s['skip_no_actual2']++;
            continue;
        }

        $candidateOthers = array_values(array_filter(
            $boats,
            static fn(array $b): bool => (int)$b['lane'] !== $head
        ));
        usort($candidateOthers, static function (array $a, array $b): int {
            if ($a['final3'] != $b['final3']) {
                return $a['final3'] < $b['final3'] ? 1 : -1;
            }
            if ($a['first'] != $b['first']) {
                return $a['first'] < $b['first'] ? 1 : -1;
            }
            return $a['lane'] <=> $b['lane'];
        });

        $candidateOrder = [$boats[$head]];
        foreach ($candidateOthers as $b) {
            $candidateOrder[] = $b;
        }

        $currentAite = buildAite($currentOrder, $head);
        $candidateAite = buildAite($candidateOrder, $head);

        $s['eligible']++;
        $actual2 = (int)$actualByRank[2];
        $cur2 = in_array($actual2, $currentAite, true);
        $new2 = in_array($actual2, $candidateAite, true);
        if ($cur2) $s['current_2nd_hit']++;
        if ($new2) $s['candidate_2nd_hit']++;

        if ($cur2 && !$new2) $s['hit_to_miss']++;
        if (!$cur2 && $new2) $s['miss_to_hit']++;

        $sameAite = ($currentAite === $candidateAite);
        if (!$sameAite) {
            $s['affected']++;

            $removed = array_values(array_diff($currentAite, $candidateAite));
            $added = array_values(array_diff($candidateAite, $currentAite));

            foreach ($removed as $lane) {
                $r = (int)($boats[$lane]['actual_rank'] ?? 0);
                if ($r > 0) {
                    $s['removed_actual_sum'] += $r;
                    $s['removed_actual_n']++;
                }
            }
            foreach ($added as $lane) {
                $r = (int)($boats[$lane]['actual_rank'] ?? 0);
                if ($r > 0) {
                    $s['added_actual_sum'] += $r;
                    $s['added_actual_n']++;
                }
            }

            if (count($s['examples']) < 10) {
                $s['examples'][] = [
                    'race_code' => $raceCode,
                    'head' => $head,
                    'current' => implode('', $currentAite),
                    'candidate' => implode('', $candidateAite),
                    'actual2' => $actual2,
                    'removed' => implode('', $removed),
                    'added' => implode('', $added),
                ];
            }
        }

        // 本命買い目への影響（3着候補は現行どおり「切る艇以外すべて」なので不変）
        if (isset($actualByRank[1], $actualByRank[3]) && (int)$actualByRank[1] === $head) {
            $s['head_actual1']++;
            $actual3 = (int)$actualByRank[3];
            $thirdOk = $actual3 !== $head && ((int)($boats[$actual3]['kiru'] ?? 0) === 0);
            if ($cur2 && $thirdOk) $s['current_bet_hit']++;
            if ($new2 && $thirdOk) $s['candidate_bet_hit']++;
        }
    }

    return $s;
}

function buildAite(array $order, int $head): array
{
    $aite = [];
    foreach ($order as $b) {
        $lane = (int)$b['lane'];
        if ($lane === $head) continue;
        if ((int)$b['kiru'] === 1) continue;
        $aite[] = $lane;
        if (count($aite) >= 3) break;
    }
    sort($aite);
    return $aite;
}

function createStats(string $label): array
{
    return [
        'label' => $label,
        'loaded_races' => 0,
        'eligible' => 0,
        'affected' => 0,
        'current_2nd_hit' => 0,
        'candidate_2nd_hit' => 0,
        'hit_to_miss' => 0,
        'miss_to_hit' => 0,
        'head_actual1' => 0,
        'current_bet_hit' => 0,
        'candidate_bet_hit' => 0,
        'removed_actual_sum' => 0,
        'removed_actual_n' => 0,
        'added_actual_sum' => 0,
        'added_actual_n' => 0,
        'skip_incomplete' => 0,
        'skip_no_head' => 0,
        'skip_no_actual2' => 0,
        'examples' => [],
    ];
}

function mergeStats(array &$to, array $from): void
{
    $sumKeys = [
        'loaded_races','eligible','affected','current_2nd_hit','candidate_2nd_hit',
        'hit_to_miss','miss_to_hit','head_actual1','current_bet_hit','candidate_bet_hit',
        'removed_actual_sum','removed_actual_n','added_actual_sum','added_actual_n',
        'skip_incomplete','skip_no_head','skip_no_actual2',
    ];
    foreach ($sumKeys as $k) {
        $to[$k] += $from[$k];
    }
}

function pct(int|float $n, int|float $d): float
{
    return $d > 0 ? ($n / $d * 100.0) : 0.0;
}

function printStats(array $s, bool $pooled = false): void
{
    echo "\n";
    echo str_repeat('=', 100) . "\n";
    echo ($pooled ? 'POOLED：' : '') . "final3同点 → 一次評価タイブレーク 相手候補検証\n";
    echo str_repeat('=', 100) . "\n";
    echo "対象: {$s['label']}\n";
    echo "読込レース             : {$s['loaded_races']}\n";
    echo "評価可能               : {$s['eligible']}\n";
    echo "相手候補が変わるレース : {$s['affected']} (" . number_format(pct($s['affected'], $s['eligible']), 2) . "%)\n";

    echo "\n【実2着の相手候補3艇への捕捉率】\n";
    printf("現行                  : %d / %d = %.2f%%\n", $s['current_2nd_hit'], $s['eligible'], pct($s['current_2nd_hit'], $s['eligible']));
    printf("一次タイブレーク      : %d / %d = %.2f%%\n", $s['candidate_2nd_hit'], $s['eligible'], pct($s['candidate_2nd_hit'], $s['eligible']));
    printf("差                    : %+.2f pt\n", pct($s['candidate_2nd_hit'], $s['eligible']) - pct($s['current_2nd_hit'], $s['eligible']));
    echo "外れ→的中             : {$s['miss_to_hit']}\n";
    echo "的中→外れ             : {$s['hit_to_miss']}\n";

    echo "\n【本命頭が実1着だったレースでの買い目捕捉】\n";
    printf("対象（本命=実1着）     : %d\n", $s['head_actual1']);
    printf("現行                  : %d / %d = %.2f%%\n", $s['current_bet_hit'], $s['head_actual1'], pct($s['current_bet_hit'], $s['head_actual1']));
    printf("一次タイブレーク      : %d / %d = %.2f%%\n", $s['candidate_bet_hit'], $s['head_actual1'], pct($s['candidate_bet_hit'], $s['head_actual1']));
    printf("差                    : %+.2f pt\n", pct($s['candidate_bet_hit'], $s['head_actual1']) - pct($s['current_bet_hit'], $s['head_actual1']));

    if ($s['affected'] > 0) {
        echo "\n【入替艇の実着順平均（参考）】\n";
        $removedAvg = $s['removed_actual_n'] > 0 ? $s['removed_actual_sum'] / $s['removed_actual_n'] : 0.0;
        $addedAvg = $s['added_actual_n'] > 0 ? $s['added_actual_sum'] / $s['added_actual_n'] : 0.0;
        printf("現行から外れる艇      : %.3f\n", $removedAvg);
        printf("一次で入る艇          : %.3f\n", $addedAvg);
        printf("改善（+が良い）       : %+.3f\n", $removedAvg - $addedAvg);
    }

    echo "\n【除外】\n";
    echo "6艇不完備              : {$s['skip_incomplete']}\n";
    echo "本命なし               : {$s['skip_no_head']}\n";
    echo "実2着なし              : {$s['skip_no_actual2']}\n";

    if (!$pooled && !empty($s['examples'])) {
        echo "\n【変更例 最大10件】\n";
        foreach ($s['examples'] as $e) {
            printf(
                "%s head=%d 現行=%s → 一次=%s / 実2着=%d / OUT=%s IN=%s\n",
                $e['race_code'], $e['head'], $e['current'], $e['candidate'],
                $e['actual2'], $e['removed'], $e['added']
            );
        }
    }

    echo "\n判断基準: P1・P2の両方で『実2着捕捉率』が非悪化、かつ買い目捕捉も悪化しない場合だけ採用候補。\n";
    echo "この検証では本命頭・切る艇・final3そのものは変更しない。\n";
}
