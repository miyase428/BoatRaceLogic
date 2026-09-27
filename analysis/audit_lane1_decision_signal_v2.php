<?php

declare(strict_types=1);

/**
 * ①判断シグナルをAI1着率 v5基準で再監査する。
 *
 * - 旧一次・二次条件に、v5本命の上で追加価値があるかを確認
 * - v5の①確率と首位差だけで、警戒・再確認を表示する方がよいかを確認
 * - 実装の閾値選定に使わない前方期間も分けて出力
 *
 * Usage:
 *   php analysis/audit_lane1_decision_signal_v2.php
 */

require_once __DIR__ . '/../common/db_connect.php';

const V5_FILE = __DIR__ . '/output/ai_winrate_motor_reset_ensemble_2026_predictions.csv.gz';
const V5_COL = 'trio_v2_15_base_21_pair_64_course_s500';
const FINAL_FILES = [
    __DIR__ . '/output/final_prediction_boats_fast_cached_20260215_20260814.csv',
    __DIR__ . '/output/final_prediction_boats_fast_cached_20260815_20260822.csv',
    __DIR__ . '/output/final_prediction_boats_fast_cached_20260823_20260831.csv',
    __DIR__ . '/output/final_prediction_boats_fast_cached_20260901_20260917.csv',
    __DIR__ . '/output/final_prediction_boats_fast_cached_20260915_20260921.csv',
];

