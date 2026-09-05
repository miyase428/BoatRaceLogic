(function () {
    'use strict';

    const STORAGE_KEY = 'boatraceAppTabEnhanced';

    function number(value) {
        const n = Number(value);
        return Number.isFinite(n) ? n : 0;
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

    function raceCode() {
        const node = document.querySelector('.app-code');
        return String(node ? node.textContent : '').trim();
    }

    function normalizeSearch(value) {
        return String(value || '')
            .trim()
            .replace(/[１-６]/g, function (char) { return String(char.charCodeAt(0) - 0xFEE0); })
            .replace(/[－–—→>\s]+/g, '-')
            .replace(/[^1-6-]/g, '')
            .replace(/-+/g, '-')
            .replace(/^-|-$/g, '');
    }

    function boatKey(row) {
        const boats = Array.isArray(row.boats) ? row.boats.map(Number) : [];
        return boats.join('-');
    }

    function activeFromRows(rows) {
        const set = new Set();
        (Array.isArray(rows) ? rows : []).forEach(function (row) {
            (Array.isArray(row.boats) ? row.boats : []).forEach(function (boat) {
                const n = Number(boat);
                if (n >= 1 && n <= 6) set.add(n);
            });
        });
        return Array.from(set).sort(function (a, b) { return a - b; });
    }

    function renormalizeRows(rows, activeBoats) {
        const active = new Set(activeBoats);
        const filtered = (Array.isArray(rows) ? rows : []).filter(function (row) {
            const boats = Array.isArray(row.boats) ? row.boats.map(Number) : [];
            return boats.length === 3 && boats.every(function (boat) { return active.has(boat); });
        });

        const n = activeBoats.length;
        const expected = n >= 3 ? n * (n - 1) * (n - 2) : 0;
        if (!expected || filtered.length !== expected) return null;

        const fields = ['base_probability', 'step2_probability', 'probability'];
        fields.forEach(function (field) {
            const sum = filtered.reduce(function (acc, row) { return acc + Math.max(0, number(row[field])); }, 0);
            if (sum > 0) {
                filtered.forEach(function (row) { row[field] = Math.max(0, number(row[field])) / sum; });
            }
        });

        filtered.sort(function (a, b) {
            const diff = number(b.probability) - number(a.probability);
            if (diff !== 0) return diff;
            return boatKey(a).localeCompare(boatKey(b));
        });

        let cumulative = 0;
        filtered.forEach(function (row, index) {
            row.rank = index + 1;
            cumulative += number(row.probability);
            row.cumulative_probability = cumulative;
        });
        return filtered;
    }

    async function preparePayload() {
        const payload = parsePayload();
        if (!payload) return null;

        const code = raceCode();
        if (!/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/.test(code)) {
            payload.active_boats = activeFromRows(payload.rows);
            return payload;
        }

        try {
            const response = await fetch('/web/effective_race_boats_api.php?race_code=' + encodeURIComponent(code), {cache: 'no-store'});
            const context = await response.json();
            const activeBoats = context && context.status === 'ok' && Array.isArray(context.active_boats)
                ? context.active_boats.map(Number).filter(function (boat) { return boat >= 1 && boat <= 6; })
                : rangeBoats();

            payload.active_boats = activeBoats;
            payload.excluded_boats = rangeBoats().filter(function (boat) { return activeBoats.indexOf(boat) < 0; });
            payload.outcome_count = Number(context && context.trifecta_count ? context.trifecta_count : 120);
            payload.exacta_count = Number(context && context.exacta_count ? context.exacta_count : 30);

            if (String(payload.status || '') === 'ok' && activeBoats.length < 6) {
                const filtered = renormalizeRows(payload.rows, activeBoats);
                if (filtered) {
                    payload.rows = filtered;
                    payload.outcome_count = filtered.length;
                    payload.totals = payload.totals && typeof payload.totals === 'object' ? payload.totals : {};
                    payload.totals.base = 1;
                    payload.totals.step2 = 1;
                    payload.totals.final = 1;
                }
            }

            const node = document.getElementById('app-trifecta-data');
            if (node) node.textContent = JSON.stringify(payload);
            return payload;
        } catch (e) {
            payload.active_boats = activeFromRows(payload.rows);
            return payload;
        }
    }

    function rangeBoats() {
        return [1, 2, 3, 4, 5, 6];
    }

    window.boatraceAppTrifectaPayloadPromise = preparePayload();

    function makeBoatBadge(boat) {
        const span = document.createElement('span');
        span.className = 'app-trifecta-boat app-trifecta-boat-' + String(boat);
        span.textContent = String(boat);
        return span;
    }

    function makeCombination(row) {
        const wrap = document.createElement('span');
        wrap.className = 'app-trifecta-combination';
        (Array.isArray(row.boats) ? row.boats : []).forEach(function (boat, index) {
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

    function sumTop(rows, n) {
        return rows.slice().sort(function (a, b) { return number(a.rank) - number(b.rank); })
            .slice(0, n).reduce(function (sum, row) { return sum + number(row.probability); }, 0);
    }

    function updateMainHead1Exacta(payload) {
        const section = document.querySelector('.app-main-exacta');
        if (!section || String(payload && payload.status || '') !== 'ok') return;
        const rows = Array.isArray(payload.rows) ? payload.rows : [];
        const headRows = rows.filter(function (row) {
            const courses = Array.isArray(row.courses) ? row.courses.map(Number) : [];
            return courses.length === 3 && courses[0] === 1;
        });
        if (!headRows.length) return;

        const map = new Map();
        let baseMass = 0;
        let aiMass = 0;
        headRows.forEach(function (row) {
            const boats = Array.isArray(row.boats) ? row.boats.map(Number) : [];
            const courses = Array.isArray(row.courses) ? row.courses.map(Number) : [];
            if (boats.length !== 3 || courses.length !== 3) return;
            const secondBoat = boats[1];
            const key = secondBoat;
            if (!map.has(key)) map.set(key, {head_boat: boats[0], second_boat: secondBoat, base: 0, ai: 0});
            const item = map.get(key);
            item.base += Math.max(0, number(row.base_probability));
            item.ai += Math.max(0, number(row.probability));
            baseMass += Math.max(0, number(row.base_probability));
            aiMass += Math.max(0, number(row.probability));
        });
        const exactaRows = Array.from(map.values()).map(function (row) {
            row.base = baseMass > 0 ? row.base / baseMass : 0;
            row.ai = aiMass > 0 ? row.ai / aiMass : 0;
            return row;
        }).sort(function (a, b) { return a.second_boat - b.second_boat; });
        if (!exactaRows.length) return;

        const grid = section.querySelector('.app-exacta-grid');
        if (!grid) return;
        grid.textContent = '';
        grid.style.gridTemplateColumns = '58px repeat(' + exactaRows.length + ', minmax(0, 1fr))';

        function cell(text, className) {
            const div = document.createElement('div');
            if (className) div.className = className;
            div.textContent = text;
            return div;
        }

        grid.appendChild(cell('', 'app-exacta-label'));
        exactaRows.forEach(function (row) { grid.appendChild(cell(row.head_boat + '-' + row.second_boat, 'app-exacta-head')); });
        grid.appendChild(cell('場平均', 'app-exacta-label'));
        exactaRows.forEach(function (row) { grid.appendChild(cell((row.base * 100).toFixed(1) + '%')); });
        grid.appendChild(cell('AI予想', 'app-exacta-label'));
        exactaRows.forEach(function (row) { grid.appendChild(cell((row.ai * 100).toFixed(1) + '%', 'app-exacta-ai')); });
        grid.appendChild(cell('差', 'app-exacta-label'));
        exactaRows.forEach(function (row) {
            const d = (row.ai - row.base) * 100;
            grid.appendChild(cell((d >= 0 ? '+' : '') + d.toFixed(1) + 'pt', d >= 0 ? 'app-exacta-plus' : 'app-exacta-minus'));
        });
    }

    function buildPanel(panel, payload, raceCodeValue) {
        const rows = Array.isArray(payload.rows) ? payload.rows.slice() : [];
        const totalCount = rows.length;
        const activeBoats = Array.isArray(payload.active_boats) && payload.active_boats.length
            ? payload.active_boats.map(Number)
            : activeFromRows(rows);
        const history = payload.history && typeof payload.history === 'object' ? payload.history : {};
        const totals = payload.totals && typeof payload.totals === 'object' ? payload.totals : {};

        const card = document.createElement('section');
        card.className = 'app-card app-trifecta-card';
        card.innerHTML = '<div class="app-card-body app-trifecta-heading">'
            + '<h2 class="app-section-title">🎲 3連単' + totalCount + '通り 出目確率</h2>'
            + '<div class="app-note">順位・3連単・基礎出目・最終出目確率・公式オッズ・基礎差・累計を表示します。</div>'
            + '<div class="app-trifecta-odds-bar" style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-top:8px;padding:7px 8px;border:1px solid #d6d3cd;border-radius:6px;background:#fffaf2;">'
            + '<span class="app-trifecta-odds-status" style="font-size:11px;color:#6b7785;">公式3連単オッズ：取得中…</span>'
            + '<button type="button" class="app-trifecta-odds-refresh" style="padding:5px 9px;border:1px solid #1683bd;border-radius:5px;background:#fff;color:#1683bd;font-weight:bold;">更新</button>'
            + '</div></div>'
            + '<div class="app-trifecta-stats"></div>'
            + '<div class="app-card-body app-trifecta-controls">'
            + '<label class="app-trifecta-search-label">買い目検索<input class="app-trifecta-search" type="text" inputmode="numeric" autocomplete="off" placeholder="例 1 / 1-2 / 1-2-3"></label>'
            + '<div class="app-trifecta-position-filters"></div>'
            + '<div class="app-trifecta-control-row"><span class="app-trifecta-count"></span><button type="button" class="app-trifecta-clear">クリア</button></div>'
            + '<div class="app-trifecta-selection-summary" title="合成オッズ = 1 ÷ Σ(1 ÷ 各買い目オッズ)" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;padding:8px;border:1px solid #d6d3cd;border-radius:6px;background:#fffdf9;font-size:11px;color:#5f6873;">'
            + '<span>最終出目確率合計：<strong class="app-trifecta-probability-sum">--</strong></span><span>合成オッズ：<strong class="app-trifecta-combined-odds">--</strong></span></div></div>'
            + '<div class="app-trifecta-table-wrap"><table class="app-trifecta-table"><thead><tr>'
            + '<th><button type="button" data-sort="rank">順位</button></th><th><button type="button" data-sort="combination">3連単</button></th>'
            + '<th><button type="button" data-sort="base">基礎出目</button></th><th><button type="button" data-sort="final">最終出目確率</button></th>'
            + '<th><button type="button" data-sort="odds">オッズ</button></th><th><button type="button" data-sort="delta">基礎差</button></th><th><button type="button" data-sort="cumulative">累計</button></th>'
            + '</tr></thead><tbody></tbody></table></div><div class="app-card-body app-trifecta-foot"></div>';
        panel.appendChild(card);

        const stats = card.querySelector('.app-trifecta-stats');
        [['Top5累計', sumTop(rows, 5)], ['Top10累計', sumTop(rows, 10)], ['Top20累計', sumTop(rows, 20)]].forEach(function (item) {
            const div = document.createElement('div'); div.className = 'app-trifecta-stat';
            const label = document.createElement('span'); label.textContent = item[0];
            const strong = document.createElement('strong'); strong.textContent = (item[1] * 100).toFixed(2) + '%';
            div.appendChild(label); div.appendChild(strong); stats.appendChild(div);
        });
        const venue = document.createElement('div'); venue.className = 'app-trifecta-stat';
        venue.innerHTML = '<span>場履歴</span><strong>' + Math.round(number(history.venue_n)).toLocaleString('ja-JP') + 'R</strong>';
        stats.appendChild(venue);

        const positionFilters = card.querySelector('.app-trifecta-position-filters');
        const selected = [new Set(), new Set(), new Set()];
        ['1着', '2着', '3着'].forEach(function (label, index) {
            const group = document.createElement('div'); group.className = 'app-trifecta-filter-group';
            group.style.gridTemplateColumns = '35px repeat(' + (activeBoats.length + 1) + ', minmax(0, 1fr))';
            const title = document.createElement('span'); title.className = 'app-trifecta-filter-label'; title.textContent = label; group.appendChild(title);
            [0].concat(activeBoats).forEach(function (boat) {
                const filter = document.createElement('button'); filter.type = 'button'; filter.className = 'app-trifecta-filter' + (boat === 0 ? ' is-active' : '');
                filter.dataset.position = String(index); filter.dataset.boat = String(boat); filter.textContent = boat === 0 ? '全' : String(boat); filter.setAttribute('aria-pressed', boat === 0 ? 'true' : 'false'); group.appendChild(filter);
            });
            positionFilters.appendChild(group);
        });

        const search = card.querySelector('.app-trifecta-search');
        const count = card.querySelector('.app-trifecta-count');
        const clear = card.querySelector('.app-trifecta-clear');
        const tbody = card.querySelector('tbody');
        const sortButtons = Array.from(card.querySelectorAll('th button[data-sort]'));
        const foot = card.querySelector('.app-trifecta-foot');
        const oddsStatus = card.querySelector('.app-trifecta-odds-status');
        const oddsRefresh = card.querySelector('.app-trifecta-odds-refresh');
        const probabilitySumNode = card.querySelector('.app-trifecta-probability-sum');
        const combinedOddsNode = card.querySelector('.app-trifecta-combined-odds');
        let sortKey = 'rank';
        let sortDirection = 1;

        function officialOdds(row) {
            const value = Number(row.official_odds);
            return Number.isFinite(value) && value > 0 ? value : null;
        }
        function compareRows(a, b) {
            let av, bv;
            switch (sortKey) {
                case 'combination': av = number((a.boats || [])[0]) * 100 + number((a.boats || [])[1]) * 10 + number((a.boats || [])[2]); bv = number((b.boats || [])[0]) * 100 + number((b.boats || [])[1]) * 10 + number((b.boats || [])[2]); break;
                case 'base': av = number(a.base_probability); bv = number(b.base_probability); break;
                case 'final': av = number(a.probability); bv = number(b.probability); break;
                case 'odds': av = officialOdds(a); bv = officialOdds(b); if (av === null && bv === null) return number(a.rank) - number(b.rank); if (av === null) return 1; if (bv === null) return -1; break;
                case 'delta': av = number(a.probability) - number(a.base_probability); bv = number(b.probability) - number(b.base_probability); break;
                case 'cumulative': av = number(a.cumulative_probability); bv = number(b.cumulative_probability); break;
                default: av = number(a.rank); bv = number(b.rank); break;
            }
            if (av === bv) return number(a.rank) - number(b.rank);
            return (av < bv ? -1 : 1) * sortDirection;
        }
        function filteredRows() {
            const query = normalizeSearch(search ? search.value : '');
            return rows.filter(function (row) {
                const boats = Array.isArray(row.boats) ? row.boats.map(Number) : [];
                if (boats.length !== 3) return false;
                for (let i = 0; i < 3; i++) if (selected[i].size && !selected[i].has(boats[i])) return false;
                if (!query) return true;
                const key = boatKey(row); return key === query || key.indexOf(query + '-') === 0;
            }).sort(compareRows);
        }
        function updateFilters() {
            card.querySelectorAll('.app-trifecta-filter-group').forEach(function (group, position) {
                group.querySelectorAll('.app-trifecta-filter').forEach(function (filter) {
                    const boat = Number(filter.dataset.boat); const active = boat === 0 ? selected[position].size === 0 : selected[position].has(boat);
                    filter.classList.toggle('is-active', active); filter.setAttribute('aria-pressed', active ? 'true' : 'false');
                });
            });
        }
        function updateSortLabels() {
            sortButtons.forEach(function (button) {
                const base = button.textContent.replace(/[▲▼]$/, '').trim(); button.textContent = base;
                button.classList.toggle('is-active', button.dataset.sort === sortKey);
                if (button.dataset.sort === sortKey) button.textContent = base + (sortDirection > 0 ? ' ▲' : ' ▼');
            });
        }
        function render() {
            const current = filteredRows(); tbody.textContent = '';
            current.forEach(function (row) {
                const tr = document.createElement('tr'); const base = number(row.base_probability); const final = number(row.probability); const odds = officialOdds(row); const delta = final - base;
                const values = [
                    String(Math.round(number(row.rank))), null, (base * 100).toFixed(3) + '%', (final * 100).toFixed(3) + '%',
                    odds === null ? '-' : odds.toLocaleString('ja-JP', {maximumFractionDigits: 1}),
                    (delta >= 0 ? '+' : '') + (delta * 100).toFixed(3) + 'pt', (number(row.cumulative_probability) * 100).toFixed(2) + '%'
                ];
                values.forEach(function (value, index) {
                    const td = document.createElement('td');
                    if (index === 1) { td.className = 'app-trifecta-combo-cell'; td.appendChild(makeCombination(row)); }
                    else td.textContent = value;
                    if (index === 0) td.className = 'app-trifecta-rank';
                    if (index === 3) td.className = 'app-trifecta-final';
                    if (index === 4) td.className = 'app-trifecta-odds';
                    if (index === 5) td.className = delta >= 0 ? 'app-trifecta-delta-plus' : 'app-trifecta-delta-minus';
                    tr.appendChild(td);
                }); tbody.appendChild(tr);
            });
            const probabilitySum = current.reduce(function (sum, row) { return sum + number(row.probability); }, 0);
            let inv = 0; let oddsReady = current.length > 0;
            current.forEach(function (row) { const odds = officialOdds(row); if (odds === null) oddsReady = false; else inv += 1 / odds; });
            if (count) count.textContent = '表示中：' + current.length + ' / ' + totalCount + '通り';
            if (probabilitySumNode) probabilitySumNode.textContent = (probabilitySum * 100).toFixed(2) + '%';
            if (combinedOddsNode) combinedOddsNode.textContent = oddsReady && inv > 0 ? (1 / inv).toFixed(2) + '倍' : '取得待ち';
            updateSortLabels();
        }
        function formatTime(iso) { const d = new Date(iso || ''); return Number.isNaN(d.getTime()) ? '' : new Intl.DateTimeFormat('ja-JP', {hour: '2-digit', minute: '2-digit', hour12: false}).format(d); }
        function applyOddsData(data) {
            const oddsMap = data && data.odds && typeof data.odds === 'object' ? data.odds : {};
            rows.forEach(function (row) { const value = Number(oddsMap[boatKey(row)]); row.official_odds = Number.isFinite(value) && value > 0 ? value : null; }); render();
        }
        async function loadOdds(force) {
            if (oddsRefresh) oddsRefresh.disabled = true;
            if (oddsStatus) oddsStatus.textContent = force ? '公式3連単オッズ：更新中…' : '公式3連単オッズ：取得中…';
            try {
                const body = new URLSearchParams(); body.set('race_code', raceCodeValue); body.set('refresh', force ? '1' : '0');
                const response = await fetch('/web/official_odds_api.php', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'}, body: body.toString(), cache: 'no-store'});
                const data = await response.json(); const fetchedCount = Number(data && data.count ? data.count : 0);
                if (data && data.status === 'ok' && fetchedCount > 0) { applyOddsData(data); if (oddsStatus) oddsStatus.textContent = 'オッズ取得 ' + (formatTime(data.fetched_at) || '--:--') + ' / ' + totalCount + '通り'; }
                else if (oddsStatus) oddsStatus.textContent = '公式3連単オッズ：' + (data && data.error ? String(data.error) : '取得できませんでした');
            } catch (e) { if (oddsStatus) oddsStatus.textContent = '公式3連単オッズ：取得エラー'; }
            finally { if (oddsRefresh) oddsRefresh.disabled = false; }
        }

        positionFilters.addEventListener('click', function (event) {
            const target = event.target.closest('.app-trifecta-filter'); if (!target) return;
            const pos = Number(target.dataset.position); const boat = Number(target.dataset.boat);
            if (boat === 0) selected[pos].clear(); else if (selected[pos].has(boat)) selected[pos].delete(boat); else { selected[pos].add(boat); if (selected[pos].size === activeBoats.length) selected[pos].clear(); }
            updateFilters(); render();
        });
        if (search) search.addEventListener('input', render);
        if (clear) clear.addEventListener('click', function () { if (search) search.value = ''; selected.forEach(function (set) { set.clear(); }); sortKey = 'rank'; sortDirection = 1; updateFilters(); render(); });
        sortButtons.forEach(function (button) { button.addEventListener('click', function () { const key = button.dataset.sort || 'rank'; if (sortKey === key) sortDirection *= -1; else { sortKey = key; sortDirection = (key === 'rank' || key === 'combination' || key === 'odds') ? 1 : -1; } render(); }); });
        if (oddsRefresh) oddsRefresh.addEventListener('click', function () { loadOdds(true); });
        if (foot) foot.textContent = totalCount + '通り合計 ' + (number(totals.final || 1) * 100).toFixed(6) + '% / P1選択 → P2完全ホールドアウト検証済み';
        render(); loadOdds(false);
    }

    async function setup() {
        const payload = await window.boatraceAppTrifectaPayloadPromise;
        const tabs = document.querySelector('.app-tabs');
        const mainPanel = document.querySelector('.app-tab-panel[data-panel="main"]');
        if (!payload || !tabs || !mainPanel || tabs.querySelector('[data-tab="trifecta"]')) return;

        updateMainHead1Exacta(payload);
        const rows = Array.isArray(payload.rows) ? payload.rows : [];
        const totalCount = rows.length;
        const button = document.createElement('button'); button.type = 'button'; button.className = 'app-tab'; button.dataset.tab = 'trifecta'; button.textContent = totalCount + '通り'; tabs.appendChild(button);
        const panel = document.createElement('div'); panel.className = 'app-tab-panel app-trifecta-panel'; panel.dataset.panel = 'trifecta'; panel.hidden = true; mainPanel.insertAdjacentElement('afterend', panel);

        function activate(name) {
            document.querySelectorAll('.app-tab').forEach(function (b) { b.classList.toggle('is-active', b.dataset.tab === name); });
            document.querySelectorAll('.app-tab-panel').forEach(function (p) { const active = p.dataset.panel === name; p.classList.toggle('is-active', active); p.hidden = !active; });
            try { sessionStorage.setItem(STORAGE_KEY, name); } catch (e) {}
        }
        document.querySelectorAll('.app-tab').forEach(function (b) { b.addEventListener('click', function () { activate(b.dataset.tab || 'basic'); }); });

        const activeBoats = Array.isArray(payload.active_boats) && payload.active_boats.length ? payload.active_boats : activeFromRows(rows);
        const expected = activeBoats.length >= 3 ? activeBoats.length * (activeBoats.length - 1) * (activeBoats.length - 2) : 0;
        if (String(payload.status || '') !== 'ok' || !expected || totalCount !== expected) {
            const card = document.createElement('section'); card.className = 'app-card';
            card.innerHTML = '<div class="app-card-body"><h2 class="app-section-title">🎲 3連単出目確率</h2><div class="app-note app-trifecta-error"></div></div>';
            const note = card.querySelector('.app-trifecta-error'); if (note) note.textContent = String(payload.error || '出目確率は計算待ちです。'); panel.appendChild(card);
        } else {
            buildPanel(panel, payload, raceCode());
        }

        button.addEventListener('click', function () { activate('trifecta'); });
        let saved = ''; try { saved = sessionStorage.getItem(STORAGE_KEY) || ''; } catch (e) {}
        if (saved === 'trifecta') activate('trifecta');
    }

    function boot() { window.setTimeout(setup, 0); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();

    // iPhoneホーム画面はJSキャッシュが残りやすいため、2連単だけ最新版を先に読み込む。
    if (!document.querySelector('script[data-app-exacta-loader="1"]')) {
        const script = document.createElement('script');
        script.src = '/web/assets/js/app_exacta_tab.js?v=20260905a';
        script.dataset.appExactaLoader = '1';
        script.async = false;
        document.head.appendChild(script);
    }
})();
