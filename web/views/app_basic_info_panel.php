<?php
// アプリの場特性関連は5タブにまとめ、その下で前向き実戦検証を記録してから既存の基本情報本体を表示する。
$stadiumCharacteristicsMode = 'app';
include __DIR__ . '/stadium_characteristics_tabs.php';
$forwardValidationMode = 'app';
include __DIR__ . '/forward_validation_panel.php';
include __DIR__ . '/app_basic_info_panel_core.php';
