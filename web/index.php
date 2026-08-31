<?php
require_once __DIR__ . '/controllers/IndexController.php';
require_once __DIR__ . '/logic/Lane1EscapeFollowerLogic.php';
require_once __DIR__ . '/logic/Lane1DecisionSignalLogic.php';

$controller = new IndexController();
$viewData   = $controller->handle();

// 前方2期間で固定条件の再現を確認した1号艇判断シグナル。
// PredictionLogicや既存買い目は変更せず、表示専用で評価する。
$lane1DecisionSignalLogic = new Lane1DecisionSignalLogic();
$lane1DecisionSignal = $lane1DecisionSignalLogic->evaluate(
    $viewData['final_predictions'] ?? [],
    (int)($viewData['honmei_head'] ?? 0)
);
$lane1DecisionSignalPanel = $lane1DecisionSignalLogic->render($lane1DecisionSignal, false);

// 検証済みの「1逃げ時 場別相手傾向」は、Controllerの既存返却形式を変えず
// index.php側で本命買い目だけへ適用する。
// 実展示進入6艇完備かつ1号艇が1Cの時だけ有効。仮想進入では適用しない。
$lane1FollowerLogic = new Lane1EscapeFollowerLogic();
$viewData = $lane1FollowerLogic->apply(
    $viewData,
    $viewData['final_predictions'] ?? [],
    $viewData['place_names'][$viewData['selected_place'] ?? ''] ?? '',
    $viewData['entry_course_by_boat'] ?? [],
    !empty($viewData['entry_map_ready']) && empty($viewData['simulation_active'])
);

extract($viewData); // $selected_date, $selected_place, $race_code などを展開

// 基本1着率パネルを独立ビューとして生成
ob_start();
include __DIR__ . '/views/base_win_rate_panel.php';
$baseWinRatePanel = ob_get_clean();

// AI3連対率パネルを独立ビューとして生成。
// 基本1着率パネル内には1号艇1着時2着率も含まれるため、その後ろへ追加する。
ob_start();
include __DIR__ . '/views/ai_trio_rate_panel.php';
$aiTrioRatePanel = ob_get_clean();

// 既存ビューはそのまま保ち、総合マトリクス直前へ確率パネルを差し込む
ob_start();
include __DIR__ . '/views/index_view.php';
$html = ob_get_clean();

// 展示・評価情報の J/K/L は内部計算用として残し、画面表示だけ削除する。
// SUM・二次評価・最終予想などの計算値には影響させない。
foreach (['J列', 'K列', 'L列(メイン評価)'] as $hiddenTenjiLabel) {
    $pattern = '/<tr>\s*<td[^>]*>\s*'
        . preg_quote($hiddenTenjiLabel, '/')
        . '\s*<\/td>.*?<\/tr>/s';
    $html = preg_replace($pattern, '', $html, 1) ?? $html;
}

$marker = '<!-- ■ 総合出走・展示マトリクス -->';
if (strpos($html, $marker) !== false) {
    $html = str_replace(
        $marker,
        $baseWinRatePanel . "\n" . $aiTrioRatePanel . "\n    " . $marker,
        $html
    );
}

// 最終予想の先頭列を「コース | 艇番」に整理する。
// コースは通常文字、艇番だけ横長の色付きバッジで表示する。
// 進入変更時も展示進入マップを使うが、最終予想の計算値・本命対抗・買い目は変更しない。
$finalMarker = '<!-- ■ 最終予想（Excel完全一致） -->';
$finalPos = strpos($html, $finalMarker);
$finalEndMarker = '<!-- ■ 展示サム理論マスタデータ -->';
$finalEndPos = $finalPos !== false ? strpos($html, $finalEndMarker, $finalPos) : false;

