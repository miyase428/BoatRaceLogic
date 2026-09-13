<?php
// 場特性関連は5タブにまとめ、その下で前向き実戦検証を記録してから既存1着率パネルを表示する。
$stadiumCharacteristicsMode = 'pc';
include __DIR__ . '/stadium_characteristics_tabs.php';
$forwardValidationMode = 'pc';
include __DIR__ . '/forward_validation_panel.php';
include __DIR__ . '/base_win_rate_panel_core.php';

// 展示後限定のAI展開予想（試験）。
// prototype v3 と同じ考え方で、今回確率 / 場平均 / 差を表示する。
include __DIR__ . '/ai_tenkai_trial_panel.php';

// 選手SUM特性はここで生成し、PCではDOMContentLoaded後に
// 「展示サム理論（レース適用値）」の直下へ表示だけ移動する。
$playerSamMode = 'pc';
include __DIR__ . '/player_sam_panel.php';
include __DIR__ . '/player_sam_cross_panel.php';
include __DIR__ . '/player_sam_ui_enhancements.php';

// 選択場の直近5開催日（最大60R）の現行Web予想×実結果。
// 重い過去再計算は大タブを開いた時だけAPI経由で実行する。
include __DIR__ . '/recent_prediction_history_panel.php';
?>
<link rel="stylesheet" href="/web/assets/css/pc_trifecta_tools.css?v=20260826c">
<script src="/web/assets/js/pc_trifecta_cleanup.js?v=20260901a"></script>

<!-- PC Webは役割別タブへ段階的に再整理する。 -->
<link rel="stylesheet" href="/web/assets/css/pc_main_tabs.css?v=20260912a">
<script src="/web/assets/js/pc_main_tabs.js?v=20260912a"></script>
<script src="/web/assets/js/pc_rate_development_tab.js?v=20260913c"></script>
<script src="/web/assets/js/pc_head1_second_merge.js?v=20260913c"></script>
<script src="/web/assets/js/pc_ai_prediction_cleanup.js?v=20260913a"></script>
<script src="/web/assets/js/pc_exacta_tab.js?v=20260901a"></script>
<script src="/web/assets/js/pc_bet_simulator_v3.js?v=20260901a"></script>
<script src="/web/assets/js/live_trifecta_top2_strategy.js?v=20260901b"></script>

<script>
// BOATERSのように「何を見るタブか」が分かる大分類へ、まずPC Webだけ段階的に整理する。
// 既存計算や既存パネルは壊さず、DOMの表示先だけを分ける。
document.addEventListener('DOMContentLoaded', function () {
    let retry = 80;

    function setupRoleTabs() {
        const tabs = document.querySelector('.pc-main-tabs');
        const basicButton = tabs ? tabs.querySelector('[data-pc-main-tab="basic"]') : null;
        const mainButton = tabs ? tabs.querySelector('[data-pc-main-tab="main"]') : null;
        const otherButton = tabs ? tabs.querySelector('[data-pc-main-tab="other"]') : null;
        const basicPanel = document.querySelector('.pc-main-tab-panel[data-pc-main-panel="basic"]');
        const mainPanel = document.querySelector('.pc-main-tab-panel[data-pc-main-panel="main"]');

        if (!tabs || !basicButton || !mainButton || !otherButton || !basicPanel || !mainPanel) {
            if (retry-- > 0) window.setTimeout(setupRoleTabs, 40);
            return;
        }
        if (tabs.querySelector('[data-pc-main-tab="player"]')) return;

        basicButton.textContent = '場・出走・展示';
        mainButton.textContent = 'AI予想';

        const playerButton = document.createElement('button');
        playerButton.type = 'button';
        playerButton.className = 'pc-main-tab';
        playerButton.dataset.pcMainTab = 'player';
        playerButton.textContent = '連対率・展開';
        tabs.insertBefore(playerButton, mainButton);

        const playerPanel = document.createElement('div');
        playerPanel.className = 'pc-main-tab-panel';
        playerPanel.dataset.pcMainPanel = 'player';
        playerPanel.hidden = true;
        mainPanel.insertAdjacentElement('beforebegin', playerPanel);

        function moveRolePanels() {
            // 現行Webとの場相性 / R別予想相性 / 基本特性は
            // BOATERS風の整理に合わせて「場・出走・展示」側へ置く。
            const stadium = document.querySelector('[id^="stadium-characteristics-tabs-pc-"]');
            if (stadium && stadium.parentElement !== basicPanel) {
                basicPanel.insertBefore(stadium, basicPanel.firstChild);
            }

            const cross = document.getElementById('player-sam-cross-panel');
            const playerSam = document.getElementById('player-sam-panel');

            // SUM関連の比較・選手特性は判断材料として、いったん「AI予想」へまとめる。
            if (cross && cross.parentElement !== mainPanel) mainPanel.appendChild(cross);
            if (playerSam && playerSam.parentElement !== mainPanel) mainPanel.appendChild(playerSam);

            // 多摩川コースサインは展開材料なので「連対率・展開」へ。
            const tmgSignal = document.querySelector('.tmg-lane4-detail-signal');
            if (tmgSignal && tmgSignal.parentElement !== playerPanel) {
                playerPanel.insertBefore(tmgSignal, playerPanel.firstChild);
            }
        }

        function activatePlayer() {
            Array.from(tabs.querySelectorAll('.pc-main-tab')).forEach(function (button) {
                const active = button.dataset.pcMainTab === 'player';
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            Array.from(document.querySelectorAll('.pc-main-tab-panel')).forEach(function (panel) {
                const active = panel.dataset.pcMainPanel === 'player';
                panel.classList.toggle('is-active', active);
                panel.hidden = !active;
            });
            try { sessionStorage.setItem('boatracePcMainTab', 'player'); } catch (e) {}
        }

        playerButton.addEventListener('click', activatePlayer);

        // pc_main_tabs.js側の列数調整MutationObserverが反応するが、念のため即時にも合わせる。
        const count = tabs.querySelectorAll('.pc-main-tab').length;
        tabs.style.gridTemplateColumns = 'repeat(' + count + ', minmax(0, 1fr))';

        moveRolePanels();
        window.setTimeout(moveRolePanels, 160);
        window.setTimeout(moveRolePanels, 500);

        const observer = new MutationObserver(moveRolePanels);
        observer.observe(document.querySelector('.container') || document.body, {childList: true, subtree: true});

        try {
            if (sessionStorage.getItem('boatracePcMainTab') === 'player') {
                activatePlayer();
            }
        } catch (e) {}
    }

    window.setTimeout(setupRoleTabs, 0);
});
</script>