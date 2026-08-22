<?php

declare(strict_types=1);

/**
 * 戸田・多摩川・大村・下関 1号艇判断ズレ分析
 *
 * レース別CSV + 艇別CSVを2期間分まとめて使い、
 * 「Webが1号艇を本命にした判断 / しなかった判断」が実際どうだったかを見る。
 * DBにはアクセスしないため、長期間CSV生成中でも並行実行できる。
 *
 * 主な確認項目
 *   - Webが1号艇を本命にした時の1号艇勝率
 *   - Webが1号艇を本命にしなかった時の1号艇勝率（逃げ取りこぼし）
 *   - 1号艇勝利レースをWebが本命1号艇で拾えた率
 *   - 1号艇本命で負けた時、何号艇が勝ったか
 *   - その勝ち艇と1号艇の一次/二次スコア・順位差
 *   - 1号艇を本命にせず1号艇が勝った時、Webが何号艇を本命にしたか
 *   - その本命艇と1号艇の一次/二次評価比較
 *
 * Usage:
 *   php analysis/analyze_lane1_decision_by_stadium.php \
 *     analysis/output/final_prediction_races_20260615_20260714.csv \
 *     analysis/output/final_prediction_boats_20260615_20260714.csv \
 *     analysis/output/final_prediction_races_20260715_20260814.csv \
 *     analysis/output/final_prediction_boats_20260715_20260814.csv
 */

if ($argc < 5) {
    echo PHP_EOL;
    echo "使用方法:" . PHP_EOL;
    echo "  php analysis/analyze_lane1_decision_by_stadium.php <期間1_races> <期間1_boats> <期間2_races> <期間2_boats>" . PHP_EOL;
    echo PHP_EOL;
    exit(1);
}

[$script, $raceCsv1, $boatCsv1, $raceCsv2, $boatCsv2] = $argv;

foreach ([$raceCsv1, $boatCsv1, $raceCsv2, $boatCsv2] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "CSVが見つかりません: {$path}" . PHP_EOL);
        exit(1);
    }
}

const TARGET_STADIUMS = ['戸田', '多摩川', '大村', '下関'];

function pct(int $count, int $total): float
{
    return $total > 0 ? ($count / $total) * 100.0 : 0.0;
}

function fmtPct(float $value): string
{
    return number_format($value, 2) . '%';
}

function readCsvAssoc(string $path, array $required): array
{
    $fp = fopen($path, 'rb');
    if ($fp === false) {
        throw new RuntimeException("CSVを開けません: {$path}");
    }

    $header = fgetcsv($fp);
    if ($header === false) {
        fclose($fp);
        throw new RuntimeException("CSVヘッダーを読めません: {$path}");
    }

    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $map = [];
    foreach ($header as $i => $name) {
        $map[(string)$name] = $i;
    }

    foreach ($required as $column) {
        if (!array_key_exists($column, $map)) {
            fclose($fp);
            throw new RuntimeException("必要な列がありません: {$column} ({$path})");
        }
    }

    $rows = [];
    while (($row = fgetcsv($fp)) !== false) {
        if (count($row) < count($header)) {
            continue;
        }
        $assoc = [];
        foreach ($map as $name => $i) {
            $assoc[$name] = $row[$i] ?? '';
        }
        $rows[] = $assoc;
    }

    fclose($fp);
    return $rows;
}

