<?php
require_once __DIR__ . '/controllers/IndexController.php';
require_once __DIR__ . '/logic/AiTrioRateLogic.php';
require_once __DIR__ . '/logic/AiTrioDisplayLogic.php';
require_once __DIR__ . '/logic/Head1SecondPlaceLogic.php';
require_once __DIR__ . '/logic/TrifectaProbabilityLogic.php';
require_once __DIR__ . '/logic/AiPlaceTrifectaDisplayLogic.php';
require_once __DIR__ . '/logic/Lane1EscapeFollowerLogic.php';
require_once __DIR__ . '/logic/Lane1DecisionSignalLogic.php';
require_once __DIR__ . '/logic/PredictionForwardSnapshotStore.php';
require_once __DIR__ . '/logic/EightPointFormationLogic.php';
require_once __DIR__ . '/logic/RecentCourseTrioRateLogic.php';
require_once __DIR__ . '/logic/SecondPlaceProbabilityLogic.php';
require_once __DIR__ . '/logic/ThirdPlaceProbabilityLogic.php';

$controller = new IndexController();
$viewData = $controller->handle();

// AI1着率 v5の確率と首位差で①の信頼度を表示する。
// PredictionLogicや既存買い目は変更せず、表示専用で評価する。
$lane1DecisionSignalLogic = new Lane1DecisionSignalLogic();
$lane1DecisionSignal = $lane1DecisionSignalLogic->evaluate(
    $viewData['final_predictions'] ?? [],
    (int)($viewData['honmei_head'] ?? 0),
    is_array($viewData['ai_win_rate_data'] ?? null) ? $viewData['ai_win_rate_data'] : []
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

// 学習済みAI1着率 v5。本命頭・120通り・表示で共用する。
$aiWinBoats = is_array($ai_win_rate_data['boats'] ?? null)
    ? $ai_win_rate_data['boats']
    : [];
$aiWinStatus = (string)($ai_win_rate_data['status'] ?? 'error');
$aiWinError = (string)($ai_win_rate_data['error'] ?? '');
$aiPlaceBoats = is_array($ai_place_rate_data['boats'] ?? null)
    ? $ai_place_rate_data['boats']
    : [];
$aiPlaceStatus = (string)($ai_place_rate_data['status'] ?? 'error');
$aiPlaceError = (string)($ai_place_rate_data['error'] ?? '');
$productionWinBoats = $aiWinStatus === 'ok' && count($aiWinBoats) === 6
    ? $aiWinBoats
    : $correctedWinBoats;

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

// 表示だけは、AI着順モデルが作る120通りから集計した3連対率へ差し替える。
// $aiTrioBoatsは既存の買い目・従来出目計算用として変更せず保持する。
$aiTrioDisplayLogic = new AiTrioDisplayLogic();
$aiTrioDisplayData = $aiTrioDisplayLogic->apply(
    $aiTrioData,
    is_array($ai_place_rate_data ?? null) ? $ai_place_rate_data : []
);
$aiTrioDisplayBoats = is_array($aiTrioDisplayData['boats'] ?? null)
    ? $aiTrioDisplayData['boats']
    : $aiTrioBoats;
$aiTrioDisplayStatus = (string)($aiTrioDisplayData['status'] ?? 'error');
$aiTrioDisplaySource = (string)($aiTrioDisplayData['display_source'] ?? 'legacy_ai_trio');

// 8点型の3着候補は、今回の展示進入に対応する直近3/6ヶ月3連対率を使う。
$recentCourseTrioLogic = new RecentCourseTrioRateLogic();
$recentCourseTrioData = $recentCourseTrioLogic->calculate(
    (string)($race_code ?? ''),
    $aiTrioCourseByBoat
);
$recentCourseTrioBoats = is_array($recentCourseTrioData['boats'] ?? null)
    ? $recentCourseTrioData['boats']
    : [];

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
// CommonSecondRuntimeBridge → AI着順率v1へ一本化する。
$outcomeCourseByBoat = [];
if (count($aiTrioCourseByBoat) === 6) {
    $outcomeCourseByBoat = $aiTrioCourseByBoat;
} elseif (is_array($prediction_course_by_boat ?? null) && count($prediction_course_by_boat) === 6) {
    $outcomeCourseByBoat = $prediction_course_by_boat;
}

$trifectaLogic = new TrifectaProbabilityLogic();
$trifectaData = $trifectaLogic->calculate(
    (string)($race_code ?? ''),
    $productionWinBoats,
    $aiTrioBoats,
    $outcomeCourseByBoat
);
$trifectaStatus = (string)($trifectaData['status'] ?? 'error');
$trifectaError = (string)($trifectaData['error'] ?? '');
$trifectaRows = is_array($trifectaData['rows'] ?? null) ? $trifectaData['rows'] : [];

// 本命買い目の2着候補はAI着順率v1を正式採用する。
// 頭・切る艇・3着候補・点数は変えず、AI版が使えない場合だけ従来値へ戻す。
$appFinalSecondTrifectaData = (new AiPlaceTrifectaDisplayLogic())->apply(
    $trifectaData,
    is_array($ai_place_rate_data ?? null) ? $ai_place_rate_data : []
);
if ((string)($appFinalSecondTrifectaData['probability_source'] ?? '') !== 'ai_place_v1_joint120') {
    $appFinalSecondTrifectaData = $trifectaData;
}

// app_main_analysis_panel.php の共通ブリッジで5通りへ上書きされる。
$appHead1ExactaRows = [];
$appHead1ExactaV1 = false;

// 基本情報は取得値だけに限定する。
// 加工・評価結果はメイン情報へ集約し、計算ロジック自体は共用する。
ob_start();
include __DIR__ . '/views/app_basic_info_panel.php';
$appBasicInfoHtml = ob_get_clean();

ob_start();
include __DIR__ . '/views/app_main_analysis_panel.php';
$appMainAnalysisHtml = ob_get_clean();

// app_main_analysis_panel.php がAI版の本命2着候補を反映した後に、
// 8点型を別レイヤーで作る。
$eightPointFormationLogic = new EightPointFormationLogic();
$viewData = $eightPointFormationLogic->apply(
    $viewData,
    is_array($final_predictions ?? null) ? $final_predictions : [],
    is_array($aiTrioBoats ?? null) ? $aiTrioBoats : [],
    $recentCourseTrioBoats
);
extract($viewData, EXTR_OVERWRITE);

// 実際に画面へ出す本命・対抗・8点型を、締切前にまとめて保存する。
PredictionForwardSnapshotStore::captureDisplayedPrediction($viewData, 'app');

// 2連単・120通りタブは、展示前でも暫定表示できるようにする。
// 暫定値は正式な本命2着候補へ流さず、アプリ表示専用で分離する。
$appTrifectaDisplayMode = 'exhibition';
$appTrifectaData = $trifectaData;
$appTrifectaStatus = $trifectaStatus;
$appTrifectaError = $trifectaError;
$appTrifectaRows = $trifectaRows;

if ($appTrifectaStatus !== 'ok' || count($appTrifectaRows) !== 120) {
    $appTrifectaDisplayMode = 'provisional';

    // 展示前は枠なり進入を基本とする。仮想進入中だけ指定進入を使用する。
    $appProvisionalCourseByBoat = [];
    if (
        !empty($simulation_active)
        && is_array($prediction_course_by_boat ?? null)
        && count($prediction_course_by_boat) === 6
    ) {
        $appProvisionalCourseByBoat = $prediction_course_by_boat;
    } else {
        for ($boat = 1; $boat <= 6; $boat++) {
            $appProvisionalCourseByBoat[$boat] = $boat;
        }
    }

    // 展示前の1着側は基本1着率を使用する。
    $appProvisionalBaseWinBoats = $baseWinBoats;
    if (!empty($simulation_active)) {
        $appProvisionalBaseWinLogic = new BaseWinRateLogic();
        $appProvisionalBaseWinData = $appProvisionalBaseWinLogic->calculate(
            (string)($race_code ?? ''),
            $appProvisionalCourseByBoat
        );
        if (is_array($appProvisionalBaseWinData['boats'] ?? null) && count($appProvisionalBaseWinData['boats']) === 6) {
            $appProvisionalBaseWinBoats = $appProvisionalBaseWinData['boats'];
        }
    }

    $appProvisionalWinBoats = [];
    for ($boat = 1; $boat <= 6; $boat++) {
        $rate = $appProvisionalBaseWinBoats[$boat]['normalized_rate']
            ?? $appProvisionalBaseWinBoats[(string)$boat]['normalized_rate']
            ?? null;
        if (is_numeric($rate)) {
            $appProvisionalWinBoats[$boat] = ['corrected_rate' => (float)$rate];
        }
    }

    // AI3連対率は二次評価だけ中立（Z=0）にし、基礎3連対率＋一次評価で暫定計算する。
    // AiTrioRateLogic本体の確定ロジックは変更しない。
    $appNeutralTenjiList = [];
    for ($boat = 1; $boat <= 6; $boat++) {
        $appNeutralTenjiList[] = [
            'teiban' => $boat,
            'tenji_course' => (int)$appProvisionalCourseByBoat[$boat],
            'final_2nd_score' => 0.0,
        ];
    }

    $appProvisionalAiTrioData = $aiTrioLogic->calculate(
        (string)($race_code ?? ''),
        is_array($results ?? null) ? $results : [],
        $appNeutralTenjiList,
        $appProvisionalCourseByBoat,
        true
    );
    $appProvisionalAiTrioBoats = is_array($appProvisionalAiTrioData['boats'] ?? null)
        ? $appProvisionalAiTrioData['boats']
        : [];

    $appTrifectaData = $trifectaLogic->calculate(
        (string)($race_code ?? ''),
        $appProvisionalWinBoats,
        $appProvisionalAiTrioBoats,
        $appProvisionalCourseByBoat
    );
    $appTrifectaStatus = (string)($appTrifectaData['status'] ?? 'error');
    $appTrifectaError = (string)($appTrifectaData['error'] ?? '');
    $appTrifectaRows = is_array($appTrifectaData['rows'] ?? null)
        ? $appTrifectaData['rows']
        : [];
}

// 2連単・120通りタブの最終確率を、AI着順率 v1 の同時120通りへ統一する。
// 本命2着候補も同じAI版を使うが、ここではタブ表示用データを組み立てる。
$appAiPlaceTrifectaDisplayLogic = new AiPlaceTrifectaDisplayLogic();
$appTrifectaData = $appAiPlaceTrifectaDisplayLogic->apply(
    $appTrifectaData,
    is_array($ai_place_rate_data ?? null) ? $ai_place_rate_data : []
);
$appTrifectaStatus = (string)($appTrifectaData['status'] ?? 'error');
$appTrifectaError = (string)($appTrifectaData['error'] ?? '');
$appTrifectaRows = is_array($appTrifectaData['rows'] ?? null)
    ? $appTrifectaData['rows']
    : [];

// 「1C頭時の今回AI2着率」は、画面の2連単・120通りと同じ表示用確率から集約する。
// 本命2着候補と同じ機械学習確率を、1C頭の表示にも集約する。
$appDisplaySecondLogic = new SecondPlaceProbabilityLogic();
$appDisplayHead1Data = $appDisplaySecondLogic->calculate($appTrifectaData, 1);
$appHead1ExactaRows = (
    (string)($appDisplayHead1Data['status'] ?? '') === 'ok'
    && is_array($appDisplayHead1Data['rows'] ?? null)
)
    ? $appDisplayHead1Data['rows']
    : [];
$appHead1ExactaV1 = (string)($appTrifectaData['probability_source'] ?? '') === 'ai_place_v1_joint120';

// アプリの「AI条件付き2着率」でも、PCと同じ120通りから全頭コースを集約する。
// 表示専用で、既存の本命・対抗・買い目ロジックには書き戻さない。
$appConditionalSecondByHead = [];
$appThirdPlaceLogic = new ThirdPlaceProbabilityLogic();
for ($appHeadCourse = 1; $appHeadCourse <= 6; $appHeadCourse++) {
    $appConditionalSecond = $appDisplaySecondLogic->calculate($appTrifectaData, $appHeadCourse);
    if ((string)($appConditionalSecond['status'] ?? '') !== 'ok') {
        continue;
    }
    $appThirdBySecondCourse = [];
    foreach ((array)($appConditionalSecond['rows'] ?? []) as $appSecondRow) {
        $appSecondCourse = (int)($appSecondRow['second_course'] ?? 0);
        $appThird = $appThirdPlaceLogic->calculate($appTrifectaData, $appHeadCourse, $appSecondCourse);
        if ((string)($appThird['status'] ?? '') === 'ok') {
            $appThirdBySecondCourse[(string)$appSecondCourse] = is_array($appThird['rows'] ?? null) ? $appThird['rows'] : [];
        }
    }
    $appConditionalSecondByHead[(string)$appHeadCourse] = [
        'head_course' => (int)($appConditionalSecond['head_course'] ?? $appHeadCourse),
        'head_boat' => (int)($appConditionalSecond['head_boat'] ?? 0),
        'rows' => is_array($appConditionalSecond['rows'] ?? null) ? $appConditionalSecond['rows'] : [],
        'third_by_second_course' => $appThirdBySecondCourse,
    ];
}

// 既存アプリViewは土台として維持し、DOM上で「基本情報 / メイン情報」の2タブへ整理する。
ob_start();
include __DIR__ . '/views/app_view.php';
$html = ob_get_clean();

// iPhoneのホーム画面アプリではCSSが残りやすいため、アプリ用CSSだけ版番号を付ける。
$html = str_replace(
    '</head>',
    '    <link rel="stylesheet" href="/web/assets/css/app_tabs.css?v=20260822-0835">' . "\n"
        . '    <link rel="stylesheet" href="/web/assets/css/app_basic_info.css?v=20260917-0915">' . "\n</head>",
    $html
);

$exactaJson = json_encode(
    $appHead1ExactaRows,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if (!is_string($exactaJson)) {
    $exactaJson = '[]';
}
$exactaV1Json = $appHead1ExactaV1 ? 'true' : 'false';
$conditionalSecondJson = json_encode(
    $appConditionalSecondByHead,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if (!is_string($conditionalSecondJson)) {
    $conditionalSecondJson = '{}';
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
    const exactaV1 = __EXACTA_V1__;
    const conditionalSecondByHead = __CONDITIONAL_SECOND_JSON__;
    window.boatraceConditionalSecondByHead = conditionalSecondByHead;
    window.boatraceConditionalSecondIsMlV1 = exactaV1;
    const basicInfoHtml = __BASIC_INFO_JSON__;
    const mainAnalysisHtml = __MAIN_ANALYSIS_JSON__;
    const lane1DecisionSignalHtml = __LANE1_DECISION_SIGNAL_JSON__;

    function buildExactaCard() {
        const section = document.createElement('section');
        section.className = 'app-card app-main-exacta';
        section.dataset.aiPlaceV1 = exactaV1 ? '1' : '0';

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
        document.dispatchEvent(new CustomEvent('boatrace:app-basic-panel-ready'));
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

        // コースサインは表示専用なので、取得完了を初期画面の表示条件にしない。
        // 本体を先に操作可能にし、サインは取得でき次第追加表示する。

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
$tabsScript = str_replace('__EXACTA_V1__', $exactaV1Json, $tabsScript);
$tabsScript = str_replace('__CONDITIONAL_SECOND_JSON__', $conditionalSecondJson, $tabsScript);
$tabsScript = str_replace('__BASIC_INFO_JSON__', $basicInfoJson, $tabsScript);
$tabsScript = str_replace('__MAIN_ANALYSIS_JSON__', $mainAnalysisJson, $tabsScript);
$tabsScript = str_replace('__LANE1_DECISION_SIGNAL_JSON__', $lane1DecisionSignalJson, $tabsScript);
$html = str_replace('</body>', $tabsScript . "\n</body>", $html);

echo $html;
