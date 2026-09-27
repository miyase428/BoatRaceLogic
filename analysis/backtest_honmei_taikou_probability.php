<?php

declare(strict_types=1);

/**
 * 展示情報が残る過去レースへ、現在の本命・対抗ロジックと120通り確率を再適用する。
 *
 * 評価するのは「本命＋対抗（重複を1点に統合）」の2点だけ。
 * - 買い目の確率合計
 * - 実3連単に対する的中率
 *
 * 回収率・オッズは一切使わない。結果確定後の情報が対象レースの計算へ
 * 混ざらないよう、BOATRACE_LATE_REPLAY=1 で対象日以降の集計を遮断する。
 *
 * Usage:
 *   php analysis/backtest_honmei_taikou_probability.php 2025-09-23 2026-09-22 --place=GMG
 *   php analysis/backtest_honmei_taikou_probability.php 2025-09-23 2026-09-22 --place=GMG --limit=60 --resume --max-new=6
 *
 * 結果JSON: analysis/output/honmei_taikou_probability_backtest.json
 */

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../web/controllers/IndexController.php';
require_once __DIR__ . '/../web/logic/Lane1EscapeFollowerLogic.php';
require_once __DIR__ . '/../web/logic/AiTrioRateLogic.php';
require_once __DIR__ . '/../web/logic/TrifectaProbabilityLogic.php';

date_default_timezone_set('Asia/Tokyo');
putenv('BOATRACE_LATE_REPLAY=1');

[$from, $to, $limit, $placeFilter, $resume, $maxNew] = parseArguments($argv);
$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$targets = loadTargets($pdo, $from, $to, $limit, $placeFilter);
$outputPath = outputPath($placeFilter);
$rows = [];
$errors = [];
if ($resume && is_file($outputPath)) {
    $saved = json_decode((string)file_get_contents($outputPath), true);
    if (is_array($saved)
        && (($saved['range']['from'] ?? '') === $from)
        && (($saved['range']['to'] ?? '') === $to)
        && (($saved['place_filter'] ?? '') === $placeFilter)) {
        $rows = array_values(array_filter((array)($saved['rows'] ?? []), 'is_array'));
        $errors = array_values(array_filter((array)($saved['error_races'] ?? []), 'is_array'));
    }
}
$finished = [];
foreach ($rows as $row) $finished[(string)($row['race_code'] ?? '')] = true;
$targets = array_values(array_filter($targets, static fn(array $target): bool => !isset($finished[(string)$target['race_code']])));
if ($maxNew !== null) $targets = array_slice($targets, 0, $maxNew);
printf(
    "今回の再現対象: %dR (%s ～ %s%s)\n",
    count($targets),
    $from,
    $to,
    $placeFilter !== '' ? " / {$placeFilter}" : ''
);

$follower = new Lane1EscapeFollowerLogic();
$aiTrioLogic = new AiTrioRateLogic();
$trifectaLogic = new TrifectaProbabilityLogic();

