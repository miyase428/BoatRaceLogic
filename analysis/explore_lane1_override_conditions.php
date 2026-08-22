<?php

declare(strict_types=1);

/**
 * 4場 インを外す条件探索
 *
 * 戸田・多摩川・大村・下関について、2期間分のレース別CSV＋艇別CSVを使い、
 * 「1号艇を本命から外した方がよい条件」と「1号艇本命でも危険な条件」を探索する。
 *
 * DBにはアクセスしないため、長期間CSV生成中でも並行実行できる。
 *
 * Usage:
 *   php analysis/explore_lane1_override_conditions.php \
 *     analysis/output/final_prediction_races_20260615_20260714.csv \
 *     analysis/output/final_prediction_boats_20260615_20260714.csv \
 *     analysis/output/final_prediction_races_20260715_20260814.csv \
 *     analysis/output/final_prediction_boats_20260715_20260814.csv
 */

if ($argc < 5) {
    echo PHP_EOL;
    echo "使用方法:" . PHP_EOL;
    echo "  php analysis/explore_lane1_override_conditions.php <期間1_races> <期間1_boats> <期間2_races> <期間2_boats>" . PHP_EOL;
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
const MIN_COMBO_N = 15;

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

function loadPeriod(string $raceCsv, string $boatCsv): array
{
    $raceRows = readCsvAssoc($raceCsv, [
        'race_code', 'race_date', 'stadium_name', 'honmei_head', 'actual_1st',
    ]);

    $boatRows = readCsvAssoc($boatCsv, [
        'race_code', 'stadium_name', 'lane_number',
        'first_total_score', 'first_rank', 'second_score', 'second_rank',
    ]);

    $boatsByRace = [];
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
        $boatsByRace[$raceCode][$lane] = $row;
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
        $actual1 = (int)$row['actual_1st'];
        $boats = $boatsByRace[$raceCode] ?? [];

        if (
            $raceCode === '' ||
            $honmei < 1 || $honmei > 6 ||
            $actual1 < 1 || $actual1 > 6 ||
            count($boats) !== 6
        ) {
            continue;
        }

        $races[$raceCode] = [
            'race_code' => $raceCode,
            'race_date' => $date,
            'stadium' => $stadium,
            'honmei' => $honmei,
            'actual1' => $actual1,
            'boats' => $boats,
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

function rankBucket(?int $rank): string
{
    if ($rank === 1) return '1位';
    if ($rank === 2) return '2位';
    if ($rank === 3) return '3位';
    if ($rank !== null && $rank >= 4 && $rank <= 6) return '4-6位';
    return '不明';
}

/**
 * challenger - 1号艇 のスコア差。
 * プラスほど「1号艇以外が上」。
 */
function challengerGapBucket(?float $gap): string
{
    if ($gap === null) return '不明';
    if ($gap <= 0.0) return '<=0';
    if ($gap < 2.0) return '0-2';
    if ($gap < 5.0) return '2-5';
    if ($gap < 10.0) return '5-10';
    return '10+';
}

/**
 * 1号艇 - 最強非1艇 の二次スコア差。
 * 大きいほど1号艇が二次評価で優位。
 */
function lane1MarginBucket(?float $margin): string
{
    if ($margin === null) return '不明';
    if ($margin < 0.0) return '<0';
    if ($margin < 1.0) return '0-1';
    if ($margin < 2.0) return '1-2';
    if ($margin < 5.0) return '2-5';
    return '5+';
}

function emptyChoiceStats(): array
{
    return [
        'n' => 0,
        'lane1_win' => 0,
        'head_win' => 0,
        'other_win' => 0,
    ];
}

function emptyLane1Stats(): array
{
    return [
        'n' => 0,
        'lane1_win' => 0,
        'non1_win' => 0,
    ];
}

function addChoiceStat(array &$groups, string $key, int $actual1, int $head): void
{
    if (!isset($groups[$key])) {
        $groups[$key] = emptyChoiceStats();
    }

    $groups[$key]['n']++;
    if ($actual1 === 1) {
        $groups[$key]['lane1_win']++;
    } elseif ($actual1 === $head) {
        $groups[$key]['head_win']++;
    } else {
        $groups[$key]['other_win']++;
    }
}

function addLane1Stat(array &$groups, string $key, int $actual1): void
{
    if (!isset($groups[$key])) {
        $groups[$key] = emptyLane1Stats();
    }

    $groups[$key]['n']++;
    if ($actual1 === 1) {
        $groups[$key]['lane1_win']++;
    } else {
        $groups[$key]['non1_win']++;
    }
}

function bestNon1Score(array $boats, string $scoreKey): ?float
{
    $best = null;
    for ($boat = 2; $boat <= 6; $boat++) {
        $score = numericValue($boats[$boat] ?? [], $scoreKey);
        if ($score === null) continue;
        if ($best === null || $score > $best) {
            $best = $score;
        }
    }
    return $best;
}

function printChoiceGroups(string $title, array $groups, array $preferredOrder = []): void
{
    echo PHP_EOL;
    echo $title . PHP_EOL;
    echo sprintf("%-20s %6s %10s %10s %10s %10s\n", '条件', 'N', '1号勝率', '別本命勝', '別-1差', 'その他勝');
    echo str_repeat('-', 74) . PHP_EOL;

    $keys = array_keys($groups);
    if ($preferredOrder) {
        usort($keys, static function (string $a, string $b) use ($preferredOrder): int {
            $ia = array_search($a, $preferredOrder, true);
            $ib = array_search($b, $preferredOrder, true);
            $ia = $ia === false ? 999 : $ia;
            $ib = $ib === false ? 999 : $ib;
            return $ia <=> $ib;
        });
    } else {
        sort($keys);
    }

    foreach ($keys as $key) {
        $s = $groups[$key];
        $n = (int)$s['n'];
        if ($n <= 0) continue;
        $lane1 = pct((int)$s['lane1_win'], $n);
        $head = pct((int)$s['head_win'], $n);
        $other = pct((int)$s['other_win'], $n);
        echo sprintf(
            "%-20s %6d %10s %10s %+9.2fpt %10s\n",
            $key,
            $n,
            fmtPct($lane1),
            fmtPct($head),
            $head - $lane1,
            fmtPct($other)
        );
    }
}

function printLane1Groups(string $title, array $groups, array $preferredOrder = []): void
{
    echo PHP_EOL;
    echo $title . PHP_EOL;
    echo sprintf("%-24s %6s %10s %10s\n", '条件', 'N', '1号勝率', '1号敗戦率');
    echo str_repeat('-', 58) . PHP_EOL;

    $keys = array_keys($groups);
    if ($preferredOrder) {
        usort($keys, static function (string $a, string $b) use ($preferredOrder): int {
            $ia = array_search($a, $preferredOrder, true);
            $ib = array_search($b, $preferredOrder, true);
            $ia = $ia === false ? 999 : $ia;
            $ib = $ib === false ? 999 : $ib;
            return $ia <=> $ib;
        });
    } else {
        sort($keys);
    }

    foreach ($keys as $key) {
        $s = $groups[$key];
        $n = (int)$s['n'];
        if ($n <= 0) continue;
        echo sprintf(
            "%-24s %6d %10s %10s\n",
            $key,
            $n,
            fmtPct(pct((int)$s['lane1_win'], $n)),
            fmtPct(pct((int)$s['non1_win'], $n))
        );
    }
}

function printChoiceCandidates(string $title, array $groups, bool $bestForHead): void
{
    $rows = [];
    foreach ($groups as $key => $s) {
        $n = (int)$s['n'];
        if ($n < MIN_COMBO_N) continue;
        $lane1 = pct((int)$s['lane1_win'], $n);
        $head = pct((int)$s['head_win'], $n);
        $rows[] = [
            'key' => $key,
            'n' => $n,
            'lane1' => $lane1,
            'head' => $head,
            'adv' => $head - $lane1,
        ];
    }

    usort($rows, static function (array $a, array $b) use ($bestForHead): int {
        return $bestForHead
            ? ($b['adv'] <=> $a['adv'])
            : ($a['adv'] <=> $b['adv']);
    });

    echo PHP_EOL;
    echo $title . '（N>=' . MIN_COMBO_N . '）' . PHP_EOL;
    if (!$rows) {
        echo "  該当なし" . PHP_EOL;
        return;
    }

    foreach (array_slice($rows, 0, 6) as $i => $r) {
        echo sprintf(
            "%2d. %-30s N=%3d / 1号 %6.2f%% / 別本命 %6.2f%% / 差 %+6.2fpt\n",
            $i + 1,
            $r['key'],
            $r['n'],
            $r['lane1'],
            $r['head'],
            $r['adv']
        );
    }
}

function printRiskCandidates(string $title, array $groups): void
{
    $rows = [];
    foreach ($groups as $key => $s) {
        $n = (int)$s['n'];
        if ($n < MIN_COMBO_N) continue;
        $rows[] = [
            'key' => $key,
            'n' => $n,
            'lane1' => pct((int)$s['lane1_win'], $n),
        ];
    }

    usort($rows, static fn(array $a, array $b): int => $a['lane1'] <=> $b['lane1']);

    echo PHP_EOL;
    echo $title . '（N>=' . MIN_COMBO_N . ' / 1号勝率が低い順）' . PHP_EOL;
    if (!$rows) {
        echo "  該当なし" . PHP_EOL;
        return;
    }

    foreach (array_slice($rows, 0, 6) as $i => $r) {
        echo sprintf(
            "%2d. %-30s N=%3d / 1号勝率 %6.2f%%\n",
            $i + 1,
            $r['key'],
            $r['n'],
            $r['lane1']
        );
    }
}

try {
    $p1 = loadPeriod($raceCsv1, $boatCsv1);
    $p2 = loadPeriod($raceCsv2, $boatCsv2);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

$races = array_merge($p1['races'], $p2['races']);

$analysis = [];
foreach (TARGET_STADIUMS as $stadium) {
    $analysis[$stadium] = [
        'all' => emptyLane1Stats(),
        'not1' => emptyChoiceStats(),
        'web1' => emptyLane1Stats(),
        'not1_by_first_rank' => [],
        'not1_by_second_rank' => [],
        'not1_by_first_gap' => [],
        'not1_by_second_gap' => [],
        'not1_rank_combo' => [],
        'not1_gap_combo' => [],
        'web1_by_first_rank' => [],
        'web1_by_primary_challenger_gap' => [],
        'web1_by_second_margin' => [],
        'web1_risk_combo' => [],
    ];
}

foreach ($races as $race) {
    $stadium = (string)$race['stadium'];
    if (!isset($analysis[$stadium])) continue;

    $boats = $race['boats'];
    $lane1 = $boats[1] ?? [];
    $honmei = (int)$race['honmei'];
    $actual1 = (int)$race['actual1'];

    $first1 = numericValue($lane1, 'first_total_score');
    $second1 = numericValue($lane1, 'second_score');
    $firstRank1 = rankValue($lane1, 'first_rank');
    $secondRank1 = rankValue($lane1, 'second_rank');
    $bestNon1First = bestNon1Score($boats, 'first_total_score');
    $bestNon1Second = bestNon1Score($boats, 'second_score');

    $a =& $analysis[$stadium];
    $a['all']['n']++;
    if ($actual1 === 1) $a['all']['lane1_win']++; else $a['all']['non1_win']++;

    if ($honmei !== 1) {
        $a['not1']['n']++;
        if ($actual1 === 1) {
            $a['not1']['lane1_win']++;
        } elseif ($actual1 === $honmei) {
            $a['not1']['head_win']++;
        } else {
            $a['not1']['other_win']++;
        }

        $headRow = $boats[$honmei] ?? [];
        $headFirst = numericValue($headRow, 'first_total_score');
        $headSecond = numericValue($headRow, 'second_score');

        $firstGap = ($headFirst !== null && $first1 !== null) ? $headFirst - $first1 : null;
        $secondGap = ($headSecond !== null && $second1 !== null) ? $headSecond - $second1 : null;

        $firstRankBucket = rankBucket($firstRank1);
        $secondRankBucket = rankBucket($secondRank1);
        $firstGapBucket = challengerGapBucket($firstGap);
        $secondGapBucket = challengerGapBucket($secondGap);

        addChoiceStat($a['not1_by_first_rank'], $firstRankBucket, $actual1, $honmei);
        addChoiceStat($a['not1_by_second_rank'], $secondRankBucket, $actual1, $honmei);
        addChoiceStat($a['not1_by_first_gap'], $firstGapBucket, $actual1, $honmei);
        addChoiceStat($a['not1_by_second_gap'], $secondGapBucket, $actual1, $honmei);
        addChoiceStat(
            $a['not1_rank_combo'],
            '一次' . $firstRankBucket . '×二次' . $secondRankBucket,
            $actual1,
            $honmei
        );
        addChoiceStat(
            $a['not1_gap_combo'],
            '一次差' . $firstGapBucket . '×二次差' . $secondGapBucket,
            $actual1,
            $honmei
        );
    } else {
        $a['web1']['n']++;
        if ($actual1 === 1) $a['web1']['lane1_win']++; else $a['web1']['non1_win']++;

        $firstRankBucket = rankBucket($firstRank1);
        $primaryChallengerGap = ($bestNon1First !== null && $first1 !== null)
            ? $bestNon1First - $first1
            : null;
        $secondMargin = ($second1 !== null && $bestNon1Second !== null)
            ? $second1 - $bestNon1Second
            : null;

        $primaryGapBucket = challengerGapBucket($primaryChallengerGap);
        $secondMarginBucket = lane1MarginBucket($secondMargin);

        addLane1Stat($a['web1_by_first_rank'], $firstRankBucket, $actual1);
        addLane1Stat($a['web1_by_primary_challenger_gap'], $primaryGapBucket, $actual1);
        addLane1Stat($a['web1_by_second_margin'], $secondMarginBucket, $actual1);
        addLane1Stat(
            $a['web1_risk_combo'],
            '一次' . $firstRankBucket . '×二次余裕' . $secondMarginBucket,
            $actual1
        );
    }

    unset($a);
}

echo PHP_EOL;
echo str_repeat('=', 118) . PHP_EOL;
echo "4場 インを外す条件探索" . PHP_EOL;
echo str_repeat('=', 118) . PHP_EOL;
echo "期間1 : {$p1['start_date']} ～ {$p1['end_date']}" . PHP_EOL;
echo "期間2 : {$p2['start_date']} ～ {$p2['end_date']}" . PHP_EOL;
echo "対象   : " . implode(' / ', TARGET_STADIUMS) . PHP_EOL;
echo "目的   : 1号艇を外す判断が有効になる条件 / 1号艇本命でも危険な条件を探す" . PHP_EOL;

$rankOrder = ['1位', '2位', '3位', '4-6位', '不明'];
$gapOrder = ['<=0', '0-2', '2-5', '5-10', '10+', '不明'];
$marginOrder = ['<0', '0-1', '1-2', '2-5', '5+', '不明'];

foreach (TARGET_STADIUMS as $stadium) {
    $a = $analysis[$stadium];
    $allN = (int)$a['all']['n'];
    $not1N = (int)$a['not1']['n'];
    $web1N = (int)$a['web1']['n'];

    echo PHP_EOL;
    echo str_repeat('=', 118) . PHP_EOL;
    echo "【{$stadium}】" . PHP_EOL;
    echo str_repeat('=', 118) . PHP_EOL;

    echo sprintf(
        "全体 %dR / 実1号勝率 %s / Web1本命 %dR（成功 %s） / Web非1本命 %dR\n",
        $allN,
        fmtPct(pct((int)$a['all']['lane1_win'], $allN)),
        $web1N,
        fmtPct(pct((int)$a['web1']['lane1_win'], $web1N)),
        $not1N
    );

    if ($not1N > 0) {
        echo sprintf(
            "Webが1を外した時: 1号勝 %s / 別本命勝 %s / その他勝 %s / 別本命-1差 %+6.2fpt\n",
            fmtPct(pct((int)$a['not1']['lane1_win'], $not1N)),
            fmtPct(pct((int)$a['not1']['head_win'], $not1N)),
            fmtPct(pct((int)$a['not1']['other_win'], $not1N)),
            pct((int)$a['not1']['head_win'], $not1N) - pct((int)$a['not1']['lane1_win'], $not1N)
        );
    }

    echo PHP_EOL;
    echo "--- A. Webが1号艇を本命から外したレース ---" . PHP_EOL;
    echo "※『別本命-1差』がプラスなら、その条件では現在の別本命選択が1固定より有利。" . PHP_EOL;

    printChoiceGroups('■ 1号艇の一次順位別', $a['not1_by_first_rank'], $rankOrder);
    printChoiceGroups('■ 1号艇の二次順位別', $a['not1_by_second_rank'], $rankOrder);
    printChoiceGroups('■ 別本命 - 1号艇 の一次スコア差別', $a['not1_by_first_gap'], $gapOrder);
    printChoiceGroups('■ 別本命 - 1号艇 の二次スコア差別', $a['not1_by_second_gap'], $gapOrder);

    printChoiceCandidates('★ 外しが有効そうな順位組合せ TOP', $a['not1_rank_combo'], true);
    printChoiceCandidates('★ 1号艇へ戻した方がよさそうな順位組合せ TOP', $a['not1_rank_combo'], false);
    printChoiceCandidates('★ 外しが有効そうなスコア差組合せ TOP', $a['not1_gap_combo'], true);
    printChoiceCandidates('★ 1号艇へ戻した方がよさそうなスコア差組合せ TOP', $a['not1_gap_combo'], false);

    echo PHP_EOL;
    echo "--- B. Webが1号艇を本命にしたレース ---" . PHP_EOL;
    echo "※ここでは1号艇勝率が低い条件ほど『イン危険条件』候補。" . PHP_EOL;

    printLane1Groups('■ 1号艇の一次順位別', $a['web1_by_first_rank'], $rankOrder);
    printLane1Groups('■ 最強非1艇 - 1号艇 の一次スコア差別', $a['web1_by_primary_challenger_gap'], $gapOrder);
    printLane1Groups('■ 1号艇の二次スコア余裕（1 - 最強非1）別', $a['web1_by_second_margin'], $marginOrder);
    printRiskCandidates('★ 1号艇本命の危険条件候補 TOP', $a['web1_risk_combo']);
}

echo PHP_EOL;
echo str_repeat('=', 118) . PHP_EOL;
echo "見方" . PHP_EOL;
echo str_repeat('=', 118) . PHP_EOL;
echo "・Aの『別本命-1差』がプラスの条件は、1号艇を外す判断に根拠がある候補。" . PHP_EOL;
echo "・Aで大きくマイナスの条件は、現行Webが1号艇を降ろしすぎている候補。" . PHP_EOL;
echo "・Bの危険条件は、1本命でも場補正で信頼度を下げる候補。" . PHP_EOL;
echo "・この段階は探索。条件採用は6ヶ月データで再現性を確認してから行う。" . PHP_EOL;
echo PHP_EOL;
