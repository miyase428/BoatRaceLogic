<?php
// アプリの場特性関連はPC Webと同じ役割で表示し、その下で前向き実戦検証を記録してから既存の基本情報本体を表示する。
$stadiumCharacteristicsMode = 'app';
include __DIR__ . '/stadium_characteristics_tabs.php';
$forwardValidationMode = 'app';
include __DIR__ . '/forward_validation_panel.php';
include __DIR__ . '/app_basic_info_panel_core.php';

// 選手SUM特性。表示専用で予想ロジックには未接続。
$playerSamMode = 'app';
include __DIR__ . '/player_sam_panel.php';
include __DIR__ . '/player_sam_cross_panel.php';
include __DIR__ . '/player_sam_ui_enhancements.php';

// PC Webと同じAI展開予想。アプリではapp_web_parity.jsが「連対率・展開」へ移動する。
include __DIR__ . '/ai_tenkai_trial_panel.php';
?>
<style>
/* app_web_parity.js の旧560px指定より強いセレクタで、iPhone幅に4列すべて収める。 */
body #ai-tenkai-trial-panel table {
    min-width: 0 !important;
    width: 100% !important;
    table-layout: fixed !important;
}
body #ai-tenkai-trial-panel th:first-child,
body #ai-tenkai-trial-panel td:first-child {
    width: 40% !important;
}
body #ai-tenkai-trial-panel th:nth-child(2),
body #ai-tenkai-trial-panel td:nth-child(2) {
    width: 20% !important;
}
body #ai-tenkai-trial-panel th:nth-child(3),
body #ai-tenkai-trial-panel td:nth-child(3) {
    width: 20% !important;
}
body #ai-tenkai-trial-panel th:nth-child(4),
body #ai-tenkai-trial-panel td:nth-child(4) {
    width: 20% !important;
}
body #ai-tenkai-trial-panel th,
body #ai-tenkai-trial-panel td {
    padding-left: 2px !important;
    padding-right: 2px !important;
}
body #ai-tenkai-trial-panel td:first-child {
    font-size: 10px !important;
}
</style>
