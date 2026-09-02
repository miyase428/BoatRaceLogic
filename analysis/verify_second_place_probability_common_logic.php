<?php

require_once __DIR__ . '/../web/controllers/IndexController.php';
require_once __DIR__ . '/../web/logic/AiTrioRateLogic.php';
require_once __DIR__ . '/../web/logic/TrifectaProbabilityLogic.php';
require_once __DIR__ . '/../web/logic/SecondPlaceProbabilityLogic.php';

/**
 * 既存「イン1着時 2連単」の手計算集約と、
 * SecondPlaceProbabilityLogic の出力が完全一致するかを実レースで確認する。
 *
 * Usage:
 *   php analysis/verify_second_place_probability_common_logic.php YYYY-MM-DD PLACE R
 *
 * 例:
 *   php analysis/verify_second_place_probability_common_logic.php 2026-09-02 OMR 12
 */

$date = $argv[1] ?? date('Y-m-d');
$place = strtoupper((string)($argv[2] ?? 'OMR'));
$race = (int)($argv[3] ?? 12);

$_GET = [
    'date' => $date,
    'place' => $place,
    'race' => (string)$race,
];
$_POST = [];

$controller = new IndexController();
$view = $controller->handle();

$raceCode = (string)($view['race_code'] ?? '');
$results = is_array($view['results'] ?? null) ? $view['results'] : [];
$tenjiList = is_array($view['tenji_list'] ?? null) ? $view['tenji_list'] : [];
$correctedWinBoats = is_array($view['corrected_win_rate_data']['boats'] ?? null)
    ? $view['corrected_win_rate_data']['boats']
    : [];

$courseByBoat = [];
if (!empty($view['simulation_active']) && is_array($view['prediction_course_by_boat'] ?? null)) {
    $courseByBoat = $view['prediction_course_by_boat'];
} elseif (!empty($view['entry_map_ready']) && is_array($view['entry_course_by_boat'] ?? null)) {
    $courseByBoat = $view['entry_course_by_boat'];
} elseif (is_array($view['prediction_course_by_boat'] ?? null)) {
    $courseByBoat = $view['prediction_course_by_boat'];
}

$aiTrioLogic = new AiTrioRateLogic();
$aiTrioData = $aiTrioLogic->calculate(
    $raceCode,
    $results,
    $tenjiList,
    $courseByBoat,
    !empty($view['simulation_active'])
);
$aiTrioBoats = is_array($aiTrioData['boats'] ?? null) ? $aiTrioData['boats'] : [];

$outcomeCourseByBoat = [];
foreach ($aiTrioBoats as $boatKey => $row) {
    $boat = (int)($row['lane'] ?? $boatKey);
    $course = (int)($row['course'] ?? 0);
    if ($boat >= 1 && $boat <= 6 && $course >= 1 && $course <= 6) {
        $outcomeCourseByBoat[$boat] = $course;
    }
}
if (count($outcomeCourseByBoat) !== 6) {
    $outcomeCourseByBoat = $courseByBoat;
}

$trifectaLogic = new TrifectaProbabilityLogic();
$trifectaData = $trifectaLogic->calculate(
    $raceCode,
    $correctedWinBoats,
    $aiTrioBoats,
    $outcomeCourseByBoat
);

$commonLogic = new SecondPlaceProbabilityLogic();
$common = $commonLogic->calculate($trifectaData, 1);

if (($common['status'] ?? '') !== 'ok') {
    fwrite(STDERR, '共通ロジック計算失敗: ' . ($common['error'] ?? 'unknown') . PHP_EOL);
    exit(2);
}

$rows120 = is_array($trifectaData['rows'] ?? null) ? $trifectaData['rows'] : [];
$boatByCourse = is_array($trifectaData['boat_by_course'] ?? null)
    ? $trifectaData['boat_by_course']
    : [];

$legacyBase = array_fill(2, 5, 0.0);
$legacyAi = array_fill(2, 5, 0.0);
$baseMass = 0.0;
$aiMass = 0.0;

