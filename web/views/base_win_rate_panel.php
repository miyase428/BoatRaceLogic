<?php
// 場特性関連は5タブにまとめ、その下で前向き実戦検証を記録してから既存1着率パネルを表示する。
$stadiumCharacteristicsMode = 'pc';
include __DIR__ . '/stadium_characteristics_tabs.php';
$forwardValidationMode = 'pc';
include __DIR__ . '/forward_validation_panel.php';
include __DIR__ . '/base_win_rate_panel_core.php';

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

<!-- PC Webは大分類「基本情報 / メイン情報 / その他 / 2連単 / 120通り / 買い目 / 直近60R」で切り替える。 -->
<link rel="stylesheet" href="/web/assets/css/pc_main_tabs.css?v=20260912a">
<script src="/web/assets/js/pc_main_tabs.js?v=20260912a"></script>
<script src="/web/assets/js/pc_exacta_tab.js?v=20260901a"></script>
<script src="/web/assets/js/pc_bet_simulator_v3.js?v=20260901a"></script>
<script src="/web/assets/js/live_trifecta_top2_strategy.js?v=20260901b"></script>

<script>
// PC Webでは決まり手の「直近1年 / 直近6ヶ月」を切替式にせず、上下に同時表示する。
// 元の集計・決まり手ロジックには触れず、表示だけ上書きする。
document.addEventListener('DOMContentLoaded', function () {
    window.setTimeout(function () {
        const matrix = document.querySelector('.matrix-table');
        if (!matrix || !matrix.tBodies.length) return;

        const rows = Array.from(matrix.tBodies[0].rows);
        const yearTitle = rows.find(function (row) {
            return String(row.textContent || '').includes('決まり手（直近1年）');
        });
        const halfTitle = rows.find(function (row) {
            return String(row.textContent || '').includes('決まり手（直近6ヶ月）');
        });
        if (!yearTitle || !halfTitle) return;

        const yearIndex = rows.indexOf(yearTitle);
        const halfIndex = rows.indexOf(halfTitle);
        if (yearIndex < 0 || halfIndex <= yearIndex) return;

        // 既存の期間切替タブは非表示。
        const tabRow = matrix.querySelector('.kimarite-period-tabs');
        if (tabRow) tabRow.style.display = 'none';

        // 各期間の見出しと、決まり手5行（列見出し + 4項目）を同時表示。
        yearTitle.style.display = '';
        halfTitle.style.display = '';
        rows.slice(yearIndex + 1, halfIndex).slice(0, 5).forEach(function (row) {
            row.style.display = '';
        });
        rows.slice(halfIndex + 1).slice(0, 5).forEach(function (row) {
            row.style.display = '';
        });
    }, 0);
});
</script>