foreach ($targets as $index => $target) {
    $raceCode = (string)$target['race_code'];
    $raceDate = (string)$target['race_date'];
    try {
        $_GET = [
            'date' => $raceDate,
            'place' => substr($raceCode, 8, 3),
            'race' => (string)(int)substr($raceCode, 11, 2),
        ];
        $_POST = [];

        $view = (new IndexController())->handle();
        $view = $follower->apply(
            $view,
            (array)($view['final_predictions'] ?? []),
            (string)($view['place_names'][$view['selected_place'] ?? ''] ?? ''),
            (array)($view['entry_course_by_boat'] ?? []),
            !empty($view['entry_map_ready']) && empty($view['simulation_active'])
        );

        $courseByBoat = (array)($view['entry_course_by_boat'] ?? []);
        $aiTrio = $aiTrioLogic->calculate(
            $raceCode,
            (array)($view['results'] ?? []),
            (array)($view['tenji_list'] ?? []),
            $courseByBoat,
            false
        );
        if (($aiTrio['status'] ?? '') !== 'ok') {
            throw new RuntimeException((string)($aiTrio['error'] ?? 'AI3連対率を再現できません'));
        }

        $trifecta = $trifectaLogic->calculate(
            $raceCode,
            (array)(($view['corrected_win_rate_data'] ?? [])['boats'] ?? []),
            (array)($aiTrio['boats'] ?? []),
            $courseByBoat
        );
        if (($trifecta['status'] ?? '') !== 'ok') {
            throw new RuntimeException((string)($trifecta['error'] ?? '120通り確率を再現できません'));
        }

        $honmeiBets = expandTrifecta((string)($view['honmei_kai'] ?? ''));
        $taikouBets = expandTrifecta((string)($view['taikou_kai'] ?? ''));
        $combinedBets = array_values(array_unique(array_merge($honmeiBets, $taikouBets)));
        if ($honmeiBets === [] || $taikouBets === [] || $combinedBets === []) {
            throw new RuntimeException('本命・対抗の買い目を再現できません');
        }

        $probabilityByBet = [];
        foreach ((array)($trifecta['rows'] ?? []) as $trifectaRow) {
            $boats = array_map('intval', (array)($trifectaRow['boats'] ?? []));
            if (count($boats) === 3) {
                $probabilityByBet[implode('-', $boats)] = (float)($trifectaRow['probability'] ?? 0.0);
            }
        }
        $actual = (string)$target['actual_result'];
        $rows[] = [
            'race_code' => $raceCode,
            'race_date' => $raceDate,
            'place_code' => substr($raceCode, 8, 3),
            'race_number' => (int)substr($raceCode, 11, 2),
            'honmei_kai' => (string)($view['honmei_kai'] ?? ''),
            'taikou_kai' => (string)($view['taikou_kai'] ?? ''),
            'actual_result' => $actual,
            'selection' => selectionMetric($combinedBets, $probabilityByBet, $actual),
        ];
    } catch (Throwable $e) {
        $errors[] = ['race_code' => $raceCode, 'error' => $e->getMessage()];
    }

    printf("[%d/%d] %s %s\n", $index + 1, count($targets), $raceCode, isset($errors[count($errors) - 1]) && $errors[count($errors) - 1]['race_code'] === $raceCode ? 'SKIP' : 'OK');
}

usort($rows, static fn(array $a, array $b): int => [$a['race_date'], $a['race_code']] <=> [$b['race_date'], $b['race_code']]);
$report = buildReport($from, $to, $placeFilter, $rows, $errors);
printReport($report);

if (!is_dir(dirname($outputPath))) {
    mkdir(dirname($outputPath), 0775, true);
}
file_put_contents(
    $outputPath,
    json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL,
    LOCK_EX
);
echo "\nJSON保存: {$outputPath}\n";

/** @return array{0:string,1:string,2:?int,3:string,4:bool,5:?int} */
function parseArguments(array $argv): array
{
    $from = trim((string)($argv[1] ?? ''));
    $to = trim((string)($argv[2] ?? ''));
    $limit = null;
    $place = '';
    $resume = false;
    $maxNew = null;
    foreach (array_slice($argv, 3) as $arg) {
        if (preg_match('/^--limit=(\d+)$/', (string)$arg, $m)) {
            $limit = max(1, (int)$m[1]);
        }
        if (preg_match('/^--place=([A-Za-z0-9]{3})$/', (string)$arg, $m)) {
            $place = strtoupper($m[1]);
        }
        if ($arg === '--resume') $resume = true;
        if (preg_match('/^--max-new=(\d+)$/', (string)$arg, $m)) $maxNew = max(1, (int)$m[1]);
    }
    foreach ([$from, $to] as $date) {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            fwrite(STDERR, "Usage: php analysis/backtest_honmei_taikou_probability.php YYYY-MM-DD YYYY-MM-DD [--place=GMG] [--limit=N] [--resume] [--max-new=N]\n");
            exit(2);
        }
    }
    if ($from > $to) {
        fwrite(STDERR, "開始日は終了日以前にしてください。\n");
        exit(2);
    }
    return [$from, $to, $limit, $place, $resume, $maxNew];
}

