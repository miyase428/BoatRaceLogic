<?php
declare(strict_types=1);

/**
 * 現行「切る艇」健康診断
 *
 * 目的:
 *   一次評価による切り保護を試す前に、現行ロジックで切られた艇が
 *   実際にどの程度3着以内へ来ているかを把握する。
 *
 * Usage:
 *   php analysis/diagnose_current_cut_boats.php \
 *     analysis/output/final_prediction_boats_20260615_20260714.csv \
 *     analysis/output/final_prediction_boats_20260715_20260814.csv
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php diagnose_current_cut_boats.php <boats.csv> [boats2.csv ...]\n");
    exit(1);
}

$files = array_slice($argv, 1);
$pooled = createStats('POOLED');

foreach ($files as $file) {
    $stats = diagnoseFile($file);
    printStats($stats);
    mergeStats($pooled, $stats);
}

if (count($files) > 1) {
    printStats($pooled);
}

function diagnoseFile(string $file): array
{
    if (!is_file($file)) {
        throw new RuntimeException("CSVファイルが見つかりません: {$file}");
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
        'race_code',
        'lane_number',
        'first_total_score',
        'first_rank',
        'final3',
        'kiru',
        'final_rank',
        'actual_rank',
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
        if ($raceCode === '') {
            continue;
        }

        $boat = (int)$row[$map['lane_number']];
        if ($boat < 1 || $boat > 6) {
            continue;
        }

        $races[$raceCode][$boat] = [
            'boat' => $boat,
            'first_total_score' => (float)$row[$map['first_total_score']],
            'first_rank' => (int)$row[$map['first_rank']],
            'final3' => (float)$row[$map['final3']],
            'kiru' => (int)$row[$map['kiru']],
            'final_rank' => (int)$row[$map['final_rank']],
            'actual_rank' => trim((string)$row[$map['actual_rank']]) === ''
                ? 0
                : (int)$row[$map['actual_rank']],
        ];
    }
    fclose($fp);

    $stats = createStats(basename($file));
    $stats['loaded_races'] = count($races);

    foreach ($races as $raceCode => $boats) {
        if (count($boats) !== 6) {
            $stats['incomplete_races']++;
            continue;
        }

        ksort($boats);
        $stats['evaluable_races']++;

        $cutBoats = [];
        $headBoat = null;
        foreach ($boats as $boat => $row) {
            if ($row['final_rank'] === 1) {
                $headBoat = $boat;
            }
            if ($row['kiru'] === 1) {
                $cutBoats[$boat] = $row;
            }
        }

        if ($cutBoats) {
            $stats['races_with_cut']++;
            $cutCount = count($cutBoats);
            $stats['cut_count_distribution'][$cutCount] = ($stats['cut_count_distribution'][$cutCount] ?? 0) + 1;
        } else {
            $stats['cut_count_distribution'][0] = ($stats['cut_count_distribution'][0] ?? 0) + 1;
        }

        $raceHasCutTop3 = false;

        foreach ($cutBoats as $boat => $row) {
            $stats['cut_boats']++;
            $actual = $row['actual_rank'];

            if ($actual <= 0) {
                $stats['cut_actual_missing']++;
            } else {
                $stats['cut_actual_known']++;
                $stats['actual_rank_distribution'][$actual] = ($stats['actual_rank_distribution'][$actual] ?? 0) + 1;

                if ($actual <= 3) {
                    $stats['cut_top3']++;
                    $raceHasCutTop3 = true;
                }
            }

            $stats['by_boat'][$boat]['cut']++;
            if ($actual > 0 && $actual <= 3) {
                $stats['by_boat'][$boat]['top3']++;
            }

            $firstRank = $row['first_rank'];
            if ($firstRank >= 1 && $firstRank <= 6) {
                $stats['by_first_rank'][$firstRank]['cut']++;
                if ($actual > 0 && $actual <= 3) {
                    $stats['by_first_rank'][$firstRank]['top3']++;
                }
            }
        }

        if ($raceHasCutTop3) {
            $stats['races_cut_top3']++;
        }

        if ($headBoat !== null && ($boats[$headBoat]['actual_rank'] ?? 0) === 1) {
            $stats['head_actual1_races']++;

            $blocked = false;
            foreach ($cutBoats as $row) {
                if ($row['actual_rank'] === 2) {
                    $stats['head1_cut_actual2']++;
                    $blocked = true;
                }
                if ($row['actual_rank'] === 3) {
                    $stats['head1_cut_actual3']++;
                    $blocked = true;
                }
            }

            if ($blocked) {
                $stats['head1_blocked_by_cut']++;
            }
        }
    }

    return $stats;
}

function createStats(string $label): array
{
    $byBoat = [];
    $byFirstRank = [];
    for ($i = 1; $i <= 6; $i++) {
        $byBoat[$i] = ['cut' => 0, 'top3' => 0];
        $byFirstRank[$i] = ['cut' => 0, 'top3' => 0];
    }

    return [
        'label' => $label,
        'loaded_races' => 0,
        'evaluable_races' => 0,
        'incomplete_races' => 0,
        'races_with_cut' => 0,
        'cut_boats' => 0,
        'cut_actual_known' => 0,
        'cut_actual_missing' => 0,
        'cut_top3' => 0,
        'races_cut_top3' => 0,
        'head_actual1_races' => 0,
        'head1_blocked_by_cut' => 0,
        'head1_cut_actual2' => 0,
        'head1_cut_actual3' => 0,
        'actual_rank_distribution' => array_fill(1, 6, 0),
        'cut_count_distribution' => array_fill(0, 7, 0),
        'by_boat' => $byBoat,
        'by_first_rank' => $byFirstRank,
    ];
}

function mergeStats(array &$to, array $from): void
{
    $scalarKeys = [
        'loaded_races', 'evaluable_races', 'incomplete_races', 'races_with_cut',
        'cut_boats', 'cut_actual_known', 'cut_actual_missing', 'cut_top3',
        'races_cut_top3', 'head_actual1_races', 'head1_blocked_by_cut',
        'head1_cut_actual2', 'head1_cut_actual3',
    ];

    foreach ($scalarKeys as $key) {
        $to[$key] += $from[$key];
    }

    foreach (range(1, 6) as $rank) {
        $to['actual_rank_distribution'][$rank] += $from['actual_rank_distribution'][$rank] ?? 0;
        $to['by_boat'][$rank]['cut'] += $from['by_boat'][$rank]['cut'] ?? 0;
        $to['by_boat'][$rank]['top3'] += $from['by_boat'][$rank]['top3'] ?? 0;
        $to['by_first_rank'][$rank]['cut'] += $from['by_first_rank'][$rank]['cut'] ?? 0;
        $to['by_first_rank'][$rank]['top3'] += $from['by_first_rank'][$rank]['top3'] ?? 0;
    }

    foreach (range(0, 6) as $n) {
        $to['cut_count_distribution'][$n] += $from['cut_count_distribution'][$n] ?? 0;
    }
}

function pct(int $num, int $den): float
{
    return $den > 0 ? ($num / $den * 100.0) : 0.0;
}

function printStats(array $s): void
{
    echo "\n";
    echo str_repeat('=', 100) . "\n";
    echo "現行『切る艇』健康診断\n";
    echo str_repeat('=', 100) . "\n";
    echo "対象: {$s['label']}\n";
    echo "読込レース             : {$s['loaded_races']}\n";
    echo "評価可能               : {$s['evaluable_races']}\n";
    echo "切る艇ありレース       : {$s['races_with_cut']} (" . number_format(pct($s['races_with_cut'], $s['evaluable_races']), 2) . "%)\n";
    echo "切られた艇数           : {$s['cut_boats']}\n";
    echo "実着順判明             : {$s['cut_actual_known']}\n";
    echo "実着順なし             : {$s['cut_actual_missing']}\n";

    echo "\n【切った艇の実着順】\n";
    for ($rank = 1; $rank <= 6; $rank++) {
        $n = $s['actual_rank_distribution'][$rank] ?? 0;
        printf("%d着 : %5d  (%6.2f%%)\n", $rank, $n, pct($n, $s['cut_actual_known']));
    }
    printf("3着以内合計 : %5d / %5d = %6.2f%%\n", $s['cut_top3'], $s['cut_actual_known'], pct($s['cut_top3'], $s['cut_actual_known']));
    printf("切る艇が1艇でも3着以内に来たレース : %5d / %5d = %6.2f%%\n",
        $s['races_cut_top3'], $s['evaluable_races'], pct($s['races_cut_top3'], $s['evaluable_races']));
    printf("切る艇ありレース内では             : %5d / %5d = %6.2f%%\n",
        $s['races_cut_top3'], $s['races_with_cut'], pct($s['races_cut_top3'], $s['races_with_cut']));

    echo "\n【本命が実1着だった場合の切り事故】\n";
    echo "対象（本命=実1着）     : {$s['head_actual1_races']}\n";
    printf("切る艇が実2着          : %5d (%6.2f%%)\n", $s['head1_cut_actual2'], pct($s['head1_cut_actual2'], $s['head_actual1_races']));
    printf("切る艇が実3着          : %5d (%6.2f%%)\n", $s['head1_cut_actual3'], pct($s['head1_cut_actual3'], $s['head_actual1_races']));
    printf("2着または3着に切る艇   : %5d / %5d = %6.2f%%\n",
        $s['head1_blocked_by_cut'], $s['head_actual1_races'], pct($s['head1_blocked_by_cut'], $s['head_actual1_races']));

    echo "\n【艇番別】\n";
    echo "艇  切り数   3着内   3連対率\n";
    foreach (range(1, 6) as $boat) {
        $cut = $s['by_boat'][$boat]['cut'];
        $top3 = $s['by_boat'][$boat]['top3'];
        printf("%d   %5d   %5d   %6.2f%%\n", $boat, $cut, $top3, pct($top3, $cut));
    }

    echo "\n【一次順位別】\n";
    echo "一次順位  切り数   3着内   3連対率\n";
    foreach (range(1, 6) as $rank) {
        $cut = $s['by_first_rank'][$rank]['cut'];
        $top3 = $s['by_first_rank'][$rank]['top3'];
        printf("%4d位     %5d   %5d   %6.2f%%\n", $rank, $cut, $top3, pct($top3, $cut));
    }

    echo "\n【1レースあたりの切る艇数】\n";
    foreach (range(0, 6) as $n) {
        $count = $s['cut_count_distribution'][$n] ?? 0;
        if ($count > 0) {
            printf("%d艇 : %5dレース (%6.2f%%)\n", $n, $count, pct($count, $s['evaluable_races']));
        }
    }

    if ($s['incomplete_races'] > 0) {
        echo "\n6艇不完備              : {$s['incomplete_races']}\n";
    }
}
