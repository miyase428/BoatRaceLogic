(function () {
    'use strict';

    const form = document.getElementById('race-search-form');
    const resetButton = document.getElementById('race-search-reset');
    const resultCount = document.getElementById('search-result-count');
    const resultLabel = document.getElementById('search-result-label');
    const visibleCount = document.getElementById('visible-race-count');
    const empty = document.getElementById('search-empty');
    const raceButtons = Array.from(document.querySelectorAll('[data-race-button]'));
    const venueCards = Array.from(document.querySelectorAll('[data-venue-card]'));

    if (!form || !raceButtons.length) return;

    function selected(name) {
        const input = form.querySelector('input[name="' + name + '"]:checked');
        return input ? input.value : '';
    }

    function numericData(node, name) {
        const raw = String(node.dataset[name] || '').trim();
        if (raw === '') return null;
        const n = Number(raw);
        return Number.isFinite(n) ? n : null;
    }

    function bandMatch(raceNo, band) {
        if (band === 'early') return raceNo >= 1 && raceNo <= 4;
        if (band === 'middle') return raceNo >= 5 && raceNo <= 8;
        if (band === 'late') return raceNo >= 9 && raceNo <= 12;
        return true;
    }

    function filterLabel(filters) {
        const parts = [];
        if (filters.lane1Min !== '') parts.push('1号艇 ' + filters.lane1Min + '以上');
        if (filters.outerMax !== '') parts.push('外艇最高 ' + filters.outerMax + '以下');
        if (filters.raceBand === 'early') parts.push('1〜4R');
        if (filters.raceBand === 'middle') parts.push('5〜8R');
        if (filters.raceBand === 'late') parts.push('9〜12R');
        if (filters.raceStatus === 'entry') parts.push('展示前');
        if (filters.raceStatus === 'exhibition') parts.push('展示済');
        if (filters.raceStatus === 'result') parts.push('結果済');
        if (filters.unresolved) parts.push('結果前');
        return parts.length ? parts.join(' / ') : '本日の全レース';
    }

    function currentFilters() {
        return {
            lane1Min: selected('lane1Min'),
            outerMax: selected('outerMax'),
            raceBand: selected('raceBand') || 'all',
            raceStatus: selected('raceStatus') || 'all',
            unresolved: form.dataset.unresolved === '1'
        };
    }

    function isDefault(filters) {
        return filters.lane1Min === ''
            && filters.outerMax === ''
            && filters.raceBand === 'all'
            && filters.raceStatus === 'all'
            && !filters.unresolved;
    }

    function matches(button, filters) {
        const raceNo = Number(button.dataset.raceNo || 0);
        const status = String(button.dataset.status || '');
        const lane1Rate = numericData(button, 'lane1Rate');
        const outerMax = numericData(button, 'outerMax');

        if (!bandMatch(raceNo, filters.raceBand)) return false;
        if (filters.raceStatus !== 'all' && status !== filters.raceStatus) return false;
        if (filters.unresolved && status === 'result') return false;

        if (filters.lane1Min !== '') {
            if (lane1Rate === null || lane1Rate < Number(filters.lane1Min)) return false;
        }
        if (filters.outerMax !== '') {
            if (outerMax === null || outerMax > Number(filters.outerMax)) return false;
        }
        return true;
    }

    function applyFilters() {
        const filters = currentFilters();
        const defaultMode = isDefault(filters);
        let matched = 0;

        raceButtons.forEach(function (button) {
            const ok = matches(button, filters);
            button.classList.toggle('is-filter-hidden', !ok);
            if (ok) matched++;
        });

        venueCards.forEach(function (card) {
            const active = card.dataset.active === '1';
            if (!active) {
                card.classList.toggle('is-filter-hidden', !defaultMode);
                return;
            }

            const buttons = Array.from(card.querySelectorAll('[data-race-button]'));
            const visible = buttons.filter(function (button) {
                return !button.classList.contains('is-filter-hidden');
            }).length;

            card.classList.toggle('is-filter-hidden', visible === 0);
            const countNode = card.querySelector('[data-match-count]');
            if (countNode) countNode.textContent = defaultMode ? '' : (visible + 'R一致');
        });

        if (resultCount) resultCount.textContent = matched + 'R';
        if (resultLabel) resultLabel.textContent = filterLabel(filters);
        if (visibleCount) visibleCount.textContent = matched + 'R表示';
        if (empty) empty.hidden = matched !== 0;
    }

    function setRadio(name, value) {
        const input = form.querySelector('input[name="' + name + '"][value="' + value + '"]');
        if (input) input.checked = true;
    }

    function resetFilters() {
        form.reset();
        delete form.dataset.unresolved;
        applyFilters();
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        delete form.dataset.unresolved;
        applyFilters();
    });

    if (resetButton) resetButton.addEventListener('click', resetFilters);

    document.querySelectorAll('[data-quick]').forEach(function (button) {
        button.addEventListener('click', function () {
            resetFilters();
            const quick = button.dataset.quick;
            if (quick === 'late') {
                setRadio('raceBand', 'late');
            } else if (quick === 'exhibition') {
                setRadio('raceStatus', 'exhibition');
            } else if (quick === 'unresolved') {
                form.dataset.unresolved = '1';
            }
            applyFilters();
        });
    });

    applyFilters();
})();

