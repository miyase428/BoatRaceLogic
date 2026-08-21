<?php
require_once __DIR__ . '/controllers/IndexController.php';
require_once __DIR__ . '/logic/AiTrioRateLogic.php';
require_once __DIR__ . '/logic/Head1SecondPlaceLogic.php';

$controller = new IndexController();
$viewData = $controller->handle();
extract($viewData);

// PC版base_win_rate_panel.phpと同じく、通常時に展示進入が変わった場合は
// 基本1着率を今回の予想進入へ合わせて表示する。
if (
    empty($simulation_active)
    && !empty($prediction_entry_changed)
    && !empty($prediction_course_by_boat)
    && is_array($prediction_course_by_boat)
    && !empty($race_code)
) {
    $baseWinRateLogicForApp = new BaseWinRateLogic();
    $base_win_rate_data = $baseWinRateLogicForApp->calculate(
        (string)$race_code,
        $prediction_course_by_boat
    );
}

$baseWinBoats = is_array($base_win_rate_data['boats'] ?? null)
    ? $base_win_rate_data['boats']
    : [];
$baseWinError = (string)($base_win_rate_data['error'] ?? '');

$correctedWinBoats = is_array($corrected_win_rate_data['boats'] ?? null)
    ? $corrected_win_rate_data['boats']
    : [];
$correctedWinStatus = (string)($corrected_win_rate_data['status'] ?? 'error');
$correctedWinError = (string)($corrected_win_rate_data['error'] ?? '');

// AI3連対率。計算ロジックはPC版と共通で、アプリ側では表示だけ変える。
$aiTrioCourseByBoat = [];
if (!empty($simulation_active) && is_array($prediction_course_by_boat ?? null)) {
    $aiTrioCourseByBoat = $prediction_course_by_boat;
} elseif (!empty($entry_map_ready) && is_array($entry_course_by_boat ?? null)) {
    $aiTrioCourseByBoat = $entry_course_by_boat;
}

$aiTrioLogic = new AiTrioRateLogic();
$aiTrioData = $aiTrioLogic->calculate(
    (string)($race_code ?? ''),
    is_array($results ?? null) ? $results : [],
    is_array($tenji_list ?? null) ? $tenji_list : [],
    $aiTrioCourseByBoat,
    !empty($simulation_active)
);
$aiTrioStatus = (string)($aiTrioData['status'] ?? 'error');
$aiTrioError = (string)($aiTrioData['error'] ?? '');
$aiTrioBoats = is_array($aiTrioData['boats'] ?? null) ? $aiTrioData['boats'] : [];

// 1号艇1着時の2着率。こちらもPC版と同じロジックを共用する。
$head1SecondLogic = new Head1SecondPlaceLogic();
$head1SecondData = $head1SecondLogic->calculate(
    (string)($race_code ?? ''),
    is_array($prediction_course_by_boat ?? null) ? $prediction_course_by_boat : []
);
$head1SecondStatus = (string)($head1SecondData['status'] ?? 'error');
$head1SecondError = (string)($head1SecondData['error'] ?? '');
$head1SecondBoats = is_array($head1SecondData['boats'] ?? null)
    ? $head1SecondData['boats']
    : [];

include __DIR__ . '/views/app_view.php';
