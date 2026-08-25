(function () {
    'use strict';

    function cleanup() {
        const panel = document.getElementById('trifecta-reference-panel');
        if (!panel) return;

        const tables = Array.from(panel.querySelectorAll('table'));
        tables.forEach(function (table) {
            const tbody = table.tBodies && table.tBodies[0];
            if (!tbody) return;

            // PC版は検索・絞り込み・ソート付き120通り表を本体とする。
            // 旧Top20プレビュー表だけ非表示にして、同じ内容の二重表示を避ける。
            if (tbody.rows.length === 20) {
                const wrap = table.parentElement;
                if (wrap) wrap.style.display = 'none';
            }
        });

        // 外側の「参考情報：3連単120通り 出目確率」自体が折りたためるため、
        // 内側の「120通りすべて表示」は二重折りたたみになる。
        // 内側は常時展開し、summaryだけ削除して検索・絞り込み・120通り表を直接表示する。
        const allDetails = document.getElementById('trifecta-all-details');
        if (allDetails) {
            allDetails.open = true;
            const summary = allDetails.querySelector(':scope > summary');
            if (summary) summary.remove();
            allDetails.style.marginTop = '10px';
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', cleanup);
    } else {
        cleanup();
    }
})();
