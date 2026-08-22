<?php

declare(strict_types=1);

/**
 * 場別TOP4 4番手艇フィルタ 全探索 / Pareto分析
 *
 * 固定済みの頭補正・TOP4条件は一切変更せず、
 * TOP4対象になった時に「4番手が何号艇なら拡張を許可するか」だけを全探索する。
 *
 * 目的:
 *   - P1/P2のHitをなるべく落とさず購入点数を減らす
 *   - 感覚で艇番セットを選ばず、全組合せから候補を出す
 *
 * 出力:
 *   1) P1/P2ともHit完全維持できる候補
 *   2) P1/P2それぞれ1Hit以内の損失で削減量が大きい候補
 *   3) 2期間合算のPareto候補（失Hitが少なく、削減点数が大きい）
 *
 * Usage:
 *   php analysis/explore_stadium_top4_boat_filter_pareto.php \
 *     analysis/output/final_prediction_races_20260615_20260714.csv \
 *     analysis/output/final_prediction_boats_20260615_20260714.csv \
 *     analysis/output/final_prediction_races_20260715_20260814.csv \
 *     analysis/output/final_prediction_boats_20260715_20260814.csv
 */

if ($argc !== 5) {
    echo PHP_EOL;
    echo "使用方法:" . PHP_EOL;
    echo "  php analysis/explore_stadium_top4_boat_filter_pareto.php <P1_races> <P1_boats> <P2_races> <P2_boats>" . PHP_EOL;
    echo PHP_EOL;
    exit(1);
}

[$script, $raceCsv1, $boatCsv1, $raceCsv2, $boatCsv2] = $argv;

const TARGET_STADIUMS = ['戸田', '多摩川', '大村', '下関'];

function pct(int $count, int $total): float
{
    return $total > 0 ? ($count / $total) * 100.0 : 0.0;
}

function avg(int $sum, int $total): float
{
    return $total > 0 ? $sum / $total : 0.0;
}

function readCsvAssoc(string $path, array $required): array
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
        if (count($row) < count($header)) continue;

        $assoc = [];
        foreach ($map as $name => $i) {
            $assoc[$name] = $row[$i] ?? '';
        }
        $rows[] = $assoc;
    }

    fclose($fp);
    return $rows;
}

function num(array $row, string $key): ?float
{
    $value = $row[$key] ?? null;
    return is_numeric($value) ? (float)$value : null;
}

function loadPeriod(string $raceCsv, string $boatCsv, string $label): array
{
    $raceRows = readCsvAssoc($raceCsv, [
        'race_code', 'race_date', 'stadium_name', 'honmei_head',
        'actual_1st', 'actual_2nd', 'actual_3rd', 'actual_trifecta',
    ]);

    $boatRows = readCsvAssoc($boatCsv, [
        'race_code', 'stadium_name', 'lane_number',
        'first_total_score', 'second_score', 'final3', 'final_rank', 'kiru',
    ]);

    $boatsByRace = [];
    foreach ($boatRows as $row) {
        $stadium = trim((string)$row['stadium_name']);
        if (!in_array($stadium, TARGET_STADIUMS, true)) continue;

        $raceCode = trim((string)$row['race_code']);
        $lane = (int)$row['lane_number'];
        if ($raceCode === '' || $lane < 1 || $lane > 6) continue;
        $boatsByRace[$raceCode][$lane] = $row;
    }

    $races = [];
    $startDate = null;
    $endDate = null;

    foreach ($raceRows as $row) {
        $stadium = trim((string)$row['stadium_name']);
        if (!in_array($stadium, TARGET_STADIUMS, true)) continue;

        $raceCode = trim((string)$row['race_code']);
        $date = trim((string)$row['race_date']);
        $honmei = (int)$row['honmei_head'];
        $actual1 = (int)$row['actual_1st'];
        $actual2 = (int)$row['actual_2nd'];
        $actual3 = (int)$row['actual_3rd'];
        $actualTri = trim((string)$row['actual_trifecta']);
        $boats = $boatsByRace[$raceCode] ?? [];

        if (
            $raceCode === '' ||
            $honmei < 1 || $honmei > 6 ||
            $actual1 < 1 || $actual1 > 6 ||
            $actual2 < 1 || $actual2 > 6 ||
            $actual3 < 1 || $actual3 > 6 ||
            $actualTri === '' ||
            count($boats) !== 6
        ) {
            continue;
        }

        $races[] = [
            'race_code' => $raceCode,
            'race_date' => $date,
            'stadium' => $stadium,
            'honmei' => $honmei,
            'actual_tri' => $actualTri,
            'boats' => $boats,
        ];

        if ($date !== '') {
            if ($startDate === null || $date < $startDate) $startDate = $date;
            if ($endDate === null || $date > $endDate) $endDate = $date;
        }
    }

    return [
        'label' => $label,
        'races' => $races,
        'start_date' => $startDate,
        'end_date' => $endDate,
    ];
}

