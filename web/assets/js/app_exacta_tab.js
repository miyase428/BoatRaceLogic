(function () {
    'use strict';

    const STORAGE_KEY = 'boatraceAppTabEnhanced';

    function number(v) { const n = Number(v); return Number.isFinite(n) ? n : 0; }
    function parsePayload() {
        const node = document.getElementById('app-trifecta-data');
        if (!node) return null;
        try { return JSON.parse(node.textContent || '{}'); } catch (e) { return null; }
    }
    function normalizeSearch(v) {
        return String(v || '').trim()
            .replace(/[１-６]/g, function (c) { return String(c.charCodeAt(0) - 0xFEE0); })
            .replace(/[－–—→>\s]+/g, '-').replace(/[^1-6-]/g, '').replace(/-+/g, '-').replace(/^-|-$/g, '');
    }
    function boatKey(row) { return String(row.first) + '-' + String(row.second); }
    function badge(boat) {
        const span = document.createElement('span');
        span.className = 'app-trifecta-boat app-trifecta-boat-' + boat;
        span.textContent = String(boat);
        return span;
    }
    function combo(row) {
        const wrap = document.createElement('span'); wrap.className = 'app-trifecta-combination';
        wrap.appendChild(badge(row.first));
        const sep = document.createElement('span'); sep.className = 'app-trifecta-separator'; sep.textContent = '-'; wrap.appendChild(sep);
        wrap.appendChild(badge(row.second)); return wrap;
    }
    function deriveRows(t3, activeBoats) {
        const map = new Map();
        (Array.isArray(t3) ? t3 : []).forEach(function (row) {
            const boats = Array.isArray(row.boats) ? row.boats.map(Number) : [];
            if (boats.length !== 3 || boats[0] === boats[1]) return;
            const key = boats[0] + '-' + boats[1];
            if (!map.has(key)) map.set(key, {first:boats[0], second:boats[1], base_probability:0, probability:0, official_odds:null, rank:0, cumulative_probability:0});
            const r = map.get(key); r.base_probability += number(row.base_probability); r.probability += number(row.probability);
        });
        const rows = Array.from(map.values()).sort(function (a,b) { const d=b.probability-a.probability; return d || ((a.first*10+a.second)-(b.first*10+b.second)); });
        let cum=0; rows.forEach(function(r,i){r.rank=i+1;cum+=r.probability;r.cumulative_probability=cum;});
        const n=activeBoats.length, expected=n>=2?n*(n-1):0;
        return expected && rows.length===expected ? rows : [];
    }
    function waitForTabs(cb,retry) {
        const tabs=document.querySelector('.app-tabs');
        const t3=document.querySelector('.app-tab-panel[data-panel="trifecta"]');
        const recent=tabs?tabs.querySelector('[data-tab="recent"]'):null;
        if(tabs&&t3&&recent){cb(tabs,t3,recent);return;}
        if(retry<=0)return; window.setTimeout(function(){waitForTabs(cb,retry-1);},40);
    }

    async function setup() {
        const payload = window.boatraceAppTrifectaPayloadPromise ? await window.boatraceAppTrifectaPayloadPromise : parsePayload();
        if (!payload) return;
        const t3rows = Array.isArray(payload.rows) ? payload.rows : [];
        const activeBoats = Array.isArray(payload.active_boats) && payload.active_boats.length
            ? payload.active_boats.map(Number)
            : Array.from(new Set(t3rows.flatMap(function(r){return Array.isArray(r.boats)?r.boats.map(Number):[];}))).sort(function(a,b){return a-b;});
        const rows = deriveRows(t3rows, activeBoats);
        if (!rows.length) return;

        waitForTabs(function(tabs,trifectaPanel,recentButton){
            if(tabs.querySelector('[data-tab="exacta"]'))return;
            const exactaCount=rows.length, trifectaCount=t3rows.length;
            tabs.style.gridTemplateColumns='repeat(5,minmax(0,1fr))';
            const button=document.createElement('button'); button.type='button'; button.className='app-tab'; button.dataset.tab='exacta'; button.textContent='2連単';
            const t3button=tabs.querySelector('[data-tab="trifecta"]'); if(t3button)tabs.insertBefore(button,t3button);else tabs.insertBefore(button,recentButton);
            const panel=document.createElement('div'); panel.className='app-tab-panel app-trifecta-panel app-exacta-panel'; panel.dataset.panel='exacta'; panel.hidden=true; trifectaPanel.insertAdjacentElement('beforebegin',panel);
            panel.innerHTML='<section class="app-card app-trifecta-card">'
                +'<div class="app-card-body app-trifecta-heading"><h2 class="app-section-title">🎯 2連単'+exactaCount+'通り 出目確率</h2><div class="app-note">3連単'+trifectaCount+'通りを1着-2着ごとに合算。</div>'
                +'<div style="display:flex;justify-content:space-between;gap:8px;align-items:center;margin-top:8px;padding:7px 8px;border:1px solid #d6d3cd;border-radius:6px;background:#fffaf2"><span class="app-exacta-odds-status" style="font-size:11px;color:#6b7785">公式2連単オッズ：取得中…</span><button type="button" class="app-exacta-refresh">更新</button></div></div>'
                +'<div class="app-card-body app-trifecta-controls"><label class="app-trifecta-search-label">買い目検索<input class="app-exacta-search" type="text" inputmode="numeric" placeholder="例 1 / 1-2"></label><div class="app-exacta-filters"></div><div class="app-trifecta-control-row"><span class="app-exacta-count"></span><button type="button" class="app-trifecta-clear app-exacta-clear">クリア</button></div><div class="app-exacta-summary" style="margin-top:8px;padding:8px;border:1px solid #d6d3cd;border-radius:6px;background:#fffaf2;font-size:12px;font-weight:bold"></div></div>'
                +'<div class="app-trifecta-table-wrap"><table class="app-trifecta-table app-exacta-table"><thead><tr><th><button data-sort="rank">順位</button></th><th><button data-sort="combination">2連単</button></th><th><button data-sort="base">基礎出目</button></th><th><button data-sort="final">最終出目確率</button></th><th><button data-sort="odds">オッズ</button></th><th><button data-sort="delta">基礎差</button></th><th><button data-sort="cumulative">累計</button></th></tr></thead><tbody></tbody></table></div><div class="app-card-body app-trifecta-foot">'+exactaCount+'通り合計100% / オッズはBOAT RACE公式</div></section>';

            const search=panel.querySelector('.app-exacta-search'), filters=panel.querySelector('.app-exacta-filters'), count=panel.querySelector('.app-exacta-count'), summary=panel.querySelector('.app-exacta-summary'), clear=panel.querySelector('.app-exacta-clear'), tbody=panel.querySelector('tbody'), sortButtons=Array.from(panel.querySelectorAll('th button[data-sort]')), oddsStatus=panel.querySelector('.app-exacta-odds-status'), refresh=panel.querySelector('.app-exacta-refresh');
            const selected=[new Set(),new Set()]; let sortKey='rank', sortDirection=1;
            ['1着','2着'].forEach(function(label,pos){
                const group=document.createElement('div'); group.className='app-trifecta-filter-group app-exacta-filter-group'; group.dataset.position=String(pos); group.style.gridTemplateColumns='35px repeat('+(activeBoats.length+1)+',minmax(0,1fr))';
                const title=document.createElement('span'); title.className='app-trifecta-filter-label'; title.textContent=label; group.appendChild(title);
                [0].concat(activeBoats).forEach(function(boat){const b=document.createElement('button');b.type='button';b.className='app-trifecta-filter app-exacta-filter'+(boat===0?' is-active':'');b.dataset.boat=String(boat);b.textContent=boat===0?'全':String(boat);group.appendChild(b);}); filters.appendChild(group);
            });
            function officialOdds(row){const n=Number(row.official_odds);return Number.isFinite(n)&&n>0?n:null;}
            function compare(a,b){let av,bv;switch(sortKey){case'combination':av=a.first*10+a.second;bv=b.first*10+b.second;break;case'base':av=a.base_probability;bv=b.base_probability;break;case'final':av=a.probability;bv=b.probability;break;case'odds':av=officialOdds(a);bv=officialOdds(b);if(av===null&&bv===null)return a.rank-b.rank;if(av===null)return 1;if(bv===null)return-1;break;case'delta':av=a.probability-a.base_probability;bv=b.probability-b.base_probability;break;case'cumulative':av=a.cumulative_probability;bv=b.cumulative_probability;break;default:av=a.rank;bv=b.rank;}if(av===bv)return a.rank-b.rank;return(av<bv?-1:1)*sortDirection;}
            function currentRows(){const q=normalizeSearch(search?search.value:'');return rows.filter(function(r){if(selected[0].size&&!selected[0].has(r.first))return false;if(selected[1].size&&!selected[1].has(r.second))return false;if(!q)return true;const k=boatKey(r);return k===q||k.indexOf(q+'-')===0||String(r.first)===q;}).sort(compare);}
            function paint(){panel.querySelectorAll('.app-exacta-filter-group').forEach(function(g){const p=Number(g.dataset.position||0);g.querySelectorAll('.app-exacta-filter').forEach(function(b){const boat=Number(b.dataset.boat||0),active=boat===0?selected[p].size===0:selected[p].has(boat);b.classList.toggle('is-active',active);b.setAttribute('aria-pressed',active?'true':'false');});});}
            function sortLabels(){sortButtons.forEach(function(b){const base=b.textContent.replace(/[▲▼]$/,'').trim();b.textContent=base;if(b.dataset.sort===sortKey)b.textContent=base+(sortDirection>0?' ▲':' ▼');});}
            function render(){const current=currentRows();tbody.textContent='';current.forEach(function(r){const tr=document.createElement('tr'),delta=r.probability-r.base_probability,odds=officialOdds(r);const vals=[String(r.rank),null,(r.base_probability*100).toFixed(3)+'%',(r.probability*100).toFixed(3)+'%',odds===null?'-':odds.toLocaleString('ja-JP',{maximumFractionDigits:1}),(delta>=0?'+':'')+(delta*100).toFixed(3)+'pt',(r.cumulative_probability*100).toFixed(2)+'%'];vals.forEach(function(v,i){const td=document.createElement('td');if(i===1){td.className='app-trifecta-combo-cell';td.appendChild(combo(r));}else td.textContent=v;if(i===0)td.className='app-trifecta-rank';if(i===3)td.className='app-trifecta-final';if(i===4)td.className='app-trifecta-odds';tr.appendChild(td);});tbody.appendChild(tr);});const ps=current.reduce(function(s,r){return s+number(r.probability);},0);let inv=0,usable=0;current.forEach(function(r){const o=officialOdds(r);if(o!==null){inv+=1/o;usable++;}});count.textContent='表示中：'+current.length+' / '+exactaCount+'通り';summary.textContent='最終出目確率合計：'+(ps*100).toFixed(2)+'%　合成オッズ：'+(usable&&inv>0?(1/inv).toFixed(2)+'倍':'-');sortLabels();}
            async function loadOdds(force){const codeNode=document.querySelector('.app-code'),code=String(codeNode?codeNode.textContent:'').trim();if(!/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/.test(code))return;refresh.disabled=true;oddsStatus.textContent=force?'公式2連単オッズ：更新中…':'公式2連単オッズ：取得中…';try{const body=new URLSearchParams();body.set('race_code',code);body.set('refresh',force?'1':'0');const res=await fetch('/web/official_exacta_odds_api.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString(),cache:'no-store'}),data=await res.json();if(data&&data.status==='ok'&&Number(data.count)>0){const map=data.odds||{};rows.forEach(function(r){const n=Number(map[boatKey(r)]);r.official_odds=Number.isFinite(n)&&n>0?n:null;});oddsStatus.textContent='オッズ取得 / '+exactaCount+'通り';render();}else oddsStatus.textContent='公式2連単オッズ：'+String(data&&data.error||'取得できませんでした');}catch(e){oddsStatus.textContent='公式2連単オッズ：取得エラー';}finally{refresh.disabled=false;}}
            filters.addEventListener('click',function(e){const t=e.target.closest('.app-exacta-filter');if(!t)return;const g=t.closest('.app-exacta-filter-group'),p=Number(g.dataset.position||0),boat=Number(t.dataset.boat||0);if(boat===0)selected[p].clear();else if(selected[p].has(boat))selected[p].delete(boat);else{selected[p].add(boat);if(selected[p].size===activeBoats.length)selected[p].clear();}paint();render();});
            search.addEventListener('input',render);clear.addEventListener('click',function(){search.value='';selected.forEach(function(s){s.clear();});sortKey='rank';sortDirection=1;paint();render();});sortButtons.forEach(function(b){b.addEventListener('click',function(){const k=b.dataset.sort||'rank';if(sortKey===k)sortDirection*=-1;else{sortKey=k;sortDirection=(k==='rank'||k==='combination'||k==='odds')?1:-1;}render();});});refresh.addEventListener('click',function(){loadOdds(true);});
            function activate(){document.querySelectorAll('.app-tab').forEach(function(t){t.classList.toggle('is-active',t.dataset.tab==='exacta');});document.querySelectorAll('.app-tab-panel').forEach(function(p){const a=p.dataset.panel==='exacta';p.classList.toggle('is-active',a);p.hidden=!a;});try{sessionStorage.setItem(STORAGE_KEY,'exacta');}catch(e){}}
            button.addEventListener('click',activate);paint();render();loadOdds(false);let saved='';try{saved=sessionStorage.getItem(STORAGE_KEY)||'';}catch(e){}if(saved==='exacta')activate();
        },120);
    }

    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',setup);else setup();

    if(!document.querySelector('script[data-app-five-bet-loader="1"]')){
        const s=document.createElement('script');s.src='/web/assets/js/app_bet_simulator_five_boat.js?v=20260905a';s.dataset.appFiveBetLoader='1';s.async=false;document.head.appendChild(s);
    }
})();