function loadPeriod(string $raceCsv, string $boatCsv): array
{
    $raceRows = readCsvAssoc($raceCsv, [
        'race_code', 'race_date', 'stadium_name',
        'honmei_head', 'taikou_head', 'actual_1st', 'actual_2nd', 'actual_3rd',
    ]);

    $boatRows = readCsvAssoc($boatCsv, [
        'race_code', 'stadium_name', 'lane_number',
        'first_total_score', 'first_rank',
        'second_score', 'second_rank',
        'final_rank', 'actual_rank',
    ]);

    $boats = [];
    foreach ($boatRows as $row) {
        $stadium = trim((string)$row['stadium_name']);
        if (!in_array($stadium, TARGET_STADIUMS, true)) {
            continue;
        }

        $raceCode = trim((string)$row['race_code']);
        $lane = (int)$row['lane_number'];
        if ($raceCode === '' || $lane < 1 || $lane > 6) {
            continue;
        }
        $boats[$raceCode][$lane] = $row;
    }

    $races = [];
    $startDate = null;
    $endDate = null;

    foreach ($raceRows as $row) {
        $stadium = trim((string)$row['stadium_name']);
        if (!in_array($stadium, TARGET_STADIUMS, true)) {
            continue;
        }

        $raceCode = trim((string)$row['race_code']);
        $date = trim((string)$row['race_date']);
        $honmei = (int)$row['honmei_head'];
        $taikou = (int)$row['taikou_head'];
        $actual1 = (int)$row['actual_1st'];
        $actual2 = (int)$row['actual_2nd'];
        $actual3 = (int)$row['actual_3rd'];

        if (
            $raceCode === '' ||
            $honmei < 1 || $honmei > 6 ||
            $actual1 < 1 || $actual1 > 6 ||
            $actual2 < 1 || $actual2 > 6 ||
            $actual3 < 1 || $actual3 > 6
        ) {
            continue;
        }

        $races[$raceCode] = [
            'race_code' => $raceCode,
            'race_date' => $date,
            'stadium' => $stadium,
            'honmei' => $honmei,
            'taikou' => $taikou,
            'actual1' => $actual1,
            'actual2' => $actual2,
            'actual3' => $actual3,
            'boats' => $boats[$raceCode] ?? [],
        ];

        if ($date !== '') {
            if ($startDate === null || $date < $startDate) $startDate = $date;
            if ($endDate === null || $date > $endDate) $endDate = $date;
        }
    }

    return [
        'races' => $races,
        'start_date' => $startDate,
        'end_date' => $endDate,
    ];
}

function numericValue(array $row, string $key): ?float
{
    $value = $row[$key] ?? null;
    return is_numeric($value) ? (float)$value : null;
}

function rankValue(array $row, string $key): ?int
{
    $value = $row[$key] ?? null;
    return is_numeric($value) ? (int)$value : null;
}

function emptyStats(): array
{
    return [
        'races' => 0,
        'actual1_win' => 0,
        'web1' => 0,
        'web1_win' => 0,
        'web1_loss' => 0,
        'web_not1' => 0,
        'web_not1_actual1_win' => 0,
        'missed_escape_taikou1' => 0,
        'loss_winners' => array_fill(1, 6, 0),
        'miss_heads' => array_fill(1, 6, 0),
        'loss_first_diff_sum' => 0.0,
        'loss_first_diff_n' => 0,
        'loss_second_diff_sum' => 0.0,
        'loss_second_diff_n' => 0,
        'loss_winner_first_better' => 0,
        'loss_winner_first_rank_better' => 0,
        'loss_first_rank_n' => 0,
        'loss_winner_second_better' => 0,
        'loss_winner_second_rank_better' => 0,
        'loss_second_rank_n' => 0,
        'miss_head_first_better' => 0,
        'miss_head_first_rank_better' => 0,
        'miss_first_rank_n' => 0,
        'miss_head_second_better' => 0,
        'miss_head_second_rank_better' => 0,
        'miss_second_rank_n' => 0,
    ];
}