/** @return list<array{race_code:string,race_date:string,actual_result:string}> */
function loadTargets(PDO $pdo, string $from, string $to, ?int $limit, string $placeFilter): array
{
    $sql = <<<'SQL'
SELECT
    rm.race_code,
    rm.race_date::text AS race_date,
    CONCAT(
        MAX(re.lane_number) FILTER (WHERE rrd.rank = '1'), '-',
        MAX(re.lane_number) FILTER (WHERE rrd.rank = '2'), '-',
        MAX(re.lane_number) FILTER (WHERE rrd.rank = '3')
    ) AS actual_result
FROM boat_race.race_master rm
JOIN boat_race.race_entry re
  ON re.race_code = rm.race_code
JOIN boat_race.exhibition_live el
  ON el.race_code = re.race_code
 AND el.player_id = re.player_id
JOIN boat_race.race_result_detail rrd
  ON rrd.race_code = re.race_code
 AND rrd.player_id = re.player_id
WHERE rm.race_date BETWEEN :from::date AND :to::date
  AND (:place_code = '' OR SUBSTRING(rm.race_code FROM 9 FOR 3) = :place_code)
GROUP BY rm.race_code, rm.race_date
HAVING COUNT(DISTINCT re.lane_number) = 6
   AND COUNT(DISTINCT el.entry_course) = 6
   AND COUNT(*) FILTER (WHERE el.exhibition_time IS NOT NULL) = 6
   AND COUNT(*) FILTER (WHERE el.start_timing IS NOT NULL) = 6
   AND COUNT(*) FILTER (WHERE el.lap_time IS NOT NULL) = 6
   AND COUNT(*) FILTER (WHERE el.around_time IS NOT NULL) = 6
   AND (
        SUBSTRING(rm.race_code FROM 9 FOR 3) IN ('AMG', 'TKY', 'SME')
        OR COUNT(*) FILTER (WHERE el.straight_time IS NOT NULL) = 6
   )
   AND COUNT(*) FILTER (WHERE rrd.rank IN ('1', '2', '3')) = 3
ORDER BY rm.race_date, rm.race_code
SQL;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':from' => $from, ':to' => $to, ':place_code' => $placeFilter]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($limit !== null && count($rows) > $limit) {
        $rows = array_slice($rows, -$limit);
    }
    return array_values($rows);
}

/** @return list<string> */
function expandTrifecta(string $formation): array
{
    $parts = array_map('trim', explode('-', trim($formation)));
    if (count($parts) !== 3) return [];
    $sets = [];
    foreach ($parts as $part) {
        $digits = array_values(array_unique(array_map('intval', str_split(preg_replace('/[^1-6]/', '', $part)))));
        if ($digits === []) return [];
        $sets[] = $digits;
    }
    $bets = [];
    foreach ($sets[0] as $a) foreach ($sets[1] as $b) foreach ($sets[2] as $c) {
        if ($a !== $b && $a !== $c && $b !== $c) $bets[] = "{$a}-{$b}-{$c}";
    }
    return array_values(array_unique($bets));
}

function blankStats(): array
{
    return ['races' => 0, 'hits' => 0, 'probability_total' => 0.0, 'points_total' => 0];
}

function addStats(array &$stats, array $metric): void
{
    $stats['races']++;
    $stats['hits'] += !empty($metric['hit']) ? 1 : 0;
    $stats['probability_total'] += (float)$metric['probability_sum'];
    $stats['points_total'] += (int)$metric['points'];
}

function finishStats(array $stats): array
{
    $n = (int)$stats['races'];
    $avgP = $n > 0 ? $stats['probability_total'] / $n : 0.0;
    $hitRate = $n > 0 ? $stats['hits'] / $n : 0.0;
    return $stats + [
        'average_probability_sum' => $avgP,
        'actual_hit_rate' => $hitRate,
        'calibration_gap' => $hitRate - $avgP,
        'average_points' => $n > 0 ? $stats['points_total'] / $n : 0.0,
    ];
}

function probabilityBand(float $p): string
{
    if ($p < 0.20) return 'P < 20%';
    if ($p < 0.30) return '20% ≤ P < 30%';
    if ($p < 0.40) return '30% ≤ P < 40%';
    return 'P ≥ 40%';
}

function summarize(array $rows): array
{
    $all = blankStats();
    $bands = [];
    foreach ($rows as $row) {
        $metric = $row['selection'] ?? null;
        if (!is_array($metric) || (int)($metric['points'] ?? 0) <= 0) continue;
        addStats($all, $metric);
        $band = probabilityBand((float)$metric['probability_sum']);
        $bands[$band] ??= blankStats();
        addStats($bands[$band], $metric);
    }
    foreach ($bands as $label => $stats) $bands[$label] = finishStats($stats);
    return ['overall' => finishStats($all), 'probability_bands' => $bands];
}

/** @return array{points:int,probability_sum:float,hit:bool} */
function selectionMetric(array $bets, array $probabilityByBet, string $actual): array
{
    $probabilitySum = 0.0;
    foreach ($bets as $bet) $probabilitySum += (float)($probabilityByBet[$bet] ?? 0.0);
    return [
        'points' => count($bets),
        'probability_sum' => $probabilitySum,
        'hit' => in_array($actual, $bets, true),
    ];
}

