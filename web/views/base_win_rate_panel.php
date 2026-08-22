<?php
// 場別相性パネルを先頭に追加し、既存の1着率パネル本体はcoreへ分離してそのまま利用する。
$stadiumAffinityMode = 'pc';
include __DIR__ . '/stadium_affinity_panel.php';
include __DIR__ . '/base_win_rate_panel_core.php';
