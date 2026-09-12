(function () {
    'use strict';

    function findHead1Card() {
        const title = Array.from(document.querySelectorAll('div')).find(function (node) {
            return node.children.length === 0
                && String(node.textContent || '').trim() === '🎯 1号艇1着時の2着率';
        });
        if (!title) return null;

        let node = title.parentElement;
        while (node && node !== document.body) {
            if (node.tagName === 'DIV' && node.querySelector('table')) return node;
            node = node.parentElement;
        }
        return null;
    }

    function parsePercent(text) {
        const value = parseFloat(String(text || '').replace(/,/g, '').replace('%', '').replace('pt', ''));
        return Number.isFinite(value) ? value : null;
    }

    function exactaByCourse(panel) {
        const out = {};
        if (!panel) return out;

        Array.from(panel.querySelectorAll('tbody tr')).forEach(function (row) {
            if (!row.cells || row.cells.length < 5) return;

            const courseText = String(row.cells[1].textContent || '');
            const match = courseText.match(/→\s*([1-6])C/);
            if (!match) return;

            const course = Number(match[1]);
            const venue = parsePercent(row.cells[2].textContent);
            const ai = parsePercent(row.cells[3].textContent);
            const diff = parsePercent(row.cells[4].textContent);

            out[course] = {
                venue: venue,
                ai: ai,
                diff: diff
            };
        });

        return out;
    }

    function makeCell(text, options) {
        const cell = document.createElement('td');
        const opts = options || {};
        cell.textContent = text;
        cell.style.padding = '10px 8px';
        cell.style.textAlign = opts.align || 'center';
        cell.style.fontWeight = opts.bold ? 'bold' : 'normal';
        cell.style.fontSize = opts.size || '14px';
        cell.style.color = opts.color || '#cbd5e1';
        if (opts.left) cell.style.textAlign = 'left';
        return cell;
    }

    function buildRow(className, label, values, styleForValue) {
        const row = document.createElement('tr');
        row.className = className;
        row.style.borderTop = '1px solid #334155';
        row.appendChild(makeCell(label, {left: true, bold: true, color: '#f8fafc', size: '13px'}));

        for (let course = 1; course <= 6; course++) {
            const value = values[course];
            const opts = typeof styleForValue === 'function'
                ? styleForValue(value, course)
                : {};
            row.appendChild(makeCell(value === null || value === undefined ? '-' : value, opts));
        }
        return row;
    }

    function merge() {
        const head1Card = findHead1Card();
        const exactaPanel = document.getElementById('head1-exacta-panel');
        if (!head1Card || !exactaPanel) return false;

        const table = head1Card.querySelector('table');
        const tbody = table && table.tBodies ? table.tBodies[0] : null;
        if (!tbody) return false;

        const map = exactaByCourse(exactaPanel);
        if (!Object.keys(map).length) return false;

        // 既存の独立カードは計算・他JS参照用にDOMへ残し、表示だけまとめる。
        exactaPanel.style.display = 'none';

        Array.from(tbody.querySelectorAll('.pc-head1-exacta-merged')).forEach(function (row) {
            row.remove();
        });

        const venueValues = {};
        const aiValues = {};
        const diffValues = {};
        for (let course = 1; course <= 6; course++) {
            if (course === 1 || !map[course]) {
                venueValues[course] = '-';
                aiValues[course] = '-';
                diffValues[course] = '-';
                continue;
            }

            venueValues[course] = map[course].venue === null ? '-' : map[course].venue.toFixed(1) + '%';
            aiValues[course] = map[course].ai === null ? '-' : map[course].ai.toFixed(1) + '%';
            diffValues[course] = map[course].diff === null
                ? '-'
                : (map[course].diff >= 0 ? '+' : '') + map[course].diff.toFixed(1) + 'pt';
        }

        const section = document.createElement('tr');
        section.className = 'pc-head1-exacta-merged';
        section.style.borderTop = '2px solid #475569';
        const sectionCell = document.createElement('td');
        sectionCell.colSpan = 7;
        sectionCell.style.cssText = 'padding:9px 8px;text-align:left;font-size:12px;font-weight:bold;color:#fbbf24;background:#172033;';
        sectionCell.textContent = '今回AI（イン1Cが1着の場合）';
        section.appendChild(sectionCell);
        tbody.appendChild(section);

        tbody.appendChild(buildRow(
            'pc-head1-exacta-merged',
            'AI側場平均',
            venueValues,
            function () { return {color: '#cbd5e1', size: '14px'}; }
        ));

        tbody.appendChild(buildRow(
            'pc-head1-exacta-merged',
            'AI2着率',
            aiValues,
            function () { return {color: '#a78bfa', size: '18px', bold: true}; }
        ));

        tbody.appendChild(buildRow(
            'pc-head1-exacta-merged',
            'AI場平均との差',
            diffValues,
            function (value, course) {
                const diff = map[course] ? map[course].diff : null;
                return {
                    color: diff !== null && diff >= 0 ? '#fbbf24' : '#94a3b8',
                    size: '13px',
                    bold: true
                };
            }
        ));

        let note = head1Card.querySelector('.pc-head1-exacta-note');
        if (!note) {
            note = document.createElement('div');
            note.className = 'pc-head1-exacta-note';
            note.style.cssText = 'margin-top:7px;font-size:11px;color:#94a3b8;line-height:1.5;';
            head1Card.appendChild(note);
        }
        note.textContent = 'AI2着率：最終3連単出目確率から P(2着 | 1C頭) を集約。AI側場平均・差は従来の「イン1着時 2連単」と同じ値。';

        return true;
    }

    function schedule() {
        let tries = 100;
        function run() {
            if (merge()) {
                window.setTimeout(merge, 200);
                window.setTimeout(merge, 700);
                return;
            }
            if (tries-- > 0) window.setTimeout(run, 50);
        }
        run();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            window.setTimeout(schedule, 30);
        });
    } else {
        window.setTimeout(schedule, 30);
    }
})();
