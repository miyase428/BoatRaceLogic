(function () {
    'use strict';

    function playerPanel() {
        return document.querySelector('.pc-main-tab-panel[data-pc-main-panel="player"]');
    }

    function findTableByRowLabel(label) {
        const tables = Array.from(document.querySelectorAll('table'));
        return tables.find(function (table) {
            if (table.closest('#pc-rate-development-summary')) return false;
            return Array.from(table.querySelectorAll('tbody tr')).some(function (row) {
                return String(row.cells[0] ? row.cells[0].textContent : '').trim() === label;
            });
        }) || null;
    }

    function cloneRowByLabel(label) {
        const table = findTableByRowLabel(label);
        if (!table) return null;
        const row = Array.from(table.querySelectorAll('tbody tr')).find(function (item) {
            return String(item.cells[0] ? item.cells[0].textContent : '').trim() === label;
        });
        if (!row) return null;
        const clone = row.cloneNode(true);
        clone.style.display = '';
        return clone;
    }

    function buildRateSummary(panel) {
        let card = document.getElementById('pc-rate-development-summary');
        if (card) return card;

        const headerSource = findTableByRowLabel('補正後1着率') || findTableByRowLabel('AI3連対率');
        if (!headerSource) return null;

        card = document.createElement('div');
        card.id = 'pc-rate-development-summary';
        card.style.cssText = 'margin:12px 0 14px;background:#fffaf2;border:1px solid #d8cdbc;border-radius:8px;padding:14px;color:#334155;';

        const title = document.createElement('div');
        title.style.cssText = 'font-size:16px;font-weight:800;color:#75659b;margin-bottom:4px;';
        title.textContent = '📊 連対率・勝率';
        card.appendChild(title);

        const note = document.createElement('div');
        note.style.cssText = 'font-size:11px;color:#6b7785;margin-bottom:10px;line-height:1.6;';
        note.textContent = '既存の1着率・AI3連対率を、連対率を見るための一覧に集約。表示整理のみで予想ロジックは変更していません。';
        card.appendChild(note);

        const scroll = document.createElement('div');
        scroll.style.overflowX = 'auto';
        const table = document.createElement('table');
        table.style.cssText = 'width:100%;min-width:760px;border-collapse:collapse;';

        const sourceHead = headerSource.querySelector('thead');
        if (sourceHead) table.appendChild(sourceHead.cloneNode(true));

        const tbody = document.createElement('tbody');
        ['基本1着率', '補正後1着率', '基礎3連対率', 'AI3連対率'].forEach(function (label) {
            const row = cloneRowByLabel(label);
            if (row) tbody.appendChild(row);
        });
        if (!tbody.children.length) return null;
        table.appendChild(tbody);
        scroll.appendChild(table);
        card.appendChild(scroll);
        panel.appendChild(card);
        return card;
    }

    function findCardByExactTitle(titleText) {
        const title = Array.from(document.querySelectorAll('div')).find(function (node) {
            return node.children.length === 0 && String(node.textContent || '').trim() === titleText;
        });
        if (!title) return null;

        let node = title.parentElement;
        while (node && node !== document.body) {
            if (node.tagName === 'DIV' && node.querySelector('table')) return node;
            node = node.parentElement;
        }
        return null;
    }

    function kimariteRows() {
        const matrix = document.querySelector('.matrix-table');
        if (!matrix || !matrix.tBodies.length) return null;

        const rows = Array.from(matrix.tBodies[0].rows);
        const yearTitle = rows.find(function (row) {
            return String(row.textContent || '').includes('決まり手（直近1年）');
        });
        const halfTitle = rows.find(function (row) {
            return String(row.textContent || '').includes('決まり手（直近6ヶ月）');
        });
        if (!yearTitle || !halfTitle) return null;

        const yearIndex = rows.indexOf(yearTitle);
        const halfIndex = rows.indexOf(halfTitle);
        if (yearIndex < 0 || halfIndex <= yearIndex) return null;

        return {
            matrix: matrix,
            yearTitle: yearTitle,
            halfTitle: halfTitle,
            yearGroup: rows.slice(yearIndex + 1, halfIndex).slice(0, 5),
            halfGroup: rows.slice(halfIndex + 1).slice(0, 5),
            tabRow: matrix.querySelector('.kimarite-period-tabs')
        };
    }

    function buildKimariteSummary(panel) {
        let card = document.getElementById('pc-rate-development-kimarite');
        if (card) return card;

        const source = kimariteRows();
        if (!source) return null;

        const selected = [source.yearTitle]
            .concat(source.yearGroup)
            .concat([source.halfTitle])
            .concat(source.halfGroup);

        card = document.createElement('div');
        card.id = 'pc-rate-development-kimarite';
        card.style.cssText = 'margin:12px 0 14px;background:#fffaf2;border:1px solid #d8cdbc;border-radius:8px;padding:12px;color:#334155;';

        const title = document.createElement('div');
        title.style.cssText = 'font-size:15px;font-weight:800;color:#75659b;margin-bottom:8px;';
        title.textContent = '🎯 決まり手傾向';
        card.appendChild(title);

        const note = document.createElement('div');
        note.style.cssText = 'font-size:11px;color:#6b7785;margin-bottom:8px;';
        note.textContent = '今回進入コース基準。直近1年と直近6ヶ月を同時表示。';
        card.appendChild(note);

        const scroll = document.createElement('div');
        scroll.style.overflowX = 'auto';
        const table = document.createElement('table');
        table.style.cssText = 'width:100%;min-width:760px;border-collapse:collapse;font-size:12px;';
        const tbody = document.createElement('tbody');
        selected.forEach(function (row) {
            const clone = row.cloneNode(true);
            clone.style.display = '';
            tbody.appendChild(clone);
        });
        table.appendChild(tbody);
        scroll.appendChild(table);
        card.appendChild(scroll);
        panel.appendChild(card);
        return card;
    }

    function hideOriginalKimarite() {
        const source = kimariteRows();
        if (!source) return;

        // 元の総合マトリクスは既存ロジック参照用にDOMへ残し、画面上だけ隠す。
        // 決まり手は「連対率・展開」側の複製カードだけを表示する。
        [source.tabRow, source.yearTitle, source.halfTitle]
            .concat(source.yearGroup, source.halfGroup)
            .filter(Boolean)
            .forEach(function (row) {
                row.style.display = 'none';
            });
    }

    function arrange() {
        const panel = playerPanel();
        if (!panel) return false;

        const rateCard = buildRateSummary(panel);
        const head1Card = findCardByExactTitle('🎯 1号艇1着時の2着率');
        const head1ExactaCard = document.getElementById('head1-exacta-panel');
        const kimariteCard = buildKimariteSummary(panel);
        const aiTenkaiCard = document.getElementById('ai-tenkai-trial-panel');

        // 「連対率 → 1号艇1着時2着率 → イン1着時2連単 → 決まり手 → AI展開予想」の順。
        // 基本2着率と今回AIの2着分布を続けて比較できるよう、2連単カードを直下へ置く。
        if (rateCard) panel.appendChild(rateCard);
        if (head1Card) panel.appendChild(head1Card);
        if (head1ExactaCard) {
            head1ExactaCard.style.marginTop = '0';
            panel.appendChild(head1ExactaCard);
        }
        if (kimariteCard) panel.appendChild(kimariteCard);
        if (aiTenkaiCard) panel.appendChild(aiTenkaiCard);

        // 総合出走・展示マトリクス側の決まり手は重複表示しない。
        hideOriginalKimarite();

        return !!(rateCard || head1Card || head1ExactaCard || kimariteCard || aiTenkaiCard);
    }

    function schedule() {
        let tries = 50;
        function run() {
            if (arrange()) {
                window.setTimeout(arrange, 180);
                window.setTimeout(arrange, 600);
                return;
            }
            if (tries-- > 0) window.setTimeout(run, 60);
        }
        run();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            window.setTimeout(schedule, 20);
        });
    } else {
        window.setTimeout(schedule, 20);
    }
})();
