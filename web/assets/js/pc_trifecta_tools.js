(function () {
    'use strict';

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

    function parseNumber(text) {
        var value = String(text || '')
            .replace(/,/g, '')
            .replace(/[%＋+]/g, '')
            .trim();
        var parsed = parseFloat(value);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function extractBoats(cell) {
        var text = String(cell ? cell.textContent : '');
        var matches = text.match(/[1-6](?=号艇)/g);
        if (matches && matches.length >= 3) {
            return matches.slice(0, 3).map(Number);
        }

        var compact = text.replace(/号艇/g, '').match(/[1-6]/g);
        return compact && compact.length >= 3
            ? compact.slice(0, 3).map(Number)
            : [];
    }

    function findTargetTable() {
        var tables = Array.from(document.querySelectorAll('table'));
        return tables.find(function (table) {
            var tbody = table.tBodies && table.tBodies[0];
            if (!tbody || tbody.rows.length !== 120) return false;
            var headers = Array.from(table.querySelectorAll('thead th')).map(function (th) {
                return th.textContent.trim();
            });
            return headers.some(function (label) { return label.indexOf('3連単') !== -1; })
                && headers.some(function (label) { return label.indexOf('最終出目確率') !== -1; });
        }) || null;
    }

    function addStyles() {
        if (document.getElementById('pc-trifecta-tools-style')) return;
        var style = document.createElement('style');
        style.id = 'pc-trifecta-tools-style';
        style.textContent = [
            '.pc-trifecta-tools{margin:10px 0;padding:10px 12px;border:1px solid #d8cdbc;border-radius:7px;background:#fffaf2;color:#3f4b5a;}',
            '.pc-trifecta-tools-row{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;}',
            '.pc-trifecta-tools-search{display:flex;flex-direction:column;gap:4px;min-width:230px;}',
            '.pc-trifecta-tools-label{font-size:11px;font-weight:700;color:#6b7785;}',
            '.pc-trifecta-tools input{box-sizing:border-box;width:100%;padding:7px 9px;border:1px solid #cfc4b5;border-radius:6px;background:#fff;font-size:12px;color:#334155;}',
            '.pc-trifecta-filter-area{display:flex;flex-direction:column;gap:6px;margin-top:9px;}',
            '.pc-trifecta-filter-group{display:flex;align-items:center;gap:5px;flex-wrap:wrap;}',
            '.pc-trifecta-filter-title{width:34px;font-size:11px;font-weight:700;color:#6b7785;}',
            '.pc-trifecta-filter,.pc-trifecta-clear{border:1px solid #b7ab9a;border-radius:5px;background:#f8f4ec;color:#475569;padding:4px 9px;font-size:11px;font-weight:700;cursor:pointer;}',
            '.pc-trifecta-filter.is-active{background:#334155;color:#fff;border-color:#334155;}',
            '.pc-trifecta-clear{margin-left:auto;background:#fff;}',
            '.pc-trifecta-count{font-size:11px;font-weight:700;color:#6b7785;}',
            '.pc-trifecta-sort{appearance:none;border:0;background:transparent;color:inherit;font:inherit;font-weight:700;cursor:pointer;padding:0;white-space:nowrap;}',
            '.pc-trifecta-sort.is-active{color:#8a5f18;text-decoration:underline;text-underline-offset:3px;}',
            '@media (max-width:760px){.pc-trifecta-tools-search{min-width:100%;}.pc-trifecta-filter-title{width:30px;}}'
        ].join('');
        document.head.appendChild(style);
    }

    function setup() {
        var table = findTargetTable();
        if (!table || table.dataset.pcTrifectaEnhanced === '1') return;
        table.dataset.pcTrifectaEnhanced = '1';

        var tbody = table.tBodies[0];
        var rows = Array.from(tbody.rows);
        if (rows.length !== 120) return;

        addStyles();

        rows.forEach(function (row) {
            var boats = extractBoats(row.cells[1]);
            row.dataset.trifectaKey = boats.join('-');
            row.dataset.trifecta1 = boats[0] || 0;
            row.dataset.trifecta2 = boats[1] || 0;
            row.dataset.trifecta3 = boats[2] || 0;
            row.dataset.rankValue = parseNumber(row.cells[0] ? row.cells[0].textContent : '0');
            row.dataset.combinationValue = boats.length === 3 ? (boats[0] * 100 + boats[1] * 10 + boats[2]) : 999;
            row.dataset.baseValue = parseNumber(row.cells[2] ? row.cells[2].textContent : '0');
            row.dataset.finalValue = parseNumber(row.cells[3] ? row.cells[3].textContent : '0');
            row.dataset.deltaValue = parseNumber(row.cells[4] ? row.cells[4].textContent : '0');
            row.dataset.cumulativeValue = parseNumber(row.cells[5] ? row.cells[5].textContent : '0');
        });

        var controls = document.createElement('div');
        controls.className = 'pc-trifecta-tools';
        controls.innerHTML = ''
            + '<div class="pc-trifecta-tools-row">'
            + '  <label class="pc-trifecta-tools-search">'
            + '    <span class="pc-trifecta-tools-label">買い目検索</span>'
            + '    <input type="text" inputmode="numeric" autocomplete="off" placeholder="例 1 / 1-2 / 1-2-3">'
            + '  </label>'
            + '  <span class="pc-trifecta-count">120 / 120件</span>'
            + '  <button type="button" class="pc-trifecta-clear">クリア</button>'
            + '</div>'
            + '<div class="pc-trifecta-filter-area"></div>';

        var tableWrap = table.parentElement;
        if (!tableWrap || !tableWrap.parentElement) return;
        tableWrap.parentElement.insertBefore(controls, tableWrap);

        var search = controls.querySelector('input');
        var count = controls.querySelector('.pc-trifecta-count');
        var clear = controls.querySelector('.pc-trifecta-clear');
        var filterArea = controls.querySelector('.pc-trifecta-filter-area');
        var selected = [new Set(), new Set(), new Set()];

        ['1着', '2着', '3着'].forEach(function (label, position) {
            var group = document.createElement('div');
            group.className = 'pc-trifecta-filter-group';

            var title = document.createElement('span');
            title.className = 'pc-trifecta-filter-title';
            title.textContent = label;
            group.appendChild(title);

            for (var boat = 0; boat <= 6; boat++) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'pc-trifecta-filter' + (boat === 0 ? ' is-active' : '');
                button.dataset.position = String(position);
                button.dataset.boat = String(boat);
                button.textContent = boat === 0 ? '全' : String(boat);
                button.setAttribute('aria-pressed', boat === 0 ? 'true' : 'false');
                group.appendChild(button);
            }
            filterArea.appendChild(group);
        });

        var sortKeys = ['rank', 'combination', 'base', 'final', 'delta', 'cumulative'];
        var sortKey = 'rank';
        var sortDirection = 1;
        var sortButtons = [];

        Array.from(table.querySelectorAll('thead th')).slice(0, 6).forEach(function (th, index) {
            var label = th.textContent.trim();
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'pc-trifecta-sort';
            button.dataset.sort = sortKeys[index];
            button.dataset.label = label;
            button.textContent = label;
            th.textContent = '';
            th.appendChild(button);
            sortButtons.push(button);
        });

        function updateFilterButtons(group, position) {
            var set = selected[position];
            group.querySelectorAll('.pc-trifecta-filter').forEach(function (button) {
                var boat = Number(button.dataset.boat);
                var active = boat === 0 ? set.size === 0 : set.has(boat);
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        }

        function rowValue(row, key) {
            switch (key) {
                case 'combination': return Number(row.dataset.combinationValue || 999);
                case 'base': return Number(row.dataset.baseValue || 0);
                case 'final': return Number(row.dataset.finalValue || 0);
                case 'delta': return Number(row.dataset.deltaValue || 0);
                case 'cumulative': return Number(row.dataset.cumulativeValue || 0);
                case 'rank':
                default: return Number(row.dataset.rankValue || 999);
            }
        }

        function matches(row) {
            for (var position = 0; position < 3; position++) {
                if (selected[position].size > 0) {
                    var boat = Number(row.dataset['trifecta' + String(position + 1)] || 0);
                    if (!selected[position].has(boat)) return false;
                }
            }

            var query = normalizeSearch(search ? search.value : '');
            if (!query) return true;
            var key = row.dataset.trifectaKey || '';
            return key === query || key.indexOf(query + '-') === 0;
        }

        function updateSortButtons() {
            sortButtons.forEach(function (button) {
                var active = button.dataset.sort === sortKey;
                button.classList.toggle('is-active', active);
                var label = button.dataset.label || '';
                button.textContent = active
                    ? label + (sortDirection > 0 ? ' ▲' : ' ▼')
                    : label;
            });
        }

        function render() {
            var sorted = rows.slice().sort(function (a, b) {
                var av = rowValue(a, sortKey);
                var bv = rowValue(b, sortKey);
                if (av === bv) {
                    return Number(a.dataset.rankValue || 999) - Number(b.dataset.rankValue || 999);
                }
                return (av < bv ? -1 : 1) * sortDirection;
            });

            var visible = 0;
            sorted.forEach(function (row) {
                var show = matches(row);
                row.hidden = !show;
                if (show) visible++;
                tbody.appendChild(row);
            });

            if (count) count.textContent = visible + ' / 120件';
            updateSortButtons();
        }

        filterArea.addEventListener('click', function (event) {
            var button = event.target.closest('.pc-trifecta-filter');
            if (!button) return;
            var position = Number(button.dataset.position);
            var boat = Number(button.dataset.boat);
            var set = selected[position];
            var group = button.parentElement;

            if (boat === 0) {
                set.clear();
            } else if (set.has(boat)) {
                set.delete(boat);
            } else {
                set.add(boat);
                if (set.size === 6) set.clear();
            }
            updateFilterButtons(group, position);
            render();
        });

        sortButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var nextKey = button.dataset.sort || 'rank';
                if (sortKey === nextKey) {
                    sortDirection *= -1;
                } else {
                    sortKey = nextKey;
                    sortDirection = (nextKey === 'rank' || nextKey === 'combination') ? 1 : -1;
                }
                render();
            });
        });

        if (search) search.addEventListener('input', render);
        if (clear) {
            clear.addEventListener('click', function () {
                if (search) search.value = '';
                selected.forEach(function (set) { set.clear(); });
                filterArea.querySelectorAll('.pc-trifecta-filter-group').forEach(function (group, position) {
                    updateFilterButtons(group, position);
                });
                sortKey = 'rank';
                sortDirection = 1;
                render();
            });
        }

        render();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup);
    } else {
        setup();
    }
})();
