(function () {
    'use strict';

    const ENDPOINT = '/web/course4_attack_type_api.php';
    // ③展開AIは収集・前方検証を継続するが、PC・アプリでの正式表示は保留する。
    // 正式表示へ戻すときは、このフラグを true にするだけでよい。
    const PANEL_DISPLAY_ENABLED = false;
    const WAITING_REASONS = new Set([
        'EXHIBITION_NOT_COMPLETE',
        'ENTRY_MAPPING_INCOMPLETE',
        'MISSING_REQUIRED_FEATURE'
    ]);

    function raceCode() {
        const node = document.querySelector('.app-code, .code-value');
        return String(node ? node.textContent : '').trim().toUpperCase();
    }

    function formatPercent(value) {
        const number = Number(value);
        return Number.isFinite(number) ? (number * 100).toFixed(1) + '%' : '-';
    }

    function unavailableMessage(reason) {
        if (reason === 'NON_WAKUNARI') return '進入変化のため対象外';
        if (WAITING_REASONS.has(reason)) return '展示データ待ち';
        return '展開予測は現在利用できません';
    }

    function createPanel() {
        const panel = document.createElement('section');
        panel.className = 'course4-attack-type-panel';
        panel.dataset.course4AttackType = '1';

        const title = document.createElement('h2');
        title.className = 'course4-attack-type-title';
        title.textContent = '4コース展開予測';
        panel.appendChild(title);

        const note = document.createElement('p');
        note.className = 'course4-attack-type-note';
        note.textContent = '4号艇（4コース）・展示情報反映後';
        panel.appendChild(note);

        const body = document.createElement('div');
        body.className = 'course4-attack-type-body';
        body.setAttribute('role', 'status');
        body.setAttribute('aria-live', 'polite');
        body.textContent = '展開予測を取得中…';
        panel.appendChild(body);
        return panel;
    }

    function showText(panel, text, state) {
        const body = panel.querySelector('.course4-attack-type-body');
        if (!body) return;
        body.className = 'course4-attack-type-body is-' + state;
        body.textContent = text;
    }

    function showPrediction(panel, probabilities) {
        const body = panel.querySelector('.course4-attack-type-body');
        if (!body) return;
        body.className = 'course4-attack-type-body is-available';
        body.replaceChildren();
        [
            ['まくり成功見込み', probabilities.makuri_success],
            ['まくり差し成功見込み', probabilities.makurizashi_success],
            ['攻撃成功なし', probabilities.no_attack_success]
        ].forEach(function (row) {
            const item = document.createElement('div');
            item.className = 'course4-attack-type-row';
            const label = document.createElement('span');
            label.textContent = row[0];
            const value = document.createElement('strong');
            value.textContent = formatPercent(row[1]);
            item.append(label, value);
            body.appendChild(item);
        });
    }

    async function load(panel, code) {
        const controller = new AbortController();
        const timeout = window.setTimeout(function () { controller.abort(); }, 17000);
        try {
            const response = await fetch(ENDPOINT + '?race_code=' + encodeURIComponent(code), {
                cache: 'no-store',
                credentials: 'same-origin',
                signal: controller.signal
            });
            const data = await response.json();
            if (data && data.success === true && data.prediction_available === true && data.probabilities) {
                showPrediction(panel, data.probabilities);
                return;
            }
            if (data && data.success === true && data.prediction_available === false) {
                showText(panel, unavailableMessage(String(data.reason_code || '')), 'unavailable');
                return;
            }
            showText(panel, '展開予測を取得できませんでした', 'error');
        } catch (error) {
            showText(panel, '展開予測を取得できませんでした', 'error');
        } finally {
            window.clearTimeout(timeout);
        }
    }

    function mount() {
        if (document.querySelector('[data-course4-attack-type="1"]')) return true;
        const code = raceCode();
        if (!/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/.test(code)) return false;

        const panel = createPanel();
        const appBasic = document.querySelector('.app-tab-panel[data-panel="basic"] .app-basic-card');
        if (appBasic) {
            appBasic.insertAdjacentElement('afterend', panel);
        } else {
            const matrixHeader = document.querySelector('.matrix-header-area');
            if (!matrixHeader) return false;
            matrixHeader.insertAdjacentElement('beforebegin', panel);
        }
        load(panel, code);
        return true;
    }

    function start() {
        // PC・アプリではパネルを生成しないため、専用APIも呼ばれず余白も残らない。
        if (!PANEL_DISPLAY_ENABLED) {
            return;
        }
        if (mount()) return;
        let remaining = 40;
        const retry = function () {
            if (mount() || --remaining <= 0) return;
            window.setTimeout(retry, 100);
        };
        retry();
    }

    document.addEventListener('boatrace:app-basic-panel-ready', start);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
