(function () {
    'use strict';

    function yen(value) { return Number(value || 0).toLocaleString('ja-JP') + '円'; }

    function tickets(value) {
        const seen = new Set();
        const expanded = [];
        String(value || '').split(/[、,\n\r]+/).forEach(function (source) {
            const item = source.trim().replace(/[－ー]/g, '-').replace(/\s+/g, '');
            const match = item.match(/^([1-6]+)-([1-6]+)-([1-6]+)$/);
            if (!match) return;
            const first = Array.from(new Set(match[1].split('')));
            const second = Array.from(new Set(match[2].split('')));
            const third = Array.from(new Set(match[3].split('')));
            first.forEach(function (one) {
                second.forEach(function (two) {
                    third.forEach(function (three) {
                        if (one === two || one === three || two === three) return;
                        const ticket = one + '-' + two + '-' + three;
                        if (!seen.has(ticket)) {
                            seen.add(ticket);
                            expanded.push(ticket);
                        }
                    });
                });
            });
        });
        return expanded;
    }

    function setup(root) {
        if (!root || root.dataset.airPredictionReady === '1') return;
        root.dataset.airPredictionReady = '1';
        const raceCode = String(root.dataset.raceCode || '').toUpperCase();
        const apiUrl = String(root.dataset.apiUrl || '/web/air_prediction_api.php');
        if (!/^\d{8}[A-Z]{3}(0[1-9]|1[0-2])$/.test(raceCode)) return;

        const input = root.querySelector('.air-prediction-tickets');
        const stake = root.querySelector('.air-prediction-stake');
        const memo = root.querySelector('.air-prediction-memo');
        const save = root.querySelector('.air-prediction-save');
        const clear = root.querySelector('.air-prediction-clear');
        const saveMessage = root.querySelector('.air-prediction-save-message');
        const status = root.querySelector('.air-prediction-result-status');
        const details = root.querySelector('.air-prediction-result-details');
        const fetchButton = root.querySelector('.air-prediction-fetch');
        const officialLink = root.querySelector('.air-prediction-official-link');
        const key = 'boatraceAirPrediction:' + raceCode;
        let saved = {};
        let latestResult = null;
        try { saved = JSON.parse(localStorage.getItem(key) || '{}') || {}; } catch (e) {}
        if (input) input.value = String(saved.tickets || '');
        if (stake && saved.stake) stake.value = String(saved.stake);
        if (memo) memo.value = String(saved.memo || '');

        function applySaved(prediction) {
            if (!prediction || !Array.isArray(prediction.tickets)) return;
            saved = {
                tickets: String(prediction.ticket_input || prediction.tickets.join(', ')),
                stake: Number(prediction.stake || 100) || 100,
                memo: String(prediction.memo || '')
            };
            if (input) input.value = saved.tickets;
            if (stake) stake.value = String(saved.stake);
            if (memo) memo.value = saved.memo;
            try { localStorage.setItem(key, JSON.stringify(saved)); } catch (e) {}
        }

        function render(result, message) {
            latestResult = result || null;
            if (!result) {
                if (status) status.textContent = message || '結果はまだありません。';
                if (details) { details.hidden = true; details.textContent = ''; }
                return;
            }
            const actual = String(result.combination || '');
            const selected = tickets(saved.tickets || '');
            const hit = selected.includes(actual);
            const perTicket = Number(saved.stake || 100) || 100;
            const investment = selected.length * perTicket;
            const payout = Number(result.payout || 0);
            const returned = hit && payout > 0 ? Math.round(payout * perTicket / 100) : 0;
            if (status) {
                status.textContent = result.source === 'official'
                    ? '公式サイトから取得した結果です。'
                    : '保存済みの結果を表示しています。';
            }
            if (!details) return;
            let html = '3連単結果：<strong>' + actual + '</strong><br>払戻：' + (payout > 0 ? yen(payout) + '（100円あたり）' : '未登録');
            if (selected.length) {
                html += '<br><span class="' + (hit ? 'air-prediction-hit' : 'air-prediction-miss') + '">' + (hit ? '的中！' : '不的中') + '</span>　投資 ' + yen(investment);
                if (payout > 0) html += ' / 払戻 ' + yen(returned) + ' / 収支 ' + (returned - investment >= 0 ? '+' : '') + yen(returned - investment);
            } else {
                html += '<br>エア予想を登録すると、ここで的中と収支を確認できます。';
            }
            details.innerHTML = html;
            details.hidden = false;
        }

        function setOfficialLink(url) {
            if (!officialLink) return;
            officialLink.hidden = !url;
            if (url) officialLink.href = url;
        }

        function loadResult(official) {
            if (fetchButton) { fetchButton.disabled = true; fetchButton.textContent = official ? '公式結果を取得中…' : '結果を確認中…'; }
            if (status) status.textContent = official ? '公式サイトを確認中…' : 'DBを確認中…';
            fetch(apiUrl + '?race_code=' + encodeURIComponent(raceCode) + (official ? '&official=1' : ''), {credentials:'same-origin', cache:'no-store'})
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    if (!payload || !payload.ok) throw new Error((payload && payload.message) || '結果を取得できませんでした。');
                    applySaved(payload.prediction);
                    setOfficialLink((payload.result && payload.result.official_url) || payload.official_url || '');
                    render(payload.result || null, payload.message);
                })
                .catch(function (error) { render(null, error && error.message ? error.message : '結果を取得できませんでした。'); })
                .finally(function () { if (fetchButton) { fetchButton.disabled = false; fetchButton.textContent = '結果を取得'; } });
        }

        if (save) save.addEventListener('click', function () {
            const selected = tickets(input ? input.value : '');
            if (!selected.length) { if (saveMessage) saveMessage.textContent = '3連単を入力してください。'; return; }
            saved = {tickets:String(input ? input.value : '').trim(), stake:Number(stake ? stake.value : 100) || 100, memo:String(memo ? memo.value : '').trim(), savedAt:new Date().toISOString()};
            if (save) save.disabled = true;
            if (saveMessage) saveMessage.textContent = 'サーバーへ登録中…';
            fetch(apiUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action:'save', race_code:raceCode, tickets:selected, ticket_input:saved.tickets, stake:saved.stake, memo:saved.memo})
            })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    if (!payload || !payload.ok) throw new Error((payload && payload.message) || '保存できませんでした。');
                    applySaved(payload.prediction);
                    if (saveMessage) saveMessage.textContent = selected.length + '点をサーバーへ登録しました。';
                    if (payload.result) render(payload.result); else if (latestResult) render(latestResult);
                })
                .catch(function (error) { if (saveMessage) saveMessage.textContent = error && error.message ? error.message : '保存できませんでした。'; })
                .finally(function () { if (save) save.disabled = false; });
        });
        if (clear) clear.addEventListener('click', function () {
            saved = {};
            try { localStorage.removeItem(key); } catch (e) {}
            if (input) input.value = '';
            if (stake) stake.value = '100';
            if (memo) memo.value = '';
            if (clear) clear.disabled = true;
            if (saveMessage) saveMessage.textContent = '登録を削除中…';
            if (latestResult) render(latestResult);
            fetch(apiUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action:'delete', race_code:raceCode})
            })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    if (!payload || !payload.ok) throw new Error((payload && payload.message) || '削除できませんでした。');
                    if (saveMessage) saveMessage.textContent = '登録したエア予想を削除しました。';
                })
                .catch(function (error) { if (saveMessage) saveMessage.textContent = error && error.message ? error.message : '削除できませんでした。'; })
                .finally(function () { if (clear) clear.disabled = false; });
        });
        if (fetchButton) fetchButton.addEventListener('click', function () { loadResult(true); });
        loadResult(false);
    }

    function init() { document.querySelectorAll('#air-prediction-panel').forEach(setup); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