function aggregate(array $races): array
{
    $stats = [];
    foreach (TARGET_STADIUMS as $stadium) {
        $stats[$stadium] = emptyStats();
    }

    foreach ($races as $race) {
        $stadium = $race['stadium'];
        if (!isset($stats[$stadium])) {
            continue;
        }

        $s =& $stats[$stadium];
        $s['races']++;

        $honmei = (int)$race['honmei'];
        $taikou = (int)$race['taikou'];
        $actual1 = (int)$race['actual1'];
        $boats = is_array($race['boats']) ? $race['boats'] : [];

        if ($actual1 === 1) {
            $s['actual1_win']++;
        }

        if ($honmei === 1) {
            $s['web1']++;
            if ($actual1 === 1) {
                $s['web1_win']++;
            } else {
                $s['web1_loss']++;
                if ($actual1 >= 2 && $actual1 <= 6) {
                    $s['loss_winners'][$actual1]++;
                }

                $lane1 = $boats[1] ?? [];
                $winner = $boats[$actual1] ?? [];

                $first1 = numericValue($lane1, 'first_total_score');
                $firstW = numericValue($winner, 'first_total_score');
                if ($first1 !== null && $firstW !== null) {
                    $diff = $firstW - $first1;
                    $s['loss_first_diff_sum'] += $diff;
                    $s['loss_first_diff_n']++;
                    if ($diff > 0) $s['loss_winner_first_better']++;
                }

                $rank1 = rankValue($lane1, 'first_rank');
                $rankW = rankValue($winner, 'first_rank');
                if ($rank1 !== null && $rankW !== null) {
                    $s['loss_first_rank_n']++;
                    if ($rankW < $rank1) $s['loss_winner_first_rank_better']++;
                }

                $second1 = numericValue($lane1, 'second_score');
                $secondW = numericValue($winner, 'second_score');
                if ($second1 !== null && $secondW !== null) {
                    $diff = $secondW - $second1;
                    $s['loss_second_diff_sum'] += $diff;
                    $s['loss_second_diff_n']++;
                    if ($diff > 0) $s['loss_winner_second_better']++;
                }

                $rank1 = rankValue($lane1, 'second_rank');
                $rankW = rankValue($winner, 'second_rank');
                if ($rank1 !== null && $rankW !== null) {
                    $s['loss_second_rank_n']++;
                    if ($rankW < $rank1) $s['loss_winner_second_rank_better']++;
                }
            }
        } else {
            $s['web_not1']++;
            if ($actual1 === 1) {
                $s['web_not1_actual1_win']++;
                if ($taikou === 1) {
                    $s['missed_escape_taikou1']++;
                }
                if ($honmei >= 2 && $honmei <= 6) {
                    $s['miss_heads'][$honmei]++;
                }

                $lane1 = $boats[1] ?? [];
                $head = $boats[$honmei] ?? [];

                $first1 = numericValue($lane1, 'first_total_score');
                $firstH = numericValue($head, 'first_total_score');
                if ($first1 !== null && $firstH !== null && $firstH > $first1) {
                    $s['miss_head_first_better']++;
                }

                $rank1 = rankValue($lane1, 'first_rank');
                $rankH = rankValue($head, 'first_rank');
                if ($rank1 !== null && $rankH !== null) {
                    $s['miss_first_rank_n']++;
                    if ($rankH < $rank1) $s['miss_head_first_rank_better']++;
                }

                $second1 = numericValue($lane1, 'second_score');
                $secondH = numericValue($head, 'second_score');
                if ($second1 !== null && $secondH !== null && $secondH > $second1) {
                    $s['miss_head_second_better']++;
                }

                $rank1 = rankValue($lane1, 'second_rank');
                $rankH = rankValue($head, 'second_rank');
                if ($rank1 !== null && $rankH !== null) {
                    $s['miss_second_rank_n']++;
                    if ($rankH < $rank1) $s['miss_head_second_rank_better']++;
                }
            }
        }

        unset($s);
    }

    return $stats;
}

function combineStats(array $a, array $b): array
{
    $out = [];
    foreach (TARGET_STADIUMS as $stadium) {
        $x = $a[$stadium] ?? emptyStats();
        $y = $b[$stadium] ?? emptyStats();
        $z = emptyStats();

        foreach ($z as $key => $default) {
            if (is_array($default)) {
                for ($i = 1; $i <= 6; $i++) {
                    $z[$key][$i] = (int)($x[$key][$i] ?? 0) + (int)($y[$key][$i] ?? 0);
                }
            } elseif (is_float($default)) {
                $z[$key] = (float)($x[$key] ?? 0.0) + (float)($y[$key] ?? 0.0);
            } else {
                $z[$key] = (int)($x[$key] ?? 0) + (int)($y[$key] ?? 0);
            }
        }
        $out[$stadium] = $z;
    }
    return $out;
}

function winnerDistribution(array $counts, int $total): string
{
    $parts = [];
    for ($boat = 2; $boat <= 6; $boat++) {
        $count = (int)($counts[$boat] ?? 0);
        if ($count <= 0) continue;
        $parts[] = $boat . '号 ' . $count . 'R(' . number_format(pct($count, $total), 1) . '%)';
    }
    return $parts ? implode(' / ', $parts) : '-';
}

