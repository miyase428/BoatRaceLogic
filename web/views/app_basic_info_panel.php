<?php
// アプリの基本情報タブ先頭に場別相性を表示する。
// 既存の基本情報本体はcoreへ分離し、表示・計算内容は変更しない。
$stadiumAffinityMode = 'app';
include __DIR__ . '/stadium_affinity_panel.php';
include __DIR__ . '/app_basic_info_panel_core.php';
