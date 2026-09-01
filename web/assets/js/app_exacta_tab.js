(function () {
    'use strict';

    const STORAGE_KEY = 'boatraceAppTabEnhanced';

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

    function parsePayload() {
        const node = document.getElementById('app-trifecta-data');
        if (!node) return null;
        try {
            return JSON.parse(node.textContent || '{}');
        } catch (e) {
            return null;
        }
    }

    function deriveExactaRows(trifectaRows) {
        const map = new Map();
        (Array.isArray(trifectaRows) ? trifectaRows : []).forEach(function (row) {
            const boats = Array.isArray(row.boats) ? row.boats.map(Number) : [];
            if (boats.length !== 3 || boats[0] === boats[1]) return;

            const key = boats[0] + '-' + boats[1];
            if (!map.has(key)) {
                map.set(key, {
                    first: boats[0],
                    second: boats[1],
                    base_probability: 0,
                    probability: 0,
                    official_odds: null,
                    rank: 0,
                    cumulative_probability: 0
                });
            }

            const exacta = map.get(key);
            exacta.base_probability += number(row.base_probability);
            exacta.probability += number(row.probability);
        });

        const rows = Array.from(map.values());
        rows.sort(function (a, b) {
            if (a.probability === b.probability) {
                return (a.first * 10 + a.second) - (b.first * 10 + b.second);
            }
            return b.probability - a.probability;
        });

        let cumulative = 0;
        rows.forEach(function (row, index) {
            row.rank = index + 1;
            cumulative += row.probability;
            row.cumulative_probability = cumulative;
        });
        return rows.length === 30 ? rows : [];
    }

    function boatKey(row) {
        return String(row.first) + '-' + String(row.second);
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
        wrap.appendChild(makeBoatBadge(row.first));
        const sep = document.createElement('span');
        sep.className = 'app-trifecta-separator';
        sep.textContent = '-';
        wrap.appendChild(sep);
        wrap.appendChild(makeBoatBadge(row.second));
        return wrap;
    }

    function waitForTabs(callback, retry) {
        const tabs = document.querySelector('.app-tabs');
        const trifectaPanel = document.querySelector('.app-tab-panel[data-panel="trifecta"]');
        const recentButton = tabs ? tabs.querySelector('[data-tab="recent"]') : null;
        if (tabs && trifectaPanel && recentButton) {
            callback(tabs, trifectaPanel, recentButton);
            return;
        }
        if (retry <= 0) return;
        window.setTimeout(function () {
            waitForTabs(callback, retry - 1);
        }, 40);
    }

    function setup() {
        const payload = parsePayload();
        const trifectaRows = payload && Array.isArray(payload.rows) ? payload.rows : [];
        const rows = deriveExactaRows(trifectaRows);
        if (rows.length !== 30) return;

        waitForTabs(function (tabs, trifectaPanel, recentButton) {
            if (tabs.querySelector('[data-tab="exacta"]')) return;

            tabs.style.gridTemplateColumns = 'repeat(5, minmax(0, 1fr))';

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'app-tab';
            button.dataset.tab = 'exacta';
            button.textContent = '2連単';
            const trifectaButton = tabs.querySelector('[data-tab="trifecta"]');
            if (trifectaButton) {
                tabs.insertBefore(button, trifectaButton);
            } else {
                tabs.insertBefore(button, recentButton);
            }

            const panel = document.createElement('div');
            panel.className = 'app-tab-panel app-trifecta-panel app-exacta-panel';
            panel.dataset.panel = 'exacta';
            panel.hidden = true;
            trifectaPanel.insertAdjacentElement('beforebegin', panel);

            panel.innerHTML = ''
                + '<section class="app-card app-trifecta-card">'
                + '  <div class="app-card-body app-trifecta-heading">'
                + '    <h2 class="app-section-title">🎯 2連単30通り 出目確率</h2>'
                + '    <div class="app-note">3連単120通りの最終出目確率を1着-2着ごとに合算して30通りへ集約。</div>'
                + '    <div class="app-exacta-odds-bar" style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-top:8px;padding:7px 8px;border:1px solid #d6d3cd;border-radius:6px;background:#fffaf2;">'
                + '      <span class="app-exacta-odds-status" style="font-size:11px;color:#6b7785;">公式2連単オッズ：取得中…</span>'
                + '      <button type="button" class="app-exacta-refresh" style="padding:5px 9px;border:1px solid #1683bd;border-radius:5px;background:#fff;color:#1683bd;font-weight:bold;">更新</button>'
                + '    </div>'
                + '  </div>'
                + '  <div class="app-card-body app-trifecta-controls">'
                + '    <label class="app-trifecta-search-label">買い目検索'
                + '      <input class="app-exacta-search" type="text" inputmode="numeric" autocomplete="off" placeholder="例 1 / 1-2">'
                + '    </label>'
                + '    <div class="app-exacta-filters"></div>'
                + '    <div class="app-trifecta-control-row">'
                + '      <span class="app-exacta-count"></span>'
                + '      <button type="button" class="app-trifecta-clear app-exacta-clear">クリア</button>'
                + '    </div>'
                + '    <div class="app-exacta-summary" style="margin-top:8px;padding:8px;border:1px solid #d6d3cd;border-radius:6px;background:#fffaf2;font-size:12px;font-weight:bold;color:#3f4b5a;"></div>'
                + '  </div>'
                + '  <div class="app-trifecta-table-wrap">'
                + '    <table class="app-trifecta-table app-exacta-table">'
                + '      <thead><tr>'
                + '        <th><button type="button" data-sort="rank">順位</button></th>'
                + '        <th><button type="button" data-sort="combination">2連単</button></th>'
                + '        <th><button type="button" data-sort="base">基礎出目</button></th>'
                + '        <th><button type="button" data-sort="final">最終出目確率</button></th>'
                + '        <th><button type="button" data-sort="odds">オッズ</button></th>'
                + '        <th><button type="button" data-sort="delta">基礎差</button></th>'
                + '        <th><button type="button" data-sort="cumulative">累計</button></th>'
                + '      </tr></thead>'
                + '      <tbody></tbody>'
                + '    </table>'
                + '  </div>'
                + '  <div class="app-card-body app-trifecta-foot">30通り合計100% / オッズはBOAT RACE公式</div>'
                + '</section>';

            const search = panel.querySelector('.app-exacta-search');
            const filters = panel.querySelector('.app-exacta-filters');
            const count = panel.querySelector('.app-exacta-count');
            const summary = panel.querySelector('.app-exacta-summary');
            const clear = panel.querySelector('.app-exacta-clear');
            const tbody = panel.querySelector('tbody');
            const sortButtons = Array.from(panel.querySelectorAll('th button[data-sort]'));
            const oddsStatus = panel.querySelector('.app-exacta-odds-status');
            const refresh = panel.querySelector('.app-exacta-refresh');
            const selected = [new Set(), new Set()];
            let sortKey = 'rank';
            let sortDirection = 1;

            ['1着', '2着'].forEach(function (label, position) {
                const group = document.createElement('div');
                group.className = 'app-trifecta-filter-group app-exacta-filter-group';
                group.dataset.position = String(position);

                const title = document.createElement('span');
                title.className = 'app-trifecta-filter-label';
                title.textContent = label;
                group.appendChild(title);

                const all = document.createElement('button');
                all.type = 'button';
                all.className = 'app-trifecta-filter app-exacta-filter is-active';
                all.dataset.boat = '0';
                all.textContent = '全';
                group.appendChild(all);

                for (let boat = 1; boat <= 6; boat++) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'app-trifecta-filter app-exacta-filter';
                    btn.dataset.boat = String(boat);
                    btn.textContent = String(boat);
                    group.appendChild(btn);
                }
                filters.appendChild(group);
            });

            function updateFilterGroup(group, position) {
                group.querySelectorAll('.app-exacta-filter').forEach(function (filter) {
                    const boat = Number(filter.dataset.boat || 0);
                    const active = boat === 0 ? selected[position].size === 0 : selected[position].has(boat);
                    filter.classList.toggle('is-active', active);
                    filter.setAttribute('aria-pressed', active ? 'true' : 'false');
                });
            }

            function officialOdds(row) {
                const value = Number(row.official_odds);
                return Number.isFinite(value) && value > 0 ? value : null;
            }

            function compareRows(a, b) {
                let av;
                let bv;
                switch (sortKey) {
                    case 'combination': av = a.first * 10 + a.second; bv = b.first * 10 + b.second; break;
                    case 'base': av = a.base_probability; bv = b.base_probability; break;
                    case 'final': av = a.probability; bv = b.probability; break;
                    case 'odds':
                        av = officialOdds(a); bv = officialOdds(b);
                        if (av === null && bv === null) return a.rank - b.rank;
                        if (av === null) return 1;
                        if (bv === null) return -1;
                        break;
                    case 'delta': av = a.probability - a.base_probability; bv = b.probability - b.base_probability; break;
                    case 'cumulative': av = a.cumulative_probability; bv = b.cumulative_probability; break;
                    case 'rank':
                    default: av = a.rank; bv = b.rank; break;
                }
                if (av === bv) return a.rank - b.rank;
                return (av < bv ? -1 : 1) * sortDirection;
            }

            function filteredRows() {
                const query = normalizeSearch(search ? search.value : '');
                return rows.filter(function (row) {
                    if (selected[0].size > 0 && !selected[0].has(row.first)) return false;
                    if (selected[1].size > 0 && !selected[1].has(row.second)) return false;
                    if (!query) return true;
                    const key = boatKey(row);
                    return key === query || key.indexOf(query + '-') === 0 || String(row.first) === query;
                }).sort(compareRows);
            }

            function oddsText(value) {
                if (value === null) return '-';
                return value.toLocaleString('ja-JP', {maximumFractionDigits: 1});
            }

            function combinedOdds(current) {
                let inv = 0;
                let usable = 0;
                current.forEach(function (row) {
                    const odds = officialOdds(row);
                    if (odds !== null) {
                        inv += 1 / odds;
                        usable++;
                    }
                });
                if (!usable || inv <= 0) return null;
                return 1 / inv;
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
                    const delta = row.probability - row.base_probability;
                    const odds = officialOdds(row);

                    const rank = document.createElement('td');
                    rank.className = 'app-trifecta-rank';
                    rank.textContent = String(row.rank);
                    tr.appendChild(rank);

                    const combo = document.createElement('td');
                    combo.className = 'app-trifecta-combo-cell';
                    combo.appendChild(makeCombination(row));
                    tr.appendChild(combo);

                    const base = document.createElement('td');
                    base.textContent = (row.base_probability * 100).toFixed(3) + '%';
                    tr.appendChild(base);

                    const finalCell = document.createElement('td');
                    finalCell.className = 'app-trifecta-final';
                    finalCell.textContent = (row.probability * 100).toFixed(3) + '%';
                    tr.appendChild(finalCell);

                    const oddsCell = document.createElement('td');
                    oddsCell.className = 'app-trifecta-odds';
                    oddsCell.textContent = oddsText(odds);
                    tr.appendChild(oddsCell);

                    const deltaCell = document.createElement('td');
                    deltaCell.className = delta >= 0 ? 'app-trifecta-delta-plus' : 'app-trifecta-delta-minus';
                    deltaCell.textContent = (delta >= 0 ? '+' : '') + (delta * 100).toFixed(3) + 'pt';
                    tr.appendChild(deltaCell);

                    const cumulative = document.createElement('td');
                    cumulative.textContent = (row.cumulative_probability * 100).toFixed(2) + '%';
                    tr.appendChild(cumulative);

                    tbody.appendChild(tr);
                });

                const probabilitySum = current.reduce(function (sum, row) { return sum + number(row.probability); }, 0);
                const combined = combinedOdds(current);
                if (count) count.textContent = '表示中：' + current.length + ' / 30通り';
                if (summary) {
                    summary.textContent = '最終出目確率合計：' + (probabilitySum * 100).toFixed(2) + '%'
                        + '　合成オッズ：' + (combined === null ? '-' : combined.toFixed(2) + '倍');
                }
                updateSortLabels();
            }

            function formatTime(iso) {
                if (!iso) return '';
                const date = new Date(iso);
                if (Number.isNaN(date.getTime())) return '';
                return new Intl.DateTimeFormat('ja-JP', {hour: '2-digit', minute: '2-digit', hour12: false}).format(date);
            }

            function applyOdds(data) {
                const oddsMap = data && data.odds && typeof data.odds === 'object' ? data.odds : {};
                rows.forEach(function (row) {
                    const key = boatKey(row);
                    const value = Object.prototype.hasOwnProperty.call(oddsMap, key) ? Number(oddsMap[key]) : NaN;
                    row.official_odds = Number.isFinite(value) && value > 0 ? value : null;
                });
                render();
            }

            async function loadOdds(force) {
                const raceCodeNode = document.querySelector('.app-code');
                const raceCode = String(raceCodeNode ? raceCodeNode.textContent : '').trim();
                if (!/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/.test(raceCode)) {
                    if (oddsStatus) oddsStatus.textContent = '公式2連単オッズ：race_codeを確認できません';
                    return;
                }
                if (refresh) refresh.disabled = true;
                if (oddsStatus) oddsStatus.textContent = force ? '公式2連単オッズ：更新中…' : '公式2連単オッズ：取得中…';

                try {
                    const body = new URLSearchParams();
                    body.set('race_code', raceCode);
                    body.set('refresh', force ? '1' : '0');
                    const response = await fetch('/web/official_exacta_odds_api.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                        body: body.toString(),
                        cache: 'no-store'
                    });
                    const data = await response.json();
                    const fetchedCount = Number(data && data.count ? data.count : 0);
                    if (data && data.status === 'ok' && fetchedCount === 30) {
                        applyOdds(data);
                        const time = formatTime(data.fetched_at || '');
                        if (oddsStatus) oddsStatus.textContent = 'オッズ取得 ' + (time || '--:--') + ' / 30通り';
                    } else if (oddsStatus) {
                        oddsStatus.textContent = '公式2連単オッズ：' + (data && data.error ? String(data.error) : '取得できませんでした');
                    }
                } catch (e) {
                    if (oddsStatus) oddsStatus.textContent = '公式2連単オッズ：取得エラー';
                } finally {
                    if (refresh) refresh.disabled = false;
                }
            }

            filters.addEventListener('click', function (event) {
                const target = event.target.closest('.app-exacta-filter');
                if (!target) return;
                const group = target.closest('.app-exacta-filter-group');
                if (!group) return;
                const position = Number(group.dataset.position || 0);
                const boat = Number(target.dataset.boat || 0);
                if (boat === 0) {
                    selected[position].clear();
                } else if (selected[position].has(boat)) {
                    selected[position].delete(boat);
                } else {
                    selected[position].add(boat);
                    if (selected[position].size === 6) selected[position].clear();
                }
                updateFilterGroup(group, position);
                render();
            });

            if (search) search.addEventListener('input', render);
            if (clear) {
                clear.addEventListener('click', function () {
                    if (search) search.value = '';
                    selected.forEach(function (set) { set.clear(); });
                    panel.querySelectorAll('.app-exacta-filter-group').forEach(function (group) {
                        updateFilterGroup(group, Number(group.dataset.position || 0));
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
                        sortDirection = (nextKey === 'rank' || nextKey === 'combination' || nextKey === 'odds') ? 1 : -1;
                    }
                    render();
                });
            });

            if (refresh) refresh.addEventListener('click', function () { loadOdds(true); });

            function activateExacta() {
                document.querySelectorAll('.app-tab').forEach(function (tab) {
                    tab.classList.toggle('is-active', tab.dataset.tab === 'exacta');
                });
                document.querySelectorAll('.app-tab-panel').forEach(function (tabPanel) {
                    const active = tabPanel.dataset.panel === 'exacta';
                    tabPanel.classList.toggle('is-active', active);
                    tabPanel.hidden = !active;
                });
                try { sessionStorage.setItem(STORAGE_KEY, 'exacta'); } catch (e) {}
            }

            button.addEventListener('click', activateExacta);
            panel.querySelectorAll('.app-exacta-filter-group').forEach(function (group) {
                updateFilterGroup(group, Number(group.dataset.position || 0));
            });
            render();
            loadOdds(false);

            let saved = '';
            try { saved = sessionStorage.getItem(STORAGE_KEY) || ''; } catch (e) {}
            if (saved === 'exacta') activateExacta();
        }, 100);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup);
    } else {
        setup();
    }
})();
