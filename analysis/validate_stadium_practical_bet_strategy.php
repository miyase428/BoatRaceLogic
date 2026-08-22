<?php

declare(strict_types=1);

/**
 * 4場 実戦向け固定戦略 検証
 *
 * ここまでの探索結果から、各場で1本ずつ条件を固定して比較する。
 *
 * 頭補正
 *   戸田   : Web非1時、別本命-1の一次差2～5だけ非1許可。それ以外は1へ戻す。
 *   多摩川 : Web非1時、一次差5～10×二次差5～10だけ非1許可。それ以外は1へ戻す。
 *   大村   : Web非1なら1へ戻す。
 *   下関   : Web非1時、一次差5～10だけ非1許可。それ以外は1へ戻す。
 *
 * 条件付き2着TOP4
 *   戸田   : 3番手-4番手 final3差 <= 1.0
 *   多摩川 : 3番手-4番手 final3差 <= 2.0
 *   大村   : 3番手-4番手 final3差 <= 2.0
 *   下関   : 頭補正が実際に入ったレースだけ
 *
 * 比較
 *   1) 現行Web本命買い目
 *   2) 頭補正のみ（2着TOP3）
 *   3) 頭補正 + 条件付きTOP4（固定実戦候補）
 *
 * DBにはアクセスしない。
 *
 * Usage:
 *   1期間:
 *     php analysis/validate_stadium_practical_bet_strategy.php <races.csv> <boats.csv>
 *
 *   2期間:
 *     php analysis/validate_stadium_practical_bet_strategy.php \
 *       <P1_races.csv> <P1_boats.csv> <P2_races.csv> <P2_boats.csv>
 */

if ($argc !== 3 && $argc !== 5) {
    echo PHP_EOL;
    echo "使用方法:" . PHP_EOL;
    echo "  1期間: php analysis/validate_stadium_practical_bet_strategy.php <races> <boats>" . PHP_EOL;
    echo "  2期間: php analysis/validate_stadium_practical_bet_strategy.php <P1_races> <P1_boats> <P2_races> <P2_boats>" . PHP_EOL;
    echo PHP_EOL;
    exit(1);
}

const TARGET_STADIUMS = ['戸田', '多摩川', '大村', '下関'];

function pct(int $count, int $total): float
{
    return $total > 0 ? ($count / $total) * 100.0 : 0.0;
}

