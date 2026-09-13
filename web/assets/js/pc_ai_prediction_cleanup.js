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

    function findSlitCard() {
        const heading = Array.from(document.querySelectorAll('h2')).find(function (node) {
            return String(node.textContent || '').trim() === '📊 スリット体系';
        });
        return heading ? heading.parentElement : null;
    }

    function hideSamMaster() {
        const heading = Array.from(document.querySelectorAll('h2')).find(function (node) {
            return String(node.textContent || '').trim() === '📐 サム理論（コース・区間別マスタ）';
        });
        const toggle = document.getElementById('toggle-sam');
        const block = document.getElementById('sam-block');

        if (heading) heading.style.display = 'none';
        if (toggle) toggle.style.display = 'none';
        if (block) block.style.display = 'none';

        return !!(heading || toggle || block);
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

        // 最近は実戦で参照していないため、PC Webではスリット体系も表示だけ隠す。
        // 判定・計算データはそのまま残す。
        const slitCard = findSlitCard();
        if (slitCard) slitCard.style.display = 'none';

        // コース・区間別のSUMマスタは参照頻度が低いため、PC Webでは見出し・切替・本体を非表示。
        // 展示SUMのレース適用値や選手SUMなど、実戦用のSUM表示は残す。
        const samMasterHidden = hideSamMaster();

        return !!(winRateCard || trioRateCard || slitCard || samMasterHidden);
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
