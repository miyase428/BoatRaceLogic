<?php
require_once __DIR__ . '/controllers/IndexController.php';
require_once __DIR__ . '/logic/AiTrioRateLogic.php';
require_once __DIR__ . '/logic/Head1SecondPlaceLogic.php';
require_once __DIR__ . '/logic/TrifectaProbabilityLogic.php';

$controller = new IndexController();
$viewData = $controller->handle();
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

// Excelモックのメイン情報にある「イン1着時 2連単」を、
// PC版と同じTrifectaProbabilityLogicからアプリ用に取り出す。
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
$trifectaBoatByCourse = is_array($trifectaData['boat_by_course'] ?? null)
    ? $trifectaData['boat_by_course']
    : [];

$appHead1ExactaRows = [];
if ($trifectaStatus === 'ok' && count($trifectaRows) === 120) {
    $baseBySecondCourse = array_fill(2, 5, 0.0);
    $aiBySecondCourse = array_fill(2, 5, 0.0);
    $baseMass = 0.0;
    $aiMass = 0.0;

    foreach ($trifectaRows as $row) {
        $courses = is_array($row['courses'] ?? null) ? $row['courses'] : [];
        if (count($courses) !== 3 || (int)$courses[0] !== 1) {
            continue;
        }

        $secondCourse = (int)$courses[1];
        if ($secondCourse < 2 || $secondCourse > 6) {
            continue;
        }

        $baseP = (float)($row['base_probability'] ?? 0.0);
        $aiP = (float)($row['probability'] ?? 0.0);
        $baseBySecondCourse[$secondCourse] += $baseP;
        $aiBySecondCourse[$secondCourse] += $aiP;
        $baseMass += $baseP;
        $aiMass += $aiP;
    }

    $headBoat = (int)($trifectaBoatByCourse[1] ?? 1);
    for ($secondCourse = 2; $secondCourse <= 6; $secondCourse++) {
        $secondBoat = (int)($trifectaBoatByCourse[$secondCourse] ?? $secondCourse);
        $base = $baseMass > 0.0 ? $baseBySecondCourse[$secondCourse] / $baseMass : 0.0;
        $ai = $aiMass > 0.0 ? $aiBySecondCourse[$secondCourse] / $aiMass : 0.0;

        $appHead1ExactaRows[] = [
            'second_course' => $secondCourse,
            'head_boat' => $headBoat,
            'second_boat' => $secondBoat,
            'base' => $base,
            'ai' => $ai,
            'delta' => $ai - $base,
        ];
    }
}

// 既存アプリViewは維持し、DOM上で「基本情報 / メイン情報」の2タブへ整理する。
// 今回はレイアウト確認用の第一段階。計算・予想ロジックは変更しない。
ob_start();
include __DIR__ . '/views/app_view.php';
$html = ob_get_clean();

$html = str_replace(
    '</head>',
    '    <link rel="stylesheet" href="/web/assets/css/app_tabs.css">' . "\n</head>",
    $html
);

$exactaJson = json_encode(
    $appHead1ExactaRows,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if (!is_string($exactaJson)) {
    $exactaJson = '[]';
}

$tabsScript = <<<'HTML'
<script>
(function () {
    const exactaRows = __EXACTA_JSON__;

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

        basicPanel.appendChild(quickCard);
        if (detailCard) basicPanel.appendChild(detailCard);

        mainPanel.appendChild(finalCard);
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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupTabs);
    } else {
        setupTabs();
    }
})();
</script>
HTML;

$tabsScript = str_replace('__EXACTA_JSON__', $exactaJson, $tabsScript);
$html = str_replace('</body>', $tabsScript . "\n</body>", $html);

echo $html;
