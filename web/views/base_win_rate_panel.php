<?php
// 場特性関連は5タブにまとめ、その下で前向き実戦検証を記録してから既存1着率パネルを表示する。
$stadiumCharacteristicsMode = 'pc';
include __DIR__ . '/stadium_characteristics_tabs.php';
$forwardValidationMode = 'pc';
include __DIR__ . '/forward_validation_panel.php';
include __DIR__ . '/base_win_rate_panel_core.php';

// 選手SUM特性はここで生成し、PCではDOMContentLoaded後に
// 「展示サム理論（レース適用値）」の直下へ表示だけ移動する。
$playerSamMode = 'pc';
include __DIR__ . '/player_sam_panel.php';
include __DIR__ . '/player_sam_cross_panel.php';
include __DIR__ . '/player_sam_ui_enhancements.php';

// 選択場の直近5開催日（最大60R）の現行Web予想×実結果。
// 重い過去再計算は大タブを開いた時だけAPI経由で実行する。
include __DIR__ . '/recent_prediction_history_panel.php';
?>
<link rel="stylesheet" href="/web/assets/css/pc_trifecta_tools.css?v=20260826c">
<script src="/web/assets/js/pc_trifecta_cleanup.js?v=20260901a"></script>

<!-- PC Webは大分類「基本情報 / メイン情報 / 2連単 / 120通り / 買い目 / 直近60R」で切り替える。 -->
<link rel="stylesheet" href="/web/assets/css/pc_main_tabs.css?v=20260901a">
<script src="/web/assets/js/pc_main_tabs.js?v=20260826c"></script>
<script src="/web/assets/js/pc_exacta_tab.js?v=20260901a"></script>
<script src="/web/assets/js/pc_bet_simulator_v2.js?v=20260901b"></script>