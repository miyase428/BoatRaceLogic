<?php

require_once __DIR__ . '/../web/controllers/IndexController.php';
require_once __DIR__ . '/../web/logic/AiTrioRateLogic.php';
require_once __DIR__ . '/../web/logic/TrifectaProbabilityLogic.php';
require_once __DIR__ . '/../web/logic/SecondPlaceProbabilityLogic.php';
require_once __DIR__ . '/../web/logic/FinalSecondCandidateLogic.php';

/**
 * 全頭コースへ一般化した共通2着確率を、実レースの現行本命へ仮反映して確認する。
 * 本番Webは変更しない。
 *
 * Usage:
 *   php analysis/verify_final_second_candidate_all_head_logic.php YYYY-MM-DD PLACE R
 *
 * 例:
 *   php analysis/verify_final_second_candidate_all_head_logic.php 2026-09-01 OMR 12
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
$finalPredictions = is_array($view['final_predictions'] ?? null) ? $view['final_predictions'] : [];
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

if ((string)($trifectaData['status'] ?? '') !== 'ok') {
    fwrite(STDERR, '120通り計算失敗: ' . (string)($trifectaData['error'] ?? 'unknown') . PHP_EOL);
    exit(2);
}

$honmeiHead = (int)($view['honmei_head'] ?? 0);
$headCourse = (int)($outcomeCourseByBoat[$honmeiHead] ?? 0);
if ($honmeiHead < 1 || $honmeiHead > 6 || $headCourse < 1 || $headCourse > 6) {
    fwrite(STDERR, '本命頭または本命頭コースを特定できません' . PHP_EOL);
    exit(2);
}

$secondLogic = new SecondPlaceProbabilityLogic();
$secondData = $secondLogic->calculate($trifectaData, $headCourse);
if ((string)($secondData['status'] ?? '') !== 'ok') {
    fwrite(STDERR, '共通2着確率計算失敗: ' . (string)($secondData['error'] ?? 'unknown') . PHP_EOL);
    exit(2);
}

$currentSummary = [
    'honmei_head' => $honmeiHead,
    'honmei_aite_str' => (string)($view['honmei_aite_str'] ?? ''),
    'honmei_aite_kako' => (string)($view['honmei_aite_kako'] ?? ''),
    'honmei_third_kako' => (string)($view['honmei_third_kako'] ?? ''),
    'honmei_kai' => (string)($view['honmei_kai'] ?? ''),
];

$finalSecondLogic = new FinalSecondCandidateLogic();
$after = $finalSecondLogic->applyHonmei(
    $currentSummary,
    $finalPredictions,
    $secondData
);

$kiru = [];
foreach ($finalPredictions as $boatKey => $fp) {
    $boat = (int)($fp['boat'] ?? $boatKey);
    if ((int)($fp['kiru'] ?? 0) === 1) {
        $kiru[] = $boat;
    }
}
sort($kiru);

printf("%s\n", str_repeat('=', 104));
printf("共通2着確率 → 最終予想2着候補 全頭コース仮反映確認\n");
printf("%s\n", str_repeat('=', 104));
printf("race_code          : %s\n", $raceCode);
printf("本命頭             : %d号艇\n", $honmeiHead);
printf("本命頭の今回コース : %dC\n", $headCourse);
printf("切る艇             : %s\n", $kiru ? implode('・', $kiru) : '-');
printf("共通2着順位        : %s\n", implode(' > ', array_map('strval', $secondData['ranked_second_boats'] ?? [])));
printf("共通2着確率        :\n");

$ranked = is_array($secondData['ranked_second_boats'] ?? null)
    ? $secondData['ranked_second_boats']
    : [];
$probabilityByBoat = is_array($secondData['probability_by_boat'] ?? null)
    ? $secondData['probability_by_boat']
    : [];
foreach ($ranked as $boat) {
    $boat = (int)$boat;
    printf("  %d号艇 : %6.2f%%\n", $boat, (float)($probabilityByBoat[$boat] ?? 0.0) * 100.0);
}

printf("%s\n", str_repeat('-', 104));
printf("現在 2着候補表示   : %s\n", (string)($currentSummary['honmei_aite_str'] ?? ''));
printf("現在 買い目         : %s\n", (string)($currentSummary['honmei_kai'] ?? ''));
printf("仮反映 2着候補表示 : %s\n", (string)($after['honmei_aite_str'] ?? ''));
printf("仮反映 買い目       : %s\n", (string)($after['honmei_kai'] ?? ''));
printf("3着候補             : %s\n", (string)($after['honmei_third_kako'] ?? $currentSummary['honmei_third_kako'] ?? ''));
printf("適用                : %s\n", !empty($after['common_second_applied']) ? 'YES' : 'NO');
printf("理由                : %s\n", (string)($after['common_second_reason'] ?? ''));
printf("%s\n", str_repeat('=', 104));

exit(!empty($after['common_second_applied']) ? 0 : 1);
