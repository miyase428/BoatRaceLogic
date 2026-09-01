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
        const tbody = table.tBodies && table.tBodies[0];
        const countNode = document.getElementById('web-trifecta-count');
        const filters = document.getElementById('web-trifecta-filters');
        const search = document.getElementById('web-trifecta-search');
        const clear = document.getElementById('web-trifecta-clear');

        const selectionSummary = document.createElement('div');
        selectionSummary.id = 'web-trifecta-selection-summary';
        selectionSummary.style.cssText = 'display:flex;gap:10px;flex-wrap:wrap;margin:8px 0 0;padding:8px 10px;border:1px solid #d8cdbc;border-radius:6px;background:#fffdf9;color:#4b5866;font-size:12px;';
        selectionSummary.innerHTML = '<span>最終出目確率合計：<strong class="web-trifecta-probability-sum">--</strong></span>'
            + '<span>合成オッズ：<strong class="web-trifecta-combined-odds">--</strong></span>';
        selectionSummary.title = '合成オッズ = 1 ÷ Σ(1 ÷ 各買い目オッズ)';

        const controls = document.getElementById('web-trifecta-filters')?.parentElement;
        if (controls) {
            controls.appendChild(selectionSummary);
        } else {
            box.insertAdjacentElement('afterend', selectionSummary);
        }

        const probabilitySumNode = selectionSummary.querySelector('.web-trifecta-probability-sum');
        const combinedOddsNode = selectionSummary.querySelector('.web-trifecta-combined-odds');
        let summaryTimer = null;

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
            const body = targetTable.tBodies && targetTable.tBodies[0];
            if (!headRow || !body || headRow.querySelector('[data-official-odds-column="1"]')) return;

            const th = document.createElement('th');
            th.dataset.officialOddsColumn = '1';
            th.textContent = 'オッズ';
            th.title = 'クリックでオッズ順に並べ替え';
            th.style.cssText = 'padding:7px 8px;text-align:right;min-width:90px;cursor:pointer;user-select:none;';
            const beforeHeader = headRow.cells[4] || null;
            headRow.insertBefore(th, beforeHeader);

            Array.from(body.rows).forEach(function (row) {
                const td = document.createElement('td');
                td.className = 'web-official-odds-cell';
                td.textContent = '-';
                td.style.cssText = 'padding:7px 8px;text-align:right;font-weight:bold;color:#7b6332;';
                const beforeCell = row.cells[4] || null;
                row.insertBefore(td, beforeCell);
            });

            let direction = 1;
            th.addEventListener('click', function () {
                const rows = Array.from(body.rows);
                rows.sort(function (a, b) {
                    const av = Number(a.querySelector('.web-official-odds-cell')?.dataset.odds || Number.POSITIVE_INFINITY);
                    const bv = Number(b.querySelector('.web-official-odds-cell')?.dataset.odds || Number.POSITIVE_INFINITY);
                    if (av === bv) return 0;
                    return (av < bv ? -1 : 1) * direction;
                });
                rows.forEach(function (row) { body.appendChild(row); });
                th.textContent = 'オッズ' + (direction > 0 ? ' ▲' : ' ▼');
                direction *= -1;
                scheduleSelectionSummary();
            });
        }

        function applyOdds(odds) {
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
            scheduleSelectionSummary();
        }

        function parsePercent(cell) {
            const value = parseFloat(String(cell ? cell.textContent : '').replace(/,/g, '').replace('%', ''));
            return Number.isFinite(value) ? value : 0;
        }

        function updateSelectionSummary() {
            if (!tbody) return;

            const visibleRows = Array.from(tbody.rows).filter(function (row) {
                return row.style.display !== 'none';
            });

            const probabilitySum = visibleRows.reduce(function (sum, row) {
                return sum + parsePercent(row.cells[3]);
            }, 0);

            let inverseOddsSum = 0;
            let oddsReady = visibleRows.length > 0;
            visibleRows.forEach(function (row) {
                const cell = row.querySelector('.web-official-odds-cell');
                const odds = Number(cell?.dataset.odds || 0);
                if (!Number.isFinite(odds) || odds <= 0) {
                    oddsReady = false;
                    return;
                }
                inverseOddsSum += 1 / odds;
            });

            const combinedOdds = oddsReady && inverseOddsSum > 0 ? 1 / inverseOddsSum : null;

            if (countNode) {
                countNode.textContent = '表示中：' + visibleRows.length + ' / 120通り';
            }
            if (probabilitySumNode) {
                probabilitySumNode.textContent = probabilitySum.toFixed(2) + '%';
            }
            if (combinedOddsNode) {
                combinedOddsNode.textContent = combinedOdds === null ? '取得待ち' : combinedOdds.toFixed(2) + '倍';
            }
        }

        function scheduleSelectionSummary() {
            if (summaryTimer !== null) window.clearTimeout(summaryTimer);
            summaryTimer = window.setTimeout(function () {
                summaryTimer = null;
                updateSelectionSummary();
            }, 0);
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
            scheduleSelectionSummary();
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
                scheduleSelectionSummary();
            } finally {
                if (refreshButton) refreshButton.disabled = false;
            }
        }

        if (refreshButton) {
            refreshButton.addEventListener('click', function () {
                loadOdds(true);
            });
        }

        // 既存の絞り込み処理がイベント内で行表示を更新した後に再集計する。
        if (filters) filters.addEventListener('click', scheduleSelectionSummary);
        if (search) search.addEventListener('input', scheduleSelectionSummary);
        if (clear) clear.addEventListener('click', scheduleSelectionSummary);

        if (tbody && typeof MutationObserver !== 'undefined') {
            const observer = new MutationObserver(scheduleSelectionSummary);
            observer.observe(tbody, {
                childList: true,
                subtree: false,
                attributes: true,
                attributeFilter: ['style']
            });
        }

        scheduleSelectionSummary();
        loadOdds(false);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', cleanup);
    } else {
        cleanup();
    }
})();
