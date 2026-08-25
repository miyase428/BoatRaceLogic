<?php
// 場別相性 → 場特性 → イン敗戦時特性 → 外枠到達率 → R別予想相性 → 既存1着率パネルの順で表示する。
$stadiumAffinityMode = 'pc';
include __DIR__ . '/stadium_affinity_panel.php';
$stadiumPracticalMode = 'pc';
include __DIR__ . '/stadium_practical_characteristics_panel.php';
$stadiumNonLane1Mode = 'pc';
include __DIR__ . '/stadium_non_lane1_practical_panel.php';
$stadiumOuterMode = 'pc';
include __DIR__ . '/stadium_outer_reach_panel.php';
$raceNumberCompatibilityMode = 'pc';
include __DIR__ . '/race_number_compatibility_panel.php';
include __DIR__ . '/base_win_rate_panel_core.php';