if ($finalPos !== false && $finalEndPos !== false) {
    $beforeFinal = substr($html, 0, $finalPos);
    $finalHtml = substr($html, $finalPos, $finalEndPos - $finalPos);
    $afterFinal = substr($html, $finalEndPos);

    $finalHtml = preg_replace(
        '/<th>艇番<\/th>\s*<th>枠番<\/th>/',
        '<th>コース</th><th>艇番</th>',
        $finalHtml,
        1
    ) ?? $finalHtml;

    for ($boat = 1; $boat <= 6; $boat++) {
        $course = !empty($entry_map_ready)
            ? (int)($entry_course_by_boat[$boat] ?? $boat)
            : $boat;
        if ($course < 1 || $course > 6) {
            $course = $boat;
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

        $replacement = '<td>' . $course . 'コース</td>'
            . $boatBadge;

        $pattern = '/<td>\s*'
            . preg_quote((string)$boat, '/')
            . '\s*<\/td>\s*<td>\s*<span class="lane-badge"[^>]*>.*?<\/span>\s*<\/td>/s';

        $finalHtml = preg_replace(
            $pattern,
            $replacement,
            $finalHtml,
            1
        ) ?? $finalHtml;
    }

    $html = $beforeFinal . $finalHtml . $afterFinal;
}

// 場別1逃げ相手補正の適用状況を最終予想の直後に表示する。
// 判定値を表示するだけで、予想内容には影響させない。
$followerApplied = !empty($lane1_escape_follower_applied);
$followerReason = (string)($lane1_escape_follower_reason ?? '');
$followerLabel = $followerApplied ? '適用' : '未適用';
$followerExtra = '';

if ($followerApplied) {
    $stadiumLabel = trim((string)($lane1_escape_follower_stadium ?? ''));
    $sampleN = (int)($lane1_escape_follower_sample_n ?? 0);
    if ($stadiumLabel !== '') {
        $followerExtra .= ' / ' . $stadiumLabel;
    }
    if ($sampleN > 0) {
        $followerExtra .= ' N=' . number_format($sampleN);
    }
} elseif ($followerReason !== '') {
    $followerExtra = '（' . $followerReason . '）';
}

$followerDiagnostic = '<div style="margin:8px 0 14px; padding:8px 12px; border:1px solid #d6d3d1; border-radius:8px; background:#fafaf9; font-size:12px; color:#57534e;">'
    . '場別1逃げ相手補正：<strong>'
    . htmlspecialchars($followerLabel, ENT_QUOTES, 'UTF-8')
    . '</strong>'
    . htmlspecialchars($followerExtra, ENT_QUOTES, 'UTF-8')
    . '</div>';

if (strpos($html, $finalEndMarker) !== false) {
    $html = str_replace(
        $finalEndMarker,
        $lane1DecisionSignalPanel . "\n" . $followerDiagnostic . "\n" . $finalEndMarker,
        $html
    );
}

// 最終予想テーブルは表示だけ展示コース順（1→6C）に並べる。
// 本命・対抗・買い目などの計算順や値は変更しない。
$finalCourseSortScript = <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function () {
    const headings = Array.from(document.querySelectorAll('h2'));
    const heading = headings.find(function (el) {
        return el.textContent.includes('最終予想');
    });
    if (!heading) return;

    const tableContainer = heading.nextElementSibling;
    const table = tableContainer ? tableContainer.querySelector('table') : null;
    const tbody = table ? table.querySelector('tbody') : null;
    if (!tbody) return;

    const rows = Array.from(tbody.querySelectorAll('tr'));
    rows.sort(function (a, b) {
        const aCourse = parseInt((a.cells[0]?.textContent || '').replace(/[^0-9]/g, ''), 10) || 99;
        const bCourse = parseInt((b.cells[0]?.textContent || '').replace(/[^0-9]/g, ''), 10) || 99;
        return aCourse - bCourse;
    });

    rows.forEach(function (row) {
        tbody.appendChild(row);
    });
});
</script>
HTML;

// 展示サム理論（レース適用値）のJ/K/Lは内部列名のまま計算に使い、
// 画面見出しだけ features.json の実際の3指標名へ置き換える。
// 設定が読めない場合は J列/K列/L列へ安全にフォールバックする。
$samFeatureLabels = ['J列', 'K列', 'L列'];
$samFeatureDisplayNames = [
    'exhibition_time' => '展示タイム',
    'lap_time'        => '周回',
    'around_time'     => '周り足',
    'straight_time'   => '直線',
];
$samFeaturesPath = __DIR__ . '/../theories/new_sam/features.json';

