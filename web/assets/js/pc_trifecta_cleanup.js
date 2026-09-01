(function () {
    'use strict';

    function cleanup() {
        const panel = document.getElementById('trifecta-reference-panel');
        if (!panel) return;

        const tables = Array.from(panel.querySelectorAll('table'));
        tables.forEach(function (table) {
            const tbody = table.tBodies && table.tBodies[0];
            if (!tbody) return;

            // PC版は検索・絞り込み・ソート付き120通り表を本体とする。
            // 旧Top20プレビュー表だけ非表示にして、同じ内容の二重表示を避ける。
            if (tbody.rows.length === 20) {
                const wrap = table.parentElement;
                if (wrap) wrap.style.display = 'none';
            }
        });

        // 外側の「参考情報：3連単120通り 出目確率」自体が折りたためるため、
        // 内側の「120通りすべて表示」は二重折りたたみになる。
        // 内側は常時展開し、summaryだけ削除して検索・絞り込み・120通り表を直接表示する。
        const allDetails = document.getElementById('trifecta-all-details');
        if (allDetails) {
            allDetails.open = true;
            const summary = allDetails.querySelector(':scope > summary');
            if (summary) summary.remove();
            allDetails.style.marginTop = '10px';
        }

        // 元の120通りスクリプトが行メタ情報を作った後でオッズ列を追加する。
        // 既存の検索・絞り込み・ソートの列位置を壊さないため0ms後へ送る。
        window.setTimeout(setupOfficialOdds, 0);
    }

    function setupOfficialOdds() {
        const panel = document.getElementById('trifecta-reference-panel');
        const allDetails = document.getElementById('trifecta-all-details');
        const tableBox = document.getElementById('web-trifecta-all-table');
        const table = tableBox ? tableBox.querySelector('table') : null;
        const raceCodeNode = document.querySelector('.code-value');
        const raceCode = String(raceCodeNode ? raceCodeNode.textContent : '').trim();

        if (!panel || !allDetails || !table || !/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/.test(raceCode)) {
            return;
        }

        if (document.getElementById('web-official-odds-box')) return;

        ensureOddsColumn(table);

        const box = document.createElement('div');
        box.id = 'web-official-odds-box';
        box.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:0 0 10px;padding:8px 10px;border:1px solid #cbbda9;border-radius:6px;background:#fffaf2;color:#4b5866;font-size:12px;';
        box.innerHTML = '<span class="web-official-odds-status">公式3連単オッズ：取得中…</span>'
            + '<button type="button" class="web-official-odds-refresh" style="padding:5px 10px;border:1px solid #1683bd;border-radius:5px;background:#fff;color:#1683bd;font-weight:bold;cursor:pointer;">更新</button>';
        allDetails.insertAdjacentElement('afterbegin', box);

        const statusNode = box.querySelector('.web-official-odds-status');
        const refreshButton = box.querySelector('.web-official-odds-refresh');

        function oddsText(value) {
            const n = Number(value);
            if (!Number.isFinite(n) || n <= 0) return '-';
            return n.toLocaleString('ja-JP', {
                minimumFractionDigits: Number.isInteger(n) ? 0 : 1,
                maximumFractionDigits: 1
            });
        }

        function combinationKey(row) {
            const cell = row && row.cells ? row.cells[1] : null;
            const boats = (String(cell ? cell.textContent : '').match(/[1-6]/g) || []).slice(0, 3);
            return boats.length === 3 ? boats.join('-') : '';
        }

        function ensureOddsColumn(targetTable) {
            const headRow = targetTable.tHead && targetTable.tHead.rows[0];
            const tbody = targetTable.tBodies && targetTable.tBodies[0];
            if (!headRow || !tbody || headRow.querySelector('[data-official-odds-column="1"]')) return;

            const th = document.createElement('th');
            th.dataset.officialOddsColumn = '1';
            th.textContent = 'オッズ';
            th.title = 'クリックでオッズ順に並べ替え';
            th.style.cssText = 'padding:7px 8px;text-align:right;min-width:90px;cursor:pointer;user-select:none;';
            const beforeHeader = headRow.cells[4] || null;
            headRow.insertBefore(th, beforeHeader);

            Array.from(tbody.rows).forEach(function (row) {
                const td = document.createElement('td');
                td.className = 'web-official-odds-cell';
                td.textContent = '-';
                td.style.cssText = 'padding:7px 8px;text-align:right;font-weight:bold;color:#7b6332;';
                const beforeCell = row.cells[4] || null;
                row.insertBefore(td, beforeCell);
            });

            let direction = 1;
            th.addEventListener('click', function () {
                const rows = Array.from(tbody.rows);
                rows.sort(function (a, b) {
                    const av = Number(a.querySelector('.web-official-odds-cell')?.dataset.odds || Number.POSITIVE_INFINITY);
                    const bv = Number(b.querySelector('.web-official-odds-cell')?.dataset.odds || Number.POSITIVE_INFINITY);
                    if (av === bv) return 0;
                    return (av < bv ? -1 : 1) * direction;
                });
                rows.forEach(function (row) { tbody.appendChild(row); });
                th.textContent = 'オッズ' + (direction > 0 ? ' ▲' : ' ▼');
                direction *= -1;
            });
        }

        function applyOdds(odds) {
            const tbody = table.tBodies && table.tBodies[0];
            if (!tbody) return;

            Array.from(tbody.rows).forEach(function (row) {
                const key = combinationKey(row);
                const cell = row.querySelector('.web-official-odds-cell');
                if (!cell) return;
                const value = key && odds && Object.prototype.hasOwnProperty.call(odds, key)
                    ? Number(odds[key])
                    : NaN;
                cell.textContent = oddsText(value);
                if (Number.isFinite(value) && value > 0) {
                    cell.dataset.odds = String(value);
                } else {
                    delete cell.dataset.odds;
                }
            });
        }

        function formatTime(iso) {
            if (!iso) return '';
            const date = new Date(iso);
            if (Number.isNaN(date.getTime())) return '';
            return new Intl.DateTimeFormat('ja-JP', {
                hour: '2-digit',
                minute: '2-digit',
                hour12: false
            }).format(date);
        }

        function showResult(data) {
            if (!statusNode) return;
            const count = Number(data && data.count ? data.count : 0);
            const time = formatTime(data && data.fetched_at ? data.fetched_at : '');

            if (data && data.status === 'ok' && count === 120) {
                applyOdds(data.odds || {});
                statusNode.textContent = 'オッズ取得 ' + (time || '--:--') + ' / 120通り';
                return;
            }

            const error = data && data.error ? String(data.error) : '公式オッズを取得できませんでした。';
            statusNode.textContent = '公式3連単オッズ：' + error;
        }

        async function loadOdds(force) {
            if (refreshButton) refreshButton.disabled = true;
            if (statusNode) statusNode.textContent = force ? '公式3連単オッズ：更新中…' : '公式3連単オッズ：取得中…';

            try {
                const body = new URLSearchParams();
                body.set('race_code', raceCode);
                body.set('refresh', force ? '1' : '0');

                const response = await fetch('/web/official_odds_api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: body.toString(),
                    cache: 'no-store'
                });
                const data = await response.json();
                showResult(data);
            } catch (e) {
                if (statusNode) statusNode.textContent = '公式3連単オッズ：取得エラー';
            } finally {
                if (refreshButton) refreshButton.disabled = false;
            }
        }

        if (refreshButton) {
            refreshButton.addEventListener('click', function () {
                loadOdds(true);
            });
        }

        loadOdds(false);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', cleanup);
    } else {
        cleanup();
    }
})();
