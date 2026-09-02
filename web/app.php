<?php
require_once __DIR__ . '/controllers/IndexController.php';
require_once __DIR__ . '/logic/AiTrioRateLogic.php';
require_once __DIR__ . '/logic/Head1SecondPlaceLogic.php';
require_once __DIR__ . '/logic/TrifectaProbabilityLogic.php';
require_once __DIR__ . '/logic/Lane1EscapeFollowerLogic.php';
require_once __DIR__ . '/logic/Lane1DecisionSignalLogic.php';

$controller = new IndexController();
$viewData = $controller->handle();

// 前方2期間で固定条件の再現を確認した1号艇判断シグナル。
// PredictionLogicや既存買い目は変更せず、表示専用で評価する。
$lane1DecisionSignalLogic = new Lane1DecisionSignalLogic();
$lane1DecisionSignal = $lane1DecisionSignalLogic->evaluate(
    $viewData['final_predictions'] ?? [],
    (int)($viewData['honmei_head'] ?? 0)
);
$lane1DecisionSignalPanel = $lane1DecisionSignalLogic->render($lane1DecisionSignal, true);

// Web版と同じ「1逃げ時 場別相手傾向」をアプリにも適用する。
// 実展示進入6艇完備かつ1号艇が1Cの時だけ有効。仮想進入では適用しない。
$lane1FollowerLogic = new Lane1EscapeFollowerLogic();
$viewData = $lane1FollowerLogic->apply(
    $viewData,
    $viewData['final_predictions'] ?? [],
    $viewData['place_names'][$viewData['selected_place'] ?? ''] ?? '',
    $viewData['entry_course_by_boat'] ?? [],
    !empty($viewData['entry_map_ready']) && empty($viewData['simulation_active'])
);

extract($viewData);

// PC版base_win_rate_panel.phpと同じく、通常時に展示進入が変わった場合は
// 基本1着率を今回の予想進入へ合わせて表示する。
if (
    empty($simulation_active)
    && !empty($prediction_entry_changed)
    && !empty($prediction_course_by_boat)
    && is_array($prediction_course_by_boat)
    && !empty($race_code)
) {
    $baseWinRateLogicForApp = new BaseWinRateLogic();
    $base_win_rate_data = $baseWinRateLogicForApp->calculate(
        (string)$race_code,
        $prediction_course_by_boat
    );
}

$baseWinBoats = is_array($base_win_rate_data['boats'] ?? null)
    ? $base_win_rate_data['boats']
    : [];
$baseWinError = (string)($base_win_rate_data['error'] ?? '');

$correctedWinBoats = is_array($corrected_win_rate_data['boats'] ?? null)
    ? $corrected_win_rate_data['boats']
    : [];
$correctedWinStatus = (string)($corrected_win_rate_data['status'] ?? 'error');
$correctedWinError = (string)($corrected_win_rate_data['error'] ?? '');

// AI3連対率。計算ロジックはPC版と共通で、アプリ側では表示だけ変える。
$aiTrioCourseByBoat = [];
if (!empty($simulation_active) && is_array($prediction_course_by_boat ?? null)) {
    $aiTrioCourseByBoat = $prediction_course_by_boat;
} elseif (!empty($entry_map_ready) && is_array($entry_course_by_boat ?? null)) {
    $aiTrioCourseByBoat = $entry_course_by_boat;
}

$aiTrioLogic = new AiTrioRateLogic();
$aiTrioData = $aiTrioLogic->calculate(
    (string)($race_code ?? ''),
    is_array($results ?? null) ? $results : [],
    is_array($tenji_list ?? null) ? $tenji_list : [],
    $aiTrioCourseByBoat,
    !empty($simulation_active)
);
$aiTrioStatus = (string)($aiTrioData['status'] ?? 'error');
$aiTrioError = (string)($aiTrioData['error'] ?? '');
$aiTrioBoats = is_array($aiTrioData['boats'] ?? null) ? $aiTrioData['boats'] : [];

