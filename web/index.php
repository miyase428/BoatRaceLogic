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
                    // コース番号だけを色付きバッジ内に残し、号艇表示はバッジ外へ出す。
                    // 1号艇・5号艇の黒文字が暗い背景に溶けるのを防ぐ。
                    return $m[1]
                        . $course . 'C'
                        . $m[2]
                        . '<span style="margin-left:4px; color:#f8fafc; font-size:12px;">（'
                        . $boat
                        . '号艇）</span>';
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
    if (headers.length >= 5) {
        headers[3].textContent = '3着率（未採用）';
    }

    table.querySelectorAll('tbody tr').forEach(function (row) {
        if (row.cells.length >= 5) {
            row.cells[3].innerHTML = '<span style="color:#94a3b8;">—</span>';
            row.cells[3].title = '固定2期間検証でBrierが両期間とも悪化したため未採用';
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