function buildReport(string $from, string $to, string $placeFilter, array $rows, array $errors): array
{
    $periods = buildRollingPeriods($rows, $to, true);
    $months = [];
    foreach ($rows as $row) $months[substr((string)$row['race_date'], 0, 7)][] = $row;
    foreach ($months as $month => $monthRows) $periods[$month] = $monthRows;

    $summaries = [];
    foreach ($periods as $label => $periodRows) $summaries[$label] = summarize($periodRows);

    // 場別はレース数ベースの直近60/180Rに加え、12/24か月も同じ定義で比較する。
    // 月ごとまで展開すると24場分で読めなくなるため、月次は全場横断だけにする。
    $stadiumRows = [];
    foreach ($rows as $row) {
        $place = (string)($row['place_code'] ?? '');
        if (preg_match('/^[A-Z0-9]{3}$/', $place) === 1) $stadiumRows[$place][] = $row;
    }
    ksort($stadiumRows);
    $stadiums = [];
    foreach ($stadiumRows as $place => $placeRows) {
        $stadiums[$place] = [];
        foreach (buildRollingPeriods($placeRows, $to, false) as $label => $periodRows) {
            $stadiums[$place][$label] = summarize($periodRows);
        }
    }

    return [
        'generated_at' => date('c'),
        'method' => [
            'logic' => 'current_honmei_taikou_and_trifecta_probability',
            'validation_mode' => 'late_replay_before_target_date',
            'odds_used' => false,
            'note' => '現在のロジックを過去展示へ再適用した再現検証であり、当時の保存予想ではありません。',
        ],
        'range' => ['from' => $from, 'to' => $to],
        'place_filter' => $placeFilter,
        'evaluated_races' => count($rows),
        'error_races' => $errors,
        'periods' => $summaries,
        'stadiums' => $stadiums,
        'rows' => $rows,
    ];
}

function outputPath(string $placeFilter): string
{
    $suffix = $placeFilter !== '' ? '_' . strtolower($placeFilter) : '';
    return __DIR__ . '/output/honmei_taikou_probability_backtest' . $suffix . '.json';
}

/** @return array<string,list<array>> */
function buildRollingPeriods(array $rows, string $to, bool $includeAll): array
{
    $periods = $includeAll ? ['指定全体' => $rows] : ['場別全体' => $rows];
    if ($rows === []) return $periods;

    $periods['直近60R'] = array_slice($rows, -60);
    $periods['直近180R'] = array_slice($rows, -180);
    $end = new DateTimeImmutable($to);
    foreach ([12, 24] as $months) {
        $start = $end->modify('-' . ($months - 1) . ' months')->modify('first day of this month');
        $periods['直近' . $months . 'カ月'] = array_values(array_filter(
            $rows,
            static fn(array $row): bool => (string)$row['race_date'] >= $start->format('Y-m-d')
        ));
    }
    return $periods;
}

function printReport(array $report): void
{
    echo "\n本命＋対抗 3連単：確率合計と的中率\n";
    echo "（オッズ・回収率は未使用 / 本命＋対抗の重複除外買い目だけを集計）\n";
    foreach ((array)$report['periods'] as $label => $summary) {
        printSummary((string)$label, (array)$summary, '');
    }
    echo "\n場別（確率合計と実的中率）\n";
    foreach ((array)($report['stadiums'] ?? []) as $place => $periods) {
        echo "\n{$place}\n";
        foreach ((array)$periods as $label => $summary) {
            printSummary((string)$label, (array)$summary, '  ', false);
        }
    }
    if (($report['error_races'] ?? []) !== []) printf("\n再現できなかったレース: %dR\n", count($report['error_races']));
}

function printSummary(string $label, array $summary, string $indent, bool $withBands = true): void
{
    $overall = (array)($summary['overall'] ?? []);
    printf(
        "%s%-12s N=%3d / 平均確率合計=%5.1f%% / 実的中率=%5.1f%% / 差=%+5.1fpt / 平均点数=%.1f\n",
        $indent,
        $label,
        (int)($overall['races'] ?? 0),
        (float)($overall['average_probability_sum'] ?? 0) * 100,
        (float)($overall['actual_hit_rate'] ?? 0) * 100,
        (float)($overall['calibration_gap'] ?? 0) * 100,
        (float)($overall['average_points'] ?? 0)
    );
    if (!$withBands) return;
    foreach ((array)($summary['probability_bands'] ?? []) as $band => $stats) {
        printf(
            "%s  %-18s N=%3d / 平均P=%5.1f%% / 的中=%5.1f%% / 差=%+5.1fpt\n",
            $indent,
            $band,
            (int)($stats['races'] ?? 0),
            (float)($stats['average_probability_sum'] ?? 0) * 100,
            (float)($stats['actual_hit_rate'] ?? 0) * 100,
            (float)($stats['calibration_gap'] ?? 0) * 100
        );
    }
}
