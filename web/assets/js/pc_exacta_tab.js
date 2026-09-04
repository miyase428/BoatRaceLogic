(function () {
    'use strict';

    const STORAGE_KEY = 'boatracePcMainTab';

    function number(value) {
        const parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function parsePercentCell(cell) {
        const value = parseFloat(String(cell ? cell.textContent : '').replace(/,/g, ''));
        return Number.isFinite(value) ? value / 100 : 0;
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

    function combinationKey(row) {
        return String(row.first) + '-' + String(row.second);
    }

    function deriveExactaRows() {
        const tableBox = document.getElementById('web-trifecta-all-table');
        const table = tableBox ? tableBox.querySelector('table') : null;
        const tbody = table && table.tBodies ? table.tBodies[0] : null;
        if (!tbody) return [];

        const map = new Map();
        const active = new Set();
        Array.from(tbody.rows).forEach(function (tr) {
            if (!tr.cells || tr.cells.length < 4) return;
            const boats = (String(tr.cells[1].textContent || '').match(/[1-6]/g) || []).slice(0, 3).map(Number);
            if (boats.length !== 3 || boats[0] === boats[1]) return;
            boats.forEach(function (boat) { active.add(boat); });

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

            const row = map.get(key);
            row.base_probability += parsePercentCell(tr.cells[2]);
            row.probability += parsePercentCell(tr.cells[3]);
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

        const activeCount = active.size;
        const expected = activeCount >= 2 ? activeCount * (activeCount - 1) : 0;
        return expected > 0 && rows.length === expected ? rows : [];
    }

    function activeBoatsFromRows(rows) {
        const set = new Set();
        rows.forEach(function (row) {
            set.add(Number(row.first));
            set.add(Number(row.second));
        });
        return Array.from(set).filter(function (boat) { return boat >= 1 && boat <= 6; }).sort(function (a, b) { return a - b; });
    }

    function trifectaCountFromDom() {
        const tableBox = document.getElementById('web-trifecta-all-table');
        const tbody = tableBox ? tableBox.querySelector('tbody') : null;
        return tbody ? tbody.rows.length : 0;
    }

    function badge(boat) {
        const span = document.createElement('span');
        span.textContent = String(boat) + '号艇';
        const styles = {
            1: ['#fff', '#222', '#c9c9c9'],
            2: ['#1f2937', '#fff', '#1f2937'],
            3: ['#ef4444', '#fff', '#ef4444'],
            4: ['#3b82f6', '#fff', '#3b82f6'],
            5: ['#facc15', '#222', '#d7aa00'],
            6: ['#22c55e', '#fff', '#22c55e']
        };
        const c = styles[boat] || styles[1];
        span.style.cssText = 'display:inline-block;min-width:42px;padding:2px 6px;border-radius:4px;box-sizing:border-box;white-space:nowrap;text-align:center;font-weight:bold;font-size:12px;background:' + c[0] + ';color:' + c[1] + ';border:1px solid ' + c[2] + ';';
        return span;
    }

    function makeCombination(row) {
        const wrap = document.createElement('span');
        wrap.style.whiteSpace = 'nowrap';
        wrap.appendChild(badge(row.first));
        const sep = document.createElement('span');
        sep.textContent = '-';
        sep.style.cssText = 'color:#8a8176;margin:0 4px;';
        wrap.appendChild(sep);
        wrap.appendChild(badge(row.second));
        return wrap;
    }

    function waitForTabs(callback, retry) {
        const tabs = document.querySelector('.pc-main-tabs');
        const trifectaPanel = document.querySelector('.pc-main-tab-panel[data-pc-main-panel="trifecta"]');
        if (tabs && trifectaPanel && document.getElementById('web-trifecta-all-table')) {
            callback(tabs, trifectaPanel);
            return;
        }
        if (retry <= 0) return;
        window.setTimeout(function () {
            waitForTabs(callback, retry - 1);
        }, 40);
    }

    function setup() {
        waitForTabs(function (tabs, trifectaPanel) {
            if (tabs.querySelector('[data-pc-main-tab="exacta"]')) return;

            const rows = deriveExactaRows();
            if (rows.length < 2) return;
            const activeBoats = activeBoatsFromRows(rows);
            const exactaCount = rows.length;
            const trifectaCount = trifectaCountFromDom();

            const recentButton = tabs.querySelector('[data-pc-main-tab="recent"]');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'pc-main-tab';
            button.dataset.pcMainTab = 'exacta';
            button.textContent = '2連単';
            if (recentButton) {
                tabs.insertBefore(button, tabs.querySelector('[data-pc-main-tab="trifecta"]') || recentButton);
            } else {
                tabs.appendChild(button);
            }

            tabs.style.gridTemplateColumns = 'repeat(5, minmax(0, 1fr))';

            const panel = document.createElement('div');
            panel.className = 'pc-main-tab-panel pc-main-exacta-panel';
            panel.dataset.pcMainPanel = 'exacta';
            panel.hidden = true;
            trifectaPanel.insertAdjacentElement('beforebegin', panel);

            const raceCodeNode = document.querySelector('.code-value');
            const raceCode = String(raceCodeNode ? raceCodeNode.textContent : '').trim();

            panel.innerHTML = ''
                + '<div style="margin:0 0 14px;background:#f8f4ec;border:1px solid #d8cdbc;border-radius:8px;overflow:hidden;color:#3f4b5a;">'
                + '  <div style="padding:14px;">'
                + '    <div style="font-size:16px;font-weight:bold;color:#aa741f;">🎯 2連単' + exactaCount + '通り 出目確率</div>'
                + '    <div style="font-size:12px;color:#6b7785;margin-top:3px;">3連単' + trifectaCount + '通りの最終出目確率を1着-2着ごとに合算して' + exactaCount + '通りへ集約。</div>'
                + '    <div class="pc-exacta-odds-bar" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:10px 0;padding:8px 10px;border:1px solid #cbbda9;border-radius:6px;background:#fffaf2;">'
                + '      <span class="pc-exacta-odds-status" style="font-size:12px;color:#4b5866;">公式2連単オッズ：取得中…</span>'
                + '      <button type="button" class="pc-exacta-refresh" style="padding:5px 10px;border:1px solid #1683bd;border-radius:5px;background:#fff;color:#1683bd;font-weight:bold;cursor:pointer;">更新</button>'
                + '    </div>'
                + '    <div style="padding:10px;background:#f2ece2;border:1px solid #d8cdbc;border-radius:6px;">'
                + '      <label style="display:block;color:#6b7785;font-size:12px;font-weight:bold;">買い目検索'
                + '        <input class="pc-exacta-search" type="text" inputmode="numeric" autocomplete="off" placeholder="例 1 / 1-2" style="display:block;width:100%;box-sizing:border-box;margin-top:5px;padding:8px 10px;border:1px solid #cbbda9;border-radius:5px;background:#fffdf9;color:#2b3440;font-size:14px;">'
                + '      </label>'
                + '      <div class="pc-exacta-filters" style="display:grid;gap:6px;margin-top:10px;"></div>'
                + '      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:10px;">'
                + '        <span class="pc-exacta-count" style="color:#6b7785;font-size:12px;font-weight:bold;"></span>'
                + '        <button type="button" class="pc-exacta-clear" style="padding:6px 12px;border:1px solid #cbbda9;border-radius:5px;background:#eee6da;color:#4b5866;font-weight:bold;cursor:pointer;">クリア</button>'
                + '      </div>'
                + '      <div class="pc-exacta-summary" style="margin-top:8px;padding:8px 10px;border:1px solid #d8cdbc;border-radius:5px;background:#fffdf9;color:#3f4b5a;font-size:12px;font-weight:bold;"></div>'
                + '    </div>'
                + '    <div style="overflow-x:auto;margin-top:10px;">'
                + '      <table class="pc-exacta-table" style="width:100%;min-width:690px;border-collapse:collapse;">'
                + '        <thead><tr style="background:#e8dfd2;color:#2b3440;">'
                + '          <th data-sort="rank" style="padding:7px 8px;text-align:center;cursor:pointer;">順位</th>'
                + '          <th data-sort="combination" style="padding:7px 8px;text-align:left;cursor:pointer;">2連単</th>'
                + '          <th data-sort="base" style="padding:7px 8px;text-align:right;cursor:pointer;">基礎出目</th>'
                + '          <th data-sort="final" style="padding:7px 8px;text-align:right;cursor:pointer;">最終出目確率</th>'
                + '          <th data-sort="odds" style="padding:7px 8px;text-align:right;cursor:pointer;">オッズ</th>'
                + '          <th data-sort="delta" style="padding:7px 8px;text-align:right;cursor:pointer;">基礎差</th>'
                + '          <th data-sort="cumulative" style="padding:7px 8px;text-align:right;cursor:pointer;">累計</th>'
                + '        </tr></thead>'
                + '        <tbody></tbody>'
                + '      </table>'
                + '    </div>'
                + '    <div style="margin-top:8px;font-size:11px;color:#6b7785;">' + exactaCount + '通り合計は100% / オッズはBOAT RACE公式 / 通常表示はキャッシュ・更新時のみ再取得</div>'
                + '  </div>'
                + '</div>';

            const search = panel.querySelector('.pc-exacta-search');
            const filters = panel.querySelector('.pc-exacta-filters');
            const count = panel.querySelector('.pc-exacta-count');
            const summary = panel.querySelector('.pc-exacta-summary');
            const clear = panel.querySelector('.pc-exacta-clear');
            const tbody = panel.querySelector('tbody');
            const headers = Array.from(panel.querySelectorAll('th[data-sort]'));
            const oddsStatus = panel.querySelector('.pc-exacta-odds-status');
            const refresh = panel.querySelector('.pc-exacta-refresh');
            const selected = [new Set(), new Set()];
            let sortKey = 'rank';
            let sortDirection = 1;

            ['1着', '2着'].forEach(function (label, position) {
                const group = document.createElement('div');
                group.className = 'pc-exacta-filter-group';
                group.dataset.position = String(position);
                group.style.cssText = 'display:flex;align-items:center;gap:5px;flex-wrap:wrap;';

                const title = document.createElement('span');
                title.textContent = label;
                title.style.cssText = 'width:34px;color:#6b7785;font-size:12px;font-weight:bold;text-align:center;';
                group.appendChild(title);

                const allBoats = [0].concat(activeBoats);
                allBoats.forEach(function (boat) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'pc-exacta-filter';
                    btn.dataset.boat = String(boat);
                    btn.textContent = boat === 0 ? '全' : String(boat);
                    btn.style.cssText = 'min-width:42px;padding:6px 9px;border:1px solid #cbbda9;border-radius:5px;background:#eee6da;color:#4b5866;font-weight:bold;cursor:pointer;';
                    group.appendChild(btn);
                });
                filters.appendChild(group);
            });

            function paintGroup(group, position) {
                group.querySelectorAll('.pc-exacta-filter').forEach(function (btn) {
                    const boat = Number(btn.dataset.boat || 0);
                    const active = boat === 0 ? selected[position].size === 0 : selected[position].has(boat);
                    btn.style.borderColor = active ? '#1683bd' : '#cbbda9';
                    btn.style.background = active ? '#fffaf2' : '#eee6da';
                    btn.style.color = active ? '#1683bd' : '#4b5866';
                    btn.style.boxShadow = active ? 'inset 0 0 0 1px #1683bd' : 'none';
                });
            }

            function officialOdds(row) {
                const value = Number(row.official_odds);
                return Number.isFinite(value) && value > 0 ? value : null;
            }

            function compare(a, b) {
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
                    const key = combinationKey(row);
                    return key === query || key.indexOf(query + '-') === 0 || String(row.first) === query;
                }).sort(compare);
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

            function updateHeaders() {
                headers.forEach(function (th) {
                    const base = String(th.textContent || '').replace(/[▲▼]$/, '').trim();
                    th.textContent = base;
                    if (th.dataset.sort === sortKey) {
                        th.textContent = base + (sortDirection > 0 ? ' ▲' : ' ▼');
                        th.style.color = '#1683bd';
                    } else {
                        th.style.color = '#2b3440';
                    }
                });
            }

            function render() {
                const current = filteredRows();
                tbody.textContent = '';

                current.forEach(function (row) {
                    const tr = document.createElement('tr');
                    tr.style.borderTop = '1px solid #d8cdbc';
                    const delta = row.probability - row.base_probability;
                    const odds = officialOdds(row);

                    const rank = document.createElement('td');
                    rank.textContent = String(row.rank);
                    rank.style.cssText = 'padding:7px 8px;text-align:center;color:#6b7785;font-weight:bold;';
                    tr.appendChild(rank);

                    const combo = document.createElement('td');
                    combo.style.cssText = 'padding:7px 8px;';
                    combo.appendChild(makeCombination(row));
                    tr.appendChild(combo);

                    const base = document.createElement('td');
                    base.textContent = (row.base_probability * 100).toFixed(3) + '%';
                    base.style.cssText = 'padding:7px 8px;text-align:right;color:#6b7785;';
                    tr.appendChild(base);

                    const finalCell = document.createElement('td');
                    finalCell.textContent = (row.probability * 100).toFixed(3) + '%';
                    finalCell.style.cssText = 'padding:7px 8px;text-align:right;font-size:16px;font-weight:bold;color:#aa741f;';
                    tr.appendChild(finalCell);

                    const oddsCell = document.createElement('td');
                    oddsCell.textContent = oddsText(odds);
                    oddsCell.style.cssText = 'padding:7px 8px;text-align:right;font-weight:bold;color:#7b6332;';
                    tr.appendChild(oddsCell);

                    const deltaCell = document.createElement('td');
                    deltaCell.textContent = (delta >= 0 ? '+' : '') + (delta * 100).toFixed(3) + 'pt';
                    deltaCell.style.cssText = 'padding:7px 8px;text-align:right;color:' + (delta >= 0 ? '#2f789f' : '#6b7785') + ';';
                    tr.appendChild(deltaCell);

                    const cum = document.createElement('td');
                    cum.textContent = (row.cumulative_probability * 100).toFixed(2) + '%';
                    cum.style.cssText = 'padding:7px 8px;text-align:right;color:#3f4b5a;';
                    tr.appendChild(cum);

                    tbody.appendChild(tr);
                });

                const probabilitySum = current.reduce(function (sum, row) { return sum + number(row.probability); }, 0);
                const combined = combinedOdds(current);
                if (count) count.textContent = '表示中：' + current.length + ' / ' + exactaCount + '通り';
                if (summary) {
                    summary.textContent = '最終出目確率合計：' + (probabilitySum * 100).toFixed(2) + '%'
                        + '　合成オッズ：' + (combined === null ? '-' : combined.toFixed(2) + '倍');
                }
                updateHeaders();
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
                    const key = combinationKey(row);
                    const value = Object.prototype.hasOwnProperty.call(oddsMap, key) ? Number(oddsMap[key]) : NaN;
                    row.official_odds = Number.isFinite(value) && value > 0 ? value : null;
                });
                render();
            }

            async function loadOdds(force) {
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
                    if (data && data.status === 'ok' && fetchedCount > 0) {
                        applyOdds(data);
                        const time = formatTime(data.fetched_at || '');
                        if (oddsStatus) oddsStatus.textContent = 'オッズ取得 ' + (time || '--:--') + ' / 表示' + exactaCount + '通り';
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
                const target = event.target.closest('.pc-exacta-filter');
                if (!target) return;
                const group = target.closest('.pc-exacta-filter-group');
                if (!group) return;
                const position = Number(group.dataset.position || 0);
                const boat = Number(target.dataset.boat || 0);
                if (boat === 0) {
                    selected[position].clear();
                } else if (selected[position].has(boat)) {
                    selected[position].delete(boat);
                } else {
                    selected[position].add(boat);
                    if (selected[position].size === activeBoats.length) selected[position].clear();
                }
                paintGroup(group, position);
                render();
            });

            if (search) search.addEventListener('input', render);
            if (clear) {
                clear.addEventListener('click', function () {
                    if (search) search.value = '';
                    selected.forEach(function (set) { set.clear(); });
                    filters.querySelectorAll('.pc-exacta-filter-group').forEach(function (group) {
                        paintGroup(group, Number(group.dataset.position || 0));
                    });
                    sortKey = 'rank';
                    sortDirection = 1;
                    render();
                });
            }

            headers.forEach(function (th) {
                th.addEventListener('click', function () {
                    const nextKey = th.dataset.sort || 'rank';
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
                document.querySelectorAll('.pc-main-tab').forEach(function (tab) {
                    const active = tab.dataset.pcMainTab === 'exacta';
                    tab.classList.toggle('is-active', active);
                    tab.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                document.querySelectorAll('.pc-main-tab-panel').forEach(function (tabPanel) {
                    const active = tabPanel.dataset.pcMainPanel === 'exacta';
                    tabPanel.classList.toggle('is-active', active);
                    tabPanel.hidden = !active;
                });
                try { sessionStorage.setItem(STORAGE_KEY, 'exacta'); } catch (e) {}
            }

            button.addEventListener('click', activateExacta);
            tabs.querySelectorAll('.pc-main-tab:not([data-pc-main-tab="exacta"])').forEach(function (tab) {
                tab.addEventListener('click', function () {
                    panel.classList.remove('is-active');
                    panel.hidden = true;
                });
            });

            filters.querySelectorAll('.pc-exacta-filter-group').forEach(function (group) {
                paintGroup(group, Number(group.dataset.position || 0));
            });
            render();
            loadOdds(false);

            let saved = '';
            try { saved = sessionStorage.getItem(STORAGE_KEY) || ''; } catch (e) {}
            if (saved === 'exacta') activateExacta();
        }, 80);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup);
    } else {
        setup();
    }
})();