// -----------------------------------------------------------------------------
// 公式締切予定時刻
// -----------------------------------------------------------------------------
(function () {
    'use strict';

    const root = document.getElementById('home-highlights');
    if (!root) return;

    const date = String(root.dataset.date || '').trim();
    const datePrefix = date.replace(/\D/g, '');
    let currentData = null;
    let currentPromise = null;

    const style = document.createElement('style');
    style.textContent = [
        '.race-button{min-height:54px}',
        '.deadline-time{display:block;margin-top:1px;color:#168bc3;font-size:8px;font-style:normal;font-weight:900;line-height:1.05}',
        '.pick-deadline{color:#168bc3;font-weight:900}',
        '.race-button.is-deadline-past{opacity:.48;filter:grayscale(.55);background:#f0ece6!important;border-color:#d8d0c7!important}',
        '.race-button.is-deadline-past .deadline-time{color:#8d8d8d}',
        '.pick-item.is-deadline-past{opacity:.50;filter:grayscale(.45);background:#f3efe9}',
        '.pick-item.is-deadline-past .pick-deadline{color:#8a8a8a}',
        '.pick-item.is-deadline-past .pick-alert-badge{opacity:.75}',
        '.home-deadline-controls{display:inline-flex;align-items:center;gap:5px;margin-left:8px}',
        '.home-deadline-refresh{border:1px solid #9fcce0;border-radius:999px;background:#f5fcff;color:#168bc3;padding:3px 7px;font-size:9px;font-weight:900;cursor:pointer}',
        '.home-deadline-refresh:disabled{opacity:.55;cursor:wait}',
        '.home-deadline-status{font-size:9px;color:#7b8793;font-weight:700}'
    ].join('');
    document.head.appendChild(style);

    function raceCodeFor(place, raceNo) {
        const n = Number(raceNo || 0);
        if (!datePrefix || !place || n < 1 || n > 12) return '';
        return datePrefix + String(place).toUpperCase() + String(n).padStart(2, '0');
    }

    function timeForRow(row) {
        const deadlines = currentData && currentData.deadlines && typeof currentData.deadlines === 'object'
            ? currentData.deadlines
            : {};
        const direct = String(row && row.race_code ? row.race_code : '').toUpperCase();
        const code = direct || raceCodeFor(row && row.place, row && row.race_no);
        return code && deadlines[code] ? String(deadlines[code]) : '';
    }

    function isPastDeadlineTime(time) {
        const value = String(time || '').trim();
        if (!/^\d{2}:\d{2}$/.test(value) || !/^\d{4}-\d{2}-\d{2}$/.test(date)) {
            return false;
        }
        const deadlineMs = Date.parse(date + 'T' + value + ':00+09:00');
        return Number.isFinite(deadlineMs) && Date.now() >= deadlineMs;
    }

    function isPastDeadline(row) {
        return isPastDeadlineTime(timeForRow(row));
    }

    function sortRows(rows) {
        return (Array.isArray(rows) ? rows : []).map(function (row, index) {
            return {row: row, index: index, time: timeForRow(row)};
        }).sort(function (a, b) {
            const at = a.time || '99:99';
            const bt = b.time || '99:99';
            const cmp = at.localeCompare(bt);
            return cmp !== 0 ? cmp : a.index - b.index;
        }).map(function (item) {
            return item.row;
        });
    }

    function codeFromRaceLink(link) {
        try {
            const url = new URL(link.href, window.location.origin);
            const place = String(url.searchParams.get('place') || '').toUpperCase();
            const raceNo = Number(url.searchParams.get('race') || 0);
            return raceCodeFor(place, raceNo);
        } catch (e) {
            return '';
        }
    }

    function decorateRaceButtons() {
        const deadlines = currentData && currentData.deadlines && typeof currentData.deadlines === 'object'
            ? currentData.deadlines
            : {};

        document.querySelectorAll('[data-race-button]').forEach(function (link) {
            const code = codeFromRaceLink(link);
            const time = code ? String(deadlines[code] || '') : '';
            let node = link.querySelector('.deadline-time');
            if (!time) {
                if (node) node.remove();
                link.classList.remove('is-deadline-past');
                return;
            }
            if (!node) {
                node = document.createElement('time');
                node.className = 'deadline-time';
                link.appendChild(node);
            }
            node.textContent = time;
            node.dateTime = date + 'T' + time + ':00+09:00';
            link.classList.toggle('is-deadline-past', isPastDeadlineTime(time));

            const originalTitle = String(link.dataset.deadlineBaseTitle || link.title || '').replace(/\s*\/\s*締切予定\s*\d{2}:\d{2}.*$/, '');
            link.dataset.deadlineBaseTitle = originalTitle;
            link.title = (originalTitle ? originalTitle + ' / ' : '')
                + '締切予定 ' + time
                + (isPastDeadlineTime(time) ? '（締切時刻経過）' : '');
        });
    }

    const summaryLegend = document.querySelector('.summary-legend');
    let refreshButton = null;
    let statusNode = null;
    if (summaryLegend) {
        const controls = document.createElement('span');
        controls.className = 'home-deadline-controls';

        statusNode = document.createElement('span');
        statusNode.className = 'home-deadline-status';
        statusNode.textContent = '時刻取得中';
        controls.appendChild(statusNode);

        refreshButton = document.createElement('button');
        refreshButton.type = 'button';
        refreshButton.className = 'home-deadline-refresh';
        refreshButton.textContent = '時刻更新';
        controls.appendChild(refreshButton);
        summaryLegend.appendChild(controls);
    }

    function updateStatus(data) {
        if (!statusNode) return;
        if (!data || (data.status !== 'ok' && data.status !== 'partial')) {
            statusNode.textContent = '時刻取得失敗';
            return;
        }
        const complete = Number(data.complete_places || 0);
        const requested = Number(data.requested_places || 0);
        statusNode.textContent = '公式時刻 ' + complete + '/' + requested + '場'
            + (data.cache && data.cache.used ? '・保存済' : '');
    }

    function refreshClockState() {
        if (!currentData) return;
        decorateRaceButtons();
        document.dispatchEvent(new CustomEvent('boatrace:clock'));
    }

    async function load(force) {
        if (currentPromise && !force) return currentPromise;

        if (refreshButton) {
            refreshButton.disabled = true;
            refreshButton.textContent = force ? '更新中…' : '取得中…';
        }

        currentPromise = fetch(
            '/web/home_deadlines_api.php?date=' + encodeURIComponent(date) + (force ? '&force=1' : ''),
            {cache: 'no-store'}
        ).then(async function (response) {
            const data = await response.json();
            if ((!response.ok && data.status !== 'partial') || !data || !data.deadlines) {
                throw new Error(String((data && data.error) || ('HTTP ' + response.status)));
            }
            currentData = data;
            decorateRaceButtons();
            updateStatus(data);
            document.dispatchEvent(new CustomEvent('boatrace:deadlines', {detail: data}));
            return data;
        }).catch(function (error) {
            updateStatus(null);
            throw error;
        }).finally(function () {
            if (refreshButton) {
                refreshButton.disabled = false;
                refreshButton.textContent = '時刻更新';
            }
            currentPromise = null;
        });

        return currentPromise;
    }

    window.BoatRaceHomeDeadlines = {
        load: load,
        timeForRow: timeForRow,
        sortRows: sortRows,
        isPastDeadline: isPastDeadline,
        isPastDeadlineTime: isPastDeadlineTime,
        getData: function () { return currentData; }
    };

    if (refreshButton) {
        refreshButton.addEventListener('click', function () {
            load(true).catch(function () {});
        });
    }

    window.setInterval(refreshClockState, 30000);
    window.BoatRaceDeadlinesPromise = load(false).catch(function () { return null; });
})();

