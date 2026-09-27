(function () {
    'use strict';

    const BOAT_COLORS = {
        1: {bg: '#ffffff', text: '#0f172a', border: '#cbd5e1'},
        2: {bg: '#111827', text: '#ffffff', border: '#111827'},
        3: {bg: '#ef4444', text: '#ffffff', border: '#dc2626'},
        4: {bg: '#3b82f6', text: '#ffffff', border: '#2563eb'},
        5: {bg: '#facc15', text: '#111827', border: '#eab308'},
        6: {bg: '#16a34a', text: '#ffffff', border: '#15803d'}
    };

    let scheduled = false;

    function cell(className, text) {
        const node = document.createElement('div');
        node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function recordsForDay(boat, day) {
        const records = Array.isArray(boat && boat.records) ? boat.records : [];
        return records.filter(function (record) {
            return Number(record && record.day_index) === day;
        });
    }

    function dayCount(data, boats) {
        let max = Number(data && data.day_count) || 0;
        for (let boat = 1; boat <= 6; boat++) {
            const records = Array.isArray((boats[boat] || boats[String(boat)] || {}).records)
                ? (boats[boat] || boats[String(boat)] || {}).records
                : [];
            records.forEach(function (record) {
                max = Math.max(max, Number(record && record.day_index) || 0);
            });
        }
        return max;
    }

    function makeRun(record) {
        const raceNo = Number(record && record.race_no) || 0;
        const finishRaw = String(record && record.finish != null ? record.finish : '').trim();
        const finish = finishRaw === '' ? '-' : (/^\d+$/.test(finishRaw) ? finishRaw + '着' : finishRaw);
        const course = Number(record && record.course) || 0;
        const courseText = course >= 1 && course <= 6 ? course + 'C' : '-';
        const historicalBoat = Number(record && record.boat_number) || 0;
        const color = BOAT_COLORS[historicalBoat] || BOAT_COLORS[course] || null;

        const run = document.createElement('div');
        run.className = 'app-current-meet-run';

        const race = document.createElement('div');
        race.className = 'app-current-meet-race';
        race.textContent = raceNo > 0 ? raceNo + 'R' : '-';
        run.appendChild(race);

        const result = document.createElement('div');
        result.className = 'app-current-meet-result';
        result.textContent = finish + '（' + courseText + '）';
        if (color) {
            result.style.background = color.bg;
            result.style.color = color.text;
            result.style.border = '1px solid ' + color.border;
            result.style.borderRadius = '3px';
            result.style.padding = '2px 4px';
        }
        run.appendChild(result);

        const st = document.createElement('div');
        st.className = 'app-current-meet-st';
        st.textContent = String(record && record.st_raw != null ? record.st_raw : '').trim() || '-';
        run.appendChild(st);

        return run;
    }

    function render(anchor, data) {
        const boats = data && data.boats && typeof data.boats === 'object' ? data.boats : null;
        if (!boats || dayCount(data, boats) <= 0) return;

        const fragment = document.createDocumentFragment();
        fragment.appendChild(cell('app-basic-section', '📈 今節成績（公式）'));
        fragment.appendChild(cell('app-basic-label app-current-meet-label', '概要'));

        for (let boat = 1; boat <= 6; boat++) {
            const info = boats[boat] || boats[String(boat)] || {};
            const records = Array.isArray(info.records) ? info.records : [];
            const runCount = Number(info.run_count || records.length || 0);
            const avg = Number(info.average_st);
            const value = cell('app-basic-value app-current-meet-column app-current-meet-summary-cell');
            const summary = document.createElement('div');
            summary.className = 'app-current-meet-summary';
            summary.textContent = (runCount > 0 ? runCount + '走' : '-')
                + ' / 平均ST ' + (Number.isFinite(avg) ? avg.toFixed(2) : '-');
            value.appendChild(summary);
            fragment.appendChild(value);
        }

        const totalDays = dayCount(data, boats);
        for (let day = 1; day <= totalDays; day++) {
            fragment.appendChild(cell('app-basic-label app-current-meet-label', day + '日目'));
            for (let boat = 1; boat <= 6; boat++) {
                const info = boats[boat] || boats[String(boat)] || {};
                const value = cell('app-basic-value app-current-meet-column');
                const rows = recordsForDay(info, day);
                if (rows.length) {
                    rows.forEach(function (record) { value.appendChild(makeRun(record)); });
                } else {
                    value.appendChild(cell('app-current-meet-empty', '-'));
                }
                fragment.appendChild(value);
            }
        }

        anchor.replaceWith(fragment);
    }

    function load() {
        scheduled = false;
        const anchor = document.getElementById('app-current-meet');
        if (!anchor || anchor.dataset.loading === '1') return;

        const raceCode = String(anchor.dataset.raceCode || '').trim().toUpperCase();
        if (!/^\d{8}[A-Z]{3}\d{2}$/.test(raceCode)) return;
        anchor.dataset.loading = '1';

        fetch('/web/current_meet_api.php?race_code=' + encodeURIComponent(raceCode), {
            credentials: 'same-origin',
            cache: 'no-store'
        }).then(function (response) {
            if (!response.ok) throw new Error('今節成績を取得できませんでした');
            return response.json();
        }).then(function (data) {
            if (data && data.status === 'ok') render(anchor, data);
        }).catch(function () {
            // 表示専用データなので、取得失敗時も初期画面はそのまま使える。
        });
    }

    function scheduleLoad() {
        if (scheduled) return;
        scheduled = true;
        window.setTimeout(load, 250);
    }

    document.addEventListener('boatrace:app-basic-panel-ready', scheduleLoad);
    if (document.readyState !== 'loading') scheduleLoad();
})();
