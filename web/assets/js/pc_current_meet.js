(function () {
    'use strict';

    const COLORS = {
        1: {line: '#cbd5e1', text: '#0f172a'},
        2: {line: '#1e293b', text: '#0f172a'},
        3: {line: '#ef4444', text: '#0f172a'},
        4: {line: '#3b82f6', text: '#0f172a'},
        5: {line: '#eab308', text: '#0f172a'},
        6: {line: '#22c55e', text: '#0f172a'}
    };

    function getRaceCode() {
        const code = (document.querySelector('.code-value')?.textContent || '').trim().toUpperCase();
        return /^\d{8}[A-Z]{3}\d{2}$/.test(code) ? code : '';
    }

    function findInsertRow() {
        const table = document.querySelector('.matrix-table');
        if (!table) return null;
        const rows = Array.from(table.querySelectorAll('tbody > tr'));
        return rows.find(function (row) {
            const first = row.cells && row.cells[0] ? row.cells[0].textContent.trim() : '';
            return first === '最終二次予想スコア';
        }) || null;
    }

    function formatSt(value) {
        const s = String(value == null ? '' : value).trim();
        return s || '-';
    }

    function formatFinish(value) {
        const s = String(value == null ? '' : value).trim();
        if (!s) return '-';
        return /^\d+$/.test(s) ? s + '着' : s;
    }

    function buildHeaderRow() {
        const tr = document.createElement('tr');
        tr.id = 'pc-current-meet-header';
        const td = document.createElement('td');
        td.colSpan = 7;
        td.textContent = '📈 今節成績（公式）';
        td.style.cssText = [
            'text-align:left',
            'padding:8px 12px',
            'font-weight:bold',
            'color:#38bdf8',
            'background:#1e293b',
            'border-top:2px solid #334155',
            'border-bottom:1px solid #334155'
        ].join(';');
        tr.appendChild(td);
        return tr;
    }

    function buildDataRow(boats) {
        const tr = document.createElement('tr');
        tr.id = 'pc-current-meet-row';

        const label = document.createElement('td');
        label.textContent = '今節';
        label.style.cssText = [
            'position:sticky',
            'left:0',
            'z-index:1',
            'background:#0f172a',
            'color:#f8fafc',
            'font-weight:bold',
            'border-right:2px solid #334155',
            'vertical-align:top',
            'padding:10px 12px'
        ].join(';');
        tr.appendChild(label);

        for (let boat = 1; boat <= 6; boat++) {
            const info = boats[boat] || boats[String(boat)] || {};
            const records = Array.isArray(info.records) ? info.records : [];
            const td = document.createElement('td');
            td.style.cssText = [
                'vertical-align:top',
                'padding:8px 7px',
                'background:#fbf8f2',
                'text-align:center'
            ].join(';');

            const wrap = document.createElement('div');
            wrap.style.cssText = [
                'border-top:4px solid ' + (COLORS[boat]?.line || '#94a3b8'),
                'border-radius:4px',
                'padding-top:6px'
            ].join(';');

            const summary = document.createElement('div');
            const runCount = Number(info.run_count || records.length || 0);
            const avg = Number(info.average_st);
            summary.textContent = (runCount ? runCount + '走' : '-') + ' / 平均ST ' + (Number.isFinite(avg) ? avg.toFixed(2) : '-');
            summary.style.cssText = 'font-size:11px;font-weight:700;color:#64748b;margin-bottom:6px;white-space:nowrap;';
            wrap.appendChild(summary);

            if (!records.length) {
                const empty = document.createElement('div');
                empty.textContent = '-';
                empty.style.cssText = 'padding:8px 0;color:#94a3b8;';
                wrap.appendChild(empty);
            } else {
                records.forEach(function (record, idx) {
                    const card = document.createElement('div');
                    card.style.cssText = [
                        'padding:6px 2px',
                        idx ? 'border-top:1px solid #e2e8f0' : ''
                    ].filter(Boolean).join(';');

                    const race = document.createElement('div');
                    race.textContent = record.race_no ? String(record.race_no) + 'R' : '-';
                    race.style.cssText = 'font-size:12px;font-weight:800;color:#2563eb;line-height:1.25;';

                    const finish = document.createElement('div');
                    const course = Number(record.course);
                    const courseText = course >= 1 && course <= 6 ? String(course) + 'C' : '-';
                    finish.textContent = formatFinish(record.finish) + '（' + courseText + '）';
                    finish.style.cssText = 'font-size:12px;font-weight:800;color:#0f172a;line-height:1.35;';

                    const st = document.createElement('div');
                    st.textContent = formatSt(record.st_raw);
                    st.style.cssText = 'font-size:12px;color:#475569;line-height:1.3;';

                    card.appendChild(race);
                    card.appendChild(finish);
                    card.appendChild(st);
                    wrap.appendChild(card);
                });
            }

            td.appendChild(wrap);
            tr.appendChild(td);
        }

        return tr;
    }

    async function render() {
        if (document.getElementById('pc-current-meet-header')) return true;
        const raceCode = getRaceCode();
        const anchor = findInsertRow();
        if (!raceCode || !anchor) return false;

        try {
            const res = await fetch('/web/current_meet_api.php?race_code=' + encodeURIComponent(raceCode), {
                credentials: 'same-origin',
                cache: 'no-store'
            });
            if (!res.ok) return false;
            const data = await res.json();
            const boats = data && data.status === 'ok' && data.boats ? data.boats : null;
            if (!boats) return false;

            const header = buildHeaderRow();
            const row = buildDataRow(boats);
            anchor.insertAdjacentElement('afterend', row);
            row.insertAdjacentElement('beforebegin', header);
            return true;
        } catch (e) {
            console.warn('[pc-current-meet] load failed', e);
            return false;
        }
    }

    function setup() {
        let tries = 80;
        function run() {
            render().then(function (ok) {
                if (!ok && tries-- > 0) {
                    window.setTimeout(run, 80);
                }
            });
        }
        run();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup);
    } else {
        setup();
    }
})();