// -----------------------------------------------------------------------------
// 荒れ警戒
// -----------------------------------------------------------------------------
(function () {
    'use strict';

    const root = document.getElementById('home-highlights');
    const list = document.getElementById('upset-pick-list');
    const meta = document.getElementById('upset-pick-meta');
    if (!root || !list) return;

    const DISPLAY_LIMIT = 8;
    let showAll = false;
    let latestData = null;

    const card = list.closest('.pick-card-upset');
    if (card) {
        const subtitle = card.querySelector('.pick-card-head small');
        const mode = card.querySelector('.pick-mode');
        const rule = card.querySelector('.pick-rule');
        if (subtitle) subtitle.textContent = '展示前から使える荒れサインを一覧化';
        if (mode) mode.textContent = '展示不要';
        if (rule) rule.textContent = '直近1年の決まり手を共通判定 / 当日展示情報は不使用';
    }

    const date = String(root.dataset.date || '').trim();
    const predictionPath = String(root.dataset.predictionPath || '/web/index.php').trim() || '/web/index.php';

    function clear(node) {
        while (node.firstChild) node.removeChild(node.firstChild);
    }

    function addEmpty(text) {
        clear(list);
        const div = document.createElement('div');
        div.className = 'pick-empty';
        div.textContent = text;
        list.appendChild(div);
    }

    function levelClass(severity) {
        return severity === 'strong' ? 'pick-alert-very-high' : 'pick-alert-attention';
    }

    function raceUrl(row) {
        return predictionPath
            + '?date=' + encodeURIComponent(date)
            + '&place=' + encodeURIComponent(String(row.place || ''))
            + '&race=' + encodeURIComponent(String(row.race_no || ''));
    }

    function pct(value) {
        const n = Number(value);
        return Number.isFinite(n) ? n.toFixed(1) + '%' : '-';
    }

    function deadlineFor(row) {
        return window.BoatRaceHomeDeadlines
            ? window.BoatRaceHomeDeadlines.timeForRow(row)
            : '';
    }

    function pastDeadline(row) {
        return window.BoatRaceHomeDeadlines
            ? window.BoatRaceHomeDeadlines.isPastDeadline(row)
            : false;
    }

    function sortedRows(rows) {
        return window.BoatRaceHomeDeadlines
            ? window.BoatRaceHomeDeadlines.sortRows(rows)
            : rows.slice();
    }

    function appendRow(row) {
        const link = document.createElement('a');
        link.className = 'pick-item pick-item-upset';
        link.href = raceUrl(row);
        if (pastDeadline(row)) {
            link.classList.add('is-deadline-past');
            link.title = '締切予定時刻を過ぎています';
        }

        const main = document.createElement('div');
        main.className = 'pick-item-main';

        const title = document.createElement('strong');
        const time = deadlineFor(row);
        title.textContent = (time ? time + ' ' : '') + String(row.venue || row.place || '') + ' ' + Number(row.race_no || 0) + 'R';
        if (time) title.classList.add('pick-deadline');
        main.appendChild(title);

        const badge = document.createElement('span');
        badge.className = 'pick-alert-badge ' + levelClass(String(row.severity || 'watch'));
        badge.textContent = String(row.badge || '荒れ注意');
        main.appendChild(badge);
        link.appendChild(main);

        const sub = document.createElement('div');
        sub.className = 'pick-item-sub';

        const chaos = document.createElement('span');
        chaos.textContent = String(row.primary || '平常');
        sub.appendChild(chaos);

        const nige = document.createElement('span');
        nige.textContent = '1C逃げ ' + pct(row.nige);
        sub.appendChild(nige);

        const attack = document.createElement('span');
        attack.textContent = '外攻め最大 ' + pct(row.attack_max);
        sub.appendChild(attack);

        const note = document.createElement('b');
        note.textContent = '展示前判定';
        sub.appendChild(note);

        link.appendChild(sub);
        list.appendChild(link);
    }

    function render(data) {
        if (!data || data.status !== 'ok') {
            throw new Error(String((data && data.error) || '荒れ判定を取得できませんでした。'));
        }

        latestData = data;
        const rawRows = Array.isArray(data.rows) ? data.rows : [];
        const rows = sortedRows(rawRows);
        clear(list);

        if (!rows.length) {
            addEmpty(Number(data.evaluated_races || 0) > 0
                ? '現在、荒れ警戒に該当するレースなし'
                : '決まり手データの判定対象レースなし');
        } else {
            const visibleRows = showAll ? rows : rows.slice(0, DISPLAY_LIMIT);
            visibleRows.forEach(appendRow);
        }

        if (meta) {
            clear(meta);

            const total = Number(data.total_alerts || rows.length || 0);
            const evaluated = Number(data.evaluated_races || 0);
            const waiting = Number(data.waiting_races || 0);

            const info = document.createElement('div');
            info.textContent = '警戒 ' + total + 'R / 判定 ' + evaluated + 'R'
                + (waiting > 0 ? ' / 母数待ち ' + waiting + 'R' : '')
                + (data.cache_used ? ' / キャッシュ' : '');
            meta.appendChild(info);

            if (rows.length > DISPLAY_LIMIT) {
                const toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.className = 'search-reset';
                toggle.textContent = showAll
                    ? DISPLAY_LIMIT + '件表示に戻す'
                    : 'すべて表示（' + rows.length + 'R）';
                toggle.addEventListener('click', function () {
                    showAll = !showAll;
                    render(data);
                    if (!showAll && card) {
                        card.scrollIntoView({behavior: 'smooth', block: 'start'});
                    }
                });
                meta.appendChild(toggle);
            }
        }
    }

    async function load() {
        try {
            const response = await fetch(
                '/web/home_highlights_api.php?date=' + encodeURIComponent(date),
                {cache: 'no-store'}
            );
            const data = await response.json();
            if (!response.ok && (!data || data.status !== 'ok')) {
                throw new Error(String((data && data.error) || ('HTTP ' + response.status)));
            }
            render(data);
        } catch (error) {
            addEmpty('荒れ判定の読み込みに失敗しました');
            if (meta) meta.textContent = String(error && error.message ? error.message : error);
        }
    }

    document.addEventListener('boatrace:deadlines', function () {
        if (latestData) render(latestData);
    });
    document.addEventListener('boatrace:clock', function () {
        if (latestData) render(latestData);
    });

    load();
})();

