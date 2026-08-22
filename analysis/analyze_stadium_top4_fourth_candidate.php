<?php

declare(strict_types=1);

/**
 * 場別TOP4 4番手艇プロファイル分析
 *
 * 固定済みの頭補正 + 条件付きTOP4について、
 * 「追加された4番手艇」がどの艇番で、どれだけ実2着を拾っているかを見る。
 *
 * さらに、固定TOP4条件に該当しても
 * 「4番手が特定艇番の時だけTOP4」に絞った場合のHit/点数効率も比較する。
 *
 * 対象固定戦略
 *   戸田   : 頭補正A + final3差<=1.0でTOP4
 *   多摩川 : 頭補正 + final3差<=2.0でTOP4
 *   大村   : 非1本命なら1へ戻す + final3差<=2.0でTOP4
 *   下関   : 頭補正 + 頭変更時だけTOP4
 *
 * DBにはアクセスしない。
 *
 * Usage:
 *   php analysis/analyze_stadium_top4_fourth_candidate.php \
 *     analysis/output/final_prediction_races_20260615_20260714.csv \
 *     analysis/output/final_prediction_boats_20260615_20260714.csv \
 *     analysis/output/final_prediction_races_20260715_20260814.csv \
 *     analysis/output/final_prediction_boats_20260715_20260814.csv
 */

if ($argc !== 5) {
    echo PHP_EOL;
    echo "使用方法:" . PHP_EOL;
    echo "  php analysis/analyze_stadium_top4_fourth_candidate.php <P1_races> <P1_boats> <P2_races> <P2_boats>" . PHP_EOL;
    echo PHP_EOL;
    exit(1);
}

[$script, $raceCsv1, $boatCsv1, $raceCsv2, $boatCsv2] = $argv;

const TARGET_STADIUMS = ['戸田', '多摩川', '大村', '下関'];

function pct(int $count, int $total): float
{
    return $total > 0 ? ($count / $total) * 100.0 : 0.0;
}

function avg(int|float $sum, int $total): float
{
    return $total > 0 ? (float)$sum / $total : 0.0;
}

