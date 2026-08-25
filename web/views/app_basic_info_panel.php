<?php
// アプリの基本情報タブ先頭に、場別相性 → 場特性 → イン敗戦時特性 → 外枠到達率 → R別予想相性の順で表示する。
// 既存の基本情報本体はcoreへ分離し、表示・計算内容は変更しない。
$stadiumAffinityMode = 'app';
include __DIR__ . '/stadium_affinity_panel.php';
$stadiumPracticalMode = 'app';
include __DIR__ . '/stadium_practical_characteristics_panel.php';
$stadiumNonLane1Mode = 'app';
include __DIR__ . '/stadium_non_lane1_practical_panel.php';
$stadiumOuterMode = 'app';
include __DIR__ . '/stadium_outer_reach_panel.php';
$raceNumberCompatibilityMode = 'app';
include __DIR__ . '/race_number_compatibility_panel.php';
include __DIR__ . '/app_basic_info_panel_core.php';