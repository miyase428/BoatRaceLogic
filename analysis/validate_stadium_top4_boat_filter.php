<?php

declare(strict_types=1);

/**
 * 場別TOP4 4番手艇フィルタ 省点数化検証
 *
 * 4場の固定戦略は維持したまま、条件付きTOP4で追加される「4番手艇」の
 * 艇番だけを絞った場合に、Hitをどれだけ残して平均点数を削減できるかを見る。
 *
 * 固定頭補正
 *   戸田   : Web非1時、別本命-1の一次差2～5だけ非1許可。それ以外は1へ戻す。
 *   多摩川 : Web非1時、一次差5～10×二次差5～10だけ非1許可。それ以外は1へ戻す。
 *   大村   : Web非1なら1へ戻す。
 *   下関   : Web非1時、一次差5～10だけ非1許可。それ以外は1へ戻す。
 *
 * 固定TOP4条件
 *   戸田   : 3番手-4番手 final3差 <= 1.0
 *   多摩川 : 3番手-4番手 final3差 <= 2.0
 *   大村   : 3番手-4番手 final3差 <= 2.0
 *   下関   : 頭補正が実際に入ったレースだけ
 *
 * 省点数フィルタ候補
 *   戸田   : 4番手が 2/3/5/6号艇の時だけTOP4（4号艇を除外）
 *   多摩川 : 4番手が 2/3/4/5号艇の時だけTOP4（6号艇を除外）
 *   大村   : 4番手が 2/3/4/6号艇の時だけTOP4（5号艇を除外）
 *   下関   : 4番手が 2/3号艇の時だけTOP4
 *
 * Usage:
 *   1期間:
 *     php analysis/validate_stadium_top4_boat_filter.php <races.csv> <boats.csv>
 *
 *   2期間:
 *     php analysis/validate_stadium_top4_boat_filter.php \
 *       <P1_races.csv> <P1_boats.csv> <P2_races.csv> <P2_boats.csv>
 */

