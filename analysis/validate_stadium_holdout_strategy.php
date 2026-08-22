<?php

declare(strict_types=1);

/**
 * 4場 固定戦略 ホールドアウト検証
 *
 * 6か月CSV（想定: 2026-02-15～2026-08-14）を、
 *   - 未使用ホールドアウト: 2026-02-15～2026-06-14
 *   - 探索済み期間      : 2026-06-15～2026-08-14
 * に分け、探索済み2か月で固定した条件を変更せず再検証する。
 *
 * 比較する4段階
 *   1) 現行Web本命買い目
 *   2) 場別頭補正 + 2着TOP3
 *   3) 場別頭補正 + 固定条件付き2着TOP4
 *   4) 3) + 保守的な4番手艇フィルタ（省点数候補）
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
 * 保守的な艇番フィルタ候補
 *   戸田   : 4号艇だけ除外（2/3/5/6を許可）
 *   多摩川 : フィルタなし
 *   大村   : 5号艇だけ除外（2/3/4/6を許可）
 *   下関   : 6号艇だけ除外（2/3/4/5を許可）
 *
 * Usage:
 *   php analysis/validate_stadium_holdout_strategy.php \
 *     analysis/output/final_prediction_races_20260215_20260814.csv \
 *     analysis/output/final_prediction_boats_20260215_20260814.csv
 */

if ($argc !== 3) {
    echo PHP_EOL;
    echo "使用方法:" . PHP_EOL;
    echo "  php analysis/validate_stadium_holdout_strategy.php <6か月_races.csv> <6か月_boats.csv>" . PHP_EOL;
    echo PHP_EOL;
    exit(1);
}

[$script, $raceCsv, $boatCsv] = $argv;

const TARGET_STADIUMS = ['戸田', '多摩川', '大村', '下関'];
const HOLDOUT_CUTOFF = '2026-06-15';

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