function inRange(?float $value, float $min, float $max): bool
{
    return $value !== null && $value >= $min && $value < $max;
}

function currentHeadGaps(array $race): array
{
    $head = (int)$race['honmei'];
    if ($head === 1) return ['primary' => null, 'secondary' => null];

    $boats = $race['boats'];
    $lane1 = $boats[1] ?? [];
    $headRow = $boats[$head] ?? [];

    $p1 = num($lane1, 'first_total_score');
    $ph = num($headRow, 'first_total_score');
    $s1 = num($lane1, 'second_score');
    $sh = num($headRow, 'second_score');

    return [
        'primary' => ($p1 !== null && $ph !== null) ? $ph - $p1 : null,
        'secondary' => ($s1 !== null && $sh !== null) ? $sh - $s1 : null,
    ];
}

function adjustedHead(array $race): int
{
    $stadium = $race['stadium'];
    $head = (int)$race['honmei'];
    if ($head === 1) return 1;

    $g = currentHeadGaps($race);

    if ($stadium === '戸田') {
        return inRange($g['primary'], 2.0, 5.0) ? $head : 1;
    }

    if ($stadium === '多摩川') {
        $keep = inRange($g['primary'], 5.0, 10.0)
            && inRange($g['secondary'], 5.0, 10.0);
        return $keep ? $head : 1;
    }

    if ($stadium === '大村') return 1;

    if ($stadium === '下関') {
        return inRange($g['primary'], 5.0, 10.0) ? $head : 1;
    }

    return $head;
}

function finalOrder(array $boats): array
{
    $rows = [];
    foreach ($boats as $lane => $row) {
        $rank = isset($row['final_rank']) && is_numeric($row['final_rank'])
            ? (int)$row['final_rank'] : 999;
        $rows[] = ['lane' => (int)$lane, 'rank' => $rank];
    }

    usort($rows, static function (array $a, array $b): int {
        $cmp = $a['rank'] <=> $b['rank'];
        return $cmp !== 0 ? $cmp : ($a['lane'] <=> $b['lane']);
    });

    return array_column($rows, 'lane');
}

function eligibleAfterHead(array $race, int $head): array
{
    $order = finalOrder($race['boats']);
    $order = array_values(array_filter(
        $order,
        static fn(int $lane): bool => $lane !== $head
    ));
    array_unshift($order, $head);

    $eligible = [];
    foreach ($order as $lane) {
        if ($lane === $head) continue;
        if ((int)($race['boats'][$lane]['kiru'] ?? 0) === 1) continue;
        $eligible[] = $lane;
    }
    return $eligible;
}

function buildBets(array $race, int $head, int $secondLimit): array
{
    $eligible = eligibleAfterHead($race, $head);
    $second = array_slice($eligible, 0, $secondLimit);
    $third = $eligible;

    $bets = [];
    foreach ($second as $b) {
        foreach ($third as $c) {
            if ($head === $b || $head === $c || $b === $c) continue;
            $bets[] = "{$head}-{$b}-{$c}";
        }
    }
    return array_values(array_unique($bets));
}

function candidateGap(array $race, int $head, string $scoreKey): ?float
{
    $eligible = eligibleAfterHead($race, $head);
    if (count($eligible) < 4) return null;

    $lane3 = $eligible[2];
    $lane4 = $eligible[3];
    $s3 = num($race['boats'][$lane3] ?? [], $scoreKey);
    $s4 = num($race['boats'][$lane4] ?? [], $scoreKey);
    if ($s3 === null || $s4 === null) return null;

    return abs($s3 - $s4);
}

function fourthCandidate(array $race, int $head): ?int
{
    $eligible = eligibleAfterHead($race, $head);
    return count($eligible) >= 4 ? (int)$eligible[3] : null;
}

function shouldTop4Original(array $race, int $head, bool $headChanged): bool
{
    if ($race['stadium'] === '戸田') {
        $gap = candidateGap($race, $head, 'final3');
        return $gap !== null && $gap <= 1.0;
    }

    if ($race['stadium'] === '多摩川') {
        $gap = candidateGap($race, $head, 'final3');
        return $gap !== null && $gap <= 2.0;
    }

    if ($race['stadium'] === '大村') {
        $gap = candidateGap($race, $head, 'final3');
        return $gap !== null && $gap <= 2.0;
    }

    if ($race['stadium'] === '下関') {
        return $headChanged;
    }

    return false;
}

