<?php
require_once __DIR__ . '/controllers/IndexController.php';

$controller = new IndexController();
$viewData   = $controller->handle();

extract($viewData); // $selected_date, $selected_place, $race_code などを展開

// 基本1着率パネルを独立ビューとして生成
ob_start();
include __DIR__ . '/views/base_win_rate_panel.php';
$baseWinRatePanel = ob_get_clean();

// 既存ビューはそのまま保ち、総合マトリクス直前へ基本1着率を差し込む
ob_start();
include __DIR__ . '/views/index_view.php';
$html = ob_get_clean();

$marker = '<!-- ■ 総合出走・展示マトリクス -->';
if (strpos($html, $marker) !== false) {
    $html = str_replace($marker, $baseWinRatePanel . "\n    " . $marker, $html);
}

echo $html;