// 1号艇1着時の2着率。こちらもPC版と同じロジックを共用する。
$head1SecondLogic = new Head1SecondPlaceLogic();
$head1SecondData = $head1SecondLogic->calculate(
    (string)($race_code ?? ''),
    is_array($prediction_course_by_boat ?? null) ? $prediction_course_by_boat : []
);
$head1SecondStatus = (string)($head1SecondData['status'] ?? 'error');
$head1SecondError = (string)($head1SecondData['error'] ?? '');
$head1SecondBoats = is_array($head1SecondData['boats'] ?? null)
    ? $head1SecondData['boats']
    : [];

// 120通り出目確率を1度だけ計算する。
// 2着分布の集計はここでは行わず、app_main_analysis_panel.php 内の
// CommonSecondRuntimeBridge → SecondPlaceProbabilityLogic（③ AI_FINAL）に一本化する。
$outcomeCourseByBoat = [];
if (count($aiTrioCourseByBoat) === 6) {
    $outcomeCourseByBoat = $aiTrioCourseByBoat;
} elseif (is_array($prediction_course_by_boat ?? null) && count($prediction_course_by_boat) === 6) {
    $outcomeCourseByBoat = $prediction_course_by_boat;
}

$trifectaLogic = new TrifectaProbabilityLogic();
$trifectaData = $trifectaLogic->calculate(
    (string)($race_code ?? ''),
    $correctedWinBoats,
    $aiTrioBoats,
    $outcomeCourseByBoat
);
$trifectaStatus = (string)($trifectaData['status'] ?? 'error');
$trifectaRows = is_array($trifectaData['rows'] ?? null) ? $trifectaData['rows'] : [];

// app_main_analysis_panel.php の共通ブリッジで5通りへ上書きされる。
$appHead1ExactaRows = [];

// 基本情報は取得値だけに限定する。
// 加工・評価結果はメイン情報へ集約し、計算ロジック自体は共用する。
ob_start();
include __DIR__ . '/views/app_basic_info_panel.php';
$appBasicInfoHtml = ob_get_clean();

ob_start();
include __DIR__ . '/views/app_main_analysis_panel.php';
$appMainAnalysisHtml = ob_get_clean();

// 既存アプリViewは土台として維持し、DOM上で「基本情報 / メイン情報」の2タブへ整理する。
ob_start();
include __DIR__ . '/views/app_view.php';
$html = ob_get_clean();

// iPhoneのホーム画面アプリではCSSが残りやすいため、アプリ用CSSだけ版番号を付ける。
$html = str_replace(
    '</head>',
    '    <link rel="stylesheet" href="/web/assets/css/app_tabs.css?v=20260822-0835">' . "\n"
        . '    <link rel="stylesheet" href="/web/assets/css/app_basic_info.css?v=20260822-0835">' . "\n</head>",
    $html
);

