(function () {
    'use strict';

    const GATE = 0.65;
    const PC_GATE_GUARD = 0.00015;

    function number(value) {
        const n = Number(value);
        return Number.isFinite(n) ? n : 0;
    }

    function pct(value, digits) {
        return (number(value) * 100).toFixed(digits == null ? 2 : digits) + '%';
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function parseAppPayload() {
        const node = document.getElementById('app-trifecta-data');
        if (!node) return null;
        try {
            const payload = JSON.parse(node.textContent || '{}');
            const rows = Array.isArray(payload.rows) ? payload.rows : [];
            if (rows.length !== 120) return null;
            return rows.map(function (row) {
                const boats = Array.isArray(row.boats) ? row.boats.map(Number) : [];
                const courses = Array.isArray(row.courses) ? row.courses.map(Number) : [];
                return {
                    rank: number(row.rank),
                    combo: boats.length === 3 ? boats.join('-') : '',
                    probability: number(row.probability),
                    headIsCourse1: courses.length === 3 && courses[0] === 1
                };
            }).filter(function (row) {
                return /^([1-6])-([1-6])-([1-6])$/.test(row.combo);
            });
        } catch (e) {
            return null;
        }
    }

    function parsePcRows() {
        const tableBox = document.getElementById('web-trifecta-all-table');
        const table = tableBox ? tableBox.querySelector('table') : null;
        const tbody = table && table.tBodies ? table.tBodies[0] : null;
        const exactaPanel = document.getElementById('head1-exacta-panel');
        const firstExactaRow = exactaPanel ? exactaPanel.querySelector('tbody tr') : null;
        const headBadge = firstExactaRow ? firstExactaRow.querySelector('td .lane-badge') : null;
        const headMatch = String(headBadge ? headBadge.textContent : '').match(/[1-6]/);
        const headBoat = headMatch ? Number(headMatch[0]) : 0;
        if (!tbody || tbody.rows.length !== 120 || headBoat < 1 || headBoat > 6) return null;

        return Array.from(tbody.rows).map(function (row) {
            const boats = (String(row.cells[1] ? row.cells[1].textContent : '').match(/[1-6]/g) || []).slice(0, 3).map(Number);
            const rawPct = parseFloat(String(row.cells[3] ? row.cells[3].textContent : '').replace(/,/g, '').replace('%', ''));
            return {
                rank: parseInt(String(row.cells[0] ? row.cells[0].textContent : '').replace(/[^0-9]/g, ''), 10) || 0,
                combo: boats.length === 3 ? boats.join('-') : '',
                probability: Number.isFinite(rawPct) ? rawPct / 100 : 0,
                headIsCourse1: boats.length === 3 && boats[0] === headBoat
            };
        }).filter(function (row) {
            return /^([1-6])-([1-6])-([1-6])$/.test(row.combo);
        });
    }

    function deriveStrategy(rows, mode) {
        if (!Array.isArray(rows) || rows.length !== 120) return null;
        const ranked = rows.slice().sort(function (a, b) {
            const ar = number(a.rank);
            const br = number(b.rank);
            if (ar > 0 && br > 0 && ar !== br) return ar - br;
            if (number(b.probability) !== number(a.probability)) return number(b.probability) - number(a.probability);
            return String(a.combo).localeCompare(String(b.combo));
        });
        const top = ranked.slice(0, 2);
        if (top.length !== 2) return null;

        const head1 = rows.reduce(function (sum, row) {
            return sum + (row.headIsCourse1 ? number(row.probability) : 0);
        }, 0);

        return {
            mode: mode,
            head1: head1,
            top1: top[0],
            top2: top[1],
            cover: number(top[0].probability) + number(top[1].probability),
            eligible: head1 >= GATE,
            nearGate: mode === 'pc' && Math.abs(head1 - GATE) <= PC_GATE_GUARD
        };
    }

    function raceCode() {
        const node = document.querySelector('.app-code') || document.querySelector('.code-value');
        const value = String(node ? node.textContent : '').trim();
        return /^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/.test(value) ? value : '';
    }

    function detectData() {
        const appRows = parseAppPayload();
        if (appRows && appRows.length === 120) {
            return {mode: 'app', rows: appRows};
        }
        const pcRows = parsePcRows();
        if (pcRows && pcRows.length === 120) {
            return {mode: 'pc', rows: pcRows};
        }
        return null;
    }

    function waitForPanel(callback, retry) {
        const appPanel = document.querySelector('.app-tab-panel[data-panel="bet"]');
        if (appPanel) {
            callback('app', appPanel);
            return;
        }
        const pcPanel = document.querySelector('.pc-main-tab-panel[data-pc-main-panel="simulator"]');
        if (pcPanel) {
            callback('pc', pcPanel);
            return;
        }
        if (retry <= 0) return;
        window.setTimeout(function () { waitForPanel(callback, retry - 1); }, 50);
    }

    function injectStyle() {
        if (document.getElementById('live-t3-top2-strategy-style')) return;
        const style = document.createElement('style');
        style.id = 'live-t3-top2-strategy-style';
        style.textContent = ''
            + '.live-t3-card{margin:0 0 12px;padding:12px;border:1px solid #d8cdbc;border-radius:8px;background:#fffaf2;color:#3f4b5a;box-sizing:border-box;}'
            + '.live-t3-head{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;flex-wrap:wrap;}'
            + '.live-t3-title{font-size:15px;font-weight:800;color:#aa741f;}'
            + '.live-t3-note{font-size:11px;color:#6b7785;margin-top:2px;}'
            + '.live-t3-badge{padding:5px 8px;border-radius:999px;font-size:11px;font-weight:800;white-space:nowrap;}'
            + '.live-t3-badge.is-target{background:#e8f4ea;color:#2f6e3f;border:1px solid #b9d8bf;}'
            + '.live-t3-badge.is-skip{background:#f3e9e4;color:#984d3d;border:1px solid #dfc0b7;}'
            + '.live-t3-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:7px;margin-top:10px;}'
            + '.live-t3-stat{padding:8px;border:1px solid #e1d8ca;border-radius:6px;background:#fff;}'
            + '.live-t3-stat span{display:block;font-size:10px;color:#6b7785;}'
            + '.live-t3-stat strong{display:block;margin-top:2px;font-size:14px;color:#3f4b5a;}'
            + '.live-t3-tickets{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-top:8px;}'
            + '.live-t3-ticket{padding:8px;border:1px solid #e1d8ca;border-radius:6px;background:#fff;}'
            + '.live-t3-ticket strong{font-size:14px;color:#2b3440;}'
            + '.live-t3-ticket div{font-size:11px;color:#6b7785;margin-top:3px;}'
            + '.live-t3-status{margin-top:8px;padding-top:8px;border-top:1px solid #e1d8ca;font-size:11px;color:#6b7785;}'
            + '.live-t3-actions{margin-top:7px;display:flex;gap:6px;justify-content:flex-end;}'
            + '.live-t3-actions button{width:auto!important;padding:6px 9px;border:1px solid #1683bd;border-radius:5px;background:#fff;color:#1683bd;font-size:11px;font-weight:700;}'
            + '@media(max-width:600px){.live-t3-grid{grid-template-columns:1fr 1fr}.live-t3-tickets{grid-template-columns:1fr}.live-t3-card{margin-left:0;margin-right:0}}';
        document.head.appendChild(style);
    }

    function createCard(panel, strategy) {
        const card = document.createElement('section');
        card.className = 'live-t3-card';
        card.dataset.liveT3Top2 = '1';
        panel.insertBefore(card, panel.firstChild);
        renderInitial(card, strategy);
        return card;
    }

    function renderInitial(card, strategy) {
        const target = strategy.eligible && !strategy.nearGate;
        const badgeClass = target ? 'is-target' : 'is-skip';
        let badge = target ? '固定戦略：対象' : '固定戦略：対象外';
        let status = '65%未満のため自動記録しません。';

        if (strategy.nearGate) {
            badge = '65%境界付近';
            status = 'PC版は表の丸め誤差があり得るため、このレースだけ自動記録を保留します。アプリ版は精密値で判定します。';
        } else if (target) {
            status = '固定条件を通過。公式オッズを確認して前方記録を自動保存します。';
        }

        card.innerHTML = ''
            + '<div class="live-t3-head">'
            + '  <div><div class="live-t3-title">🎯 3連単Top2固定戦略</div><div class="live-t3-note">前方検証固定：P(1C頭)65%以上 / 最終3連単Top2 / 500円＋500円。購入指示ではありません。</div></div>'
            + '  <span class="live-t3-badge ' + badgeClass + '">' + escapeHtml(badge) + '</span>'
            + '</div>'
            + '<div class="live-t3-grid">'
            + '  <div class="live-t3-stat"><span>P(1C頭)</span><strong>' + pct(strategy.head1, 2) + '</strong></div>'
            + '  <div class="live-t3-stat"><span>固定閾値</span><strong>65.00%</strong></div>'
            + '  <div class="live-t3-stat"><span>Top2確率合計</span><strong>' + pct(strategy.cover, 2) + '</strong></div>'
            + '  <div class="live-t3-stat"><span>配分</span><strong>500 / 500円</strong></div>'
            + '</div>'
            + '<div class="live-t3-tickets">'
            + '  <div class="live-t3-ticket"><strong>Top1 ' + escapeHtml(strategy.top1.combo) + '</strong><div>P=' + pct(strategy.top1.probability, 3) + '</div></div>'
            + '  <div class="live-t3-ticket"><strong>Top2 ' + escapeHtml(strategy.top2.combo) + '</strong><div>P=' + pct(strategy.top2.probability, 3) + '</div></div>'
            + '</div>'
            + '<div class="live-t3-status">' + escapeHtml(status) + '</div>';
    }

    function renderResult(card, data) {
        const top1 = data && data.top1 ? data.top1 : {};
        const top2 = data && data.top2 ? data.top2 : {};
        const recordStatus = String(data && data.record_status || '');
        let recordLabel = '前方記録：確認中';
        if (recordStatus === 'saved') recordLabel = '前方記録：自動保存しました';
        if (recordStatus === 'already_recorded') recordLabel = '前方記録：すでに記録済みです';
        if (recordStatus === 'waiting_odds') recordLabel = '前方記録：公式オッズ待ちです';

        card.innerHTML = ''
            + '<div class="live-t3-head">'
            + '  <div><div class="live-t3-title">🎯 3連単Top2固定戦略</div><div class="live-t3-note">前方検証固定：P(1C頭)65%以上 / 最終3連単Top2 / 500円＋500円。購入指示ではありません。</div></div>'
            + '  <span class="live-t3-badge is-target">固定戦略：対象</span>'
            + '</div>'
            + '<div class="live-t3-grid">'
            + '  <div class="live-t3-stat"><span>P(1C頭)</span><strong>' + pct(data.head1_probability, 2) + '</strong></div>'
            + '  <div class="live-t3-stat"><span>Top2確率合計</span><strong>' + pct(data.top2_cover_probability, 2) + '</strong></div>'
            + '  <div class="live-t3-stat"><span>2点合成オッズ</span><strong>' + number(data.combined_odds).toFixed(2) + '倍</strong></div>'
            + '  <div class="live-t3-stat"><span>モデル期待ROI</span><strong>' + pct(data.model_expected_roi, 2) + '</strong></div>'
            + '</div>'
            + '<div class="live-t3-tickets">'
            + '  <div class="live-t3-ticket"><strong>Top1 ' + escapeHtml(top1.combo || '-') + '　500円</strong><div>P=' + pct(top1.probability, 3) + ' / odds=' + number(top1.odds).toFixed(1) + ' / p×odds=' + number(top1.model_value).toFixed(3) + '</div></div>'
            + '  <div class="live-t3-ticket"><strong>Top2 ' + escapeHtml(top2.combo || '-') + '　500円</strong><div>P=' + pct(top2.probability, 3) + ' / odds=' + number(top2.odds).toFixed(1) + ' / p×odds=' + number(top2.model_value).toFixed(3) + '</div></div>'
            + '</div>'
            + '<div class="live-t3-status">' + escapeHtml(recordLabel)
            + (data.official_odds_fetched_at ? ' / オッズ取得 ' + escapeHtml(String(data.official_odds_fetched_at)) : '')
            + '<br>※合成オッズ・期待ROIは前方記録用の参考値。閾値で買う/見送るルールはまだ作りません。</div>'
            + '<div class="live-t3-actions"><button type="button" class="live-t3-refresh">記録を再確認</button></div>';
    }

    function renderWaiting(card, strategy, message) {
        const status = card.querySelector('.live-t3-status');
        if (status) status.textContent = message || '公式オッズを確認中です…';
        if (!card.querySelector('.live-t3-actions')) {
            const actions = document.createElement('div');
            actions.className = 'live-t3-actions';
            actions.innerHTML = '<button type="button" class="live-t3-refresh">オッズ再取得</button>';
            card.appendChild(actions);
        }
    }

    function renderError(card, message) {
        const status = card.querySelector('.live-t3-status');
        if (status) {
            status.textContent = '前方記録の自動保存に失敗しました：' + String(message || '不明なエラー');
            status.style.color = '#984d3d';
        }
        if (!card.querySelector('.live-t3-actions')) {
            const actions = document.createElement('div');
            actions.className = 'live-t3-actions';
            actions.innerHTML = '<button type="button" class="live-t3-refresh">再試行</button>';
            card.appendChild(actions);
        }
    }

    async function requestRecord(card, strategy, code, force) {
        if (!strategy.eligible || strategy.nearGate || !code) return;
        renderWaiting(card, strategy, force ? '公式オッズを更新して前方記録を確認中です…' : '公式オッズを確認して前方記録を自動保存中です…');

        const body = new URLSearchParams();
        body.set('race_code', code);
        body.set('head1_probability', strategy.head1.toFixed(8));
        body.set('top1_combo', strategy.top1.combo);
        body.set('top1_probability', strategy.top1.probability.toFixed(8));
        body.set('top2_combo', strategy.top2.combo);
        body.set('top2_probability', strategy.top2.probability.toFixed(8));
        body.set('refresh', force ? '1' : '0');

        try {
            const response = await fetch('/web/live_trifecta_top2_strategy_api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: body.toString(),
                cache: 'no-store'
            });
            const data = await response.json();
            if (!response.ok || !data || (data.status !== 'ok' && data.status !== 'waiting')) {
                throw new Error(String(data && data.error ? data.error : ('HTTP ' + response.status)));
            }
            if (data.status === 'waiting' || data.record_status === 'waiting_odds') {
                renderWaiting(card, strategy, String(data.error || '公式オッズ待ちです。'));
                return;
            }
            renderResult(card, data);
        } catch (error) {
            renderError(card, error && error.message ? error.message : error);
        }
    }

    function bindRefresh(card, strategy, code) {
        card.addEventListener('click', function (event) {
            const button = event.target.closest('.live-t3-refresh');
            if (!button) return;
            requestRecord(card, strategy, code, true);
        });
    }

    function setup() {
        const data = detectData();
        if (!data) return;
        const strategy = deriveStrategy(data.rows, data.mode);
        const code = raceCode();
        if (!strategy || !code) return;

        waitForPanel(function (panelMode, panel) {
            if (panel.querySelector('[data-live-t3-top2="1"]')) return;
            injectStyle();
            const card = createCard(panel, strategy);
            bindRefresh(card, strategy, code);
            if (strategy.eligible && !strategy.nearGate) {
                window.setTimeout(function () {
                    requestRecord(card, strategy, code, false);
                }, 120);
            }
        }, 120);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup);
    } else {
        setup();
    }
})();
