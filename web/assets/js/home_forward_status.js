(function (root) {
    'use strict';

    function numberOrZero(value) {
        const parsed = Number(value);
        return Number.isFinite(parsed) && parsed >= 0 ? Math.floor(parsed) : 0;
    }

    function buildViewModel(payload) {
        if (!payload || payload.ok !== true) throw new Error('status_unavailable');

        const status = String(payload.gate_c_status || 'UNKNOWN');
        const eligible = numberOrZero(payload.eligible_distinct_races);
        const required = Math.max(1, numberOrZero(payload.gate_c_required_races) || 2);
        const accepted = numberOrZero(payload.accepted_samples);
        const official = payload.official_epoch === true;
        const blocked = payload.blocked === true || status === 'FAIL' || status.indexOf('BLOCKED_') === 0;
        let tone = 'neutral';
        let icon = '⚪';
        let label = '状態を確認中';

        if (official) {
            tone = 'official'; icon = '🔵'; label = 'Official Forward：検証中';
        } else if (blocked) {
            tone = 'blocked'; icon = '🔴'; label = 'Gate C：要確認';
        } else if (status === 'PASS') {
            tone = 'pass'; icon = '🟢'; label = 'Gate C PASS：CG確認待ち';
        } else if (status === 'PENDING_LIVE') {
            tone = 'pending'; icon = '🟡'; label = 'Gate C LIVE：データ収集中';
        }

        const updated = payload.last_updated_at ? new Date(payload.last_updated_at) : null;
        const updatedText = updated && !Number.isNaN(updated.getTime())
            ? updated.toLocaleTimeString('ja-JP', {hour: '2-digit', minute: '2-digit'})
            : '不明';
        return {
            tone: tone,
            icon: icon,
            label: label,
            gateStatus: status,
            eligibleText: eligible >= required ? eligible + '件（条件達成）' : eligible + ' / ' + required + ' レース',
            acceptedText: accepted + ' 件',
            alertText: blocked ? '要確認' : '異常なし',
            epochText: official ? '開始済み' : '未開始',
            updatedText: updatedText,
            helpText: official
                ? '現在はOfficial Forwardとして前方検証を実施中です。'
                : blocked
                    ? '現在は異常または記録の不一致があり、内容の確認が必要です。待つだけの状態ではありません。'
                    : status === 'PASS'
                        ? 'Gate Cの条件は揃っています。証拠確認と明示的な承認を待っています。'
                        : status === 'PENDING_LIVE'
                            ? '現在は通常運転の中で、条件を満たすレースが集まるのを待っています。'
                            : '現在の前方検証状態を確認しています。'
        };
    }

    function render(panel, view) {
        panel.classList.remove('is-pending', 'is-pass', 'is-blocked', 'is-official', 'is-unavailable');
        panel.classList.add('is-' + view.tone);
        panel.querySelector('[data-forward-icon]').textContent = view.icon;
        panel.querySelector('[data-forward-label]').textContent = view.label;
        panel.querySelector('[data-forward-gate]').textContent = view.gateStatus;
        panel.querySelector('[data-forward-eligible]').textContent = view.eligibleText;
        panel.querySelector('[data-forward-accepted]').textContent = view.acceptedText;
        panel.querySelector('[data-forward-alert]').textContent = view.alertText;
        panel.querySelector('[data-forward-epoch]').textContent = view.epochText;
        panel.querySelector('[data-forward-updated]').textContent = view.updatedText;
        panel.querySelector('[data-forward-help-current]').textContent = view.helpText;
    }

    function renderUnavailable(panel) {
        panel.classList.remove('is-pending', 'is-pass', 'is-blocked', 'is-official');
        panel.classList.add('is-unavailable');
        panel.querySelector('[data-forward-icon]').textContent = '⚪';
        panel.querySelector('[data-forward-label]').textContent = '状態を取得できません';
        panel.querySelector('[data-forward-gate]').textContent = '取得失敗';
        panel.querySelector('[data-forward-eligible]').textContent = '—';
        panel.querySelector('[data-forward-accepted]').textContent = '—';
        panel.querySelector('[data-forward-alert]').textContent = '確認できません';
        panel.querySelector('[data-forward-epoch]').textContent = '—';
        panel.querySelector('[data-forward-updated]').textContent = '—';
        panel.querySelector('[data-forward-help-current]').textContent = '現在の状態を取得できません。時間を置いて再確認してください。';
    }

    async function refresh(panel) {
        try {
            const response = await fetch('/web/forward_status_api.php', {method: 'GET', cache: 'no-store', credentials: 'same-origin'});
            if (!response.ok) throw new Error('status_http_' + response.status);
            render(panel, buildViewModel(await response.json()));
        } catch (error) {
            renderUnavailable(panel);
        }
    }

    function bindHelpDialog() {
        const dialog = document.getElementById('forward-status-help-dialog');
        const openButton = document.querySelector('[data-forward-help-open]');
        const closeButton = document.querySelector('[data-forward-help-close]');
        if (!dialog || !openButton || !closeButton) return;

        function openDialog() {
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
        }
        function closeDialog() {
            if (typeof dialog.close === 'function') dialog.close();
            else dialog.removeAttribute('open');
        }

        openButton.addEventListener('click', openDialog);
        closeButton.addEventListener('click', closeDialog);
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) closeDialog();
        });
    }

    function start() {
        const panel = document.getElementById('forward-status-panel');
        if (!panel) return;
        bindHelpDialog();
        refresh(panel);
        root.setInterval(function () { refresh(panel); }, 60000);
    }

    if (typeof document !== 'undefined') {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
        else start();
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {buildViewModel: buildViewModel, render: render, renderUnavailable: renderUnavailable};
    }
}(typeof window !== 'undefined' ? window : globalThis));
