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

    function levelClass(level) {
        if (level === 'very_high') return 'pick-alert-very-high';
        if (level === 'high') return 'pick-alert-high';
        return 'pick-alert-attention';
    }

    function raceUrl(row) {
        return predictionPath
            + '?date=' + encodeURIComponent(date)
            + '&place=' + encodeURIComponent(String(row.place || ''))
            + '&race=' + encodeURIComponent(String(row.race_no || ''));
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
                : '展示済み・結果前の判定対象レースなし');
        } else {
            rows.forEach(function (row) {
                const link = document.createElement('a');
                link.className = 'pick-item pick-item-upset';
                link.href = raceUrl(row);

                const main = document.createElement('div');
                main.className = 'pick-item-main';

                const title = document.createElement('strong');
                title.textContent = String(row.venue || row.place || '') + ' ' + Number(row.race_no || 0) + 'R';
                main.appendChild(title);

                const badge = document.createElement('span');
                badge.className = 'pick-alert-badge ' + levelClass(String(row.level || 'attention'));
                badge.textContent = String(row.level_label || '注意');
                main.appendChild(badge);
                link.appendChild(main);

                const sub = document.createElement('div');
                sub.className = 'pick-item-sub';

                const inRate = document.createElement('span');
                const rate = Number(row.in_rate);
                inRate.textContent = 'イン補正1着 ' + (Number.isFinite(rate) ? rate.toFixed(1) + '%' : '-');
                sub.appendChild(inRate);

                const current = document.createElement('span');
                current.textContent = '現行本命 ' + Number(row.current_head || 0) + '号';
                sub.appendChild(current);

                const note = document.createElement('b');
                note.textContent = 'イン飛び警報';
                sub.appendChild(note);

                link.appendChild(sub);
                list.appendChild(link);
            });
        }

        if (meta) {
            const total = Number(data.total_alerts || 0);
            const evaluated = Number(data.evaluated_races || 0);
            const candidate = Number(data.candidate_races || 0);
            meta.textContent = '警戒 ' + total + 'R / 判定 ' + evaluated + 'R'
                + (candidate > evaluated ? ' / 準備中 ' + (candidate - evaluated) + 'R' : '')
                + (data.cache_used ? ' / キャッシュ' : '');
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