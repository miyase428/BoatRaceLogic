<?php

require_once __DIR__ . '/../web/controllers/IndexController.php';
require_once __DIR__ . '/../web/logic/AiTrioRateLogic.php';
require_once __DIR__ . '/../web/logic/TrifectaProbabilityLogic.php';
require_once __DIR__ . '/../web/logic/SecondPlaceProbabilityLogic.php';
require_once __DIR__ . '/../web/logic/FinalSecondCandidateLogic.php';

/**
 * 共通2着確率を最終予想の本命2着候補へ反映した時の実レース確認。
 * 本番コードは変更せず、現在summaryと仮反映後summaryを並べて確認する。
 *
 * Usage:
 *   php analysis/verify_final_second_candidate_common_logic.php YYYY-MM-DD PLACE R
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
$finalPredictions = is_array($view['final_predictions'] ?? null) ? $view['final_predictions'] : [];
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

$secondLogic = new SecondPlaceProbabilityLogic();
$secondData = $secondLogic->calculate($trifectaData, 1);

$currentSummary = [
    'honmei_head' => (int)($view['honmei_head'] ?? 0),
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

printf("%s\n", str_repeat('=', 96));
printf("共通2着確率 → 最終予想2着候補 仮反映確認\n");
printf("%s\n", str_repeat('=', 96));
printf("race_code          : %s\n", $raceCode);
printf("本命頭             : %d\n", (int)($currentSummary['honmei_head'] ?? 0));
printf("1C艇               : %d\n", (int)($secondData['head_boat'] ?? 0));
printf("切る艇             : %s\n", $kiru ? implode('・', $kiru) : '-');
printf("共通2着順位        : %s\n", implode(' > ', array_map('strval', $secondData['ranked_second_boats'] ?? [])));
printf("共通2着確率        :\n");
foreach (($secondData['ranked_second_boats'] ?? []) as $boat) {
    $p = (float)($secondData['probability_by_boat'][$boat] ?? 0.0);
    printf("  %d号艇 : %6.2f%%\n", (int)$boat, $p * 100.0);
}
printf("%s\n", str_repeat('-', 96));
printf("現在 2着候補表示   : %s\n", (string)($currentSummary['honmei_aite_str'] ?? ''));
printf("現在 買い目         : %s\n", (string)($currentSummary['honmei_kai'] ?? ''));
printf("仮反映 2着候補表示 : %s\n", (string)($after['honmei_aite_str'] ?? ''));
printf("仮反映 買い目       : %s\n", (string)($after['honmei_kai'] ?? ''));
printf("3着候補             : %s\n", (string)($after['honmei_third_kako'] ?? ''));
printf("適用                : %s\n", !empty($after['common_second_applied']) ? 'YES' : 'NO');
printf("理由                : %s\n", (string)($after['common_second_reason'] ?? ''));
printf("%s\n", str_repeat('=', 96));

if (($secondData['status'] ?? '') !== 'ok') {
    fwrite(STDERR, '共通2着確率計算失敗: ' . ($secondData['error'] ?? 'unknown') . PHP_EOL);
    exit(2);
}

exit(0);
