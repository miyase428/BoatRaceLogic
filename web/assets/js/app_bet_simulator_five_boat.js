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
        try { return JSON.parse(node.textContent || '{}'); } catch (e) { return null; }
    }

    function deriveExacta(outcomes) {
        const map = new Map();
        outcomes.forEach(function (row) {
            const key = row.first + '-' + row.second;
            if (!map.has(key)) map.set(key, {key:key, first:row.first, second:row.second, probability:0, odds:null});
            map.get(key).probability += row.probability;
        });
        return Array.from(map.values());
    }

    function waitForReady(callback, retry) {
        const tabs = document.querySelector('.app-tabs');
        const recentButton = tabs ? tabs.querySelector('[data-tab="recent"]') : null;
        const recentPanel = document.querySelector('.app-tab-panel[data-panel="recent"]');
        const exactaButton = tabs ? tabs.querySelector('[data-tab="exacta"]') : null;
        const trifectaButton = tabs ? tabs.querySelector('[data-tab="trifecta"]') : null;
        if (tabs && recentButton && recentPanel && exactaButton && trifectaButton) {
            callback(tabs, recentButton, recentPanel);
            return;
        }
        if (retry <= 0) return;
        window.setTimeout(function () { waitForReady(callback, retry - 1); }, 60);
    }

    function injectStyle() {
        if (document.getElementById('app-bet-five-style')) return;
        const style = document.createElement('style');
        style.id = 'app-bet-five-style';
        style.textContent = ''
            + '.app-bet5-card{overflow:hidden}.app-bet5-grid{display:grid;gap:8px}.app-bet5-controls{display:grid;grid-template-columns:1fr 1fr;gap:8px}'
            + '.app-bet5-controls label{font-size:11px;font-weight:700;color:#6b7785}.app-bet5-controls input,.app-bet5-controls select{width:100%;box-sizing:border-box;margin-top:4px;padding:8px;border:1px solid #d6d3cd;border-radius:6px;background:#fff}'
            + '.app-bet5-position{padding:8px;border:1px solid #d6d3cd;border-radius:7px;background:#fffdf9}.app-bet5-position strong{display:block;margin-bottom:6px;font-size:12px;color:#3f4b5a}'
            + '.app-bet5-boats{display:grid;gap:4px}.app-bet5-boat{min-width:0;padding:8px 0;border:1px solid #cbbda9;border-radius:5px;background:#eee6da;color:#4b5866;font-weight:700}.app-bet5-boat.is-active{border-color:#1683bd;background:#fffaf2;color:#1683bd;box-shadow:inset 0 0 0 1px #1683bd}'
            + '.app-bet5-actions{display:flex;gap:6px;flex-wrap:wrap}.app-bet5-actions button,.app-bet5-add{padding:7px 9px;border:1px solid #1683bd;border-radius:6px;background:#1683bd;color:#fff;font-weight:700}.app-bet5-actions button.is-secondary{background:#fff;color:#1683bd}'
            + '.app-bet5-box{margin-top:9px;border:1px solid #d6d3cd;border-radius:7px;overflow:hidden;background:#fff}.app-bet5-head{display:flex;justify-content:space-between;gap:6px;padding:8px;background:#f2ece2;font-size:12px;font-weight:700;color:#3f4b5a}'
            + '.app-bet5-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:center;padding:8px;border-top:1px solid #ece6dc}.app-bet5-row:first-child{border-top:0}.app-bet5-combo{font-weight:700;color:#2b3440}.app-bet5-meta{margin-top:3px;font-size:10px;color:#6b7785}'
            + '.app-bet5-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px;margin-top:8px}.app-bet5-summary>div{padding:8px;border:1px solid #d6d3cd;border-radius:7px;background:#fffdf9}.app-bet5-summary span{display:block;font-size:10px;color:#6b7785}.app-bet5-summary strong{display:block;margin-top:2px;font-size:14px;color:#3f4b5a}'
            + '.app-bet5-ticket-amount{width:70px;padding:6px;border:1px solid #d6d3cd;border-radius:5px;text-align:right}.app-bet5-remove{padding:5px 7px;border:1px solid #cbbda9;border-radius:5px;background:#eee6da;color:#4b5866}'
            + '.app-bet5-outcome{padding:7px 0;border-top:1px solid #ece6dc;font-size:11px}@media(max-width:380px){.app-bet5-controls{grid-template-columns:1fr}}';
        document.head.appendChild(style);
    }

    async function setup() {
        const payload = window.boatraceAppTrifectaPayloadPromise
            ? await window.boatraceAppTrifectaPayloadPromise
            : parsePayload();
        if (!payload || String(payload.status || '') !== 'ok') return;

        const activeBoats = Array.isArray(payload.active_boats) ? payload.active_boats.map(Number) : [];
        if (activeBoats.length !== 5) return;

        const outcomes = (Array.isArray(payload.rows) ? payload.rows : []).map(function (row) {
            const boats = Array.isArray(row.boats) ? row.boats.map(Number) : [];
            if (boats.length !== 3) return null;
            return {key:boats.join('-'), first:boats[0], second:boats[1], third:boats[2], probability:number(row.probability), odds:null};
        }).filter(Boolean);
        if (outcomes.length !== 60) return;
        const exactaRows = deriveExacta(outcomes);
        if (exactaRows.length !== 20) return;

        waitForReady(function (tabs, recentButton, recentPanel) {
            if (tabs.querySelector('[data-tab="bet"]')) return;
            injectStyle();

            const codeNode = document.querySelector('.app-code');
            const raceCode = String(codeNode ? codeNode.textContent : '').trim();
            if (!/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/.test(raceCode)) return;

            const button = document.createElement('button');
            button.type = 'button'; button.className = 'app-tab'; button.dataset.tab = 'bet'; button.textContent = '買い目';
            tabs.insertBefore(button, recentButton);
            tabs.style.gridTemplateColumns = 'repeat(6,minmax(0,1fr))';

            const panel = document.createElement('div');
            panel.className = 'app-tab-panel app-bet-panel'; panel.dataset.panel = 'bet'; panel.hidden = true;
            recentPanel.insertAdjacentElement('beforebegin', panel);
            panel.innerHTML = '<section class="app-card app-bet5-card">'
                + '<div class="app-card-body"><h2 class="app-section-title">💰 買い目シミュレーター</h2><div class="app-note">5艇立て対応。3連単60通り＋2連単20通りを同時に扱います。</div>'
                + '<div class="app-bet5-odds" style="margin-top:8px;padding:7px 8px;border:1px solid #d6d3cd;border-radius:6px;background:#fffaf2;font-size:11px;color:#6b7785;">公式オッズ：読み込み中…</div>'
                + '<div class="app-bet5-controls" style="margin-top:8px"><label>予算<input class="app-bet5-budget" type="number" min="100" step="100" value="1000"></label><label>自動配分<select class="app-bet5-mode"><option value="equal">均等配分</option><option value="minpayout">最低払戻重視</option></select></label></div></div>'
                + '<div class="app-card-body" style="padding-top:0"><div class="app-bet5-grid"></div><div class="app-bet5-actions" style="margin-top:8px"><button type="button" class="app-bet5-add-all">候補を全部追加</button><button type="button" class="app-bet5-allocate">自動再配分</button><button type="button" class="app-bet5-clear-selection is-secondary">選択クリア</button></div><div class="app-bet5-candidates"></div></div>'
                + '<div class="app-card-body" style="border-top:1px solid #ece6dc"><div class="app-bet5-head"><span>購入する舟券</span><button type="button" class="app-bet5-clear-tickets app-bet5-remove">クリア</button></div><div class="app-bet5-summary"></div><div class="app-bet5-tickets app-bet5-box"></div><details style="margin-top:10px"><summary style="cursor:pointer;font-size:12px;font-weight:700;color:#3f4b5a">結果別払戻</summary><div class="app-bet5-outcomes"></div></details></div></section>';

            const selected = [new Set(), new Set(), new Set()];
            const tickets = new Map();
            let exactaOdds = {};
            let trifectaOdds = {};
            const grid = panel.querySelector('.app-bet5-grid');
            const candidatesBox = panel.querySelector('.app-bet5-candidates');
            const ticketsBox = panel.querySelector('.app-bet5-tickets');
            const summary = panel.querySelector('.app-bet5-summary');
            const outcomesBox = panel.querySelector('.app-bet5-outcomes');
            const oddsStatus = panel.querySelector('.app-bet5-odds');
            const budgetInput = panel.querySelector('.app-bet5-budget');
            const modeSelect = panel.querySelector('.app-bet5-mode');

            ['1着','2着','3着'].forEach(function (label, pos) {
                const box = document.createElement('div'); box.className = 'app-bet5-position';
                const title = document.createElement('strong'); title.textContent = label; box.appendChild(title);
                const boats = document.createElement('div'); boats.className = 'app-bet5-boats'; boats.style.gridTemplateColumns = 'repeat(' + activeBoats.length + ',minmax(0,1fr))';
                activeBoats.forEach(function (boat) {
                    const b = document.createElement('button'); b.type='button'; b.className='app-bet5-boat'; b.dataset.pos=String(pos); b.dataset.boat=String(boat); b.textContent=String(boat); boats.appendChild(b);
                }); box.appendChild(boats); grid.appendChild(box);
            });

            function exactaProbability(key) {
                const row = exactaRows.find(function (item) { return item.key === key; });
                return row ? row.probability : 0;
            }
            function oddsFor(type, key) {
                const map = type === 'exacta' ? exactaOdds : trifectaOdds;
                const n = Number(map[key]); return Number.isFinite(n) && n > 0 ? n : null;
            }
            function candidates() {
                const exacta = [];
                const trifecta = [];
                if (selected[0].size && selected[1].size) {
                    selected[0].forEach(function (a) { selected[1].forEach(function (b) { if (a !== b) exacta.push({type:'exacta',key:a+'-'+b,probability:exactaProbability(a+'-'+b)}); }); });
                }
                if (selected[0].size && selected[1].size && selected[2].size) {
                    selected[0].forEach(function (a) { selected[1].forEach(function (b) { selected[2].forEach(function (c) {
                        if (a===b || a===c || b===c) return;
                        const key=a+'-'+b+'-'+c; const row=outcomes.find(function (item) { return item.key===key; }); if (row) trifecta.push({type:'trifecta',key:key,probability:row.probability});
                    }); }); });
                }
                return exacta.concat(trifecta);
            }
            function addTicket(item) {
                const id=item.type+':'+item.key;
                if (!tickets.has(id)) tickets.set(id,{type:item.type,key:item.key,probability:item.probability,amount:100});
            }
            function renderCandidates() {
                const list=candidates(); candidatesBox.textContent='';
                if (!list.length) return;
                const box=document.createElement('div'); box.className='app-bet5-box';
                const head=document.createElement('div'); head.className='app-bet5-head'; head.textContent='候補 '+list.length+'点'; box.appendChild(head);
                list.forEach(function (item) {
                    const row=document.createElement('div'); row.className='app-bet5-row';
                    const main=document.createElement('div'); main.innerHTML='<div class="app-bet5-combo">'+(item.type==='exacta'?'2連単 ':'3連単 ')+item.key+'</div><div class="app-bet5-meta">確率 '+pct(item.probability,2)+' / オッズ '+(oddsFor(item.type,item.key) || '-')+'</div>';
                    const add=document.createElement('button'); add.type='button'; add.className='app-bet5-add'; add.textContent='追加'; add.addEventListener('click',function(){addTicket(item);renderTickets();});
                    row.appendChild(main); row.appendChild(add); box.appendChild(row);
                }); candidatesBox.appendChild(box);
            }
            function allocate() {
                const list=Array.from(tickets.values()); if (!list.length) return;
                const budget=Math.max(100,Math.floor(number(budgetInput.value)/100)*100);
                const units=Math.max(list.length,Math.floor(budget/100));
                list.forEach(function (t){t.amount=100;}); let remaining=units-list.length;
                if (remaining<=0) return;
                if (modeSelect.value==='minpayout') {
                    const weights=list.map(function(t){const o=oddsFor(t.type,t.key);return o?1/o:1;}); const sum=weights.reduce(function(a,b){return a+b;},0);
                    list.forEach(function(t,i){const add=Math.floor(remaining*(weights[i]/sum));t.amount+=add*100;remaining-=add;});
                }
                let i=0; while(remaining>0){list[i%list.length].amount+=100;remaining--;i++;}
            }
            function payoutForOutcome(outcome) {
                let payout=0;
                tickets.forEach(function(t){
                    if(t.type==='trifecta' && t.key===outcome.key){const o=oddsFor('trifecta',t.key);if(o)payout+=t.amount*o;}
                    if(t.type==='exacta' && t.key===outcome.first+'-'+outcome.second){const o=oddsFor('exacta',t.key);if(o)payout+=t.amount*o;}
                }); return payout;
            }
            function renderTickets() {
                ticketsBox.textContent=''; const list=Array.from(tickets.values());
                list.forEach(function(t){
                    const row=document.createElement('div'); row.className='app-bet5-row';
                    const main=document.createElement('div'); main.innerHTML='<div class="app-bet5-combo">'+(t.type==='exacta'?'2連単 ':'3連単 ')+t.key+'</div><div class="app-bet5-meta">確率 '+pct(t.probability,2)+' / オッズ '+(oddsFor(t.type,t.key)||'-')+'</div>';
                    const side=document.createElement('div');
                    const amount=document.createElement('input'); amount.type='number'; amount.min='0'; amount.step='100'; amount.className='app-bet5-ticket-amount'; amount.value=String(t.amount); amount.addEventListener('change',function(){t.amount=Math.max(0,Math.floor(number(amount.value)/100)*100);renderSummary();});
                    const remove=document.createElement('button'); remove.type='button'; remove.className='app-bet5-remove'; remove.textContent='削除'; remove.addEventListener('click',function(){tickets.delete(t.type+':'+t.key);renderTickets();});
                    side.appendChild(amount); side.appendChild(remove); row.appendChild(main); row.appendChild(side); ticketsBox.appendChild(row);
                }); renderSummary();
            }
            function renderSummary() {
                const list=Array.from(tickets.values()); const stake=list.reduce(function(s,t){return s+t.amount;},0);
                let expected=0; let minPayout=null; outcomes.forEach(function(o){const p=payoutForOutcome(o);expected+=o.probability*p;if(p>0&&(minPayout===null||p<minPayout))minPayout=p;});
                const roi=stake>0?expected/stake:0;
                summary.innerHTML='<div><span>購入</span><strong>'+yen(stake)+'</strong></div><div><span>点数</span><strong>'+list.length+'点</strong></div><div><span>期待払戻</span><strong>'+yen(expected)+'</strong></div><div><span>期待回収率</span><strong>'+pct(roi,1)+'</strong></div>';
                outcomesBox.textContent=''; outcomes.forEach(function(o){const payout=payoutForOutcome(o);if(payout<=0)return;const div=document.createElement('div');div.className='app-bet5-outcome';div.innerHTML='<strong>'+o.key+'</strong>　'+pct(o.probability,2)+'　払戻 '+yen(payout);outcomesBox.appendChild(div);});
            }
            async function loadOdds(force) {
                oddsStatus.textContent=force?'公式オッズ：更新中…':'公式オッズ：取得中…';
                try {
                    const body1=new URLSearchParams();body1.set('race_code',raceCode);body1.set('refresh',force?'1':'0');
                    const body2=new URLSearchParams(body1.toString());
                    const values=await Promise.all([
                        fetch('/web/official_exacta_odds_api.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body1.toString(),cache:'no-store'}).then(function(r){return r.json();}),
                        fetch('/web/official_odds_api.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body2.toString(),cache:'no-store'}).then(function(r){return r.json();})
                    ]);
                    if(values[0].status==='ok')exactaOdds=values[0].odds||{};
                    if(values[1].status==='ok')trifectaOdds=values[1].odds||{};
                    oddsStatus.textContent='公式オッズ：2連単 '+Object.keys(exactaOdds).length+' / 3連単 '+Object.keys(trifectaOdds).length+' 通り取得';
                    renderCandidates();renderTickets();
                } catch(e){oddsStatus.textContent='公式オッズ：取得エラー';}
            }

            grid.addEventListener('click',function(event){const target=event.target.closest('.app-bet5-boat');if(!target)return;const pos=Number(target.dataset.pos);const boat=Number(target.dataset.boat);if(selected[pos].has(boat))selected[pos].delete(boat);else selected[pos].add(boat);target.classList.toggle('is-active',selected[pos].has(boat));renderCandidates();});
            panel.querySelector('.app-bet5-clear-selection').addEventListener('click',function(){selected.forEach(function(s){s.clear();});panel.querySelectorAll('.app-bet5-boat').forEach(function(b){b.classList.remove('is-active');});renderCandidates();});
            panel.querySelector('.app-bet5-add-all').addEventListener('click',function(){candidates().forEach(addTicket);renderTickets();});
            panel.querySelector('.app-bet5-allocate').addEventListener('click',function(){allocate();renderTickets();});
            panel.querySelector('.app-bet5-clear-tickets').addEventListener('click',function(){tickets.clear();renderTickets();});
            budgetInput.addEventListener('change',function(){allocate();renderTickets();});
            modeSelect.addEventListener('change',function(){allocate();renderTickets();});

            function activate(){document.querySelectorAll('.app-tab').forEach(function(t){t.classList.toggle('is-active',t.dataset.tab==='bet');});document.querySelectorAll('.app-tab-panel').forEach(function(p){const active=p.dataset.panel==='bet';p.classList.toggle('is-active',active);p.hidden=!active;});try{sessionStorage.setItem(STORAGE_KEY,'bet');}catch(e){}}
            button.addEventListener('click',activate);
            loadOdds(false); renderTickets();
            let saved='';try{saved=sessionStorage.getItem(STORAGE_KEY)||'';}catch(e){}if(saved==='bet')activate();
        },120);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setup); else setup();
})();
