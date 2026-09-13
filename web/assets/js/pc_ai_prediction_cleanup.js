(function () {
    'use strict';

    function findCardByExactTitle(panel, titleText) {
        if (!panel) return null;

        const title = Array.from(panel.querySelectorAll('div')).find(function (node) {
            return node.children.length === 0
                && String(node.textContent || '').trim() === titleText;
        });
        if (!title) return null;

        let node = title.parentElement;
        while (node && node !== panel) {
            if (node.tagName === 'DIV' && node.querySelector('table')) {
                return node;
            }
            node = node.parentElement;
        }
        return null;
    }

    function cleanup() {
        const aiPanel = document.querySelector('.pc-main-tab-panel[data-pc-main-panel="main"]');
        if (!aiPanel) return false;

        // 「連対率・展開」の連対率・勝率カードへ同じ内容を集約済みなので、
        // AI予想側では重複する元カードだけを非表示にする。
        // DOM自体は残すため、既存計算や他JSの参照には影響させない。
        const winRateCard = findCardByExactTitle(aiPanel, '🎯 1着率');
        const trioRateCard = findCardByExactTitle(aiPanel, '🤖 AI3連対率');

        if (winRateCard) winRateCard.style.display = 'none';
        if (trioRateCard) trioRateCard.style.display = 'none';

        return !!(winRateCard || trioRateCard);
    }

    function schedule() {
        let tries = 80;
        function run() {
            cleanup();
            if (tries-- > 0) {
                window.setTimeout(run, 80);
            }
        }
        run();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            window.setTimeout(schedule, 40);
        });
    } else {
        window.setTimeout(schedule, 40);
    }
})();