function csvRows(string $path): Generator
{
    $fp = fopen($path, 'rb');
    if ($fp === false) {
        throw new RuntimeException("CSVを開けません: {$path}");
    }
    $header = fgetcsv($fp);
    if (!is_array($header)) {
        fclose($fp);
        return;
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    while (($cols = fgetcsv($fp)) !== false) {
        if (count($cols) === count($header)) {
            yield array_combine($header, $cols);
        }
    }
    fclose($fp);
}

function gzCsvRows(string $path): Generator
{
    $fp = gzopen($path, 'rb');
    if ($fp === false) {
        throw new RuntimeException("gzip CSVを開けません: {$path}");
    }
    $header = fgetcsv($fp);
    if (!is_array($header)) {
        gzclose($fp);
        return;
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    while (($cols = fgetcsv($fp)) !== false) {
        if (count($cols) === count($header)) {
            yield array_combine($header, $cols);
        }
    }
    gzclose($fp);
}

function i(mixed $value, int $default = 0): int
{
    return is_numeric($value) ? (int)$value : $default;
}

function f(mixed $value, float $default = 0.0): float
{
    return is_numeric($value) ? (float)$value : $default;
}

function pct(int $n, int $d): float
{
    return $d > 0 ? 100.0 * $n / $d : 0.0;
}

function loadFinal(): array
{
    $out = [];
    foreach (FINAL_FILES as $path) {
        foreach (csvRows($path) as $row) {
            $code = trim((string)($row['race_code'] ?? ''));
            $boat = i($row['lane_number'] ?? 0);
            if ($code === '' || $boat < 1 || $boat > 6) {
                continue;
            }
            $out[$code][$boat] = [
                'date' => (string)($row['race_date'] ?? ''),
                'actual' => i($row['actual_rank'] ?? 99, 99),
                'primary' => i($row['first_rank'] ?? 99, 99),
                'secondary' => i($row['second_rank'] ?? 99, 99),
            ];
        }
    }
    return $out;
}

function loadV5(): array
{
    $out = [];
    foreach (gzCsvRows(V5_FILE) as $row) {
        $date = (string)($row['race_date'] ?? '');
        if ($date < '2026-08-15' || $date > '2026-09-21') {
            continue;
        }
        $code = trim((string)($row['race_code'] ?? ''));
        $course = i($row['course'] ?? 0);
        if ($code !== '' && $course >= 1 && $course <= 6) {
            $out[$code][$course] = f($row[V5_COL] ?? 0.0);
        }
    }
    return $out;
}

function loadCourseMaps(PDO $pdo): array
{
    $sql = <<<'SQL'
SELECT re.race_code, re.lane_number, el.entry_course
FROM boat_race.race_entry re
JOIN boat_race.race_master rm ON rm.race_code = re.race_code
LEFT JOIN LATERAL (
    SELECT x.entry_course
    FROM boat_race.exhibition_live x
    WHERE x.race_code = re.race_code AND x.player_id = re.player_id
    LIMIT 1
) el ON TRUE
WHERE rm.race_date BETWEEN '2026-08-15'::date AND '2026-09-21'::date
ORDER BY re.race_code, re.lane_number
SQL;
    $raw = [];
    foreach ($pdo->query($sql) as $row) {
        $code = (string)$row['race_code'];
        $boat = i($row['lane_number']);
        $course = i($row['entry_course']);
        if ($boat >= 1 && $boat <= 6 && $course >= 1 && $course <= 6) {
            $raw[$code][$boat] = $course;
        }
    }
    $out = [];
    foreach ($raw as $code => $map) {
        ksort($map);
        $courses = array_values($map);
        sort($courses);
        if (array_keys($map) === range(1, 6) && $courses === range(1, 6)) {
            $out[$code] = $map;
        }
    }
    return $out;
}

function period(string $date): ?string
{
    if ($date >= '2026-08-15' && $date <= '2026-08-31') return 'reference_0815_0831';
    if ($date >= '2026-09-01' && $date <= '2026-09-10') return 'design_0901_0910';
    if ($date >= '2026-09-11' && $date <= '2026-09-21') return 'forward_0911_0921';
    return null;
}

function emptyStat(): array
{
    return ['n' => 0, 'lane1_win' => 0, 'ai_head_win' => 0, 'lane1_top3' => 0];
}

function add(array &$s, bool $lane1Win, bool $aiHeadWin, bool $lane1Top3): void
{
    $s['n']++;
    $s['lane1_win'] += $lane1Win ? 1 : 0;
    $s['ai_head_win'] += $aiHeadWin ? 1 : 0;
    $s['lane1_top3'] += $lane1Top3 ? 1 : 0;
}

function show(string $label, array $s): void
{
    printf(
        "%-34s N=%5d | ①勝 %6.2f%% | AI本命的中 %6.2f%% | ①3連対 %6.2f%% | ①変更差 %+6.2fpt\n",
        $label,
        $s['n'],
        pct($s['lane1_win'], $s['n']),
        pct($s['ai_head_win'], $s['n']),
        pct($s['lane1_top3'], $s['n']),
        pct($s['lane1_win'] - $s['ai_head_win'], $s['n'])
    );
}

$final = loadFinal();
$v5 = loadV5();
$maps = loadCourseMaps(getPDO());
$stats = [];
$skip = [];

foreach ($final as $code => $boats) {
    if (count($boats) !== 6) continue;
    $date = (string)($boats[1]['date'] ?? '');
    $pname = period($date);
    if ($pname === null) continue;
    if (!isset($maps[$code]) || !isset($v5[$code]) || count($v5[$code]) !== 6) {
        $skip[$pname] = ($skip[$pname] ?? 0) + 1;
        continue;
    }
    $prob = [];
    foreach (range(1, 6) as $boat) {
        $course = $maps[$code][$boat];
        $prob[$boat] = f($v5[$code][$course] ?? null, -1.0);
    }
    if (min($prob) < 0.0) continue;
    $order = range(1, 6);
    usort($order, static fn(int $a, int $b): int => ($prob[$b] <=> $prob[$a]) ?: ($a <=> $b));
    $head = $order[0];
    $headP = 100.0 * $prob[$head];
    $p1 = 100.0 * $prob[1];
    $rank1 = array_search(1, $order, true) + 1;
    $margin = $head === 1 ? $p1 - 100.0 * $prob[$order[1]] : $headP - $p1;
    $winner = 0;
    foreach (range(1, 6) as $boat) {
        if ($boats[$boat]['actual'] === 1) $winner = $boat;
    }
    if ($winner === 0) continue;

    $lane1Win = $winner === 1;
    $headWin = $winner === $head;
    $lane1Top3 = $boats[1]['actual'] <= 3;
    $primary = $boats[1]['primary'];
    $secondary = $boats[1]['secondary'];

    $conditions = [
        'ALL' => true,
        'HEAD_1_ALL' => $head === 1,
        'HEAD_1_P_LT40' => $head === 1 && $p1 < 40.0,
        'HEAD_1_P_LT50' => $head === 1 && $p1 < 50.0,
        'V2_DANGER_HIGH' => $head === 1 && ($p1 < 40.0 || $margin < 10.0),
        'V2_CAUTION_MID' => $head === 1 && $p1 >= 40.0 && $p1 < 50.0 && $margin >= 10.0,
        'V2_STRONG' => $head === 1 && $p1 >= 60.0 && $margin >= 15.0,
        'HEAD_1_MARGIN_LT5' => $head === 1 && $margin < 5.0,
        'HEAD_1_MARGIN_LT10' => $head === 1 && $margin < 10.0,
        'OLD_DANGER' => $head === 1 && $primary >= 4 && $secondary === 1,
        'OLD_DANGER_AND_P_LT50' => $head === 1 && $primary >= 4 && $secondary === 1 && $p1 < 50.0,
        'HEAD_NOT1_ALL' => $head !== 1,
        'OLD_RESCUE' => $head !== 1 && $primary === 1,
        'RESCUE_RANK2_GAP5' => $head !== 1 && $rank1 === 2 && $margin <= 5.0,
        'RESCUE_RANK2_GAP10' => $head !== 1 && $rank1 === 2 && $margin <= 10.0,
        'RESCUE_PRIMARY1_RANK2_GAP10' => $head !== 1 && $primary === 1 && $rank1 === 2 && $margin <= 10.0,
    ];
    foreach ($conditions as $name => $ok) {
        if (!$ok) continue;
        $stats[$pname][$name] ??= emptyStat();
        add($stats[$pname][$name], $lane1Win, $headWin, $lane1Top3);
    }
}

foreach (['reference_0815_0831', 'design_0901_0910', 'forward_0911_0921'] as $pname) {
    echo "\n=== {$pname} (course-map skip " . ($skip[$pname] ?? 0) . ") ===\n";
    foreach (array_keys($stats[$pname] ?? []) as $name) {
        show($name, $stats[$pname][$name]);
    }
}