function evaluateSubset(array $period, string $stadium, array $allowed): array
{
    $out = [
        'n' => 0,
        'hit' => 0,
        'bets' => 0,
        'top4_races' => 0,
    ];

    foreach ($period['races'] as $race) {
        if ($race['stadium'] !== $stadium) continue;

        $head = adjustedHead($race);
        $headChanged = $head !== (int)$race['honmei'];
        $originalTop4 = shouldTop4Original($race, $head, $headChanged);
        $fourth = $originalTop4 ? fourthCandidate($race, $head) : null;

        $useTop4 = $originalTop4
            && $fourth !== null
            && in_array($fourth, $allowed, true);

        $bets = buildBets($race, $head, $useTop4 ? 4 : 3);

        $out['n']++;
        $out['bets'] += count($bets);
        if ($useTop4) $out['top4_races']++;
        if (in_array($race['actual_tri'], $bets, true)) $out['hit']++;
    }

    return $out;
}

function mergeStats(array $a, array $b): array
{
    return [
        'n' => $a['n'] + $b['n'],
        'hit' => $a['hit'] + $b['hit'],
        'bets' => $a['bets'] + $b['bets'],
        'top4_races' => $a['top4_races'] + $b['top4_races'],
    ];
}

function allNonEmptySubsets(array $items): array
{
    $out = [];
    $n = count($items);
    $max = 1 << $n;

    for ($mask = 1; $mask < $max; $mask++) {
        $subset = [];
        for ($i = 0; $i < $n; $i++) {
            if (($mask & (1 << $i)) !== 0) $subset[] = $items[$i];
        }
        $out[] = $subset;
    }
    return $out;
}

function observedFourthBoats(array $p1, array $p2, string $stadium): array
{
    $seen = [];
    foreach ([$p1, $p2] as $period) {
        foreach ($period['races'] as $race) {
            if ($race['stadium'] !== $stadium) continue;
            $head = adjustedHead($race);
            $changed = $head !== (int)$race['honmei'];
            if (!shouldTop4Original($race, $head, $changed)) continue;
            $fourth = fourthCandidate($race, $head);
            if ($fourth !== null) $seen[$fourth] = true;
        }
    }

    $boats = array_map('intval', array_keys($seen));
    sort($boats);
    return $boats;
}

function subsetLabel(array $subset): string
{
    sort($subset);
    return implode('/', $subset);
}

function buildRows(array $p1, array $p2, string $stadium): array
{
    $observed = observedFourthBoats($p1, $p2, $stadium);
    if (!$observed) return [];

    $full1 = evaluateSubset($p1, $stadium, $observed);
    $full2 = evaluateSubset($p2, $stadium, $observed);
    $fullAll = mergeStats($full1, $full2);

    $rows = [];
    foreach (allNonEmptySubsets($observed) as $subset) {
        $s1 = evaluateSubset($p1, $stadium, $subset);
        $s2 = evaluateSubset($p2, $stadium, $subset);
        $all = mergeStats($s1, $s2);

        $lost1 = $full1['hit'] - $s1['hit'];
        $lost2 = $full2['hit'] - $s2['hit'];
        $lostAll = $fullAll['hit'] - $all['hit'];
        $savedBets = $fullAll['bets'] - $all['bets'];
        $avgSaved = avg($savedBets, $fullAll['n']);

        $rows[] = [
            'subset' => $subset,
            'label' => subsetLabel($subset),
            'p1_lost' => $lost1,
            'p2_lost' => $lost2,
            'lost' => $lostAll,
            'saved_bets' => $savedBets,
            'avg_saved' => $avgSaved,
            'hit_rate' => pct($all['hit'], $all['n']),
            'top4_races' => $all['top4_races'],
            'saved_per_lost' => $lostAll > 0 ? ($savedBets / $lostAll) : null,
            'full_hit_rate' => pct($fullAll['hit'], $fullAll['n']),
            'full_avg_bets' => avg($fullAll['bets'], $fullAll['n']),
            'full_hits' => $fullAll['hit'],
            'full_bets' => $fullAll['bets'],
            'full_top4' => $fullAll['top4_races'],
            'observed' => $observed,
        ];
    }

    return $rows;
}

function paretoRows(array $rows): array
{
    $pareto = [];

    foreach ($rows as $i => $a) {
        $dominated = false;
        foreach ($rows as $j => $b) {
            if ($i === $j) continue;

            $noWorseLoss = $b['lost'] <= $a['lost'];
            $noWorseSave = $b['saved_bets'] >= $a['saved_bets'];
            $strict = $b['lost'] < $a['lost'] || $b['saved_bets'] > $a['saved_bets'];

            if ($noWorseLoss && $noWorseSave && $strict) {
                $dominated = true;
                break;
            }
        }

        if (!$dominated) $pareto[] = $a;
    }

    usort($pareto, static function (array $a, array $b): int {
        $cmp = $a['lost'] <=> $b['lost'];
        if ($cmp !== 0) return $cmp;
        return $b['saved_bets'] <=> $a['saved_bets'];
    });

    return $pareto;
}

