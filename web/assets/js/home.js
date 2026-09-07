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

(function () {
    'use strict';

    const root = document.getElementById('home-highlights');
    const list = document.getElementById('upset-pick-list');
    const meta = document.getElementById('upset-pick-meta');
    if (!root || !list) return;

    const DISPLAY_LIMIT = 8;
    let showAll = false;

    // 初期HTMLは旧表示との互換を残しているため、荒れ警戒だけ正しい用途へ置換する。
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

    function appendRow(row) {
        const link = document.createElement('a');
        link.className = 'pick-item pick-item-upset';
        link.href = raceUrl(row);

        const main = document.createElement('div');
        main.className = 'pick-item-main';

        const title = document.createElement('strong');
        title.textContent = String(row.venue || row.place || '') + ' ' + Number(row.race_no || 0) + 'R';
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

        const rows = Array.isArray(data.rows) ? data.rows : [];
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

    load();
})();

(function () {
    'use strict';

    const root = document.getElementById('home-highlights');
    const card = document.querySelector('.pick-card-solid');
    if (!root || !card) return;

    const list = card.querySelector('.pick-list');
    const more = card.querySelector('.pick-more');
    if (!list || !more) return;

    const DISPLAY_LIMIT = list.querySelectorAll('.pick-item-solid').length;
    const moreMatch = String(more.textContent || '').match(/(\d+)R/);
    const moreCount = moreMatch ? Number(moreMatch[1]) : 0;
    if (!Number.isFinite(moreCount) || moreCount <= 0 || DISPLAY_LIMIT <= 0) return;

    const totalFromInitial = DISPLAY_LIMIT + moreCount;
    more.remove();
    const compactHtml = list.innerHTML;

    const meta = document.createElement('div');
    meta.className = 'pick-meta';
    list.insertAdjacentElement('afterend', meta);

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'search-reset';
    meta.appendChild(toggle);

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

    function appendRow(row) {
        const link = document.createElement('a');
        link.className = 'pick-item pick-item-solid';
        link.href = raceUrl(row);

        const main = document.createElement('div');
        main.className = 'pick-item-main';

        const title = document.createElement('strong');
        title.textContent = String(row.venue || row.place || '') + ' ' + Number(row.race_no || 0) + 'R';
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

    function updateButton(total) {
        toggle.textContent = showAll
            ? DISPLAY_LIMIT + '件表示に戻す'
            : 'すべて表示（' + total + 'R）';
    }

    async function loadAll() {
        if (Array.isArray(rows)) return rows;

        toggle.disabled = true;
        toggle.textContent = '読み込み中…';
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
            return rows;
        } finally {
            toggle.disabled = false;
        }
    }

    toggle.addEventListener('click', async function () {
        if (showAll) {
            showAll = false;
            list.innerHTML = compactHtml;
            updateButton(Array.isArray(rows) ? rows.length : totalFromInitial);
            card.scrollIntoView({behavior: 'smooth', block: 'start'});
            return;
        }

        try {
            const allRows = await loadAll();
            clear(list);
            allRows.forEach(appendRow);
            showAll = true;
            updateButton(allRows.length);
        } catch (error) {
            showAll = false;
            updateButton(totalFromInitial);
            const message = document.createElement('div');
            message.className = 'pick-empty';
            message.textContent = 'カチカチ候補の全件取得に失敗しました';
            list.appendChild(message);
            window.setTimeout(function () {
                if (!showAll) list.innerHTML = compactHtml;
            }, 2200);
        }
    });

    updateButton(totalFromInitial);
})();