(function () {
    'use strict';

    const STORAGE_KEY = 'boatracePcMainTab';
    const UNIT = 100;

    function number(value) {
        const n = Number(value);
        return Number.isFinite(n) ? n : 0;
    }

    function yen(value) {
        return Math.round(number(value)).toLocaleString('ja-JP') + '円';
    }

    function pct(value, digits) {
        return (number(value) * 100).toFixed(digits == null ? 2 : digits) + '%';
    }

    function normalizeText(value) {
        return String(value || '')
            .replace(/[１-６]/g, function (char) {
                return String(char.charCodeAt(0) - 0xFEE0);
            })
            .replace(/[－–—→>]/g, '-')
            .replace(/[、，,／/\n\r\t]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function unique(values) {
        return Array.from(new Set(values));
    }

    function expandTicketText(value, type) {
        const source = normalizeText(value);
        if (!source) return [];
        const results = [];
        const tokens = source.split(' ');

        tokens.forEach(function (token) {
            const parts = token.split('-').filter(Boolean);
            if ((type === 'exacta' && parts.length !== 2) || (type === 'trifecta' && parts.length !== 3)) {
                return;
            }
            if (parts.some(function (part) { return !/^[1-6]+$/.test(part); })) return;

            const sets = parts.map(function (part) {
                return unique(part.split('').map(Number));
            });

            sets[0].forEach(function (a) {
                sets[1].forEach(function (b) {
                    if (a === b) return;
                    if (type === 'exacta') {
                        results.push(a + '-' + b);
                        return;
                    }
                    sets[2].forEach(function (c) {
                        if (c === a || c === b) return;
                        results.push(a + '-' + b + '-' + c);
                    });
                });
            });
        });

        return unique(results);
    }

    function parsePercent(cell) {
        const n = parseFloat(String(cell ? cell.textContent : '').replace(/,/g, '').replace('%', ''));
        return Number.isFinite(n) ? n / 100 : 0;
    }

    function combinationFromCell(cell, length) {
        const boats = (String(cell ? cell.textContent : '').match(/[1-6]/g) || []).slice(0, length);
        return boats.length === length ? boats.join('-') : '';
    }

    function deriveOutcomes() {
        const tableBox = document.getElementById('web-trifecta-all-table');
        const table = tableBox ? tableBox.querySelector('table') : null;
        const tbody = table && table.tBodies ? table.tBodies[0] : null;
        if (!tbody || tbody.rows.length !== 120) return [];

        return Array.from(tbody.rows).map(function (row) {
            const key = combinationFromCell(row.cells[1], 3);
            const boats = key.split('-').map(Number);
            return {
                key: key,
                first: boats[0] || 0,
                second: boats[1] || 0,
                third: boats[2] || 0,
                probability: parsePercent(row.cells[3])
            };
        }).filter(function (row) {
            return /^([1-6])-([1-6])-([1-6])$/.test(row.key) && row.probability >= 0;
        });
    }

    function visibleExactaKeys() {
        const table = document.querySelector('.pc-exacta-table');
        const tbody = table && table.tBodies ? table.tBodies[0] : null;
        if (!tbody) return [];
        return unique(Array.from(tbody.rows).map(function (row) {
            return combinationFromCell(row.cells[1], 2);
        }).filter(Boolean));
    }

    function visibleTrifectaKeys() {
        const tableBox = document.getElementById('web-trifecta-all-table');
        const table = tableBox ? tableBox.querySelector('table') : null;
        const tbody = table && table.tBodies ? table.tBodies[0] : null;
        if (!tbody) return [];
        return unique(Array.from(tbody.rows).filter(function (row) {
            return row.style.display !== 'none';
        }).map(function (row) {
            return combinationFromCell(row.cells[1], 3);
        }).filter(Boolean));
    }

    function waitForReady(callback, retry) {
        const tabs = document.querySelector('.pc-main-tabs');
        const exactaPanel = document.querySelector('.pc-main-tab-panel[data-pc-main-panel="exacta"]');
        const trifectaPanel = document.querySelector('.pc-main-tab-panel[data-pc-main-panel="trifecta"]');
        const recentPanel = document.querySelector('.pc-main-tab-panel[data-pc-main-panel="recent"]');
        const recentButton = tabs ? tabs.querySelector('[data-pc-main-tab="recent"]') : null;
        const outcomes = deriveOutcomes();

        if (tabs && exactaPanel && trifectaPanel && recentPanel && recentButton && outcomes.length === 120) {
            callback(tabs, recentPanel, recentButton, outcomes);
            return;
        }
        if (retry <= 0) return;
        window.setTimeout(function () {
            waitForReady(callback, retry - 1);
        }, 50);
    }

    function setup() {
        waitForReady(function (tabs, recentPanel, recentButton, outcomes) {
            if (tabs.querySelector('[data-pc-main-tab="simulator"]')) return;

            const raceCodeNode = document.querySelector('.code-value');
            const raceCode = String(raceCodeNode ? raceCodeNode.textContent : '').trim();
            if (!/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/.test(raceCode)) return;

            tabs.style.gridTemplateColumns = 'repeat(6, minmax(0, 1fr))';

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'pc-main-tab';
            button.dataset.pcMainTab = 'simulator';
            button.textContent = '買い目';
            tabs.insertBefore(button, recentButton);

            const panel = document.createElement('div');
            panel.className = 'pc-main-tab-panel pc-bet-simulator-panel';
            panel.dataset.pcMainPanel = 'simulator';
            panel.hidden = true;
            recentPanel.insertAdjacentElement('beforebegin', panel);

            panel.innerHTML = ''
                + '<div style="margin:0 0 14px;background:#f8f4ec;border:1px solid #d8cdbc;border-radius:8px;color:#3f4b5a;overflow:hidden;">'
                + '  <div style="padding:14px;">'
                + '    <div style="font-size:16px;font-weight:bold;color:#aa741f;">💰 買い目シミュレーター</div>'
                + '    <div style="font-size:12px;color:#6b7785;margin-top:3px;">2連単＋3連単を同時購入した時の結果別払戻を計算。同じ着順では両方の的中払戻を合算します。</div>'
                + '    <div class="pc-sim-odds-status" style="margin-top:9px;padding:7px 9px;border:1px solid #d8cdbc;border-radius:6px;background:#fffaf2;font-size:12px;color:#6b7785;">公式オッズ：読み込み中…</div>'
                + '  </div>'
                + '  <div style="padding:0 14px 14px;display:grid;gap:10px;">'
                + '    <div style="display:grid;grid-template-columns:minmax(120px,180px) minmax(180px,240px) 1fr;gap:8px;align-items:end;">'
                + '      <label style="font-size:12px;font-weight:bold;color:#6b7785;">予算'
                + '        <input class="pc-sim-budget" type="number" min="100" step="100" value="1000" style="display:block;width:100%;box-sizing:border-box;margin-top:5px;">'
                + '      </label>'
                + '      <label style="font-size:12px;font-weight:bold;color:#6b7785;">自動配分'
                + '        <select class="pc-sim-mode" style="display:block;width:100%;box-sizing:border-box;margin-top:5px;">'
                + '          <option value="equal">均等配分</option>'
                + '          <option value="minpayout">的中時最低払戻を重視</option>'
                + '        </select>'
                + '      </label>'
                + '      <div style="display:flex;gap:6px;justify-content:flex-end;">'
                + '        <button type="button" class="pc-sim-reload" style="width:auto;padding:8px 12px;background:#fff;color:#1683bd;border:1px solid #1683bd;">オッズ再読込</button>'
                + '        <button type="button" class="pc-sim-allocate" style="width:auto;padding:8px 12px;">自動再配分</button>'
                + '      </div>'
                + '    </div>'
                + '    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">'
                + '      <div style="padding:10px;border:1px solid #d8cdbc;border-radius:6px;background:#f2ece2;">'
                + '        <label style="font-size:12px;font-weight:bold;color:#6b7785;">2連単買い目'
                + '          <textarea class="pc-sim-exacta" rows="3" placeholder="例 1-2 1-4 / 1-24 も可" style="display:block;width:100%;box-sizing:border-box;margin-top:5px;padding:8px;border:1px solid #cbbda9;border-radius:5px;background:#fffdf9;color:#2b3440;"></textarea>'
                + '        </label>'
                + '        <button type="button" class="pc-sim-import-exacta" style="width:auto;margin-top:7px;padding:6px 10px;background:#fff;color:#1683bd;border:1px solid #1683bd;">2連単の表示中を取込</button>'
                + '      </div>'
                + '      <div style="padding:10px;border:1px solid #d8cdbc;border-radius:6px;background:#f2ece2;">'
                + '        <label style="font-size:12px;font-weight:bold;color:#6b7785;">3連単買い目'
                + '          <textarea class="pc-sim-trifecta" rows="3" placeholder="例 1-2-4 1-4-2 / 1-234-234 も可" style="display:block;width:100%;box-sizing:border-box;margin-top:5px;padding:8px;border:1px solid #cbbda9;border-radius:5px;background:#fffdf9;color:#2b3440;"></textarea>'
                + '        </label>'
                + '        <button type="button" class="pc-sim-import-trifecta" style="width:auto;margin-top:7px;padding:6px 10px;background:#fff;color:#1683bd;border:1px solid #1683bd;">120通りの表示中を取込</button>'
                + '      </div>'
                + '    </div>'
                + '    <div style="display:flex;gap:8px;align-items:center;">'
                + '      <button type="button" class="pc-sim-apply" style="width:auto;padding:8px 16px;">買い目を反映</button>'
                + '      <button type="button" class="pc-sim-clear" style="width:auto;padding:8px 16px;background:#eee6da;color:#4b5866;border:1px solid #cbbda9;">クリア</button>'
                + '      <span class="pc-sim-message" style="font-size:12px;color:#6b7785;"></span>'
                + '    </div>'
                + '    <div class="pc-sim-summary" style="display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:7px;"></div>'
                + '    <div style="overflow-x:auto;">'
                + '      <table class="pc-sim-ticket-table" style="width:100%;min-width:760px;border-collapse:collapse;">'
                + '        <thead><tr style="background:#e8dfd2;"><th>種別</th><th>買い目</th><th>予測確率</th><th>オッズ</th><th>金額</th><th>単独払戻</th></tr></thead>'
                + '        <tbody></tbody>'
                + '      </table>'
                + '    </div>'
                + '    <div style="font-size:13px;font-weight:bold;color:#3f4b5a;margin-top:4px;">結果別払戻</div>'
                + '    <div style="overflow-x:auto;max-height:480px;">'
                + '      <table class="pc-sim-outcome-table" style="width:100%;min-width:720px;border-collapse:collapse;">'
                + '        <thead><tr style="background:#e8dfd2;"><th>3連単結果</th><th>最終出目確率</th><th>的中券</th><th>払戻</th><th>収支</th></tr></thead>'
                + '        <tbody></tbody>'
                + '      </table>'
                + '    </div>'
                + '    <div style="font-size:11px;color:#6b7785;">※100円単位。モデル期待回収率は最終出目確率をそのまま発生確率とみなした参考値で、実際の回収を保証するものではありません。</div>'
                + '  </div>'
                + '</div>';

            const budgetInput = panel.querySelector('.pc-sim-budget');
            const modeSelect = panel.querySelector('.pc-sim-mode');
            const exactaInput = panel.querySelector('.pc-sim-exacta');
            const trifectaInput = panel.querySelector('.pc-sim-trifecta');
            const applyButton = panel.querySelector('.pc-sim-apply');
            const allocateButton = panel.querySelector('.pc-sim-allocate');
            const clearButton = panel.querySelector('.pc-sim-clear');
            const reloadButton = panel.querySelector('.pc-sim-reload');
            const importExactaButton = panel.querySelector('.pc-sim-import-exacta');
            const importTrifectaButton = panel.querySelector('.pc-sim-import-trifecta');
            const message = panel.querySelector('.pc-sim-message');
            const oddsStatus = panel.querySelector('.pc-sim-odds-status');
            const summary = panel.querySelector('.pc-sim-summary');
            const ticketBody = panel.querySelector('.pc-sim-ticket-table tbody');
            const outcomeBody = panel.querySelector('.pc-sim-outcome-table tbody');

            let exactaOdds = {};
            let trifectaOdds = {};
            let tickets = [];

            const outcomeByKey = new Map(outcomes.map(function (row) { return [row.key, row]; }));

            function exactaProbability(key) {
                return outcomes.reduce(function (sum, row) {
                    return row.key.indexOf(key + '-') === 0 ? sum + row.probability : sum;
                }, 0);
            }

            function ticketHits(ticket, outcome) {
                if (ticket.type === 'exacta') return outcome.key.indexOf(ticket.key + '-') === 0;
                return outcome.key === ticket.key;
            }

            function buildTickets() {
                const exactaKeys = expandTicketText(exactaInput.value, 'exacta');
                const trifectaKeys = expandTicketText(trifectaInput.value, 'trifecta');
                const next = [];

                exactaKeys.forEach(function (key) {
                    const odds = Number(exactaOdds[key]);
                    next.push({
                        id: 'E:' + key,
                        type: 'exacta',
                        label: '2連単',
                        key: key,
                        probability: exactaProbability(key),
                        odds: Number.isFinite(odds) && odds > 0 ? odds : null,
                        stake: 0
                    });
                });

                trifectaKeys.forEach(function (key) {
                    const odds = Number(trifectaOdds[key]);
                    const outcome = outcomeByKey.get(key);
                    next.push({
                        id: 'T:' + key,
                        type: 'trifecta',
                        label: '3連単',
                        key: key,
                        probability: outcome ? outcome.probability : 0,
                        odds: Number.isFinite(odds) && odds > 0 ? odds : null,
                        stake: 0
                    });
                });

                tickets = next;
            }

            function normalizedBudget() {
                const raw = Math.max(UNIT, Math.floor(number(budgetInput.value) / UNIT) * UNIT);
                budgetInput.value = String(raw);
                return raw;
            }

            function coveredOutcomes(currentTickets) {
                return outcomes.filter(function (outcome) {
                    return currentTickets.some(function (ticket) {
                        return ticket.stake > 0 && ticketHits(ticket, outcome);
                    });
                });
            }

            function payoutFor(outcome, currentTickets) {
                return currentTickets.reduce(function (sum, ticket) {
                    if (ticket.stake <= 0 || !ticket.odds || !ticketHits(ticket, outcome)) return sum;
                    return sum + ticket.stake * ticket.odds;
                }, 0);
            }

            function minCoveredPayout(currentTickets) {
                const covered = coveredOutcomes(currentTickets);
                if (!covered.length) return 0;
                return Math.min.apply(null, covered.map(function (outcome) {
                    return payoutFor(outcome, currentTickets);
                }));
            }

            function expectedPayout(currentTickets) {
                return outcomes.reduce(function (sum, outcome) {
                    return sum + outcome.probability * payoutFor(outcome, currentTickets);
                }, 0);
            }

            function allocateEqual(budget) {
                const units = Math.floor(budget / UNIT);
                const count = tickets.length;
                if (!count || units < count) return false;

                const base = Math.floor(units / count);
                let remainder = units - base * count;
                const order = tickets.slice().sort(function (a, b) {
                    return b.probability - a.probability;
                });
                tickets.forEach(function (ticket) { ticket.stake = base * UNIT; });
                for (let i = 0; i < order.length && remainder > 0; i++, remainder--) {
                    order[i].stake += UNIT;
                }
                return true;
            }

            function allocateMinPayout(budget) {
                const units = Math.floor(budget / UNIT);
                if (!tickets.length || units < tickets.length) return false;

                tickets.forEach(function (ticket) { ticket.stake = UNIT; });
                let remaining = units - tickets.length;

                while (remaining > 0) {
                    let best = null;
                    tickets.forEach(function (ticket) {
                        ticket.stake += UNIT;
                        const minPayout = minCoveredPayout(tickets);
                        const expected = expectedPayout(tickets);
                        ticket.stake -= UNIT;

                        if (
                            best === null
                            || minPayout > best.minPayout + 0.0001
                            || (Math.abs(minPayout - best.minPayout) <= 0.0001 && expected > best.expected)
                        ) {
                            best = {ticket: ticket, minPayout: minPayout, expected: expected};
                        }
                    });

                    if (!best) break;
                    best.ticket.stake += UNIT;
                    remaining--;
                }
                return true;
            }

            function autoAllocate() {
                if (!tickets.length) {
                    showMessage('買い目を入力してください。', true);
                    renderAll();
                    return;
                }

                const missing = tickets.filter(function (ticket) { return !ticket.odds; });
                if (missing.length) {
                    showMessage('オッズ未取得の買い目があります。オッズ再読込を押してください。', true);
                }

                const budget = normalizedBudget();
                if (Math.floor(budget / UNIT) < tickets.length) {
                    tickets.forEach(function (ticket) { ticket.stake = 0; });
                    showMessage('100円単位では ' + tickets.length + '点すべてを購入できません。予算を ' + yen(tickets.length * UNIT) + ' 以上にしてください。', true);
                    renderAll();
                    return;
                }

                const ok = modeSelect.value === 'minpayout'
                    ? allocateMinPayout(budget)
                    : allocateEqual(budget);
                if (!ok) {
                    showMessage('配分を計算できませんでした。', true);
                } else {
                    showMessage('自動配分しました。各金額は手動でも変更できます。', false);
                }
                renderAll();
            }

            function showMessage(text, error) {
                message.textContent = text || '';
                message.style.color = error ? '#a74932' : '#6b7785';
            }

            function summaryCard(label, value, strong) {
                const div = document.createElement('div');
                div.style.cssText = 'padding:9px;border:1px solid #d8cdbc;border-radius:6px;background:#fffdf9;';
                const l = document.createElement('div');
                l.textContent = label;
                l.style.cssText = 'font-size:11px;color:#6b7785;';
                const v = document.createElement('div');
                v.textContent = value;
                v.style.cssText = 'margin-top:3px;font-size:' + (strong ? '17px' : '15px') + ';font-weight:bold;color:' + (strong ? '#aa741f' : '#3f4b5a') + ';';
                div.appendChild(l);
                div.appendChild(v);
                return div;
            }

            function renderSummary() {
                summary.textContent = '';
                const invest = tickets.reduce(function (sum, ticket) { return sum + ticket.stake; }, 0);
                const budget = normalizedBudget();
                const covered = coveredOutcomes(tickets);
                const coverage = covered.reduce(function (sum, outcome) { return sum + outcome.probability; }, 0);
                const payouts = covered.map(function (outcome) { return payoutFor(outcome, tickets); });
                const minPayout = payouts.length ? Math.min.apply(null, payouts) : 0;
                const maxPayout = payouts.length ? Math.max.apply(null, payouts) : 0;
                const expected = expectedPayout(tickets);
                const roi = invest > 0 ? expected / invest : 0;

                summary.appendChild(summaryCard('購入 / 予算', yen(invest) + ' / ' + yen(budget), false));
                summary.appendChild(summaryCard('的中カバー確率', pct(coverage, 2), true));
                summary.appendChild(summaryCard('的中時最低払戻', payouts.length ? yen(minPayout) : '-', false));
                summary.appendChild(summaryCard('的中時最高払戻', payouts.length ? yen(maxPayout) : '-', false));
                summary.appendChild(summaryCard('モデル期待回収率', invest > 0 ? (roi * 100).toFixed(1) + '%' : '-', true));

                if (invest > budget) {
                    showMessage('購入額が予算を ' + yen(invest - budget) + ' 超えています。', true);
                }
            }

            function renderTickets() {
                ticketBody.textContent = '';
                tickets.forEach(function (ticket) {
                    const tr = document.createElement('tr');
                    tr.style.borderTop = '1px solid #d8cdbc';

                    const cells = [ticket.label, ticket.key, pct(ticket.probability, 2), ticket.odds ? ticket.odds.toFixed(1) : '-'];
                    cells.forEach(function (value, index) {
                        const td = document.createElement('td');
                        td.textContent = value;
                        td.style.padding = '7px 8px';
                        td.style.textAlign = index >= 2 ? 'right' : (index === 0 ? 'center' : 'left');
                        if (index === 1) td.style.fontWeight = 'bold';
                        tr.appendChild(td);
                    });

                    const stakeCell = document.createElement('td');
                    stakeCell.style.cssText = 'padding:5px 8px;text-align:right;';
                    const input = document.createElement('input');
                    input.type = 'number';
                    input.min = '0';
                    input.step = '100';
                    input.value = String(ticket.stake);
                    input.style.cssText = 'width:92px;box-sizing:border-box;text-align:right;padding:5px 7px;';
                    input.addEventListener('change', function () {
                        ticket.stake = Math.max(0, Math.floor(number(input.value) / UNIT) * UNIT);
                        input.value = String(ticket.stake);
                        renderSummary();
                        renderOutcomes();
                        const payoutCell = tr.querySelector('.pc-sim-single-payout');
                        if (payoutCell) payoutCell.textContent = ticket.odds && ticket.stake > 0 ? yen(ticket.stake * ticket.odds) : '-';
                    });
                    stakeCell.appendChild(input);
                    tr.appendChild(stakeCell);

                    const payoutCell = document.createElement('td');
                    payoutCell.className = 'pc-sim-single-payout';
                    payoutCell.style.cssText = 'padding:7px 8px;text-align:right;font-weight:bold;color:#7b6332;';
                    payoutCell.textContent = ticket.odds && ticket.stake > 0 ? yen(ticket.stake * ticket.odds) : '-';
                    tr.appendChild(payoutCell);

                    ticketBody.appendChild(tr);
                });
            }

            function renderOutcomes() {
                outcomeBody.textContent = '';
                const invest = tickets.reduce(function (sum, ticket) { return sum + ticket.stake; }, 0);
                const covered = coveredOutcomes(tickets).map(function (outcome) {
                    const hitTickets = tickets.filter(function (ticket) {
                        return ticket.stake > 0 && ticketHits(ticket, outcome);
                    });
                    return {
                        outcome: outcome,
                        hitTickets: hitTickets,
                        payout: payoutFor(outcome, tickets)
                    };
                }).sort(function (a, b) {
                    if (a.payout === b.payout) return b.outcome.probability - a.outcome.probability;
                    return a.payout - b.payout;
                });

                covered.forEach(function (row) {
                    const tr = document.createElement('tr');
                    tr.style.borderTop = '1px solid #d8cdbc';
                    const net = row.payout - invest;
                    const values = [
                        row.outcome.key,
                        pct(row.outcome.probability, 3),
                        row.hitTickets.map(function (ticket) { return ticket.label + ' ' + ticket.key; }).join(' ＋ '),
                        yen(row.payout),
                        (net >= 0 ? '+' : '') + yen(net)
                    ];
                    values.forEach(function (value, index) {
                        const td = document.createElement('td');
                        td.textContent = value;
                        td.style.padding = '7px 8px';
                        td.style.textAlign = index === 0 || index === 2 ? 'left' : 'right';
                        if (index === 0 || index === 3) td.style.fontWeight = 'bold';
                        if (index === 4) td.style.color = net >= 0 ? '#2f789f' : '#a74932';
                        tr.appendChild(td);
                    });
                    outcomeBody.appendChild(tr);
                });
            }

            function renderAll() {
                renderTickets();
                renderSummary();
                renderOutcomes();
            }

            function applySelections() {
                buildTickets();
                if (!tickets.length) {
                    showMessage('有効な買い目がありません。例：1-2 / 1-2-4', true);
                    renderAll();
                    return;
                }
                autoAllocate();
            }

            async function fetchOdds(url) {
                const body = new URLSearchParams();
                body.set('race_code', raceCode);
                body.set('refresh', '0');
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: body.toString(),
                    cache: 'no-store'
                });
                return response.json();
            }

            async function loadOdds() {
                reloadButton.disabled = true;
                oddsStatus.textContent = '公式オッズ：読み込み中…';
                try {
                    const values = await Promise.all([
                        fetchOdds('/web/official_exacta_odds_api.php'),
                        fetchOdds('/web/official_odds_api.php')
                    ]);
                    const exacta = values[0] || {};
                    const trifecta = values[1] || {};
                    if (exacta.status === 'ok' && Number(exacta.count) === 30) exactaOdds = exacta.odds || {};
                    if (trifecta.status === 'ok' && Number(trifecta.count) === 120) trifectaOdds = trifecta.odds || {};

                    const exactaCount = Object.keys(exactaOdds).length;
                    const trifectaCount = Object.keys(trifectaOdds).length;
                    oddsStatus.textContent = '公式オッズ：2連単 ' + exactaCount + '/30 / 3連単 ' + trifectaCount + '/120（キャッシュ利用）';

                    if (tickets.length) {
                        const stakes = new Map(tickets.map(function (ticket) { return [ticket.id, ticket.stake]; }));
                        buildTickets();
                        tickets.forEach(function (ticket) {
                            ticket.stake = stakes.get(ticket.id) || 0;
                        });
                        renderAll();
                    }
                } catch (e) {
                    oddsStatus.textContent = '公式オッズ：取得エラー';
                } finally {
                    reloadButton.disabled = false;
                }
            }

            function activateSimulator() {
                document.querySelectorAll('.pc-main-tab').forEach(function (tab) {
                    tab.classList.toggle('is-active', tab.dataset.pcMainTab === 'simulator');
                });
                document.querySelectorAll('.pc-main-tab-panel').forEach(function (tabPanel) {
                    const active = tabPanel.dataset.pcMainPanel === 'simulator';
                    tabPanel.classList.toggle('is-active', active);
                    tabPanel.hidden = !active;
                });
                try { sessionStorage.setItem(STORAGE_KEY, 'simulator'); } catch (e) {}
            }

            button.addEventListener('click', activateSimulator);
            applyButton.addEventListener('click', applySelections);
            allocateButton.addEventListener('click', function () {
                if (!tickets.length) buildTickets();
                autoAllocate();
            });
            reloadButton.addEventListener('click', loadOdds);
            clearButton.addEventListener('click', function () {
                exactaInput.value = '';
                trifectaInput.value = '';
                tickets = [];
                showMessage('', false);
                renderAll();
            });
            importExactaButton.addEventListener('click', function () {
                const keys = visibleExactaKeys();
                exactaInput.value = keys.join(' ');
                showMessage('2連単の表示中 ' + keys.length + '点を取り込みました。', false);
            });
            importTrifectaButton.addEventListener('click', function () {
                const keys = visibleTrifectaKeys();
                trifectaInput.value = keys.join(' ');
                showMessage('3連単の表示中 ' + keys.length + '点を取り込みました。', false);
            });
            budgetInput.addEventListener('change', function () {
                if (tickets.length) autoAllocate();
            });
            modeSelect.addEventListener('change', function () {
                if (tickets.length) autoAllocate();
            });

            renderAll();
            loadOdds();

            let saved = '';
            try { saved = sessionStorage.getItem(STORAGE_KEY) || ''; } catch (e) {}
            if (saved === 'simulator') activateSimulator();
        }, 100);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup);
    } else {
        setup();
    }
})();