// -----------------------------------------------------------------------------
// カチカチ候補
// -----------------------------------------------------------------------------
(function () {
    'use strict';

    const root = document.getElementById('home-highlights');
    const card = document.querySelector('.pick-card-solid');
    if (!root || !card) return;

    const list = card.querySelector('.pick-list');
    if (!list) return;

    const DISPLAY_LIMIT = 5;
    const oldMore = card.querySelector('.pick-more');
    if (oldMore) oldMore.remove();

    let meta = card.querySelector('.pick-meta');
    if (!meta) {
        meta = document.createElement('div');
        meta.className = 'pick-meta';
        list.insertAdjacentElement('afterend', meta);
    }

    const date = String(root.dataset.date || '').trim();
    const predictionPath = String(root.dataset.predictionPath || '/web/index.php').trim() || '/web/index.php';
    let rows = null;
    let showAll = false;

    function clear(node) {
        while (node.firstChild) node.removeChild(node.firstChild);
    }

    function raceUrl(row) {
        return predictionPath
            + '?date=' + encodeURIComponent(date)
            + '&place=' + encodeURIComponent(String(row.place || ''))
            + '&race=' + encodeURIComponent(String(row.race_no || ''));
    }

    function deadlineFor(row) {
        return window.BoatRaceHomeDeadlines
            ? window.BoatRaceHomeDeadlines.timeForRow(row)
            : '';
    }

    function pastDeadline(row) {
        return window.BoatRaceHomeDeadlines
            ? window.BoatRaceHomeDeadlines.isPastDeadline(row)
            : false;
    }

    function sortedRows(input) {
        return window.BoatRaceHomeDeadlines
            ? window.BoatRaceHomeDeadlines.sortRows(input)
            : input.slice();
    }

    function appendRow(row) {
        const link = document.createElement('a');
        link.className = 'pick-item pick-item-solid';
        link.href = raceUrl(row);
        if (pastDeadline(row)) {
            link.classList.add('is-deadline-past');
            link.title = '締切予定時刻を過ぎています';
        }

        const main = document.createElement('div');
        main.className = 'pick-item-main';

        const title = document.createElement('strong');
        const time = deadlineFor(row);
        title.textContent = (time ? time + ' ' : '') + String(row.venue || row.place || '') + ' ' + Number(row.race_no || 0) + 'R';
        if (time) title.classList.add('pick-deadline');
        main.appendChild(title);

        const status = document.createElement('span');
        status.textContent = String(row.status || '展示前');
        main.appendChild(status);
        link.appendChild(main);

        const sub = document.createElement('div');
        sub.className = 'pick-item-sub';

        const lane1 = document.createElement('span');
        lane1.textContent = '① ' + Number(row.lane1_rate || 0).toFixed(2);
        sub.appendChild(lane1);

        const outer = document.createElement('span');
        outer.textContent = '外最高 ' + Number(row.outer_max || 0).toFixed(2);
        sub.appendChild(outer);

        const gap = document.createElement('b');
        const gapValue = Number(row.gap || 0);
        gap.textContent = '差 ' + (gapValue >= 0 ? '+' : '') + gapValue.toFixed(2);
        sub.appendChild(gap);

        link.appendChild(sub);
        list.appendChild(link);
    }

    function render() {
        if (!Array.isArray(rows)) return;

        const ordered = sortedRows(rows);
        const visible = showAll ? ordered : ordered.slice(0, DISPLAY_LIMIT);
        clear(list);
        visible.forEach(appendRow);

        clear(meta);
        if (ordered.length > DISPLAY_LIMIT) {
            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'search-reset';
            toggle.textContent = showAll
                ? DISPLAY_LIMIT + '件表示に戻す'
                : 'すべて表示（' + ordered.length + 'R）';
            toggle.addEventListener('click', function () {
                showAll = !showAll;
                render();
                if (!showAll) {
                    card.scrollIntoView({behavior: 'smooth', block: 'start'});
                }
            });
            meta.appendChild(toggle);
        }
    }

    async function loadAll() {
        try {
            const response = await fetch(
                '/web/home_solid_candidates_api.php?date=' + encodeURIComponent(date),
                {cache: 'no-store'}
            );
            const data = await response.json();
            if (!response.ok || !data || data.status !== 'ok') {
                throw new Error(String((data && data.error) || ('HTTP ' + response.status)));
            }
            rows = Array.isArray(data.rows) ? data.rows : [];
            render();
        } catch (error) {
            // 初期PHP描画は残す。API失敗だけでTOPを壊さない。
        }
    }

    document.addEventListener('boatrace:deadlines', function () {
        if (Array.isArray(rows)) render();
    });
    document.addEventListener('boatrace:clock', function () {
        if (Array.isArray(rows)) render();
    });

    loadAll();
})();
