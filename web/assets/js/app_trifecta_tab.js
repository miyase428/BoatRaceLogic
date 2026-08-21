(function () {
    'use strict';

    const STORAGE_KEY = 'boatraceAppTabEnhanced';

    function parsePayload() {
        const node = document.getElementById('app-trifecta-data');
        if (!node) return null;
        try {
            return JSON.parse(node.textContent || '{}');
        } catch (e) {
            return null;
        }
    }

    function number(value) {
        const parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function normalizeSearch(value) {
        return String(value || '')
            .trim()
            .replace(/[１-６]/g, function (char) {
                return String(char.charCodeAt(0) - 0xFEE0);
            })
            .replace(/[－–—→>\s]+/g, '-')
            .replace(/[^1-6-]/g, '')
            .replace(/-+/g, '-')
            .replace(/^-|-$/g, '');
    }

    function boatKey(row) {
        const boats = Array.isArray(row.boats) ? row.boats : [];
        return boats.map(function (boat) { return Number(boat); }).join('-');
    }

    function sumTop(rows, n) {
        return rows
            .slice()
            .sort(function (a, b) { return number(a.rank) - number(b.rank); })
            .slice(0, n)
            .reduce(function (sum, row) { return sum + number(row.probability); }, 0);
    }

    function makeBoatBadge(boat) {
        const span = document.createElement('span');
        span.className = 'app-trifecta-boat app-trifecta-boat-' + String(boat);
        span.textContent = String(boat);
        return span;
    }

    function makeCombination(row) {
        const wrap = document.createElement('span');
        wrap.className = 'app-trifecta-combination';
        const boats = Array.isArray(row.boats) ? row.boats : [];

        boats.forEach(function (boat, index) {
            if (index > 0) {
                const sep = document.createElement('span');
                sep.className = 'app-trifecta-separator';
                sep.textContent = '-';
                wrap.appendChild(sep);
            }
            wrap.appendChild(makeBoatBadge(Number(boat)));
        });
        return wrap;
    }

    function setup() {
        const payload = parsePayload();
        const tabs = document.querySelector('.app-tabs');
        const mainPanel = document.querySelector('.app-tab-panel[data-panel="main"]');
        if (!payload || !tabs || !mainPanel || tabs.querySelector('[data-tab="trifecta"]')) return;

        const rows = Array.isArray(payload.rows) ? payload.rows.slice() : [];
        const status = String(payload.status || 'error');
        const error = String(payload.error || '');
        const history = payload.history && typeof payload.history === 'object' ? payload.history : {};
        const totals = payload.totals && typeof payload.totals === 'object' ? payload.totals : {};

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'app-tab';
        button.dataset.tab = 'trifecta';
        button.textContent = '120通り';
        tabs.appendChild(button);

        const panel = document.createElement('div');
        panel.className = 'app-tab-panel app-trifecta-panel';
        panel.dataset.panel = 'trifecta';
        panel.hidden = true;
        mainPanel.insertAdjacentElement('afterend', panel);

        function activate(name) {
            document.querySelectorAll('.app-tab').forEach(function (tabButton) {
                tabButton.classList.toggle('is-active', tabButton.dataset.tab === name);
            });
            document.querySelectorAll('.app-tab-panel').forEach(function (tabPanel) {
                const active = tabPanel.dataset.panel === name;
                tabPanel.classList.toggle('is-active', active);
                tabPanel.hidden = !active;
            });
            try { sessionStorage.setItem(STORAGE_KEY, name); } catch (e) {}
        }

        document.querySelectorAll('.app-tab').forEach(function (tabButton) {
            tabButton.addEventListener('click', function () {
                activate(tabButton.dataset.tab || 'basic');
            });
        });

        if (status !== 'ok' || rows.length !== 120) {
            const card = document.createElement('section');
            card.className = 'app-card';
            card.innerHTML = '<div class="app-card-body">'
                + '<h2 class="app-section-title">🎲 3連単120通り 出目確率</h2>'
                + '<div class="app-note app-trifecta-error"></div>'
                + '</div>';
            const note = card.querySelector('.app-trifecta-error');
            if (note) note.textContent = error || '出目確率は計算待ちです。';
            panel.appendChild(card);
        } else {
            buildPanel(panel, rows, history, totals);
        }

        let saved = '';
        try { saved = sessionStorage.getItem(STORAGE_KEY) || ''; } catch (e) {}
        if (saved === 'basic' || saved === 'main' || saved === 'trifecta') {
            activate(saved);
        }
    }

    function buildPanel(panel, rows, history, totals) {
        const card = document.createElement('section');
        card.className = 'app-card app-trifecta-card';
        card.innerHTML = '<div class="app-card-body app-trifecta-heading">'
            + '<h2 class="app-section-title">🎲 3連単120通り 出目確率</h2>'
            + '<div class="app-note">PC版と同じ「順位・3連単・基礎出目・最終出目確率・基礎差・累計」を表示します。</div>'
            + '</div>'
            + '<div class="app-trifecta-stats"></div>'
            + '<div class="app-card-body app-trifecta-controls">'
            + '  <label class="app-trifecta-search-label">買い目検索'
            + '    <input class="app-trifecta-search" type="text" inputmode="numeric" autocomplete="off" placeholder="例 1 / 1-2 / 1-2-3">'
            + '  </label>'
            + '  <div class="app-trifecta-position-filters"></div>'
            + '  <div class="app-trifecta-control-row">'
            + '    <span class="app-trifecta-count"></span>'
            + '    <button type="button" class="app-trifecta-clear">クリア</button>'
            + '  </div>'
            + '</div>'
            + '<div class="app-trifecta-table-wrap">'
            + '  <table class="app-trifecta-table">'
            + '    <thead><tr>'
            + '      <th><button type="button" data-sort="rank">順位</button></th>'
            + '      <th><button type="button" data-sort="combination">3連単</button></th>'
            + '      <th><button type="button" data-sort="base">基礎出目</button></th>'
            + '      <th><button type="button" data-sort="final">最終出目確率</button></th>'
            + '      <th><button type="button" data-sort="delta">基礎差</button></th>'
            + '      <th><button type="button" data-sort="cumulative">累計</button></th>'
            + '    </tr></thead>'
            + '    <tbody></tbody>'
            + '  </table>'
            + '</div>'
            + '<div class="app-card-body app-trifecta-foot"></div>';
        panel.appendChild(card);

        const stats = card.querySelector('.app-trifecta-stats');
        const statValues = [
            ['Top5累計', (sumTop(rows, 5) * 100).toFixed(2) + '%'],
            ['Top10累計', (sumTop(rows, 10) * 100).toFixed(2) + '%'],
            ['Top20累計', (sumTop(rows, 20) * 100).toFixed(2) + '%'],
            ['場履歴', Math.round(number(history.venue_n)).toLocaleString('ja-JP') + 'R']
        ];
        statValues.forEach(function (item) {
            const div = document.createElement('div');
            div.className = 'app-trifecta-stat';
            const label = document.createElement('span');
            label.textContent = item[0];
            const strong = document.createElement('strong');
            strong.textContent = item[1];
            div.appendChild(label);
            div.appendChild(strong);
            stats.appendChild(div);
        });

        const positionFilters = card.querySelector('.app-trifecta-position-filters');
        const selected = [0, 0, 0];
        ['1着', '2着', '3着'].forEach(function (label, index) {
            const group = document.createElement('div');
            group.className = 'app-trifecta-filter-group';

            const title = document.createElement('span');
            title.className = 'app-trifecta-filter-label';
            title.textContent = label;
            group.appendChild(title);

            const all = document.createElement('button');
            all.type = 'button';
            all.className = 'app-trifecta-filter is-active';
            all.dataset.position = String(index);
            all.dataset.boat = '0';
            all.textContent = '全';
            group.appendChild(all);

            for (let boat = 1; boat <= 6; boat++) {
                const filter = document.createElement('button');
                filter.type = 'button';
                filter.className = 'app-trifecta-filter';
                filter.dataset.position = String(index);
                filter.dataset.boat = String(boat);
                filter.textContent = String(boat);
                group.appendChild(filter);
            }
            positionFilters.appendChild(group);
        });

        const search = card.querySelector('.app-trifecta-search');
        const count = card.querySelector('.app-trifecta-count');
        const clear = card.querySelector('.app-trifecta-clear');
        const tbody = card.querySelector('tbody');
        const sortButtons = Array.from(card.querySelectorAll('th button[data-sort]'));
        const foot = card.querySelector('.app-trifecta-foot');
        let sortKey = 'rank';
        let sortDirection = 1;

        function compareRows(a, b) {
            let av;
            let bv;
            switch (sortKey) {
                case 'combination':
                    av = number((a.boats || [])[0]) * 100 + number((a.boats || [])[1]) * 10 + number((a.boats || [])[2]);
                    bv = number((b.boats || [])[0]) * 100 + number((b.boats || [])[1]) * 10 + number((b.boats || [])[2]);
                    break;
                case 'base':
                    av = number(a.base_probability); bv = number(b.base_probability); break;
                case 'final':
                    av = number(a.probability); bv = number(b.probability); break;
                case 'delta':
                    av = number(a.probability) - number(a.base_probability);
                    bv = number(b.probability) - number(b.base_probability);
                    break;
                case 'cumulative':
                    av = number(a.cumulative_probability); bv = number(b.cumulative_probability); break;
                case 'rank':
                default:
                    av = number(a.rank); bv = number(b.rank); break;
            }
            if (av === bv) return number(a.rank) - number(b.rank);
            return (av < bv ? -1 : 1) * sortDirection;
        }

        function filteredRows() {
            const query = normalizeSearch(search ? search.value : '');
            return rows.filter(function (row) {
                const boats = Array.isArray(row.boats) ? row.boats.map(Number) : [];
                if (boats.length !== 3) return false;
                for (let i = 0; i < 3; i++) {
                    if (selected[i] > 0 && boats[i] !== selected[i]) return false;
                }
                if (!query) return true;
                const key = boatKey(row);
                return key === query || key.indexOf(query + '-') === 0;
            }).sort(compareRows);
        }

        function updateSortLabels() {
            sortButtons.forEach(function (sortButton) {
                const base = sortButton.textContent.replace(/[▲▼]$/, '').trim();
                sortButton.textContent = base;
                sortButton.classList.toggle('is-active', sortButton.dataset.sort === sortKey);
                if (sortButton.dataset.sort === sortKey) {
                    sortButton.textContent = base + (sortDirection > 0 ? ' ▲' : ' ▼');
                }
            });
        }

        function render() {
            const current = filteredRows();
            tbody.textContent = '';

            current.forEach(function (row) {
                const tr = document.createElement('tr');
                const base = number(row.base_probability);
                const final = number(row.probability);
                const delta = final - base;
                const cumulative = number(row.cumulative_probability);

                const rankCell = document.createElement('td');
                rankCell.className = 'app-trifecta-rank';
                rankCell.textContent = String(Math.round(number(row.rank)));
                tr.appendChild(rankCell);

                const combinationCell = document.createElement('td');
                combinationCell.className = 'app-trifecta-combo-cell';
                combinationCell.appendChild(makeCombination(row));
                tr.appendChild(combinationCell);

                const baseCell = document.createElement('td');
                baseCell.textContent = (base * 100).toFixed(3) + '%';
                tr.appendChild(baseCell);

                const finalCell = document.createElement('td');
                finalCell.className = 'app-trifecta-final';
                finalCell.textContent = (final * 100).toFixed(3) + '%';
                tr.appendChild(finalCell);

                const deltaCell = document.createElement('td');
                deltaCell.className = delta >= 0 ? 'app-trifecta-delta-plus' : 'app-trifecta-delta-minus';
                deltaCell.textContent = (delta >= 0 ? '+' : '') + (delta * 100).toFixed(3) + 'pt';
                tr.appendChild(deltaCell);

                const cumulativeCell = document.createElement('td');
                cumulativeCell.textContent = (cumulative * 100).toFixed(2) + '%';
                tr.appendChild(cumulativeCell);

                tbody.appendChild(tr);
            });

            if (count) count.textContent = current.length + ' / 120件';
            updateSortLabels();
        }

        positionFilters.addEventListener('click', function (event) {
            const target = event.target.closest('.app-trifecta-filter');
            if (!target) return;
            const position = Number(target.dataset.position);
            const boat = Number(target.dataset.boat);
            selected[position] = boat;
            target.parentElement.querySelectorAll('.app-trifecta-filter').forEach(function (filter) {
                filter.classList.toggle('is-active', filter === target);
            });
            render();
        });

        if (search) search.addEventListener('input', render);

        if (clear) {
            clear.addEventListener('click', function () {
                if (search) search.value = '';
                selected[0] = selected[1] = selected[2] = 0;
                card.querySelectorAll('.app-trifecta-filter-group').forEach(function (group) {
                    group.querySelectorAll('.app-trifecta-filter').forEach(function (filter) {
                        filter.classList.toggle('is-active', filter.dataset.boat === '0');
                    });
                });
                sortKey = 'rank';
                sortDirection = 1;
                render();
            });
        }

        sortButtons.forEach(function (sortButton) {
            sortButton.addEventListener('click', function () {
                const nextKey = sortButton.dataset.sort || 'rank';
                if (sortKey === nextKey) {
                    sortDirection *= -1;
                } else {
                    sortKey = nextKey;
                    sortDirection = (nextKey === 'rank' || nextKey === 'combination') ? 1 : -1;
                }
                render();
            });
        });

        if (foot) {
            foot.textContent = '120通り合計 ' + (number(totals.final) * 100).toFixed(6) + '% / P1選択 → P2完全ホールドアウト検証済み';
        }

        render();
    }

    function boot() {
        window.setTimeout(setup, 0);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
