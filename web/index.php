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
// 「コース」と「艇番」を別列で表示する。
// コースは「1コース」のような通常文字、艇番だけ色付きバッジにする。
// SUM / スリットの計算値は一切変更せず、表示だけを加工する。
if (!empty($entry_map_ready) && !empty($boat_by_entry_course)) {
    $slitMarker = '<!-- ■ スリット体系 -->';
    $slitPos = strpos($html, $slitMarker);

    if ($slitPos !== false) {
        $beforeSlit = substr($html, 0, $slitPos);
        $slitHtml = substr($html, $slitPos);

        // 見出しを「コース | 艇番 | ...」へ変更。
        $slitHtml = preg_replace(
            '/<th>コース<\/th>/',
            '<th>コース</th><th>艇番</th>',
            $slitHtml,
            1
        ) ?? $slitHtml;

        for ($course = 1; $course <= 6; $course++) {
            $boat = (int)($boat_by_entry_course[$course] ?? 0);
            if ($boat < 1 || $boat > 6) {
                continue;
            }

            $boatColor = $lane_colors[$boat] ?? $lane_colors[1];
            $boatBadge = '<td><span class="lane-badge" style="background-color:'
                . htmlspecialchars($boatColor['bg'], ENT_QUOTES, 'UTF-8')
                . '; color:'
                . htmlspecialchars($boatColor['text'], ENT_QUOTES, 'UTF-8')
                . '; border:1px solid '
                . htmlspecialchars($boatColor['border'], ENT_QUOTES, 'UTF-8')
                . '; display:inline-block !important; width:auto !important; height:auto !important; min-width:58px; padding:3px 8px !important; border-radius:5px !important; box-sizing:border-box; white-space:nowrap; overflow:visible !important; line-height:1.4; text-align:center; font-weight:bold; font-size:13px;">'
                . $boat
                . '号艇</span></td>';

            // 元のコース色付きバッジセルを丸ごと「1コース」の通常文字セルへ置換し、
            // その直後に実艇番の色付きバッジ列を追加する。
            $pattern = '/<td>\s*<span class="lane-badge"[^>]*>\s*'
                . preg_quote((string)$course, '/')
                . '\s*<\/span>\s*<\/td>/s';

            $slitHtml = preg_replace_callback(
                $pattern,
                static function (array $m) use ($course, $boatBadge): string {
                    return '<td>' . $course . 'コース</td>' . $boatBadge;
                },
                $slitHtml,
                1
            ) ?? $slitHtml;
        }

        $html = $beforeSlit . $slitHtml;
    }
}

// スリット3着率は固定2期間検証で両期間ともBrier悪化だったため本番未採用。
// 0.00%だと「補正効果が本当に0」と誤解しやすいので、画面上は未採用と明示する。
$slitPlace3DisplayScript = <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function () {
    const headings = Array.from(document.querySelectorAll('h2'));
    const heading = headings.find(function (el) {
        return el.textContent.includes('スリット体系');
    });
    if (!heading) return;

    const section = heading.parentElement;
    const table = section ? section.querySelector('table') : null;
    if (!table) return;

    const headers = table.querySelectorAll('thead th');
    if (headers.length >= 6) {
        headers[4].textContent = '3着率（未採用）';
    }

    table.querySelectorAll('tbody tr').forEach(function (row) {
        if (row.cells.length >= 6) {
            row.cells[4].innerHTML = '<span style="color:#94a3b8;">—</span>';
            row.cells[4].title = '固定2期間検証でBrierが両期間とも悪化したため未採用';
        }
    });

    if (!section.querySelector('.slit-place3-note')) {
        const note = document.createElement('div');
        note.className = 'slit-place3-note';
        note.style.marginTop = '8px';
        note.style.fontSize = '12px';
        note.style.color = '#94a3b8';
        note.textContent = '※3着率は固定2期間検証で悪化したため未採用。2着率は採用済み。';
        table.parentElement.insertAdjacentElement('afterend', note);
    }
});
</script>
HTML;

if (strpos($html, '</body>') !== false) {
    $html = str_replace('</body>', $slitPlace3DisplayScript . "\n</body>", $html);
}

echo $html;