if (is_file($samFeaturesPath)) {
    $samFeaturesJson = file_get_contents($samFeaturesPath);
    $samFeaturesAll = is_string($samFeaturesJson)
        ? json_decode($samFeaturesJson, true)
        : null;
    $samFeatureKeys = is_array($samFeaturesAll)
        ? ($samFeaturesAll[$selected_place] ?? [])
        : [];

    if (is_array($samFeatureKeys) && count($samFeatureKeys) >= 3) {
        for ($i = 0; $i < 3; $i++) {
            $key = (string)($samFeatureKeys[$i] ?? '');
            if (isset($samFeatureDisplayNames[$key])) {
                $samFeatureLabels[$i] = $samFeatureDisplayNames[$key];
            }
        }
    }
}

// 展示サム理論（レース適用値）もスリット体系と同じ見た目に揃える。
// コースは「1コース」の通常文字、艇番だけ横長の色付きバッジで別列表示する。
// SUMの計算値・course参照自体は変更しない。
$samAppliedMarker = '<!-- ■ 展示サム理論マスタデータ -->';
$samAppliedPos = strpos($html, $samAppliedMarker);
$samMasterHeading = '<h2>📐 サム理論（コース・区間別マスタ）</h2>';
$samMasterPos = $samAppliedPos !== false ? strpos($html, $samMasterHeading, $samAppliedPos) : false;

if ($samAppliedPos !== false && $samMasterPos !== false) {
    $beforeSamApplied = substr($html, 0, $samAppliedPos);
    $samAppliedHtml = substr($html, $samAppliedPos, $samMasterPos - $samAppliedPos);
    $afterSamApplied = substr($html, $samMasterPos);

    // 見出しを「コース | 艇番 | 実際のSUM3指標 | ...」へ変更。
    $samAppliedHtml = preg_replace(
        '/<th>コース<\/th>/',
        '<th>コース</th><th>艇番</th>',
        $samAppliedHtml,
        1
    ) ?? $samAppliedHtml;

    $samAppliedHtml = str_replace(
        ['<th>J列</th>', '<th>K列</th>', '<th>L列</th>'],
        [
            '<th>' . htmlspecialchars($samFeatureLabels[0], ENT_QUOTES, 'UTF-8') . '</th>',
            '<th>' . htmlspecialchars($samFeatureLabels[1], ENT_QUOTES, 'UTF-8') . '</th>',
            '<th>' . htmlspecialchars($samFeatureLabels[2], ENT_QUOTES, 'UTF-8') . '</th>',
        ],
        $samAppliedHtml
    );

    // 既存の6つのコースバッジを、コース通常文字＋実艇番バッジへ置換。
    $samCourseIndex = 0;
    $samAppliedHtml = preg_replace_callback(
        '/<td>\s*<span class="lane-badge"[^>]*>.*?<\/span>\s*<\/td>/s',
        static function (array $m) use (&$samCourseIndex, $entry_map_ready, $boat_by_entry_course, $lane_colors): string {
            $samCourseIndex++;
            $course = $samCourseIndex;
            if ($course < 1 || $course > 6) {
                return $m[0];
            }

            $boat = $entry_map_ready
                ? (int)($boat_by_entry_course[$course] ?? $course)
                : $course;
            if ($boat < 1 || $boat > 6) {
                $boat = $course;
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

            return '<td>' . $course . 'コース</td>' . $boatBadge;
        },
        $samAppliedHtml,
        6
    ) ?? $samAppliedHtml;

    // 艇番列を1列追加したため、フッターの列数だけ合わせる。
    $samAppliedHtml = preg_replace(
        '/<td colspan="4"([^>]*)>全体平均:<\/td>/',
        '<td colspan="5"$1>全体平均:</td>',
        $samAppliedHtml,
        1
    ) ?? $samAppliedHtml;

    $html = $beforeSamApplied . $samAppliedHtml . $afterSamApplied;
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
    $html = str_replace(
        '</body>',
        $finalCourseSortScript . "\n" . $slitPlace3DisplayScript . "\n</body>",
        $html
    );
}

echo $html;