$exactaJson = json_encode(
    $appHead1ExactaRows,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if (!is_string($exactaJson)) {
    $exactaJson = '[]';
}

$basicInfoJson = json_encode(
    $appBasicInfoHtml,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if (!is_string($basicInfoJson)) {
    $basicInfoJson = '""';
}

$mainAnalysisJson = json_encode(
    $appMainAnalysisHtml,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if (!is_string($mainAnalysisJson)) {
    $mainAnalysisJson = '""';
}

$lane1DecisionSignalJson = json_encode(
    $lane1DecisionSignalPanel,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if (!is_string($lane1DecisionSignalJson)) {
    $lane1DecisionSignalJson = '""';
}

$tabsScript = <<<'HTML'
<script>
(function () {
    const exactaRows = __EXACTA_JSON__;
    const basicInfoHtml = __BASIC_INFO_JSON__;
    const mainAnalysisHtml = __MAIN_ANALYSIS_JSON__;
    const lane1DecisionSignalHtml = __LANE1_DECISION_SIGNAL_JSON__;

    function buildExactaCard() {
        const section = document.createElement('section');
        section.className = 'app-card app-main-exacta';

        const title = document.createElement('div');
        title.className = 'app-card-body app-main-exacta-title';
        title.innerHTML = '<h2 class="app-section-title">🎯 イン1着時 2連単</h2>';
        section.appendChild(title);

        if (!Array.isArray(exactaRows) || exactaRows.length !== 5) {
            const waiting = document.createElement('div');
            waiting.className = 'app-card-body app-note';
            waiting.textContent = '展示情報がそろうとAI予想を表示します。';
            section.appendChild(waiting);
            return section;
        }

        const grid = document.createElement('div');
        grid.className = 'app-exacta-grid';

        function cell(text, className) {
            const div = document.createElement('div');
            if (className) div.className = className;
            div.textContent = text;
            return div;
        }

        grid.appendChild(cell('', 'app-exacta-label'));
        exactaRows.forEach(function (row) {
            grid.appendChild(cell(String(row.head_boat) + '-' + String(row.second_boat), 'app-exacta-head'));
        });

        grid.appendChild(cell('場平均', 'app-exacta-label'));
        exactaRows.forEach(function (row) {
            grid.appendChild(cell((Number(row.base) * 100).toFixed(1) + '%'));
        });

        grid.appendChild(cell('AI予想', 'app-exacta-label'));
        exactaRows.forEach(function (row) {
            grid.appendChild(cell((Number(row.ai) * 100).toFixed(1) + '%', 'app-exacta-ai'));
        });

        grid.appendChild(cell('差', 'app-exacta-label'));
        exactaRows.forEach(function (row) {
            const delta = Number(row.delta) * 100;
            grid.appendChild(cell((delta >= 0 ? '+' : '') + delta.toFixed(1) + 'pt', delta >= 0 ? 'app-delta-plus' : 'app-delta-minus'));
        });

        section.appendChild(grid);
        return section;
    }

    function buildLane1DecisionSignalCard() {
        if (!lane1DecisionSignalHtml) return null;
        const template = document.createElement('template');
        template.innerHTML = String(lane1DecisionSignalHtml).trim();
        return template.content.firstElementChild;
    }

    function setupTabs() {
        const shell = document.querySelector('.app-shell');
        if (!shell || shell.querySelector('.app-tabs')) return;

        const cards = Array.from(shell.children).filter(function (el) {
            return el.matches && el.matches('section.app-card');
        });
        if (cards.length < 3) return;

        const selectorCard = cards[0];
        const quickCard = cards[1];
        const finalCard = cards[2];
        const alertPanel = document.getElementById('upset-alert-panel');
        const detailCard = Array.from(shell.children).find(function (el) {
            return el.matches && el.matches('details.app-card');
        });

        const tabs = document.createElement('nav');
        tabs.className = 'app-tabs';
        // 3つ目の「120通り」は後から追加されるため、CSSキャッシュ時でも3列を強制する。
        tabs.style.gridTemplateColumns = 'repeat(3, minmax(0, 1fr))';
        tabs.style.width = '100%';
        tabs.style.maxWidth = '100%';
        tabs.innerHTML = '<button type="button" class="app-tab is-active" data-tab="basic">基本情報</button>'
            + '<button type="button" class="app-tab" data-tab="main">メイン情報</button>';

        const basicPanel = document.createElement('div');
        basicPanel.className = 'app-tab-panel is-active';
        basicPanel.dataset.panel = 'basic';

        const mainPanel = document.createElement('div');
        mainPanel.className = 'app-tab-panel';
        mainPanel.dataset.panel = 'main';
        mainPanel.hidden = true;

        selectorCard.insertAdjacentElement('afterend', tabs);
        tabs.insertAdjacentElement('afterend', basicPanel);
        basicPanel.insertAdjacentElement('afterend', mainPanel);

        // 基本情報は直接取得した出走表・展示値だけ。
        basicPanel.innerHTML = basicInfoHtml || '';
        quickCard.remove();
        if (detailCard) detailCard.remove();

        // メイン情報は1着率・AI・評価などの加工結果から始め、
        // その下に最終予想・1号艇判断シグナル・2連単・イン飛び警報をまとめる。
        mainPanel.innerHTML = mainAnalysisHtml || '';
        mainPanel.appendChild(finalCard);
        const lane1SignalCard = buildLane1DecisionSignalCard();
        if (lane1SignalCard) mainPanel.appendChild(lane1SignalCard);
        mainPanel.appendChild(buildExactaCard());
        if (alertPanel) mainPanel.appendChild(alertPanel);

        const buttons = Array.from(tabs.querySelectorAll('.app-tab'));
        const panels = [basicPanel, mainPanel];

        function activate(name) {
            buttons.forEach(function (button) {
                button.classList.toggle('is-active', button.dataset.tab === name);
            });
            panels.forEach(function (panel) {
                const active = panel.dataset.panel === name;
                panel.classList.toggle('is-active', active);
                panel.hidden = !active;
            });
            try { sessionStorage.setItem('boatraceAppTab', name); } catch (e) {}
        }

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                activate(button.dataset.tab || 'basic');
            });
        });

        let initial = 'basic';
        try {
            const saved = sessionStorage.getItem('boatraceAppTab');
            if (saved === 'basic' || saved === 'main') initial = saved;
        } catch (e) {}
        activate(initial);
    }

    function setupLoading() {
        if (document.querySelector('.app-loading-overlay')) return;

        const overlay = document.createElement('div');
        overlay.className = 'app-loading-overlay';
        overlay.setAttribute('aria-hidden', 'true');
        overlay.innerHTML = '<div class="app-loading-box" role="status" aria-live="polite">'
            + '<div class="app-loading-spinner"></div>'
            + '<div class="app-loading-message">読み込み中…</div>'
            + '</div>';
        document.body.appendChild(overlay);

        const message = overlay.querySelector('.app-loading-message');

        function showLoading(text, button) {
            if (message) message.textContent = text;
            overlay.classList.add('is-visible');
            overlay.setAttribute('aria-hidden', 'false');
            if (button) {
                button.classList.add('is-loading');
                // submitイベント中にdisabledへすると、submitterのname/valueがPOST対象から外れる。
                // 展示更新判定の update_exhibition=1 を確実に送るため、ここでは無効化しない。
            }
        }

        document.querySelectorAll('.app-shell form').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                const exhibition = !!form.querySelector('button[name="update_exhibition"]');
                const submitter = event.submitter || form.querySelector('button[type="submit"]');
                showLoading(
                    exhibition ? '展示情報を取得・更新中…' : 'レース情報を取得中…',
                    submitter
                );
            });
        });

        const reloadButton = document.querySelector('.app-actions .app-btn-secondary[type="button"]');
        if (reloadButton) {
            reloadButton.addEventListener('click', function () {
                showLoading('再読み込み中…', reloadButton);
            }, {capture: true});
        }
    }

    function initAppEnhancements() {
        setupTabs();
        setupLoading();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAppEnhancements);
    } else {
        initAppEnhancements();
    }
})();
</script>
HTML;

$tabsScript = str_replace('__EXACTA_JSON__', $exactaJson, $tabsScript);
$tabsScript = str_replace('__BASIC_INFO_JSON__', $basicInfoJson, $tabsScript);
$tabsScript = str_replace('__MAIN_ANALYSIS_JSON__', $mainAnalysisJson, $tabsScript);
$tabsScript = str_replace('__LANE1_DECISION_SIGNAL_JSON__', $lane1DecisionSignalJson, $tabsScript);
$html = str_replace('</body>', $tabsScript . "\n</body>", $html);

echo $html;