(function () {
    'use strict';

    const STORAGE_KEY = 'boatraceAppTabEnhanced';
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

    function parsePayload() {
        const node = document.getElementById('app-trifecta-data');
        if (!node) return null;
        try {
            return JSON.parse(node.textContent || '{}');
        } catch (e) {
            return null;
        }
    }

    function normalizeText(value) {
        return String(value || '')
            .replace(/[１-６]/g, function (char) { return String(char.charCodeAt(0) - 0xFEE0); })
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
            const sets = parts.map(function (part) { return unique(part.split('').map(Number)); });
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

    function deriveOutcomes(rows) {
        return (Array.isArray(rows) ? rows : []).map(function (row) {
            const boats = Array.isArray(row.boats) ? row.boats.map(Number) : [];
            if (boats.length !== 3) return null;
            return {
                key: boats.join('-'),
                first: boats[0],
                second: boats[1],
                third: boats[2],
                probability: number(row.probability)
            };
        }).filter(function (row) {
            return row && /^([1-6])-([1-6])-([1-6])$/.test(row.key);
        });
    }

    function waitForReady(callback, retry) {
        const tabs = document.querySelector('.app-tabs');
        const exactaButton = tabs ? tabs.querySelector('[data-tab="exacta"]') : null;
        const trifectaButton = tabs ? tabs.querySelector('[data-tab="trifecta"]') : null;
        const recentButton = tabs ? tabs.querySelector('[data-tab="recent"]') : null;
        const recentPanel = document.querySelector('.app-tab-panel[data-panel="recent"]');
        if (tabs && exactaButton && trifectaButton && recentButton && recentPanel) {
            callback(tabs, recentButton, recentPanel);
            return;
        }
        if (retry <= 0) return;
        window.setTimeout(function () { waitForReady(callback, retry - 1); }, 50);
    }

    function injectStyle() {
        if (document.getElementById('app-bet-simulator-v3-style')) return;
        const style = document.createElement('style');
        style.id = 'app-bet-simulator-v3-style';
        style.textContent = ''
            + '.app-bet-panel{padding-bottom:18px;}'
            + '.app-bet-card{overflow:hidden;}'
            + '.app-bet-controls{display:grid;grid-template-columns:1fr 1fr;gap:8px;}'
            + '.app-bet-controls label{font-size:11px;font-weight:700;color:#6b7785;}'
            + '.app-bet-controls input,.app-bet-controls select,.app-bet-direct textarea{width:100%;box-sizing:border-box;margin-top:4px;padding:8px;border:1px solid #d6d3cd;border-radius:6px;background:#fff;}'
            + '.app-bet-actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:8px;}'
            + '.app-bet-actions button,.app-bet-candidate button,.app-bet-ticket button{padding:7px 9px;border-radius:6px;border:1px solid #1683bd;background:#1683bd;color:#fff;font-weight:700;}'
            + '.app-bet-actions button.is-secondary,.app-bet-ticket button.is-secondary{background:#fff;color:#1683bd;}'
            + '.app-bet-formation{display:grid;gap:7px;}'
            + '.app-bet-position{padding:8px;border:1px solid #d6d3cd;border-radius:7px;background:#fffdf9;}'
            + '.app-bet-position-title{font-size:12px;font-weight:700;color:#3f4b5a;margin-bottom:6px;}'
            + '.app-bet-boats{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px;}'
            + '.app-bet-boat{min-width:0;padding:8px 0;border-radius:5px;font-weight:700;font-size:13px;}'
            + '.app-bet-summary-grid{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-top:8px;}'
            + '.app-bet-summary-card{padding:8px;border:1px solid #d6d3cd;border-radius:7px;background:#fffdf9;}'
            + '.app-bet-summary-card span{display:block;font-size:10px;color:#6b7785;}'
            + '.app-bet-summary-card strong{display:block;margin-top:2px;font-size:15px;color:#3f4b5a;}'
            + '.app-bet-summary-card strong.is-accent{color:#aa741f;}'
            + '.app-bet-group{margin-top:10px;border:1px solid #d6d3cd;border-radius:7px;overflow:hidden;background:#fff;}'
            + '.app-bet-group-head{display:flex;align-items:center;justify-content:space-between;gap:6px;padding:8px;background:#f2ece2;}'
            + '.app-bet-group-head strong{font-size:12px;color:#3f4b5a;}'
            + '.app-bet-group-head button{padding:5px 7px;border:1px solid #1683bd;border-radius:5px;background:#fff;color:#1683bd;font-size:11px;font-weight:700;}'
            + '.app-bet-candidate,.app-bet-ticket{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:center;padding:8px;border-top:1px solid #ece6dc;}'
            + '.app-bet-candidate:first-child,.app-bet-ticket:first-child{border-top:0;}'
            + '.app-bet-combo{font-weight:700;font-size:13px;color:#2b3440;}'
            + '.app-bet-meta{display:flex;gap:9px;flex-wrap:wrap;margin-top:3px;font-size:10px;color:#6b7785;}'
            + '.app-bet-ticket-main{min-width:0;}'
            + '.app-bet-ticket-side{display:flex;align-items:center;gap:5px;}'
            + '.app-bet-ticket-side input{width:74px;padding:6px;border:1px solid #d6d3cd;border-radius:5px;text-align:right;}'
            + '.app-bet-message{font-size:11px;color:#6b7785;margin-top:7px;}'
            + '.app-bet-outcomes{margin-top:10px;}'
            + '.app-bet-outcomes summary{cursor:pointer;font-weight:700;color:#3f4b5a;padding:8px 0;}'
            + '.app-bet-outcome{padding:7px 0;border-top:1px solid #ece6dc;font-size:11px;}'
            + '.app-bet-outcome strong{font-size:12px;}'
            + '.app-bet-direct{margin-top:10px;border-top:1px solid #d6d3cd;padding-top:8px;}'
            + '.app-bet-direct summary{cursor:pointer;font-size:11px;font-weight:700;color:#6b7785;}'
            + '.app-bet-direct-grid{display:grid;gap:8px;margin-top:8px;}'
            + '@media(max-width:380px){.app-bet-controls{grid-template-columns:1fr}.app-bet-boats{grid-template-columns:repeat(7,minmax(34px,1fr));gap:3px}.app-bet-boat{font-size:12px;padding:7px 0}.app-bet-summary-grid{grid-template-columns:1fr 1fr}}';
        document.head.appendChild(style);
    }

    function setup() {
        const payload = parsePayload();
        const outcomes = deriveOutcomes(payload && payload.rows);
        if (outcomes.length !== 120) return;

        waitForReady(function (tabs, recentButton, recentPanel) {
            if (tabs.querySelector('[data-tab="bet"]')) return;
            injectStyle();

            const raceCodeNode = document.querySelector('.app-code');
            const raceCode = String(raceCodeNode ? raceCodeNode.textContent : '').trim();
            if (!/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/.test(raceCode)) return;

            tabs.style.gridTemplateColumns = 'repeat(6,minmax(0,1fr))';

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'app-tab';
            button.dataset.tab = 'bet';
            button.textContent = '買い目';
            tabs.insertBefore(button, recentButton);

            const panel = document.createElement('div');
            panel.className = 'app-tab-panel app-bet-panel';
            panel.dataset.panel = 'bet';
            panel.hidden = true;
            recentPanel.insertAdjacentElement('beforebegin', panel);

            panel.innerHTML = ''
                + '<section class="app-card app-bet-card">'
                + '  <div class="app-card-body">'
                + '    <h2 class="app-section-title">💰 買い目シミュレーター</h2>'
                + '    <div class="app-note">1回のフォーメーション選択から3連単＋2連単候補を同時表示。購入舟券は混在したまま払戻を合算します。</div>'
                + '    <div class="app-bet-odds" style="margin-top:8px;padding:7px 8px;border:1px solid #d6d3cd;border-radius:6px;background:#fffaf2;font-size:11px;color:#6b7785;">公式オッズ：読み込み中…</div>'
                + '    <div class="app-bet-controls" style="margin-top:9px;">'
                + '      <label>予算<input class="app-bet-budget" type="number" min="100" step="100" value="1000"></label>'
                + '      <label>自動配分<select class="app-bet-mode"><option value="equal">均等配分</option><option value="minpayout">最低払戻重視</option></select></label>'
                + '    </div>'
                + '    <div class="app-bet-actions"><button type="button" class="app-bet-reload is-secondary">オッズ更新</button><button type="button" class="app-bet-allocate">自動再配分</button><button type="button" class="app-bet-clear-selection is-secondary">選択クリア</button></div>'
                + '  </div>'
                + '  <div class="app-card-body" style="padding-top:0;">'
                + '    <div style="font-size:12px;font-weight:700;color:#3f4b5a;margin-bottom:7px;">① フォーメーションを選択</div>'
                + '    <div class="app-bet-formation"></div>'
                + '    <div class="app-bet-candidate-summary" style="margin-top:8px;font-size:11px;color:#6b7785;"></div>'
                + '    <div class="app-bet-candidates"></div>'
                + '    <details class="app-bet-direct"><summary>直接入力（詳細）</summary><div class="app-bet-direct-grid"><div><textarea class="app-bet-exacta-input" rows="2" placeholder="2連単 例 1-2 1-4 / 1-24"></textarea><div class="app-bet-actions"><button type="button" class="app-bet-add-exacta">2連単を追加</button></div></div><div><textarea class="app-bet-trifecta-input" rows="2" placeholder="3連単 例 1-2-4 / 1-234-234"></textarea><div class="app-bet-actions"><button type="button" class="app-bet-add-trifecta">3連単を追加</button></div></div></div></details>'
                + '  </div>'
                + '  <div class="app-card-body" style="border-top:1px solid #ece6dc;">'
                + '    <div style="display:flex;align-items:center;justify-content:space-between;gap:6px;"><div style="font-size:12px;font-weight:700;color:#3f4b5a;">② 購入する舟券</div><button type="button" class="app-bet-clear-tickets" style="padding:5px 7px;border:1px solid #cbbda9;border-radius:5px;background:#eee6da;color:#4b5866;font-size:11px;">クリア</button></div>'
                + '    <div class="app-bet-message"></div>'
                + '    <div class="app-bet-summary-grid"></div>'
                + '    <div class="app-bet-tickets" style="margin-top:8px;border:1px solid #d6d3cd;border-radius:7px;overflow:hidden;background:#fff;"></div>'
                + '    <details class="app-bet-outcomes"><summary>③ 結果別払戻を見る</summary><div class="app-bet-outcome-list"></div></details>'
                + '    <div class="app-note" style="margin-top:8px;">※100円単位。同じ結果で2連単と3連単が当たる場合は払戻を合算。期待回収率は参考値です。</div>'
                + '  </div>'
                + '</section>';

            const formation = panel.querySelector('.app-bet-formation');
            const candidateSummary = panel.querySelector('.app-bet-candidate-summary');
            const candidatesBox = panel.querySelector('.app-bet-candidates');
            const budgetInput = panel.querySelector('.app-bet-budget');
            const modeSelect = panel.querySelector('.app-bet-mode');
            const reloadButton = panel.querySelector('.app-bet-reload');
            const allocateButton = panel.querySelector('.app-bet-allocate');
            const clearSelectionButton = panel.querySelector('.app-bet-clear-selection');
            const clearTicketsButton = panel.querySelector('.app-bet-clear-tickets');
            const message = panel.querySelector('.app-bet-message');
            const summary = panel.querySelector('.app-bet-summary-grid');
            const ticketsBox = panel.querySelector('.app-bet-tickets');
            const outcomeList = panel.querySelector('.app-bet-outcome-list');
            const oddsStatus = panel.querySelector('.app-bet-odds');
            const exactaInput = panel.querySelector('.app-bet-exacta-input');
            const trifectaInput = panel.querySelector('.app-bet-trifecta-input');
            const addExactaButton = panel.querySelector('.app-bet-add-exacta');
            const addTrifectaButton = panel.querySelector('.app-bet-add-trifecta');

            let exactaOdds = {};
            let trifectaOdds = {};
            let tickets = [];
            const selected = [new Set(), new Set(), new Set()];
            const outcomeByKey = new Map(outcomes.map(function (row) { return [row.key, row]; }));

            function exactaProbability(key) {
                return outcomes.reduce(function (sum, row) {
                    return row.key.indexOf(key + '-') === 0 ? sum + row.probability : sum;
                }, 0);
            }

            function oddsFor(type, key) {
                const source = type === 'exacta' ? exactaOdds : trifectaOdds;
                const n = Number(source[key]);
                return Number.isFinite(n) && n > 0 ? n : null;
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
                return ticket.type === 'exacta' ? outcome.key.indexOf(ticket.key + '-') === 0 : outcome.key === ticket.key;
            }

            function formationKeys(type) {
                if (selected[0].size === 0 || selected[1].size === 0) return [];
                if (type === 'trifecta' && selected[2].size === 0) return [];
                const keys = [];
                Array.from(selected[0]).sort().forEach(function (a) {
                    Array.from(selected[1]).sort().forEach(function (b) {
                        if (a === b) return;
                        if (type === 'exacta') {
                            keys.push(a + '-' + b);
                            return;
                        }
                        Array.from(selected[2]).sort().forEach(function (c) {
                            if (c === a || c === b) return;
                            keys.push(a + '-' + b + '-' + c);
                        });
                    });
                });
                return unique(keys);
            }

            function combinedOdds(list) {
                if (!list.length) return null;
                let inv = 0;
                for (let i = 0; i < list.length; i++) {
                    if (!list[i].odds) return null;
                    inv += 1 / list[i].odds;
                }
                return inv > 0 ? 1 / inv : null;
            }

            function palette(boat) {
                return {
                    1: ['#fff', '#222', '#c9c9c9'],
                    2: ['#1f2937', '#fff', '#1f2937'],
                    3: ['#ef4444', '#fff', '#dc2626'],
                    4: ['#3b82f6', '#fff', '#2563eb'],
                    5: ['#facc15', '#222', '#d4a900'],
                    6: ['#22c55e', '#fff', '#16a34a']
                }[boat];
            }

            function styleBoatButton(btn, boat, active) {
                if (boat === 0) {
                    btn.style.background = active ? '#e7f4fb' : '#eee6da';
                    btn.style.color = '#3f4b5a';
                    btn.style.border = '2px solid ' + (active ? '#1683bd' : '#cbbda9');
                    return;
                }
                const c = palette(boat);
                btn.style.background = c[0];
                btn.style.color = c[1];
                btn.style.border = '2px solid ' + (active ? '#1683bd' : c[2]);
                btn.style.boxShadow = active ? '0 0 0 2px rgba(22,131,189,.18)' : 'none';
            }

            function renderFormation() {
                formation.textContent = '';
                ['1着', '2着', '3着（3連単用）'].forEach(function (label, position) {
                    const box = document.createElement('div');
                    box.className = 'app-bet-position';
                    const title = document.createElement('div');
                    title.className = 'app-bet-position-title';
                    title.textContent = label;
                    box.appendChild(title);
                    const controls = document.createElement('div');
                    controls.className = 'app-bet-boats';

                    const all = document.createElement('button');
                    all.type = 'button';
                    all.className = 'app-bet-boat';
                    all.textContent = '全';
                    const allActive = selected[position].size === 6;
                    styleBoatButton(all, 0, allActive);
                    all.addEventListener('click', function () {
                        selected[position].clear();
                        if (!allActive) for (let b = 1; b <= 6; b++) selected[position].add(b);
                        renderFormation();
                        renderCandidates();
                    });
                    controls.appendChild(all);

                    for (let boat = 1; boat <= 6; boat++) {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'app-bet-boat';
                        btn.textContent = String(boat);
                        const active = selected[position].has(boat);
                        styleBoatButton(btn, boat, active);
                        btn.addEventListener('click', function () {
                            if (selected[position].has(boat)) selected[position].delete(boat); else selected[position].add(boat);
                            renderFormation();
                            renderCandidates();
                        });
                        controls.appendChild(btn);
                    }
                    box.appendChild(controls);
                    formation.appendChild(box);
                });
            }

            function candidateList(type) {
                return formationKeys(type).map(function (key) { return makeTicket(type, key); }).sort(function (a, b) {
                    return b.probability === a.probability ? a.key.localeCompare(b.key, 'ja') : b.probability - a.probability;
                });
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
                if (added > 0 && auto !== false) autoAllocate(); else renderAll();
                renderCandidates();
                return added;
            }

            function makeCandidateRow(ticket) {
                const row = document.createElement('div');
                row.className = 'app-bet-candidate';
                const left = document.createElement('div');
                const combo = document.createElement('div');
                combo.className = 'app-bet-combo';
                combo.textContent = ticket.key;
                left.appendChild(combo);
                const meta = document.createElement('div');
                meta.className = 'app-bet-meta';
                meta.innerHTML = '<span>確率 ' + pct(ticket.probability, 3) + '</span><span>オッズ ' + (ticket.odds ? ticket.odds.toFixed(1) : '-') + '</span>';
                left.appendChild(meta);
                row.appendChild(left);
                const add = document.createElement('button');
                add.type = 'button';
                const exists = tickets.some(function (x) { return x.id === ticket.id; });
                add.textContent = exists ? '追加済' : '追加';
                add.disabled = exists;
                add.addEventListener('click', function () { addKeys(ticket.type, [ticket.key], true); });
                row.appendChild(add);
                return row;
            }

            function renderCandidateGroup(title, type, list) {
                const group = document.createElement('div');
                group.className = 'app-bet-group';
                const head = document.createElement('div');
                head.className = 'app-bet-group-head';
                const label = document.createElement('strong');
                const sum = list.reduce(function (s, x) { return s + x.probability; }, 0);
                const combo = combinedOdds(list);
                label.textContent = title + ' ' + list.length + '点 / ' + pct(sum, 2) + ' / ' + (combo ? combo.toFixed(2) + '倍' : '取得待ち');
                head.appendChild(label);
                const addAll = document.createElement('button');
                addAll.type = 'button';
                addAll.textContent = '全部追加';
                addAll.disabled = list.length === 0;
                addAll.addEventListener('click', function () {
                    const added = addKeys(type, list.map(function (x) { return x.key; }), true);
                    showMessage(added ? title + 'を' + added + '点追加しました。' : 'すべて追加済みです。', false);
                });
                head.appendChild(addAll);
                group.appendChild(head);
                if (!list.length) {
                    const empty = document.createElement('div');
                    empty.style.cssText = 'padding:10px;text-align:center;font-size:11px;color:#6b7785;';
                    empty.textContent = type === 'trifecta' ? '1着・2着・3着を選択すると表示されます。' : '1着・2着を選択すると表示されます。';
                    group.appendChild(empty);
                } else {
                    list.forEach(function (ticket) { group.appendChild(makeCandidateRow(ticket)); });
                }
                return group;
            }

            function renderCandidates() {
                const tri = candidateList('trifecta');
                const ex = candidateList('exacta');
                candidatesBox.textContent = '';
                candidateSummary.textContent = '3連単 ' + tri.length + '点 / 2連単 ' + ex.length + '点';
                candidatesBox.appendChild(renderCandidateGroup('3連単', 'trifecta', tri));
                candidatesBox.appendChild(renderCandidateGroup('2連単', 'exacta', ex));
            }

            function normalizedBudget() {
                const raw = Math.max(UNIT, Math.floor(number(budgetInput.value) / UNIT) * UNIT);
                budgetInput.value = String(raw);
                return raw;
            }

            function coveredOutcomes(current) {
                return outcomes.filter(function (outcome) {
                    return current.some(function (ticket) { return ticket.stake > 0 && ticketHits(ticket, outcome); });
                });
            }

            function payoutFor(outcome, current) {
                return current.reduce(function (sum, ticket) {
                    if (ticket.stake <= 0 || !ticket.odds || !ticketHits(ticket, outcome)) return sum;
                    return sum + ticket.stake * ticket.odds;
                }, 0);
            }

            function expectedPayout(current) {
                return outcomes.reduce(function (sum, outcome) { return sum + outcome.probability * payoutFor(outcome, current); }, 0);
            }

            function minCoveredPayout(current) {
                const covered = coveredOutcomes(current);
                if (!covered.length) return 0;
                return Math.min.apply(null, covered.map(function (outcome) { return payoutFor(outcome, current); }));
            }

            function allocateEqual(budget) {
                const units = Math.floor(budget / UNIT);
                if (!tickets.length || units < tickets.length) return false;
                const base = Math.floor(units / tickets.length);
                let remain = units - base * tickets.length;
                const order = tickets.slice().sort(function (a, b) { return b.probability - a.probability; });
                tickets.forEach(function (ticket) { ticket.stake = base * UNIT; });
                for (let i = 0; i < order.length && remain > 0; i++, remain--) order[i].stake += UNIT;
                return true;
            }

            function allocateMinPayout(budget) {
                const units = Math.floor(budget / UNIT);
                if (!tickets.length || units < tickets.length) return false;
                tickets.forEach(function (ticket) { ticket.stake = UNIT; });
                let remain = units - tickets.length;
                while (remain > 0) {
                    let best = null;
                    tickets.forEach(function (ticket) {
                        ticket.stake += UNIT;
                        const minPay = minCoveredPayout(tickets);
                        const exp = expectedPayout(tickets);
                        ticket.stake -= UNIT;
                        if (best === null || minPay > best.minPay + 0.0001 || (Math.abs(minPay - best.minPay) <= 0.0001 && exp > best.exp)) {
                            best = {ticket: ticket, minPay: minPay, exp: exp};
                        }
                    });
                    if (!best) break;
                    best.ticket.stake += UNIT;
                    remain--;
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
                    showMessage('現在' + tickets.length + '点。最低' + yen(tickets.length * UNIT) + '必要です。', true);
                    renderAll();
                    return;
                }
                const ok = modeSelect.value === 'minpayout' ? allocateMinPayout(budget) : allocateEqual(budget);
                showMessage(ok ? '自動配分しました。' : '配分できませんでした。', !ok);
                renderAll();
            }

            function summaryCard(label, value, accent) {
                const card = document.createElement('div');
                card.className = 'app-bet-summary-card';
                const l = document.createElement('span');
                l.textContent = label;
                const v = document.createElement('strong');
                v.textContent = value;
                if (accent) v.className = 'is-accent';
                card.appendChild(l);
                card.appendChild(v);
                return card;
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
                const roi = invest > 0 ? expectedPayout(tickets) / invest : 0;
                summary.appendChild(summaryCard('購入 / 予算', yen(invest) + ' / ' + yen(budget), false));
                summary.appendChild(summaryCard('的中カバー確率', pct(coverage, 2), true));
                summary.appendChild(summaryCard('最低払戻', payouts.length ? yen(minPayout) : '-', false));
                summary.appendChild(summaryCard('最高払戻', payouts.length ? yen(maxPayout) : '-', false));
                summary.appendChild(summaryCard('期待回収率', invest > 0 ? (roi * 100).toFixed(1) + '%' : '-', true));
            }

            function renderTickets() {
                ticketsBox.textContent = '';
                if (!tickets.length) {
                    const empty = document.createElement('div');
                    empty.style.cssText = 'padding:12px;text-align:center;font-size:11px;color:#6b7785;';
                    empty.textContent = '候補の「追加」から購入舟券を選びます。';
                    ticketsBox.appendChild(empty);
                    return;
                }
                tickets.forEach(function (ticket) {
                    const row = document.createElement('div');
                    row.className = 'app-bet-ticket';
                    const main = document.createElement('div');
                    main.className = 'app-bet-ticket-main';
                    const combo = document.createElement('div');
                    combo.className = 'app-bet-combo';
                    combo.textContent = ticket.label + ' ' + ticket.key;
                    main.appendChild(combo);
                    const meta = document.createElement('div');
                    meta.className = 'app-bet-meta';
                    meta.innerHTML = '<span>確率 ' + pct(ticket.probability, 2) + '</span><span>オッズ ' + (ticket.odds ? ticket.odds.toFixed(1) : '-') + '</span><span>払戻 ' + (ticket.odds && ticket.stake > 0 ? yen(ticket.stake * ticket.odds) : '-') + '</span>';
                    main.appendChild(meta);
                    row.appendChild(main);
                    const side = document.createElement('div');
                    side.className = 'app-bet-ticket-side';
                    const input = document.createElement('input');
                    input.type = 'number';
                    input.min = '0';
                    input.step = '100';
                    input.value = String(ticket.stake);
                    input.addEventListener('change', function () {
                        ticket.stake = Math.max(0, Math.floor(number(input.value) / UNIT) * UNIT);
                        input.value = String(ticket.stake);
                        renderAll();
                    });
                    side.appendChild(input);
                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'is-secondary';
                    remove.textContent = '削除';
                    remove.addEventListener('click', function () {
                        tickets = tickets.filter(function (x) { return x.id !== ticket.id; });
                        if (tickets.length) autoAllocate(); else renderAll();
                        renderCandidates();
                    });
                    side.appendChild(remove);
                    row.appendChild(side);
                    ticketsBox.appendChild(row);
                });
            }

            function renderOutcomes() {
                outcomeList.textContent = '';
                const invest = tickets.reduce(function (sum, ticket) { return sum + ticket.stake; }, 0);
                const covered = coveredOutcomes(tickets).map(function (outcome) {
                    const hits = tickets.filter(function (ticket) { return ticket.stake > 0 && ticketHits(ticket, outcome); });
                    return {outcome: outcome, hits: hits, payout: payoutFor(outcome, tickets)};
                }).sort(function (a, b) {
                    return a.payout === b.payout ? b.outcome.probability - a.outcome.probability : a.payout - b.payout;
                });
                covered.forEach(function (row) {
                    const div = document.createElement('div');
                    div.className = 'app-bet-outcome';
                    const net = row.payout - invest;
                    const names = row.hits.map(function (t) { return t.label + ' ' + t.key; }).join(' ＋ ');
                    div.innerHTML = '<strong>' + row.outcome.key + '</strong> <span style="color:#6b7785;">' + pct(row.outcome.probability, 3) + '</span><div style="margin-top:2px;">' + names + '</div><div style="margin-top:2px;font-weight:700;">払戻 ' + yen(row.payout) + ' / <span style="color:' + (net >= 0 ? '#2f789f' : '#a74932') + ';">収支 ' + (net >= 0 ? '+' : '') + yen(net) + '</span></div>';
                    outcomeList.appendChild(div);
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
                    oddsStatus.textContent = '公式オッズ：2連単 ' + Object.keys(exactaOdds).length + '/30 / 3連単 ' + Object.keys(trifectaOdds).length + '/120' + (force ? '（更新済み）' : '（キャッシュ）');
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

            function activateBet() {
                document.querySelectorAll('.app-tab').forEach(function (tab) {
                    tab.classList.toggle('is-active', tab.dataset.tab === 'bet');
                });
                document.querySelectorAll('.app-tab-panel').forEach(function (tabPanel) {
                    const active = tabPanel.dataset.panel === 'bet';
                    tabPanel.classList.toggle('is-active', active);
                    tabPanel.hidden = !active;
                });
                try { sessionStorage.setItem(STORAGE_KEY, 'bet'); } catch (e) {}
            }

            button.addEventListener('click', activateBet);
            clearSelectionButton.addEventListener('click', function () {
                selected.forEach(function (set) { set.clear(); });
                renderFormation();
                renderCandidates();
            });
            clearTicketsButton.addEventListener('click', function () {
                tickets = [];
                showMessage('', false);
                renderAll();
                renderCandidates();
            });
            allocateButton.addEventListener('click', autoAllocate);
            reloadButton.addEventListener('click', function () { loadOdds(true); });
            budgetInput.addEventListener('change', function () { if (tickets.length) autoAllocate(); else renderSummary(); });
            modeSelect.addEventListener('change', function () { if (tickets.length) autoAllocate(); });
            addExactaButton.addEventListener('click', function () {
                const keys = expandTicketText(exactaInput.value, 'exacta');
                const added = addKeys('exacta', keys, true);
                showMessage(added ? '2連単を' + added + '点追加しました。' : '追加できる2連単がありません。', added === 0);
            });
            addTrifectaButton.addEventListener('click', function () {
                const keys = expandTicketText(trifectaInput.value, 'trifecta');
                const added = addKeys('trifecta', keys, true);
                showMessage(added ? '3連単を' + added + '点追加しました。' : '追加できる3連単がありません。', added === 0);
            });

            renderFormation();
            renderCandidates();
            renderAll();
            loadOdds(false);

            let saved = '';
            try { saved = sessionStorage.getItem(STORAGE_KEY) || ''; } catch (e) {}
            if (saved === 'bet') activateBet();
        }, 120);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup);
    } else {
        setup();
    }
})();