function loadRaces(string $raceCsv, string $boatCsv): array
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
            $date === '' ||
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
    }

    return $races;
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

    $lane1 = $race['boats'][1] ?? [];
    $headRow = $race['boats'][$head] ?? [];

    $p1 = num($lane1, 'first_total_score');
    $ph = num($headRow, 'first_total_score');
    $s1 = num($lane1, 'second_score');
    $sh = num($headRow, 'second_score');

    return [
        'primary' => ($p1 !== null && $ph !== null) ? ($ph - $p1) : null,
        'secondary' => ($s1 !== null && $sh !== null) ? ($sh - $s1) : null,
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
            ? (int)$row['final_rank']
            : 999;
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

function allowedFourthBoats(string $stadium): array
{
    return match ($stadium) {
        '戸田' => [2, 3, 5, 6],
        '多摩川' => [2, 3, 4, 5, 6],
        '大村' => [2, 3, 4, 6],
        '下関' => [2, 3, 4, 5],
        default => [],
    };
}

function emptyStats(): array
{
    return [
        'n' => 0,
        'current_hit' => 0,
        'head_hit' => 0,
        'top4_hit' => 0,
        'filter_hit' => 0,
        'current_bets' => 0,
        'head_bets' => 0,
        'top4_bets' => 0,
        'filter_bets' => 0,
        'head_changed' => 0,
        'top4_races' => 0,
        'filter_top4_races' => 0,
    ];
}

function addRaceToStats(array &$s, array $race): void
{
    $head = adjustedHead($race);
    $headChanged = $head !== (int)$race['honmei'];
    $top4 = shouldTop4($race, $head, $headChanged);
    $fourth = fourthCandidate($race, $head);

    $currentBets = expandTrifecta((string)$race['honmei_kai']);
    $headBets = buildBets($race, $head, 3);
    $top4Bets = $top4 ? buildBets($race, $head, 4) : $headBets;

    $filterTop4 = false;
    if ($top4 && $fourth !== null) {
        $filterTop4 = in_array($fourth, allowedFourthBoats($race['stadium']), true);
    }
    $filterBets = $filterTop4 ? buildBets($race, $head, 4) : $headBets;

    $actual = $race['actual_tri'];

    $s['n']++;
    $s['current_bets'] += count($currentBets);
    $s['head_bets'] += count($headBets);
    $s['top4_bets'] += count($top4Bets);
    $s['filter_bets'] += count($filterBets);

    if (in_array($actual, $currentBets, true)) $s['current_hit']++;
    if (in_array($actual, $headBets, true)) $s['head_hit']++;
    if (in_array($actual, $top4Bets, true)) $s['top4_hit']++;
    if (in_array($actual, $filterBets, true)) $s['filter_hit']++;

    if ($headChanged) $s['head_changed']++;
    if ($top4) $s['top4_races']++;
    if ($filterTop4) $s['filter_top4_races']++;
}

function evaluateSegment(array $races, callable $include): array
{
    $out = ['ALL' => emptyStats()];
    foreach (TARGET_STADIUMS as $stadium) {
        $out[$stadium] = emptyStats();
    }

    foreach ($races as $race) {
        if (!$include($race)) continue;
        $stadium = $race['stadium'];
        if (!isset($out[$stadium])) continue;

        addRaceToStats($out[$stadium], $race);
        addRaceToStats($out['ALL'], $race);
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

function printSegment(string $title, array $stats): void
{
    echo PHP_EOL;
    echo "【{$title}】" . PHP_EOL;
    echo sprintf(
        "%-10s %6s %-31s %9s %-34s %9s %7s %7s\n",
        '場', 'R数', 'Hit 現行→頭→TOP4→省点', '現行差',
        '平均点 現行→頭→TOP4→省点', '省点差', '頭変更', 'TOP4'
    );
    echo str_repeat('-', 144) . PHP_EOL;

    $keys = ['戸田', '多摩川', '大村', '下関', 'ALL'];
    foreach ($keys as $key) {
        $s = $stats[$key];
        if ((int)$s['n'] === 0) continue;

        $label = $key === 'ALL' ? '4場合算' : $key;
        $current = hitRate($s, 'current_hit');
        $head = hitRate($s, 'head_hit');
        $top4 = hitRate($s, 'top4_hit');
        $filter = hitRate($s, 'filter_hit');

        $currentAvg = avgBets($s, 'current_bets');
        $headAvg = avgBets($s, 'head_bets');
        $top4Avg = avgBets($s, 'top4_bets');
        $filterAvg = avgBets($s, 'filter_bets');

        echo sprintf(
            "%-10s %6d %6.2f%%→%6.2f%%→%6.2f%%→%6.2f%% %+8.2fpt %5.2f→%5.2f→%5.2f→%5.2f %+8.2f %7d %3d/%-3d\n",
            $label,
            $s['n'],
            $current, $head, $top4, $filter,
            $filter - $current,
            $currentAvg, $headAvg, $top4Avg, $filterAvg,
            $filterAvg - $top4Avg,
            $s['head_changed'],
            $s['filter_top4_races'],
            $s['top4_races']
        );
    }
}

function printHoldoutJudgement(array $stats): void
{
    echo PHP_EOL;
    echo "【ホールドアウト判定の要約】" . PHP_EOL;
    echo sprintf(
        "%-10s %12s %12s %13s %13s %s\n",
        '場', '頭補正差', 'TOP4追加差', '省点Hit差', '省点数差', '判定'
    );
    echo str_repeat('-', 92) . PHP_EOL;

    foreach (TARGET_STADIUMS as $stadium) {
        $s = $stats[$stadium];
        if ((int)$s['n'] === 0) continue;

        $current = hitRate($s, 'current_hit');
        $head = hitRate($s, 'head_hit');
        $top4 = hitRate($s, 'top4_hit');
        $filter = hitRate($s, 'filter_hit');
        $top4Avg = avgBets($s, 'top4_bets');
        $filterAvg = avgBets($s, 'filter_bets');

        $headDiff = $head - $current;
        $top4Diff = $top4 - $head;
        $filterDiff = $filter - $top4;
        $pointDiff = $filterAvg - $top4Avg;

        if ($headDiff > 0.0 && $top4Diff > 0.0 && $filterDiff >= 0.0 && $pointDiff < 0.0) {
            $judge = '◎ 全段階再現';
        } elseif ($headDiff > 0.0 && $top4Diff > 0.0 && $filterDiff >= -0.50 && $pointDiff < 0.0) {
            $judge = '○ 本体再現・省点候補';
        } elseif ($headDiff > 0.0 && $top4Diff > 0.0) {
            $judge = '○ 頭+TOP4再現';
        } elseif ($headDiff > 0.0) {
            $judge = '△ 頭のみ再現';
        } else {
            $judge = '× 頭補正未再現';
        }

        echo sprintf(
            "%-10s %+11.2fpt %+11.2fpt %+12.2fpt %+12.2f %s\n",
            $stadium,
            $headDiff,
            $top4Diff,
            $filterDiff,
            $pointDiff,
            $judge
        );
    }
}

try {
    $races = loadRaces($raceCsv, $boatCsv);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

if (!$races) {
    fwrite(STDERR, "対象レースがありません。" . PHP_EOL);
    exit(1);
}

$dates = array_column($races, 'race_date');
sort($dates);
$startDate = $dates[0] ?? '';
$endDate = $dates[count($dates) - 1] ?? '';

$holdout = evaluateSegment(
    $races,
    static fn(array $race): bool => $race['race_date'] < HOLDOUT_CUTOFF
);

$development = evaluateSegment(
    $races,
    static fn(array $race): bool => $race['race_date'] >= HOLDOUT_CUTOFF
);

$all = evaluateSegment(
    $races,
    static fn(array $race): bool => true
);

echo PHP_EOL;
echo str_repeat('=', 150) . PHP_EOL;
echo "4場 固定戦略 ホールドアウト検証" . PHP_EOL;
echo str_repeat('=', 150) . PHP_EOL;
echo "入力期間      : {$startDate} ～ {$endDate}" . PHP_EOL;
echo "未使用期間    : {$startDate} ～ 2026-06-14（最優先判定）" . PHP_EOL;
echo "探索済み期間  : 2026-06-15 ～ {$endDate}（参考）" . PHP_EOL;
echo "比較          : 現行 → 頭補正 → 固定TOP4 → 保守的艇番フィルタ" . PHP_EOL;
echo "艇番フィルタ  : 戸田=4除外 / 多摩川=なし / 大村=5除外 / 下関=6除外" . PHP_EOL;

printSegment('未使用ホールドアウト（最重要）', $holdout);
printHoldoutJudgement($holdout);
printSegment('探索済み期間（参考）', $development);
printSegment('6か月全体（参考）', $all);

echo PHP_EOL;
echo str_repeat('=', 150) . PHP_EOL;
echo "判断ルール" . PHP_EOL;
echo str_repeat('=', 150) . PHP_EOL;
echo "・採否は6か月全体より、未使用4か月の結果を優先する。" . PHP_EOL;
echo "・未使用4か月で『現行→頭補正』が改善しなければ、その場の頭補正は本番採用しない。" . PHP_EOL;
echo "・未使用4か月で『頭補正→TOP4』も改善すれば、2着TOP4条件の再現性あり。" . PHP_EOL;
echo "・艇番フィルタは本体より優先度が低い。Hit維持またはごく小さい低下で点数が下がる時だけ候補に残す。" . PHP_EOL;
echo "・条件はこの検証結果を見るまで変更しない。" . PHP_EOL;
echo PHP_EOL;
