<?php

declare(strict_types=1);

/**
 * 自動前向き検証の集計をJSONで表示する。各レース・段階・部品は、最後に表示した版を採用。
 *
 * Usage:
 *   php analysis/report_prediction_forward_accuracy.php 2026-09-01 2026-09-30
 */

require_once __DIR__ . '/../common/db_connect.php';

$start = trim((string)($argv[1] ?? date('Y-m-01')));
$end = trim((string)($argv[2] ?? date('Y-m-d')));
foreach ([$start, $end] as $value) {
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if ($dt === false || $dt->format('Y-m-d') !== $value) {
        fwrite(STDERR, "Usage: php analysis/report_prediction_forward_accuracy.php START END\n");
        exit(2);
    }
}

$pdo = getPDO();
$stmt = $pdo->prepare(<<<'SQL'
WITH latest AS (
    SELECT *, ROW_NUMBER() OVER (
        PARTITION BY race_code, stage, component, validation_mode
        ORDER BY captured_at DESC, id DESC
    ) AS rn
    FROM boat_race.prediction_forward_snapshots
    WHERE race_date BETWEEN :start::date AND :end::date
      AND graded_at IS NOT NULL
)
SELECT validation_mode, stage, component, payload, grade
FROM latest
WHERE rn = 1
ORDER BY stage, component
SQL);
$stmt->execute([':start' => $start, ':end' => $end]);

$out = [
    'date_range' => [$start, $end],
    'definition' => '全レース成績=厳密な締切前保存(strict)+結果遮断済み夜間再現(late_replay)',
    'stages' => [],
    'validation_modes' => [],
];

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $mode = (string)($row['validation_mode'] ?? 'strict');
    $stage = (string)$row['stage'];
    $component = (string)$row['component'];
    $payload = json_decode((string)$row['payload'], true);
    $grade = json_decode((string)$row['grade'], true);
    if (!is_array($payload) || !is_array($grade)) {
        continue;
    }

    accumulateReportRow($out['stages'], $stage, $component, $payload, $grade);
    $out['validation_modes'][$mode] ??= ['stages' => []];
    accumulateReportRow($out['validation_modes'][$mode]['stages'], $stage, $component, $payload, $grade);
}

finishStages($out['stages']);
foreach ($out['validation_modes'] as &$modeReport) {
    finishStages($modeReport['stages']);
}
unset($modeReport);

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

function accumulateReportRow(array &$stages, string $stage, string $component, array $payload, array $grade): void
{
    if ($component === 'course_signals') {
        $stages[$stage]['course_signal_races'] = (int)($stages[$stage]['course_signal_races'] ?? 0) + 1;
        foreach ((array)($grade['signals'] ?? []) as $signal) {
            $type = (string)($signal['type'] ?? '不明');
            $bucket =& $stages[$stage]['course_signals'][$type];
            $bucket ??= ['n' => 0, 'first' => 0, 'top2' => 0, 'top3' => 0];
            $bucket['n']++;
            $bucket['first'] += !empty($signal['first']) ? 1 : 0;
            $bucket['top2'] += !empty($signal['top2']) ? 1 : 0;
            $bucket['top3'] += !empty($signal['top3']) ? 1 : 0;
            unset($bucket);
        }
    } elseif ($component === 'prediction') {
        $prediction =& $stages[$stage]['prediction'];
        $prediction ??= [
            'races' => 0,
            'honmei_head_first' => 0,
            'taikou_head_first' => 0,
            'honmei' => blankBetReport(),
            'taikou' => blankBetReport(),
            'combined' => blankBetReport(),
        ];
        $prediction['races']++;
        $prediction['honmei_head_first'] += !empty($grade['honmei_head_first']) ? 1 : 0;
        $prediction['taikou_head_first'] += !empty($grade['taikou_head_first']) ? 1 : 0;
        foreach (['honmei', 'taikou', 'combined'] as $name) {
            $bet = (array)($grade[$name] ?? []);
            $prediction[$name]['hits'] += !empty($bet['hit']) ? 1 : 0;
            $prediction[$name]['points'] += (int)($bet['points'] ?? 0);
            $prediction[$name]['investment'] += (int)($bet['investment'] ?? 0);
            $prediction[$name]['return'] += (int)($bet['return'] ?? 0);
        }
        unset($prediction);
    } elseif ($component === 'hole_prediction') {
        $hole =& $stages[$stage]['hole_prediction'];
        $hole ??= [
            'races' => 0,
            'a_head_first' => 0,
            'b_head_first' => 0,
            'A' => blankBetReport(),
            'B' => blankBetReport(),
            'combined' => blankBetReport(),
        ];
        $hole['races']++;
        $hole['a_head_first'] += !empty($grade['a_head_first']) ? 1 : 0;
        $hole['b_head_first'] += !empty($grade['b_head_first']) ? 1 : 0;
        foreach (['A', 'B', 'combined'] as $name) {
            $bet = (array)($grade[$name] ?? []);
            $hole[$name]['hits'] += !empty($bet['hit']) ? 1 : 0;
            $hole[$name]['points'] += (int)($bet['points'] ?? 0);
            $hole[$name]['investment'] += (int)($bet['investment'] ?? 0);
            $hole[$name]['return'] += (int)($bet['return'] ?? 0);
        }
        unset($hole);
    }
}

function finishStages(array &$stages): void
{
    foreach ($stages as &$stage) {
        foreach (array_keys((array)($stage['course_signals'] ?? [])) as $type) {
            finishRateBucket($stage['course_signals'][$type]);
        }
        if (isset($stage['prediction'])) {
            $n = (int)$stage['prediction']['races'];
            $stage['prediction']['honmei_head_first_rate'] = percentage($stage['prediction']['honmei_head_first'], $n);
            $stage['prediction']['taikou_head_first_rate'] = percentage($stage['prediction']['taikou_head_first'], $n);
            foreach (['honmei', 'taikou', 'combined'] as $name) {
                $bet =& $stage['prediction'][$name];
                $bet['hit_rate'] = percentage($bet['hits'], $n);
                $bet['avg_points'] = $n > 0 ? $bet['points'] / $n : 0.0;
                $bet['roi'] = percentage($bet['return'], $bet['investment']);
                unset($bet);
            }
        }
        if (isset($stage['hole_prediction'])) {
            $n = (int)$stage['hole_prediction']['races'];
            $stage['hole_prediction']['a_head_first_rate'] = percentage($stage['hole_prediction']['a_head_first'], $n);
            $stage['hole_prediction']['b_head_first_rate'] = percentage($stage['hole_prediction']['b_head_first'], $n);
            foreach (['A', 'B', 'combined'] as $name) {
                $bet =& $stage['hole_prediction'][$name];
                $bet['hit_rate'] = percentage($bet['hits'], $n);
                $bet['avg_points'] = $n > 0 ? $bet['points'] / $n : 0.0;
                $bet['roi'] = percentage($bet['return'], $bet['investment']);
                unset($bet);
            }
        }
    }
    unset($stage);
}

function blankBetReport(): array
{
    return ['hits' => 0, 'points' => 0, 'investment' => 0, 'return' => 0];
}

function percentage(int|float $num, int|float $den): float
{
    return $den > 0 ? 100.0 * $num / $den : 0.0;
}

function finishRateBucket(array &$bucket): void
{
    $n = (int)$bucket['n'];
    $bucket['first_rate'] = percentage($bucket['first'], $n);
    $bucket['top2_rate'] = percentage($bucket['top2'], $n);
    $bucket['top3_rate'] = percentage($bucket['top3'], $n);
}
