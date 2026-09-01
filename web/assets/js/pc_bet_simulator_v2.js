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

    function unique(values) {
        return Array.from(new Set(values));
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

    function expandTicketText(value, type) {
        const source = normalizeText(value);
        if (!source) return [];
        const results = [];

        source.split(' ').forEach(function (token) {
            const parts = token.split('-').filter(Boolean);
            if ((type === 'exacta' && parts.length !== 2) || (type === 'trifecta' && parts.length !== 3)) return;
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
            return /^([1-6])-([1-6])-([1-6])$/.test(row.key);
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

            const tabButton = document.createElement('button');
            tabButton.type = 'button';
            tabButton.className = 'pc-main-tab';
            tabButton.dataset.pcMainTab = 'simulator';
            tabButton.textContent = '買い目';
            tabs.insertBefore(tabButton, recentButton);

            const panel = document.createElement('div');
            panel.className = 'pc-main-tab-panel pc-bet-simulator-panel';
            panel.dataset.pcMainPanel = 'simulator';
            panel.hidden = true;
            recentPanel.insertAdjacentElement('beforebegin', panel);

            panel.innerHTML = ''
                + '<div style="margin:0 0 14px;background:#f8f4ec;border:1px solid #d8cdbc;border-radius:8px;color:#3f4b5a;overflow:hidden;">'
                + '  <div style="padding:14px;">'
                + '    <div style="font-size:16px;font-weight:bold;color:#aa741f;">💰 買い目シミュレーター</div>'
                + '    <div style="font-size:12px;color:#6b7785;margin-top:3px;">艇を選んでフォーメーションを作成 → 候補から購入舟券へ追加 → 1,000円などの予算を自動配分。</div>'
                + '    <div class="pc-sim-odds-status" style="margin-top:9px;padding:7px 9px;border:1px solid #d8cdbc;border-radius:6px;background:#fffaf2;font-size:12px;color:#6b7785;">公式オッズ：読み込み中…</div>'
                + '  </div>'
                + '  <div style="padding:0 14px 14px;display:grid;gap:12px;">'
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
                + '      <div style="display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap;">'
                + '        <button type="button" class="pc-sim-reload" style="width:auto;padding:8px 12px;background:#fff;color:#1683bd;border:1px solid #1683bd;">オッズ更新</button>'
                + '        <button type="button" class="pc-sim-allocate" style="width:auto;padding:8px 12px;">自動再配分</button>'
                + '      </div>'
                + '    </div>'

                + '    <div style="border:1px solid #d8cdbc;border-radius:8px;background:#f2ece2;padding:10px;">'
                + '      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">'
                + '        <div style="font-size:14px;font-weight:bold;color:#3f4b5a;">① 舟券を選択</div>'
                + '        <div style="display:flex;gap:6px;">'
                + '          <button type="button" class="pc-sim-type pc-sim-type-trifecta" data-type="trifecta" style="width:auto;padding:7px 14px;">3連単</button>'
                + '          <button type="button" class="pc-sim-type pc-sim-type-exacta" data-type="exacta" style="width:auto;padding:7px 14px;background:#fff;color:#1683bd;border:1px solid #1683bd;">2連単</button>'
                + '        </div>'
                + '      </div>'
                + '      <div class="pc-sim-formation" style="display:grid;gap:8px;margin-top:10px;"></div>'
                + '      <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-top:9px;">'
                + '        <div class="pc-sim-candidate-summary" style="font-size:12px;font-weight:bold;color:#6b7785;">候補：0点</div>'
                + '        <div style="display:flex;gap:6px;">'
                + '          <button type="button" class="pc-sim-selection-clear" style="width:auto;padding:6px 10px;background:#eee6da;color:#4b5866;border:1px solid #cbbda9;">選択クリア</button>'
                + '          <button type="button" class="pc-sim-add-all" style="width:auto;padding:6px 12px;">候補を全部追加</button>'
                + '        </div>'
                + '      </div>'
                + '      <div style="overflow-x:auto;max-height:360px;margin-top:8px;">'
                + '        <table class="pc-sim-candidate-table" style="width:100%;min-width:650px;border-collapse:collapse;">'
                + '          <thead><tr style="background:#e8dfd2;"><th>券種</th><th>買い目</th><th>最終出目確率</th><th>オッズ</th><th>追加</th></tr></thead>'
                + '          <tbody></tbody>'
                + '        </table>'
                + '      </div>'
                + '    </div>'

                + '    <details style="border:1px solid #d8cdbc;border-radius:7px;background:#fffaf2;">'
                + '      <summary style="cursor:pointer;padding:9px 11px;font-size:12px;font-weight:bold;color:#6b7785;">直接入力・現在の絞り込みを追加（詳細）</summary>'
                + '      <div style="padding:0 10px 10px;display:grid;grid-template-columns:1fr 1fr;gap:10px;">'
                + '        <div><label style="font-size:12px;font-weight:bold;color:#6b7785;">2連単直接入力<textarea class="pc-sim-exacta" rows="2" placeholder="例 1-2 1-4 / 1-24" style="display:block;width:100%;box-sizing:border-box;margin-top:5px;padding:8px;border:1px solid #cbbda9;border-radius:5px;background:#fffdf9;color:#2b3440;"></textarea></label><div style="display:flex;gap:6px;margin-top:6px;"><button type="button" class="pc-sim-direct-exacta" style="width:auto;padding:6px 10px;">入力を追加</button><button type="button" class="pc-sim-import-exacta" style="width:auto;padding:6px 10px;background:#fff;color:#1683bd;border:1px solid #1683bd;">2連単表示中を追加</button></div></div>'
                + '        <div><label style="font-size:12px;font-weight:bold;color:#6b7785;">3連単直接入力<textarea class="pc-sim-trifecta" rows="2" placeholder="例 1-2-4 1-4-2 / 1-234-234" style="display:block;width:100%;box-sizing:border-box;margin-top:5px;padding:8px;border:1px solid #cbbda9;border-radius:5px;background:#fffdf9;color:#2b3440;"></textarea></label><div style="display:flex;gap:6px;margin-top:6px;"><button type="button" class="pc-sim-direct-trifecta" style="width:auto;padding:6px 10px;">入力を追加</button><button type="button" class="pc-sim-import-trifecta" style="width:auto;padding:6px 10px;background:#fff;color:#1683bd;border:1px solid #1683bd;">120通り表示中を追加</button></div></div>'
                + '      </div>'
                + '    </details>'

                + '    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">'
                + '      <div style="font-size:14px;font-weight:bold;color:#3f4b5a;">② 購入する舟券</div>'
                + '      <button type="button" class="pc-sim-clear" style="width:auto;padding:6px 12px;background:#eee6da;color:#4b5866;border:1px solid #cbbda9;">購入候補クリア</button>'
                + '    </div>'
                + '    <div class="pc-sim-message" style="font-size:12px;color:#6b7785;"></div>'
                + '    <div class="pc-sim-summary" style="display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:7px;"></div>'
                + '    <div style="overflow-x:auto;">'
                + '      <table class="pc-sim-ticket-table" style="width:100%;min-width:820px;border-collapse:collapse;">'
                + '        <thead><tr style="background:#e8dfd2;"><th>種別</th><th>買い目</th><th>予測確率</th><th>オッズ</th><th>金額</th><th>単独払戻</th><th>削除</th></tr></thead>'
                + '        <tbody></tbody>'
                + '      </table>'
                + '    </div>'
                + '    <div style="font-size:13px;font-weight:bold;color:#3f4b5a;margin-top:4px;">③ 結果別払戻</div>'
                + '    <div style="overflow-x:auto;max-height:480px;">'
                + '      <table class="pc-sim-outcome-table" style="width:100%;min-width:720px;border-collapse:collapse;">'
                + '        <thead><tr style="background:#e8dfd2;"><th>3連単結果</th><th>最終出目確率</th><th>的中券</th><th>払戻</th><th>収支</th></tr></thead>'
                + '        <tbody></tbody>'
                + '      </table>'
                + '    </div>'
                + '    <div style="font-size:11px;color:#6b7785;">※100円単位。同じ結果で2連単と3連単が当たる場合は払戻を合算。モデル期待回収率は参考値です。</div>'
                + '  </div>'
                + '</div>';

            const budgetInput = panel.querySelector('.pc-sim-budget');
            const modeSelect = panel.querySelector('.pc-sim-mode');
            const reloadButton = panel.querySelector('.pc-sim-reload');
            const allocateButton = panel.querySelector('.pc-sim-allocate');
            const typeButtons = Array.from(panel.querySelectorAll('.pc-sim-type'));
            const formation = panel.querySelector('.pc-sim-formation');
            const candidateSummary = panel.querySelector('.pc-sim-candidate-summary');
            const candidateBody = panel.querySelector('.pc-sim-candidate-table tbody');
            const addAllButton = panel.querySelector('.pc-sim-add-all');
            const selectionClearButton = panel.querySelector('.pc-sim-selection-clear');
            const exactaInput = panel.querySelector('.pc-sim-exacta');
            const trifectaInput = panel.querySelector('.pc-sim-trifecta');
            const directExactaButton = panel.querySelector('.pc-sim-direct-exacta');
            const directTrifectaButton = panel.querySelector('.pc-sim-direct-trifecta');
            const importExactaButton = panel.querySelector('.pc-sim-import-exacta');
            const importTrifectaButton = panel.querySelector('.pc-sim-import-trifecta');
            const clearButton = panel.querySelector('.pc-sim-clear');
            const message = panel.querySelector('.pc-sim-message');
            const oddsStatus = panel.querySelector('.pc-sim-odds-status');
            const summary = panel.querySelector('.pc-sim-summary');
            const ticketBody = panel.querySelector('.pc-sim-ticket-table tbody');
            const outcomeBody = panel.querySelector('.pc-sim-outcome-table tbody');

            let exactaOdds = {};
            let trifectaOdds = {};
            let tickets = [];
            let candidateType = 'trifecta';
            const selected = {
                exacta: [new Set(), new Set()],
                trifecta: [new Set(), new Set(), new Set()]
            };
            const outcomeByKey = new Map(outcomes.map(function (row) { return [row.key, row]; }));

            function exactaProbability(key) {
                return outcomes.reduce(function (sum, row) {
                    return row.key.indexOf(key + '-') === 0 ? sum + row.probability : sum;
                }, 0);
            }

            function oddsFor(type, key) {
                const source = type === 'exacta' ? exactaOdds : trifectaOdds;
                const value = Number(source[key]);
                return Number.isFinite(value) && value > 0 ? value : null;
            }

            function makeTicket(type, key) {
                const outcome = type === 'trifecta' ? outcomeByKey.get(key) : null;
                return {
                    id: (type === 'exacta' ? 'E:' : 'T:') + key,
                    type: type,
                    label: type === 'exacta' ? '2連単' : '3連単',
                    key: key,
                    probability: type === 'exacta' ? exactaProbability(key) : (outcome ? outcome.probability : 0),
                    odds: oddsFor(type, key),
                    stake: 0
                };
            }

            function ticketHits(ticket, outcome) {
                if (ticket.type === 'exacta') return outcome.key.indexOf(ticket.key + '-') === 0;
                return outcome.key === ticket.key;
            }

            function formationKeys(type) {
                const sets = selected[type];
                if (sets.some(function (set) { return set.size === 0; })) return [];
                const keys = [];
                const aList = Array.from(sets[0]).sort();
                const bList = Array.from(sets[1]).sort();

                aList.forEach(function (a) {
                    bList.forEach(function (b) {
                        if (a === b) return;
                        if (type === 'exacta') {
                            keys.push(a + '-' + b);
                            return;
                        }
                        Array.from(sets[2]).sort().forEach(function (c) {
                            if (c === a || c === b) return;
                            keys.push(a + '-' + b + '-' + c);
                        });
                    });
                });
                return unique(keys);
            }

            function currentCandidates() {
                return formationKeys(candidateType).map(function (key) {
                    return makeTicket(candidateType, key);
                }).sort(function (a, b) {
                    if (a.probability === b.probability) return a.key.localeCompare(b.key, 'ja');
                    return b.probability - a.probability;
                });
            }

            function combinedOdds(list) {
                let inverse = 0;
                let usable = 0;
                list.forEach(function (ticket) {
                    if (ticket.odds) {
                        inverse += 1 / ticket.odds;
                        usable++;
                    }
                });
                return usable === list.length && usable > 0 && inverse > 0 ? 1 / inverse : null;
            }

            function boatButtonStyle(button, boat, active) {
                const palette = {
                    1: ['#ffffff', '#202020', '#bfc3c7'],
                    2: ['#1f2937', '#ffffff', '#1f2937'],
                    3: ['#ef4444', '#ffffff', '#dc2626'],
                    4: ['#3b82f6', '#ffffff', '#2563eb'],
                    5: ['#facc15', '#222222', '#d4a900'],
                    6: ['#22c55e', '#ffffff', '#16a34a']
                };
                const c = palette[boat];
                button.style.cssText = 'width:auto;min-width:46px;padding:7px 10px;border-radius:5px;font-weight:bold;cursor:pointer;border:2px solid ' + (active ? '#1683bd' : c[2]) + ';background:' + c[0] + ';color:' + c[1] + ';box-shadow:' + (active ? '0 0 0 2px rgba(22,131,189,.18)' : 'none') + ';';
            }

            function renderTypeButtons() {
                typeButtons.forEach(function (button) {
                    const active = button.dataset.type === candidateType;
                    button.style.background = active ? '#1683bd' : '#fff';
                    button.style.color = active ? '#fff' : '#1683bd';
                    button.style.border = '1px solid #1683bd';
                });
            }

            function renderFormation() {
                formation.textContent = '';
                const positions = candidateType === 'exacta' ? ['1着', '2着'] : ['1着', '2着', '3着'];
                formation.style.gridTemplateColumns = 'repeat(' + positions.length + ', minmax(0, 1fr))';

                positions.forEach(function (label, position) {
                    const box = document.createElement('div');
                    box.style.cssText = 'border:1px solid #d8cdbc;border-radius:6px;background:#fffdf9;padding:8px;';

                    const title = document.createElement('div');
                    title.textContent = label;
                    title.style.cssText = 'font-size:12px;font-weight:bold;color:#3f4b5a;margin-bottom:7px;text-align:center;';
                    box.appendChild(title);

                    const controls = document.createElement('div');
                    controls.style.cssText = 'display:flex;gap:5px;flex-wrap:wrap;justify-content:center;';

                    const allButton = document.createElement('button');
                    allButton.type = 'button';
                    allButton.textContent = '全';
                    const allActive = selected[candidateType][position].size === 6;
                    allButton.style.cssText = 'width:auto;min-width:46px;padding:7px 10px;border-radius:5px;font-weight:bold;cursor:pointer;border:2px solid ' + (allActive ? '#1683bd' : '#cbbda9') + ';background:' + (allActive ? '#e7f4fb' : '#eee6da') + ';color:#3f4b5a;';
                    allButton.addEventListener('click', function () {
                        const set = selected[candidateType][position];
                        set.clear();
                        if (!allActive) {
                            for (let boat = 1; boat <= 6; boat++) set.add(boat);
                        }
                        renderFormation();
                        renderCandidates();
                    });
                    controls.appendChild(allButton);

                    for (let boat = 1; boat <= 6; boat++) {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.textContent = String(boat);
                        const active = selected[candidateType][position].has(boat);
                        boatButtonStyle(btn, boat, active);
                        btn.addEventListener('click', function () {
                            const set = selected[candidateType][position];
                            if (set.has(boat)) set.delete(boat); else set.add(boat);
                            renderFormation();
                            renderCandidates();
                        });
                        controls.appendChild(btn);
                    }

                    box.appendChild(controls);
                    formation.appendChild(box);
                });
            }

            function makeCombinationNode(key) {
                const wrap = document.createElement('span');
                wrap.style.whiteSpace = 'nowrap';
                key.split('-').forEach(function (part, index) {
                    const boat = Number(part);
                    if (index > 0) {
                        const sep = document.createElement('span');
                        sep.textContent = '-';
                        sep.style.cssText = 'margin:0 4px;color:#8a8176;';
                        wrap.appendChild(sep);
                    }
                    const badge = document.createElement('span');
                    badge.textContent = String(boat) + '号艇';
                    badge.style.cssText = 'display:inline-block;min-width:42px;padding:2px 6px;border-radius:4px;font-size:12px;font-weight:bold;text-align:center;';
                    const styles = {
                        1: ['#fff', '#222', '#c9c9c9'], 2: ['#1f2937', '#fff', '#1f2937'], 3: ['#ef4444', '#fff', '#ef4444'],
                        4: ['#3b82f6', '#fff', '#3b82f6'], 5: ['#facc15', '#222', '#d7aa00'], 6: ['#22c55e', '#fff', '#22c55e']
                    };
                    const c = styles[boat];
                    badge.style.background = c[0];
                    badge.style.color = c[1];
                    badge.style.border = '1px solid ' + c[2];
                    wrap.appendChild(badge);
                });
                return wrap;
            }

            function addKeys(type, keys, auto) {
                let added = 0;
                keys.forEach(function (key) {
                    const ticket = makeTicket(type, key);
                    if (!tickets.some(function (row) { return row.id === ticket.id; })) {
                        tickets.push(ticket);
                        added++;
                    }
                });
                if (added > 0 && auto !== false) autoAllocate();
                else renderAll();
                renderCandidates();
                return added;
            }

            function renderCandidates() {
                const list = currentCandidates();
                candidateBody.textContent = '';
                const probSum = list.reduce(function (sum, row) { return sum + row.probability; }, 0);
                const combo = combinedOdds(list);
                candidateSummary.textContent = '候補：' + list.length + '点 / 確率合計：' + pct(probSum, 2) + ' / 合成オッズ：' + (combo === null ? '取得待ち' : combo.toFixed(2) + '倍');
                addAllButton.disabled = list.length === 0;

                if (!list.length) {
                    const tr = document.createElement('tr');
                    const td = document.createElement('td');
                    td.colSpan = 5;
                    td.textContent = '1着・2着' + (candidateType === 'trifecta' ? '・3着' : '') + 'を選択すると候補が表示されます。';
                    td.style.cssText = 'padding:14px;text-align:center;color:#6b7785;';
                    tr.appendChild(td);
                    candidateBody.appendChild(tr);
                    return;
                }

                list.forEach(function (candidate) {
                    const tr = document.createElement('tr');
                    tr.style.borderTop = '1px solid #d8cdbc';

                    const type = document.createElement('td');
                    type.textContent = candidate.label;
                    type.style.cssText = 'padding:7px 8px;text-align:center;';
                    tr.appendChild(type);

                    const comboCell = document.createElement('td');
                    comboCell.style.cssText = 'padding:7px 8px;';
                    comboCell.appendChild(makeCombinationNode(candidate.key));
                    tr.appendChild(comboCell);

                    const probability = document.createElement('td');
                    probability.textContent = pct(candidate.probability, 3);
                    probability.style.cssText = 'padding:7px 8px;text-align:right;font-weight:bold;color:#aa741f;';
                    tr.appendChild(probability);

                    const odds = document.createElement('td');
                    odds.textContent = candidate.odds ? candidate.odds.toFixed(1) : '-';
                    odds.style.cssText = 'padding:7px 8px;text-align:right;font-weight:bold;color:#7b6332;';
                    tr.appendChild(odds);

                    const action = document.createElement('td');
                    action.style.cssText = 'padding:5px 8px;text-align:center;';
                    const add = document.createElement('button');
                    add.type = 'button';
                    const already = tickets.some(function (row) { return row.id === candidate.id; });
                    add.textContent = already ? '追加済' : '追加';
                    add.disabled = already;
                    add.style.cssText = 'width:auto;padding:5px 10px;';
                    add.addEventListener('click', function () {
                        addKeys(candidate.type, [candidate.key], true);
                    });
                    action.appendChild(add);
                    tr.appendChild(action);

                    candidateBody.appendChild(tr);
                });
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
                if (!tickets.length || units < tickets.length) return false;
                const base = Math.floor(units / tickets.length);
                let remainder = units - base * tickets.length;
                const order = tickets.slice().sort(function (a, b) { return b.probability - a.probability; });
                tickets.forEach(function (ticket) { ticket.stake = base * UNIT; });
                for (let i = 0; i < order.length && remainder > 0; i++, remainder--) order[i].stake += UNIT;
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
                        if (best === null || minPayout > best.minPayout + 0.0001 || (Math.abs(minPayout - best.minPayout) <= 0.0001 && expected > best.expected)) {
                            best = {ticket: ticket, minPayout: minPayout, expected: expected};
                        }
                    });
                    if (!best) break;
                    best.ticket.stake += UNIT;
                    remaining--;
                }
                return true;
            }

            function showMessage(text, error) {
                message.textContent = text || '';
                message.style.color = error ? '#a74932' : '#6b7785';
            }

            function autoAllocate() {
                if (!tickets.length) {
                    renderAll();
                    return;
                }

                const budget = normalizedBudget();
                if (Math.floor(budget / UNIT) < tickets.length) {
                    tickets.forEach(function (ticket) { ticket.stake = 0; });
                    showMessage('現在 ' + tickets.length + '点。100円ずつ購入するには最低 ' + yen(tickets.length * UNIT) + ' 必要です。候補を減らすか予算を増やしてください。', true);
                    renderAll();
                    return;
                }

                const ok = modeSelect.value === 'minpayout' ? allocateMinPayout(budget) : allocateEqual(budget);
                showMessage(ok ? '自動配分しました。金額は個別に変更できます。' : '配分を計算できませんでした。', !ok);
                renderAll();
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
            }

            function renderTickets() {
                ticketBody.textContent = '';
                if (!tickets.length) {
                    const tr = document.createElement('tr');
                    const td = document.createElement('td');
                    td.colSpan = 7;
                    td.textContent = '上の候補から「追加」すると購入舟券がここに入ります。';
                    td.style.cssText = 'padding:14px;text-align:center;color:#6b7785;';
                    tr.appendChild(td);
                    ticketBody.appendChild(tr);
                    return;
                }

                tickets.forEach(function (ticket) {
                    const tr = document.createElement('tr');
                    tr.style.borderTop = '1px solid #d8cdbc';

                    const type = document.createElement('td');
                    type.textContent = ticket.label;
                    type.style.cssText = 'padding:7px 8px;text-align:center;';
                    tr.appendChild(type);

                    const combo = document.createElement('td');
                    combo.style.cssText = 'padding:7px 8px;';
                    combo.appendChild(makeCombinationNode(ticket.key));
                    tr.appendChild(combo);

                    [pct(ticket.probability, 2), ticket.odds ? ticket.odds.toFixed(1) : '-'].forEach(function (value) {
                        const td = document.createElement('td');
                        td.textContent = value;
                        td.style.cssText = 'padding:7px 8px;text-align:right;';
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

                    const payout = document.createElement('td');
                    payout.className = 'pc-sim-single-payout';
                    payout.textContent = ticket.odds && ticket.stake > 0 ? yen(ticket.stake * ticket.odds) : '-';
                    payout.style.cssText = 'padding:7px 8px;text-align:right;font-weight:bold;color:#7b6332;';
                    tr.appendChild(payout);

                    const removeCell = document.createElement('td');
                    removeCell.style.cssText = 'padding:5px 8px;text-align:center;';
                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.textContent = '削除';
                    remove.style.cssText = 'width:auto;padding:5px 9px;background:#eee6da;color:#4b5866;border:1px solid #cbbda9;';
                    remove.addEventListener('click', function () {
                        tickets = tickets.filter(function (row) { return row.id !== ticket.id; });
                        if (tickets.length) autoAllocate(); else {
                            showMessage('', false);
                            renderAll();
                        }
                        renderCandidates();
                    });
                    removeCell.appendChild(remove);
                    tr.appendChild(removeCell);
                    ticketBody.appendChild(tr);
                });
            }

            function renderOutcomes() {
                outcomeBody.textContent = '';
                const invest = tickets.reduce(function (sum, ticket) { return sum + ticket.stake; }, 0);
                const covered = coveredOutcomes(tickets).map(function (outcome) {
                    const hitTickets = tickets.filter(function (ticket) { return ticket.stake > 0 && ticketHits(ticket, outcome); });
                    return {outcome: outcome, hitTickets: hitTickets, payout: payoutFor(outcome, tickets)};
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

            async function fetchOdds(url, force) {
                const body = new URLSearchParams();
                body.set('race_code', raceCode);
                body.set('refresh', force ? '1' : '0');
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: body.toString(),
                    cache: 'no-store'
                });
                return response.json();
            }

            async function loadOdds(force) {
                reloadButton.disabled = true;
                oddsStatus.textContent = force ? '公式オッズ：更新中…' : '公式オッズ：読み込み中…';
                try {
                    const values = await Promise.all([
                        fetchOdds('/web/official_exacta_odds_api.php', force),
                        fetchOdds('/web/official_odds_api.php', force)
                    ]);
                    const exacta = values[0] || {};
                    const trifecta = values[1] || {};
                    if (exacta.status === 'ok' && Number(exacta.count) === 30) exactaOdds = exacta.odds || {};
                    if (trifecta.status === 'ok' && Number(trifecta.count) === 120) trifectaOdds = trifecta.odds || {};
                    oddsStatus.textContent = '公式オッズ：2連単 ' + Object.keys(exactaOdds).length + '/30 / 3連単 ' + Object.keys(trifectaOdds).length + '/120' + (force ? '（更新済み）' : '（キャッシュ利用）');

                    tickets = tickets.map(function (ticket) {
                        const copy = makeTicket(ticket.type, ticket.key);
                        copy.stake = ticket.stake;
                        return copy;
                    });
                    renderCandidates();
                    renderAll();
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

            tabButton.addEventListener('click', activateSimulator);
            typeButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    candidateType = button.dataset.type === 'exacta' ? 'exacta' : 'trifecta';
                    renderTypeButtons();
                    renderFormation();
                    renderCandidates();
                });
            });
            selectionClearButton.addEventListener('click', function () {
                selected[candidateType].forEach(function (set) { set.clear(); });
                renderFormation();
                renderCandidates();
            });
            addAllButton.addEventListener('click', function () {
                const keys = formationKeys(candidateType);
                const added = addKeys(candidateType, keys, true);
                showMessage(added ? added + '点を購入舟券へ追加しました。' : 'すべて追加済みです。', false);
            });
            directExactaButton.addEventListener('click', function () {
                const keys = expandTicketText(exactaInput.value, 'exacta');
                const added = addKeys('exacta', keys, true);
                showMessage(added ? '2連単 ' + added + '点を追加しました。' : '追加できる2連単がありません。', added === 0);
            });
            directTrifectaButton.addEventListener('click', function () {
                const keys = expandTicketText(trifectaInput.value, 'trifecta');
                const added = addKeys('trifecta', keys, true);
                showMessage(added ? '3連単 ' + added + '点を追加しました。' : '追加できる3連単がありません。', added === 0);
            });
            importExactaButton.addEventListener('click', function () {
                const keys = visibleExactaKeys();
                const added = addKeys('exacta', keys, true);
                showMessage('2連単の表示中から ' + added + '点追加しました。', false);
            });
            importTrifectaButton.addEventListener('click', function () {
                const keys = visibleTrifectaKeys();
                const added = addKeys('trifecta', keys, true);
                showMessage('3連単の表示中から ' + added + '点追加しました。', false);
            });
            clearButton.addEventListener('click', function () {
                tickets = [];
                showMessage('', false);
                renderAll();
                renderCandidates();
            });
            allocateButton.addEventListener('click', autoAllocate);
            reloadButton.addEventListener('click', function () { loadOdds(true); });
            budgetInput.addEventListener('change', function () { if (tickets.length) autoAllocate(); else renderSummary(); });
            modeSelect.addEventListener('change', function () { if (tickets.length) autoAllocate(); });

            renderTypeButtons();
            renderFormation();
            renderCandidates();
            renderAll();
            loadOdds(false);

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