function fmtPct(float $value): string
{
    return number_format($value, 2) . '%';
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

    $header[0] = preg_replace('/^\\xEF\\xBB\\xBF/', '', (string)$header[0]);
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
            'actual1' => $actual1,
            'actual2' => $actual2,
            'actual3' => $actual3,
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
    if ($head === 1) {
        return ['primary' => null, 'secondary' => null];
    }

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

    if ($stadium === '大村') {
        return 1;
    }

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

function shouldTop4(array $race, int $head, bool $headChanged): bool
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

function fourthCandidate(array $race, int $head): ?int
{
    $eligible = eligibleAfterHead($race, $head);
    return count($eligible) >= 4 ? (int)$eligible[3] : null;
}

function emptyLaneStat(): array
{
    return [
        'n' => 0,
        'actual2' => 0,
        'actual3' => 0,
        'gain_hit' => 0,
        'added_bets' => 0,
    ];
}

function collectProfile(array $period, string $stadium): array
{
    $out = [
        'expanded' => 0,
        'base_hit' => 0,
        'top4_hit' => 0,
        'added_bets' => 0,
        'lanes' => array_fill(1, 6, null),
    ];

    for ($lane = 1; $lane <= 6; $lane++) {
        $out['lanes'][$lane] = emptyLaneStat();
    }

    foreach ($period['races'] as $race) {
        if ($race['stadium'] !== $stadium) continue;

        $head = adjustedHead($race);
        $changed = $head !== (int)$race['honmei'];
        if (!shouldTop4($race, $head, $changed)) continue;

        $fourth = fourthCandidate($race, $head);
        if ($fourth === null) continue;

        $base = buildBets($race, $head, 3);
        $top4 = buildBets($race, $head, 4);
        if (count($top4) <= count($base)) continue;

        $baseHit = in_array($race['actual_tri'], $base, true);
        $top4Hit = in_array($race['actual_tri'], $top4, true);

        $out['expanded']++;
        if ($baseHit) $out['base_hit']++;
        if ($top4Hit) $out['top4_hit']++;
        $out['added_bets'] += count($top4) - count($base);

        $ls =& $out['lanes'][$fourth];
        $ls['n']++;
        if ((int)$race['actual2'] === $fourth) $ls['actual2']++;
        if ((int)$race['actual3'] === $fourth) $ls['actual3']++;
        if (!$baseHit && $top4Hit) $ls['gain_hit']++;
        $ls['added_bets'] += count($top4) - count($base);
        unset($ls);
    }

    return $out;
}

function mergeProfile(array $a, array $b): array
{
    $out = [
        'expanded' => (int)$a['expanded'] + (int)$b['expanded'],
        'base_hit' => (int)$a['base_hit'] + (int)$b['base_hit'],
        'top4_hit' => (int)$a['top4_hit'] + (int)$b['top4_hit'],
        'added_bets' => (int)$a['added_bets'] + (int)$b['added_bets'],
        'lanes' => array_fill(1, 6, null),
    ];

    for ($lane = 1; $lane <= 6; $lane++) {
        $out['lanes'][$lane] = emptyLaneStat();
        foreach ($out['lanes'][$lane] as $key => $dummy) {
            $out['lanes'][$lane][$key] = (int)($a['lanes'][$lane][$key] ?? 0)
                + (int)($b['lanes'][$lane][$key] ?? 0);
        }
    }

    return $out;
}

function evaluateLaneRestricted(array $period, string $stadium, int $targetLane): array
{
    $out = [
        'n' => 0,
        'base_hit' => 0,
        'restricted_hit' => 0,
        'base_bets' => 0,
        'restricted_bets' => 0,
        'expanded' => 0,
        'gain_hit' => 0,
    ];

    foreach ($period['races'] as $race) {
        if ($race['stadium'] !== $stadium) continue;

        $head = adjustedHead($race);
        $changed = $head !== (int)$race['honmei'];
        $base = buildBets($race, $head, 3);
        $bets = $base;

        if (shouldTop4($race, $head, $changed)) {
            $fourth = fourthCandidate($race, $head);
            if ($fourth === $targetLane) {
                $candidate = buildBets($race, $head, 4);
                if (count($candidate) > count($base)) {
                    $bets = $candidate;
                    $out['expanded']++;
                }
            }
        }

        $baseHit = in_array($race['actual_tri'], $base, true);
        $hit = in_array($race['actual_tri'], $bets, true);

        $out['n']++;
        if ($baseHit) $out['base_hit']++;
        if ($hit) $out['restricted_hit']++;
        if (!$baseHit && $hit) $out['gain_hit']++;
        $out['base_bets'] += count($base);
        $out['restricted_bets'] += count($bets);
    }

    return $out;
}

function mergeEval(array $a, array $b): array
{
    $out = [];
    foreach ($a as $key => $value) {
        $out[$key] = (int)$value + (int)($b[$key] ?? 0);
    }
    return $out;
}

function hitRate(array $s, string $key): float
{
    return pct((int)$s[$key], (int)$s['n']);
}

function avgBets(array $s, string $key): float
{
    return avg((int)$s[$key], (int)$s['n']);
}

try {
    $p1 = loadPeriod($raceCsv1, $boatCsv1, 'P1');
    $p2 = loadPeriod($raceCsv2, $boatCsv2, 'P2');
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

$labels = [
    '戸田' => '戸田A',
    '多摩川' => '多摩川',
    '大村' => '大村',
    '下関' => '下関',
];

echo PHP_EOL;
echo str_repeat('=', 148) . PHP_EOL;
echo "場別TOP4 4番手艇プロファイル分析" . PHP_EOL;
echo str_repeat('=', 148) . PHP_EOL;
echo "P1 : {$p1['start_date']} ～ {$p1['end_date']}" . PHP_EOL;
echo "P2 : {$p2['start_date']} ～ {$p2['end_date']}" . PHP_EOL;
echo "固定戦略は変更せず、追加4番手の艇番だけを分析" . PHP_EOL;

foreach (TARGET_STADIUMS as $stadium) {
    $s1 = collectProfile($p1, $stadium);
    $s2 = collectProfile($p2, $stadium);
    $all = mergeProfile($s1, $s2);

    echo PHP_EOL;
    echo str_repeat('=', 148) . PHP_EOL;
    echo "【{$labels[$stadium]}】" . PHP_EOL;
    echo str_repeat('=', 148) . PHP_EOL;
    echo sprintf(
        "TOP4拡張R P1/P2/合算: %d / %d / %d  |  追加Hit: %d  |  追加点数: %d  |  追加点/Hit: %s\n",
        $s1['expanded'],
        $s2['expanded'],
        $all['expanded'],
        $all['top4_hit'] - $all['base_hit'],
        $all['added_bets'],
        ($all['top4_hit'] - $all['base_hit']) > 0
            ? number_format($all['added_bets'] / ($all['top4_hit'] - $all['base_hit']), 1)
            : '-'
    );

    echo PHP_EOL;
    echo "■ 追加4番手の艇番別プロファイル（2期間合算）" . PHP_EOL;
    echo sprintf("%-6s %6s %9s %9s %9s %11s\n", '艇番', 'N', '実2着', '実3着', '獲得Hit', '追加点/Hit');
    echo str_repeat('-', 72) . PHP_EOL;

    for ($lane = 1; $lane <= 6; $lane++) {
        $ls = $all['lanes'][$lane];
        if ($ls['n'] <= 0) continue;

        $perHit = $ls['gain_hit'] > 0
            ? number_format($ls['added_bets'] / $ls['gain_hit'], 1)
            : '-';

        echo sprintf(
            "%d号 %7d %8s %8s %8d %11s\n",
            $lane,
            $ls['n'],
            fmtPct(pct($ls['actual2'], $ls['n'])),
            fmtPct(pct($ls['actual3'], $ls['n'])),
            $ls['gain_hit'],
            $perHit
        );
    }

    echo PHP_EOL;
    echo "■ 『4番手がこの艇番の時だけTOP4』シミュレーション" . PHP_EOL;
    echo sprintf(
        "%-6s %8s %9s %9s %9s %9s %9s %9s\n",
        '艇番', '拡張R', 'P1差', 'P2差', '合算差', '平均点増', '獲得Hit', '追加点/Hit'
    );
    echo str_repeat('-', 100) . PHP_EOL;

    for ($lane = 1; $lane <= 6; $lane++) {
        $e1 = evaluateLaneRestricted($p1, $stadium, $lane);
        $e2 = evaluateLaneRestricted($p2, $stadium, $lane);
        $ea = mergeEval($e1, $e2);

        if ($ea['expanded'] <= 0) continue;

        $p1Diff = hitRate($e1, 'restricted_hit') - hitRate($e1, 'base_hit');
        $p2Diff = hitRate($e2, 'restricted_hit') - hitRate($e2, 'base_hit');
        $allDiff = hitRate($ea, 'restricted_hit') - hitRate($ea, 'base_hit');
        $pointDiff = avgBets($ea, 'restricted_bets') - avgBets($ea, 'base_bets');
        $addedBets = $ea['restricted_bets'] - $ea['base_bets'];
        $perHit = $ea['gain_hit'] > 0
            ? number_format($addedBets / $ea['gain_hit'], 1)
            : '-';

        echo sprintf(
            "%d号 %9d %+8.2fpt %+8.2fpt %+8.2fpt %+9.2f %9d %9s\n",
            $lane,
            $ea['expanded'],
            $p1Diff,
            $p2Diff,
            $allDiff,
            $pointDiff,
            $ea['gain_hit'],
            $perHit
        );
    }
}

echo PHP_EOL;
echo str_repeat('=', 148) . PHP_EOL;
echo "見方" . PHP_EOL;
echo str_repeat('=', 148) . PHP_EOL;
echo "・4番手艇の実2着率/獲得Hitが特定艇番に偏るかを見る。" . PHP_EOL;
echo "・艇番限定でもP1/P2ともHit差がプラスなら、TOP4条件をさらに絞れる候補。" . PHP_EOL;
echo "・平均点増と追加点/Hitが大きく改善するなら、固定TOP4条件の省点数化候補。" . PHP_EOL;
echo "・ここも探索のみ。固定4戦略そのものは6か月再検証まで変更しない。" . PHP_EOL;
echo PHP_EOL;