foreach ($rows120 as $row) {
    $courses = is_array($row['courses'] ?? null) ? $row['courses'] : [];
    if (count($courses) !== 3 || (int)$courses[0] !== 1) {
        continue;
    }
    $secondCourse = (int)$courses[1];
    if ($secondCourse < 2 || $secondCourse > 6) {
        continue;
    }
    $baseP = (float)($row['base_probability'] ?? 0.0);
    $aiP = (float)($row['probability'] ?? 0.0);
    $legacyBase[$secondCourse] += $baseP;
    $legacyAi[$secondCourse] += $aiP;
    $baseMass += $baseP;
    $aiMass += $aiP;
}

$legacyRows = [];
for ($course = 2; $course <= 6; $course++) {
    $legacyRows[$course] = [
        'boat' => (int)($boatByCourse[$course] ?? $course),
        'base' => $baseMass > 0.0 ? $legacyBase[$course] / $baseMass : 0.0,
        'ai' => $aiMass > 0.0 ? $legacyAi[$course] / $aiMass : 0.0,
    ];
}

$commonByCourse = [];
foreach (($common['rows'] ?? []) as $row) {
    $commonByCourse[(int)$row['second_course']] = $row;
}

$maxBaseDiff = 0.0;
$maxAiDiff = 0.0;
$allMatch = true;

printf("%s\n", str_repeat('=', 96));
printf("共通2着確率ロジック 一致確認\n");
printf("%s\n", str_repeat('=', 96));
printf("race_code : %s\n", $raceCode);
printf("頭条件    : 1C頭（③ AI_FINAL）\n");
printf("%-8s %-8s %-14s %-14s %-14s %-14s\n", '2着C', '艇', '旧AI', '共通AI', 'AI差', 'base差');
printf("%s\n", str_repeat('-', 96));

for ($course = 2; $course <= 6; $course++) {
    $legacy = $legacyRows[$course];
    $new = $commonByCourse[$course] ?? [];
    $newBoat = (int)($new['second_boat'] ?? 0);
    $legacyAiP = (float)$legacy['ai'];
    $newAiP = (float)($new['ai'] ?? 0.0);
    $legacyBaseP = (float)$legacy['base'];
    $newBaseP = (float)($new['base'] ?? 0.0);
    $aiDiff = abs($legacyAiP - $newAiP);
    $baseDiff = abs($legacyBaseP - $newBaseP);
    $maxAiDiff = max($maxAiDiff, $aiDiff);
    $maxBaseDiff = max($maxBaseDiff, $baseDiff);
    if ($newBoat !== (int)$legacy['boat'] || $aiDiff > 1e-12 || $baseDiff > 1e-12) {
        $allMatch = false;
    }

    printf(
        "%-8s %-8d %12.8f%% %12.8f%% %12.3e %12.3e\n",
        $course . 'C',
        (int)$legacy['boat'],
        $legacyAiP * 100.0,
        $newAiP * 100.0,
        $aiDiff,
        $baseDiff
    );
}

printf("%s\n", str_repeat('-', 96));
printf("旧AI合計   : %.12f%%\n", array_sum(array_column($legacyRows, 'ai')) * 100.0);
printf("共通AI合計 : %.12f%%\n", array_sum(array_map(
    static fn(array $r): float => (float)($r['ai'] ?? 0.0),
    $common['rows'] ?? []
)) * 100.0);
printf("最大AI差   : %.3e\n", $maxAiDiff);
printf("最大base差 : %.3e\n", $maxBaseDiff);
printf("順位        : %s\n", implode(' > ', array_map(
    static fn($b): string => (string)$b,
    $common['ranked_second_boats'] ?? []
)));
printf("判定        : %s\n", $allMatch ? '完全一致' : '不一致あり');
printf("%s\n", str_repeat('=', 96));

exit($allMatch ? 0 : 1);
