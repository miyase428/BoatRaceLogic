<?php
// 場特性関連は5タブにまとめ、その下で前向き実戦検証を記録してから既存1着率パネルを表示する。
$stadiumCharacteristicsMode = 'pc';
include __DIR__ . '/stadium_characteristics_tabs.php';
$forwardValidationMode = 'pc';
include __DIR__ . '/forward_validation_panel.php';
include __DIR__ . '/base_win_rate_panel_core.php';
?>
<link rel="stylesheet" href="/web/assets/css/pc_trifecta_tools.css?v=20260826c">