if ($argc !== 3 && $argc !== 5) {
    echo PHP_EOL;
    echo "使用方法:" . PHP_EOL;
    echo "  1期間: php analysis/validate_stadium_top4_boat_filter.php <races> <boats>" . PHP_EOL;
    echo "  2期間: php analysis/validate_stadium_top4_boat_filter.php <P1_races> <P1_boats> <P2_races> <P2_boats>" . PHP_EOL;
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

function allowedFourthBoats(string $stadium): array
{
    return match ($stadium) {
        '戸田' => [2, 3, 5, 6],
        '多摩川' => [2, 3, 4, 5],
        '大村' => [2, 3, 4, 6],
        '下関' => [2, 3],
        default => [],
    };
}

function shouldTop4Filtered(array $race, int $head, bool $headChanged): bool
{
    if (!shouldTop4Original($race, $head, $headChanged)) {
        return false;
    }

    $fourth = fourthCandidate($race, $head);
    if ($fourth === null) return false;

    return in_array($fourth, allowedFourthBoats($race['stadium']), true);
}

function emptyStats(): array
{
    return [
        'n' => 0,
        'current_hit' => 0,
        'head_hit' => 0,
        'practical_hit' => 0,
        'filtered_hit' => 0,
        'current_bets' => 0,
        'head_bets' => 0,
        'practical_bets' => 0,
        'filtered_bets' => 0,
        'head_changed' => 0,
        'top4_original' => 0,
        'top4_filtered' => 0,
        'lost_hits' => 0,
        'saved_bets' => 0,
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
        $top4Original = shouldTop4Original($race, $head, $headChanged);
        $top4Filtered = shouldTop4Filtered($race, $head, $headChanged);

        $currentBets = expandTrifecta((string)$race['honmei_kai']);
        $headBets = buildBets($race, $head, 3);
        $practicalBets = buildBets($race, $head, $top4Original ? 4 : 3);
        $filteredBets = buildBets($race, $head, $top4Filtered ? 4 : 3);

        $currentHit = in_array($race['actual_tri'], $currentBets, true);
        $headHit = in_array($race['actual_tri'], $headBets, true);
        $practicalHit = in_array($race['actual_tri'], $practicalBets, true);
        $filteredHit = in_array($race['actual_tri'], $filteredBets, true);

        foreach ([$stadium, 'ALL'] as $key) {
            $s =& $out[$key];
            $s['n']++;
            $s['current_bets'] += count($currentBets);
            $s['head_bets'] += count($headBets);
            $s['practical_bets'] += count($practicalBets);
            $s['filtered_bets'] += count($filteredBets);

            if ($currentHit) $s['current_hit']++;
            if ($headHit) $s['head_hit']++;
            if ($practicalHit) $s['practical_hit']++;
            if ($filteredHit) $s['filtered_hit']++;
            if ($headChanged) $s['head_changed']++;
            if ($top4Original) $s['top4_original']++;
            if ($top4Filtered) $s['top4_filtered']++;

            if ($practicalHit && !$filteredHit) {
                $s['lost_hits']++;
            }
            $s['saved_bets'] += max(0, count($practicalBets) - count($filteredBets));
            unset($s);
        }
    }

    return $out;
}

function mergeStats(array $a, array $b): array
{
    $out = [];
    foreach ($a as $key => $row) {
        $out[$key] = [];
        foreach ($row as $name => $value) {
            $out[$key][$name] = (int)$value + (int)($b[$key][$name] ?? 0);
        }
    }
    return $out;
}

function printPeriod(string $title, array $stats): void
{
    echo PHP_EOL;
    echo "【{$title}】" . PHP_EOL;
    echo sprintf(
        "%-10s %5s %-35s %8s %-31s %8s %7s %7s\n",
        '場', 'R数', 'Hit 現行→頭→TOP4→省点', '省点差',
        '平均点 現行→頭→TOP4→省点', '点削減', 'TOP4', '省TOP4'
    );
    echo str_repeat('-', 145) . PHP_EOL;

    $keys = ['戸田', '多摩川', '大村', '下関', 'ALL'];
    foreach ($keys as $key) {
        $s = $stats[$key];
        $n = (int)$s['n'];
        if ($n <= 0) continue;

        $currentHit = pct((int)$s['current_hit'], $n);
        $headHit = pct((int)$s['head_hit'], $n);
        $practicalHit = pct((int)$s['practical_hit'], $n);
        $filteredHit = pct((int)$s['filtered_hit'], $n);

        $currentAvg = avg((int)$s['current_bets'], $n);
        $headAvg = avg((int)$s['head_bets'], $n);
        $practicalAvg = avg((int)$s['practical_bets'], $n);
        $filteredAvg = avg((int)$s['filtered_bets'], $n);

        $label = $key === 'ALL' ? '4場合算' : $key;

        echo sprintf(
            "%-10s %5d %6.2f%%→%6.2f%%→%6.2f%%→%6.2f%% %+7.2fpt %5.2f→%5.2f→%5.2f→%5.2f %8.2f %7d %7d\n",
            $label,
            $n,
            $currentHit,
            $headHit,
            $practicalHit,
            $filteredHit,
            $filteredHit - $practicalHit,
            $currentAvg,
            $headAvg,
            $practicalAvg,
            $filteredAvg,
            $filteredAvg - $practicalAvg,
            $s['top4_original'],
            $s['top4_filtered']
        );
    }
}

function printSavings(array $stats): void
{
    echo PHP_EOL;
    echo "【TOP4艇番フィルタの省点数効果】" . PHP_EOL;
    echo sprintf(
        "%-10s %8s %10s %10s %12s %14s\n",
        '場', '削減R', '削減点数', '失ったHit', '削減点/失Hit', '判定'
    );
    echo str_repeat('-', 80) . PHP_EOL;

    foreach (['戸田', '多摩川', '大村', '下関', 'ALL'] as $key) {
        $s = $stats[$key];
        $removedRaces = (int)$s['top4_original'] - (int)$s['top4_filtered'];
        $saved = (int)$s['saved_bets'];
        $lost = (int)$s['lost_hits'];
        $ratio = $lost > 0 ? number_format($saved / $lost, 1) : '-';
        $label = $key === 'ALL' ? '4場合算' : $key;

        if ($lost === 0 && $saved > 0) {
            $judge = '◎ Hit維持で削減';
        } elseif ($lost > 0 && ($saved / $lost) >= 40.0) {
            $judge = '○ 削減効率高';
        } elseif ($lost > 0 && ($saved / $lost) >= 25.0) {
            $judge = '△ 要6か月確認';
        } else {
            $judge = '× 削りすぎ注意';
        }

        echo sprintf(
            "%-10s %8d %10d %10d %12s %14s\n",
            $label,
            $removedRaces,
            $saved,
            $lost,
            $ratio,
            $judge
        );
    }
}

try {
    if ($argc === 3) {
        $period = loadPeriod($argv[1], $argv[2], '対象期間');
        $stats = evaluatePeriod($period);

        echo PHP_EOL;
        echo str_repeat('=', 150) . PHP_EOL;
        echo "場別TOP4 4番手艇フィルタ 省点数化検証" . PHP_EOL;
        echo str_repeat('=', 150) . PHP_EOL;
        echo "期間: {$period['start_date']} ～ {$period['end_date']}" . PHP_EOL;
        echo "フィルタ: 戸田 2/3/5/6 | 多摩川 2/3/4/5 | 大村 2/3/4/6 | 下関 2/3" . PHP_EOL;

        printPeriod('対象期間', $stats);
        printSavings($stats);
    } else {
        $p1 = loadPeriod($argv[1], $argv[2], 'P1');
        $p2 = loadPeriod($argv[3], $argv[4], 'P2');
        $s1 = evaluatePeriod($p1);
        $s2 = evaluatePeriod($p2);
        $all = mergeStats($s1, $s2);

        echo PHP_EOL;
        echo str_repeat('=', 150) . PHP_EOL;
        echo "場別TOP4 4番手艇フィルタ 省点数化検証" . PHP_EOL;
        echo str_repeat('=', 150) . PHP_EOL;
        echo "P1 : {$p1['start_date']} ～ {$p1['end_date']}" . PHP_EOL;
        echo "P2 : {$p2['start_date']} ～ {$p2['end_date']}" . PHP_EOL;
        echo "フィルタ: 戸田 2/3/5/6 | 多摩川 2/3/4/5 | 大村 2/3/4/6 | 下関 2/3" . PHP_EOL;

        printPeriod('P1', $s1);
        printPeriod('P2', $s2);
        printPeriod('P1+P2 合算', $all);
        printSavings($all);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

echo PHP_EOL;
echo "見方:" . PHP_EOL;
echo "  ・TOP4→省点でHitがほぼ維持され、平均点だけ下がれば艇番フィルタは有望。" . PHP_EOL;
echo "  ・P1/P2のどちらかだけ大きく悪化する場合は過適合を疑う。" . PHP_EOL;
echo "  ・固定4戦略はまだ変更せず、6か月CSVで同じフィルタを再検証してから採否を決める。" . PHP_EOL;
echo PHP_EOL;
