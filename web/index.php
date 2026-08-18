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

// スリット体系はコース基準の値なので、進入マップが取れている場合だけ
// 「4C（5号艇）」のように実艇番を併記する。
// SUM / スリットの計算値は一切変更せず、表示だけを加工する。
if (!empty($entry_map_ready) && !empty($boat_by_entry_course)) {
    $slitMarker = '<!-- ■ スリット体系 -->';
    $slitPos = strpos($html, $slitMarker);

    if ($slitPos !== false) {
        $beforeSlit = substr($html, 0, $slitPos);
        $slitHtml = substr($html, $slitPos);

        for ($course = 1; $course <= 6; $course++) {
            $boat = (int)($boat_by_entry_course[$course] ?? 0);
            if ($boat < 1 || $boat > 6) {
                continue;
            }

            $pattern = '/(<span class="lane-badge"[^>]*>\s*)'
                . preg_quote((string)$course, '/')
                . '(\s*<\/span>)/s';

            $slitHtml = preg_replace_callback(
                $pattern,
                static function (array $m) use ($course, $boat): string {
                    return $m[1] . $course . 'C（' . $boat . '号艇）' . $m[2];
                },
                $slitHtml,
                1
            ) ?? $slitHtml;
        }

        $html = $beforeSlit . $slitHtml;
    }
}

echo $html;