function printRows(string $title, array $rows, int $limit = 10): void
{
    echo PHP_EOL . "■ {$title}" . PHP_EOL;
    if (!$rows) {
        echo "  該当なし" . PHP_EOL;
        return;
    }

    echo sprintf(
        "%-15s %7s %7s %8s %10s %10s %10s %10s\n",
        '許可4番手', 'P1失Hit', 'P2失Hit', '合算失Hit', '削減点数', '平均点削減', 'TOP4R', '削減点/失Hit'
    );
    echo str_repeat('-', 96) . PHP_EOL;

    foreach (array_slice($rows, 0, $limit) as $r) {
        $eff = $r['saved_per_lost'] === null ? '-' : number_format($r['saved_per_lost'], 1);
        echo sprintf(
            "%-15s %7d %7d %8d %10d %10.2f %10d %10s\n",
            $r['label'],
            $r['p1_lost'],
            $r['p2_lost'],
            $r['lost'],
            $r['saved_bets'],
            $r['avg_saved'],
            $r['top4_races'],
            $eff
        );
    }
}

try {
    $p1 = loadPeriod($raceCsv1, $boatCsv1, 'P1');
    $p2 = loadPeriod($raceCsv2, $boatCsv2, 'P2');
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

echo PHP_EOL;
echo str_repeat('=', 152) . PHP_EOL;
echo "場別TOP4 4番手艇フィルタ 全探索 / Pareto分析" . PHP_EOL;
echo str_repeat('=', 152) . PHP_EOL;
echo "P1 : {$p1['start_date']} ～ {$p1['end_date']}" . PHP_EOL;
echo "P2 : {$p2['start_date']} ～ {$p2['end_date']}" . PHP_EOL;
echo "固定頭補正・固定TOP4条件は変更せず、4番手艇の許可セットだけを全探索" . PHP_EOL;

foreach (TARGET_STADIUMS as $stadium) {
    $rows = buildRows($p1, $p2, $stadium);
    if (!$rows) continue;

    $meta = $rows[0];
    echo PHP_EOL;
    echo str_repeat('=', 152) . PHP_EOL;
    echo "【{$stadium}】" . PHP_EOL;
    echo str_repeat('=', 152) . PHP_EOL;
    echo "観測4番手艇: " . implode('/', $meta['observed']) . PHP_EOL;
    echo sprintf(
        "元の固定TOP4: Hit %.2f%% / 平均点 %.2f / TOP4 %dR\n",
        $meta['full_hit_rate'],
        $meta['full_avg_bets'],
        $meta['full_top4']
    );

    $perfect = array_values(array_filter(
        $rows,
        static fn(array $r): bool => $r['p1_lost'] === 0 && $r['p2_lost'] === 0
    ));
    usort($perfect, static fn(array $a, array $b): int => $b['saved_bets'] <=> $a['saved_bets']);

    $withinOne = array_values(array_filter(
        $rows,
        static fn(array $r): bool => $r['p1_lost'] <= 1 && $r['p2_lost'] <= 1
    ));
    usort($withinOne, static function (array $a, array $b): int {
        $cmp = $b['saved_bets'] <=> $a['saved_bets'];
        if ($cmp !== 0) return $cmp;
        return $a['lost'] <=> $b['lost'];
    });

    printRows('P1/P2ともHit完全維持', $perfect, 8);
    printRows('P1/P2それぞれ1Hit以内で最大削減', $withinOne, 10);
    printRows('Pareto候補（合算失Hit vs 削減点数）', paretoRows($rows), 12);
}

echo PHP_EOL;
echo str_repeat('=', 152) . PHP_EOL;
echo "見方" . PHP_EOL;
echo str_repeat('=', 152) . PHP_EOL;
echo "・まず『P1/P2ともHit完全維持』があれば最優先。" . PHP_EOL;
echo "・次に各期間1Hit以内で、平均点削減が大きい候補を見る。" . PHP_EOL;
echo "・Pareto候補は、同じ失Hitならより多く点数を削れる組合せだけを残している。" . PHP_EOL;
echo "・ここで選んでも固定4戦略はまだ変更しない。6か月データで同じ艇番セットを再検証して採否を決める。" . PHP_EOL;
echo PHP_EOL;
