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
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', cleanup);
    } else {
        cleanup();
    }
})();