function headDistribution(array $counts, int $total): string
{
    $parts = [];
    for ($boat = 2; $boat <= 6; $boat++) {
        $count = (int)($counts[$boat] ?? 0);
        if ($count <= 0) continue;
        $parts[] = $boat . '号 ' . $count . 'R(' . number_format(pct($count, $total), 1) . '%)';
    }
    return $parts ? implode(' / ', $parts) : '-';
}

try {
    $period1 = loadPeriod($raceCsv1, $boatCsv1);
    $period2 = loadPeriod($raceCsv2, $boatCsv2);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

$stats1 = aggregate($period1['races']);
$stats2 = aggregate($period2['races']);
$combined = combineStats($stats1, $stats2);

echo PHP_EOL;
echo str_repeat('=', 118) . PHP_EOL;
echo "4場 1号艇判断ズレ分析" . PHP_EOL;
echo str_repeat('=', 118) . PHP_EOL;
echo "期間1 : {$period1['start_date']} ～ {$period1['end_date']}" . PHP_EOL;
echo "期間2 : {$period2['start_date']} ～ {$period2['end_date']}" . PHP_EOL;
echo "対象   : 戸田 / 多摩川 / 大村 / 下関" . PHP_EOL;
echo PHP_EOL;

echo "【2期間比較：1号艇を本命にした時の成功率 / 本命にしなかった時の逃げ率】" . PHP_EOL;
echo sprintf("%-10s %12s %12s %12s %12s\n", '場', 'P1 1本命成功', 'P2 1本命成功', 'P1 非1→1勝', 'P2 非1→1勝');
echo str_repeat('-', 74) . PHP_EOL;
foreach (TARGET_STADIUMS as $stadium) {
    $a = $stats1[$stadium];
    $b = $stats2[$stadium];
    echo sprintf(
        "%-10s %12s %12s %12s %12s\n",
        $stadium,
        fmtPct(pct($a['web1_win'], $a['web1'])),
        fmtPct(pct($b['web1_win'], $b['web1'])),
        fmtPct(pct($a['web_not1_actual1_win'], $a['web_not1'])),
        fmtPct(pct($b['web_not1_actual1_win'], $b['web_not1']))
    );
}

echo PHP_EOL;

foreach (TARGET_STADIUMS as $stadium) {
    $s = $combined[$stadium];
    $actual1Recall = pct($s['web1_win'], $s['actual1_win']);
    $web1Success = pct($s['web1_win'], $s['web1']);
    $missEscapeRate = pct($s['web_not1_actual1_win'], $s['web_not1']);

    echo str_repeat('=', 118) . PHP_EOL;
    echo "【{$stadium}】" . PHP_EOL;
    echo str_repeat('=', 118) . PHP_EOL;
    echo "対象レース                  : {$s['races']}R" . PHP_EOL;
    echo "実際の1号艇1着             : {$s['actual1_win']}R (" . fmtPct(pct($s['actual1_win'], $s['races'])) . ")" . PHP_EOL;
    echo "Webが1号艇を本命           : {$s['web1']}R (" . fmtPct(pct($s['web1'], $s['races'])) . ")" . PHP_EOL;
    echo "  └ そのうち1号艇が勝利    : {$s['web1_win']}R / {$s['web1']}R (" . fmtPct($web1Success) . ")" . PHP_EOL;
    echo "  └ そのうち1号艇が敗戦    : {$s['web1_loss']}R" . PHP_EOL;
    echo "Webが1号艇を本命にしない   : {$s['web_not1']}R" . PHP_EOL;
    echo "  └ それでも1号艇が勝利    : {$s['web_not1_actual1_win']}R / {$s['web_not1']}R (" . fmtPct($missEscapeRate) . ")" . PHP_EOL;
    echo "1号艇勝利を本命で拾えた率  : {$s['web1_win']}R / {$s['actual1_win']}R (" . fmtPct($actual1Recall) . ")" . PHP_EOL;

    if ($s['web_not1_actual1_win'] > 0) {
        echo "  └ 1号艇が対抗にはいた     : {$s['missed_escape_taikou1']}R / {$s['web_not1_actual1_win']}R ("
            . fmtPct(pct($s['missed_escape_taikou1'], $s['web_not1_actual1_win'])) . ")" . PHP_EOL;
    }

    echo PHP_EOL;
    echo "■ 1号艇本命で負けた時、誰が勝ったか" . PHP_EOL;
    echo "  " . winnerDistribution($s['loss_winners'], $s['web1_loss']) . PHP_EOL;

    if ($s['loss_first_diff_n'] > 0) {
        echo "  勝ち艇 - 1号艇 一次スコア平均差 : "
            . sprintf('%+.3f', $s['loss_first_diff_sum'] / $s['loss_first_diff_n']) . PHP_EOL;
        echo "  勝ち艇の一次スコアが上           : {$s['loss_winner_first_better']}R / {$s['loss_first_diff_n']}R ("
            . fmtPct(pct($s['loss_winner_first_better'], $s['loss_first_diff_n'])) . ")" . PHP_EOL;
    }
    if ($s['loss_first_rank_n'] > 0) {
        echo "  勝ち艇の一次順位が1号艇より上    : {$s['loss_winner_first_rank_better']}R / {$s['loss_first_rank_n']}R ("
            . fmtPct(pct($s['loss_winner_first_rank_better'], $s['loss_first_rank_n'])) . ")" . PHP_EOL;
    }
    if ($s['loss_second_diff_n'] > 0) {
        echo "  勝ち艇 - 1号艇 二次スコア平均差 : "
            . sprintf('%+.3f', $s['loss_second_diff_sum'] / $s['loss_second_diff_n']) . PHP_EOL;
        echo "  勝ち艇の二次スコアが上           : {$s['loss_winner_second_better']}R / {$s['loss_second_diff_n']}R ("
            . fmtPct(pct($s['loss_winner_second_better'], $s['loss_second_diff_n'])) . ")" . PHP_EOL;
    }
    if ($s['loss_second_rank_n'] > 0) {
        echo "  勝ち艇の二次順位が1号艇より上    : {$s['loss_winner_second_rank_better']}R / {$s['loss_second_rank_n']}R ("
            . fmtPct(pct($s['loss_winner_second_rank_better'], $s['loss_second_rank_n'])) . ")" . PHP_EOL;
    }

    echo PHP_EOL;
    echo "■ 1号艇を本命にしなかったのに1号艇が勝った時" . PHP_EOL;
    echo "  Webが本命にした艇: " . headDistribution($s['miss_heads'], $s['web_not1_actual1_win']) . PHP_EOL;

    if ($s['web_not1_actual1_win'] > 0) {
        if ($s['miss_first_rank_n'] > 0) {
            echo "  本命艇の一次順位が1号艇より上 : {$s['miss_head_first_rank_better']}R / {$s['miss_first_rank_n']}R ("
                . fmtPct(pct($s['miss_head_first_rank_better'], $s['miss_first_rank_n'])) . ")" . PHP_EOL;
        }
        if ($s['miss_second_rank_n'] > 0) {
            echo "  本命艇の二次順位が1号艇より上 : {$s['miss_head_second_rank_better']}R / {$s['miss_second_rank_n']}R ("
                . fmtPct(pct($s['miss_head_second_rank_better'], $s['miss_second_rank_n'])) . ")" . PHP_EOL;
        }
    }

    echo PHP_EOL;
}

echo "見方:" . PHP_EOL;
echo "  ・『1本命成功率』が低い場 → 1号艇を本命にした時の過信を疑う。" . PHP_EOL;
echo "  ・『非1→1勝』が高い場 → 1号艇を本命から外しすぎている可能性。" . PHP_EOL;
echo "  ・戸田は前者、大村は後者になっているかを重点確認する。" . PHP_EOL;
echo "  ・一次/二次比較で勝ち艇側が明確に上なら、場補正より現行統合ロジック側の見直し候補。" . PHP_EOL;
echo "  ・勝ち艇側が上でないのに特定場だけ1が崩れるなら、場特有ファクター探索の優先度が高い。" . PHP_EOL;
echo PHP_EOL;
