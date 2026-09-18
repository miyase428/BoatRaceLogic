<?php

declare(strict_types=1);

/**
 * 全レース前向き保存の当日カバー率を確認する。
 * Usage: php analysis/report_prediction_forward_coverage.php [YYYY-MM-DD]
 */

require_once __DIR__ . '/../common/db_connect.php';

date_default_timezone_set('Asia/Tokyo');
$date = trim((string)($argv[1] ?? date('Y-m-d')));
$dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if ($dt === false || $dt->format('Y-m-d') !== $date) {
    fwrite(STDERR, "Usage: php analysis/report_prediction_forward_coverage.php [YYYY-MM-DD]\n");
    exit(2);
}
$ymd = $dt->format('Ymd');
$deadlinePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . '/boatrace_official_deadlines/deadlines_' . $ymd . '.json';
$deadlineData = is_file($deadlinePath)
    ? json_decode((string)file_get_contents($deadlinePath), true)
    : [];
$deadlines = is_array($deadlineData['deadlines'] ?? null) ? $deadlineData['deadlines'] : [];

$pdo = getPDO();
$raceStmt = $pdo->prepare(
    "SELECT DISTINCT race_code FROM boat_race.race_entry WHERE race_code LIKE :prefix ORDER BY race_code"
);
$raceStmt->execute([':prefix' => $ymd . '%']);
$raceCodes = array_map('strval', $raceStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

$snapStmt = $pdo->prepare(<<<'SQL'
SELECT component, validation_mode, DISTINCT_RACES.race_code
FROM (
    SELECT DISTINCT component, validation_mode, race_code
    FROM boat_race.prediction_forward_snapshots
    WHERE race_date = :race_date::date AND stage = 'exhibition'
) DISTINCT_RACES
SQL);
$snapStmt->execute([':race_date' => $date]);
$saved = [];
$savedByMode = [];
foreach ($snapStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $saved[(string)$row['component']][(string)$row['race_code']] = true;
    $savedByMode[(string)$row['validation_mode']][(string)$row['component']][(string)$row['race_code']] = true;
}

$now = new DateTimeImmutable('now');
$pastDue = [];
foreach ($raceCodes as $raceCode) {
    $time = $deadlines[$raceCode] ?? null;
    if (!is_string($time)) continue;
    $deadline = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time);
    if ($deadline !== false && $deadline <= $now) $pastDue[] = $raceCode;
}

$out = [
    'date' => $date,
    'checked_at' => $now->format(DATE_ATOM),
    'scheduled_races' => count($raceCodes),
    'official_deadlines' => count($deadlines),
    'deadline_passed' => count($pastDue),
    'exhibition_saved' => count($saved['prediction'] ?? []),
    'course_signals_saved' => count($saved['course_signals'] ?? []),
    'hole_predictions_saved' => count($saved['hole_prediction'] ?? []),
    'strict' => [
        'predictions' => count($savedByMode['strict']['prediction'] ?? []),
        'course_signals' => count($savedByMode['strict']['course_signals'] ?? []),
        'hole_predictions' => count($savedByMode['strict']['hole_prediction'] ?? []),
    ],
    'late_replay' => [
        'predictions' => count($savedByMode['late_replay']['prediction'] ?? []),
        'course_signals' => count($savedByMode['late_replay']['course_signals'] ?? []),
        'hole_predictions' => count($savedByMode['late_replay']['hole_prediction'] ?? []),
    ],
    'passed_without_prediction' => array_values(array_filter(
        $pastDue,
        static fn(string $raceCode): bool => !isset($saved['prediction'][$raceCode])
    )),
    'passed_without_course_signals' => array_values(array_filter(
        $pastDue,
        static fn(string $raceCode): bool => !isset($saved['course_signals'][$raceCode])
    )),
];

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