function avg(int $sum, int $total): float
{
    return $total > 0 ? $sum / $total : 0.0;
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

function num(array $row, string $key): ?float
{
    $value = $row[$key] ?? null;
    return is_numeric($value) ? (float)$value : null;
}

function loadPeriod(string $raceCsv, string $boatCsv, string $label): array
{
    $raceRows = readCsvAssoc($raceCsv, [
        'race_code', 'race_date', 'stadium_name', 'honmei_head', 'honmei_kai',
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
        $honmeiKai = trim((string)$row['honmei_kai']);
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
            'honmei_kai' => $honmeiKai,
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

function expandTrifecta(string $bet): array
{
    $bet = trim($bet);
    if ($bet === '') return [];

    $parts = explode('-', $bet);
    if (count($parts) !== 3) return [];

    $first = str_split(trim($parts[0]));
    $second = str_split(trim($parts[1]));
    $third = str_split(trim($parts[2]));

    $bets = [];
    foreach ($first as $a) {
        foreach ($second as $b) {
            foreach ($third as $c) {
                if ($a === $b || $a === $c || $b === $c) continue;
                $bets[] = "{$a}-{$b}-{$c}";
            }
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

function emptyStats(): array
{
    return [
        'n' => 0,
        'current_hit' => 0,
        'head_hit' => 0,
        'practical_hit' => 0,
        'current_bets' => 0,
        'head_bets' => 0,
        'practical_bets' => 0,
        'head_changed' => 0,
        'top4_races' => 0,
    ];
}

function evaluatePeriod(array $period): array
{
    $out = ['ALL' => emptyStats()];
    foreach (TARGET_STADIUMS as $stadium) {
        $out[$stadium] = emptyStats();
    }

    foreach ($period['races'] as $race) {
        $stadium = $race['stadium'];
        if (!isset($out[$stadium])) continue;

        $head = adjustedHead($race);
        $headChanged = $head !== (int)$race['honmei'];
        $top4 = shouldTop4($race, $head, $headChanged);

        $currentBets = expandTrifecta((string)$race['honmei_kai']);
        $headBets = buildBets($race, $head, 3);
        $practicalBets = buildBets($race, $head, $top4 ? 4 : 3);
        $actual = $race['actual_tri'];

        foreach ([$stadium, 'ALL'] as $key) {
            $s =& $out[$key];
            $s['n']++;
            $s['current_bets'] += count($currentBets);
            $s['head_bets'] += count($headBets);
            $s['practical_bets'] += count($practicalBets);
            if (in_array($actual, $currentBets, true)) $s['current_hit']++;
            if (in_array($actual, $headBets, true)) $s['head_hit']++;
            if (in_array($actual, $practicalBets, true)) $s['practical_hit']++;
            if ($headChanged) $s['head_changed']++;
            if ($top4 && count($practicalBets) > count($headBets)) $s['top4_races']++;
            unset($s);
        }
    }

    return $out;
}

function mergeStats(array $a, array $b): array
{
    $out = [];
    foreach ($a as $key => $value) {
        $out[$key] = (int)$value + (int)($b[$key] ?? 0);
    }
    return $out;
}

function printRow(string $label, array $s): void
{
    $n = (int)$s['n'];
    $current = pct((int)$s['current_hit'], $n);
    $head = pct((int)$s['head_hit'], $n);
    $practical = pct((int)$s['practical_hit'], $n);
    $currentAvg = avg((int)$s['current_bets'], $n);
    $headAvg = avg((int)$s['head_bets'], $n);
    $practicalAvg = avg((int)$s['practical_bets'], $n);

    echo sprintf(
        "%-10s %5d %7s→%7s→%7s  %+7.2fpt  %5.2f→%5.2f→%5.2f  %+5.2f  %5d %5d\n",
        $label,
        $n,
        fmtPct($current),
        fmtPct($head),
        fmtPct($practical),
        $practical - $current,
        $currentAvg,
        $headAvg,
        $practicalAvg,
        $practicalAvg - $currentAvg,
        (int)$s['head_changed'],
        (int)$s['top4_races']
    );
}

try {
    $periods = [];
    $periods[] = loadPeriod($argv[1], $argv[2], $argc === 3 ? '対象期間' : 'P1');
    if ($argc === 5) {
        $periods[] = loadPeriod($argv[3], $argv[4], 'P2');
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

echo PHP_EOL;
echo str_repeat('=', 132) . PHP_EOL;
echo "4場 実戦向け固定戦略 検証" . PHP_EOL;
echo str_repeat('=', 132) . PHP_EOL;
echo "固定TOP4条件: 戸田 final3差<=1.0 / 多摩川 <=2.0 / 大村 <=2.0 / 下関 頭補正あり時のみ" . PHP_EOL;
echo "比較: 現行 → 頭補正のみ → 頭補正+条件付きTOP4" . PHP_EOL;
echo PHP_EOL;

$evaluated = [];
foreach ($periods as $period) {
    $stats = evaluatePeriod($period);
    $evaluated[] = $stats;

    echo "【{$period['label']} {$period['start_date']} ～ {$period['end_date']}】" . PHP_EOL;
    echo sprintf("%-10s %5s %-27s %9s %-20s %7s %5s %5s\n", '場', 'R数', 'Hit 現行→頭→実戦', '現行差', '平均点 現行→頭→実戦', '点数差', '頭変更', 'TOP4');
    echo str_repeat('-', 132) . PHP_EOL;

    foreach (TARGET_STADIUMS as $stadium) {
        printRow($stadium, $stats[$stadium]);
    }
    printRow('4場合算', $stats['ALL']);
    echo PHP_EOL;
}

if (count($evaluated) === 2) {
    echo "【P1+P2 合算】" . PHP_EOL;
    echo sprintf("%-10s %5s %-27s %9s %-20s %7s %5s %5s\n", '場', 'R数', 'Hit 現行→頭→実戦', '現行差', '平均点 現行→頭→実戦', '点数差', '頭変更', 'TOP4');
    echo str_repeat('-', 132) . PHP_EOL;

    foreach (array_merge(TARGET_STADIUMS, ['ALL']) as $key) {
        $merged = mergeStats($evaluated[0][$key], $evaluated[1][$key]);
        printRow($key === 'ALL' ? '4場合算' : $key, $merged);
    }
    echo PHP_EOL;
}

echo "見方:" . PHP_EOL;
echo "  ・現行→頭 で上がる分が場別イン補正の効果。" . PHP_EOL;
echo "  ・頭→実戦 でさらに上がる分が条件付き2着TOP4の効果。" . PHP_EOL;
echo "  ・P1/P2とも同方向なら6か月固定検証候補として維持。" . PHP_EOL;
echo "  ・6か月CSV完成後は1期間モードで同じスクリプトをそのまま実行できる。" . PHP_EOL;
echo "  ・回収率はまだ見ず、固定条件の再現性を優先する。" . PHP_EOL;
echo PHP_EOL;
