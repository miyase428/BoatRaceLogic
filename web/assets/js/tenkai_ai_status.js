(function (root) {
    'use strict';
    const STATUS_ENDPOINT = '/web/tenkai_ai_status_api.php';
    const WALL_ENDPOINT = '/web/course34_wall_api.php';
    const ATTACK_TYPE_ENDPOINT = '/web/course4_attack_type_api.php';
    const STATUS_TEXT = Object.freeze({WAITING_EXHIBITION: '展示データ待ち', WAITING_ELIGIBILITY: '判定待ち', WAITING_FORMAL_CAPTURE: '判定待ち', FORMAL_CAPTURED: '正式データ取得済み', NOT_TARGET: '対象外', UNAVAILABLE: '展開AIを取得できませんでした'});
    const EXHIBITION_WAIT_REASONS = new Set(['EXHIBITION_NOT_COMPLETE', 'ENTRY_MAPPING_INCOMPLETE', 'EXHIBITION_AFTER_CUTOFF']);
    const PREDICTION_WAIT_REASONS = new Set(['PROSPECTIVE_SNAPSHOT_NOT_FOUND', 'MISSING_REQUIRED_FEATURE', 'SNAPSHOT_NOT_AVAILABLE_AT_CUTOFF']);
    const VENUE_UNSUPPORTED_REASON = 'VENUE_NOT_SUPPORTED';

    function validRaceCode(value) { return /^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/.test(String(value || '').trim().toUpperCase()); }
    function finiteProbability(value) { return typeof value === 'number' && Number.isFinite(value) && value >= 0 && value <= 1; }
    function validStatusPayload(data, raceCode) { return !!data && data.success === true && data.api_version === 'tenkai-ai-status/v1' && data.race_code === raceCode && Object.prototype.hasOwnProperty.call(STATUS_TEXT, data.status) && typeof data.is_candidate === 'boolean' && typeof data.formal_evidence_available === 'boolean'; }
    function validWallPayload(data, raceCode) { return !!data && data.success === true && data.race_code === raceCode && typeof data.prediction_available === 'boolean' && (!data.prediction_available || finiteProbability(data.wall_probability)); }
    function validAttackPayload(data, raceCode) {
        if (!data || data.success !== true || data.race_code !== raceCode || typeof data.prediction_available !== 'boolean') return false;
        if (!data.prediction_available) return true;
        const p = data.probabilities;
        return !!p && finiteProbability(p.no_attack_success) && finiteProbability(p.makuri_success) && finiteProbability(p.makurizashi_success) && Math.abs((p.no_attack_success + p.makuri_success + p.makurizashi_success) - 1) <= 1e-8;
    }
    function timeText(value) { const date = new Date(String(value || '')); return Number.isNaN(date.getTime()) ? '' : String(date.getHours()).padStart(2, '0') + ':' + String(date.getMinutes()).padStart(2, '0') + '取得'; }
    function percent(value) { return (value * 100).toFixed(1) + '%'; }
    function active(panel, token, raceCode) { return panel.dataset.tenkaiAiRequest === token && String(panel.dataset.raceCode || '').trim().toUpperCase() === raceCode; }
    function ui(panel) { return {body: panel.querySelector('[data-tenkai-ai-status]'), meta: panel.querySelector('[data-tenkai-ai-meta]'), notice: panel.querySelector('[data-tenkai-ai-numeric-notice]'), grid: panel.querySelector('[data-tenkai-ai-grid]'), footer: panel.querySelector('[data-tenkai-ai-footer]')}; }
    function hideNumeric(panel) { const view = ui(panel); if (view.grid) { view.grid.hidden = true; view.grid.querySelectorAll('[data-tenkai-ai-value]').forEach(node => { node.textContent = '—'; }); } if (view.footer) view.footer.hidden = true; }
    function setNotice(panel, text) { const notice = ui(panel).notice; if (!notice) return; notice.textContent = text || ''; notice.hidden = !text; }
    function renderStatus(panel, data) {
        const view = ui(panel); if (!view.body || !view.meta) return false;
        const captured = data.status === 'FORMAL_CAPTURED' && data.formal_evidence_available === true && data.capture_mode === 'LIVE_STRICT' && typeof data.captured_at === 'string';
        // CC30C candidate eligibility is independent from the Wall-R / Attack
        // Type prediction contracts.  In particular, NOT_TARGET must never be
        // rendered as an entry-change exclusion before those contracts answer.
        view.body.textContent = captured ? STATUS_TEXT.FORMAL_CAPTURED : '展開AIを確認中…'; view.meta.textContent = captured ? timeText(data.captured_at) : '';
        panel.dataset.tenkaiAiState = captured ? 'captured' : 'checking'; panel.setAttribute('aria-busy', 'false'); return captured;
    }
    function renderUnavailable(panel) { const view = ui(panel); hideNumeric(panel); setNotice(panel, ''); if (view.body) view.body.textContent = STATUS_TEXT.UNAVAILABLE; if (view.meta) view.meta.textContent = ''; panel.dataset.tenkaiAiState = 'unavailable'; panel.setAttribute('aria-busy', 'false'); }
    function renderNumericWaiting(panel, text, state) {
        const view = ui(panel); hideNumeric(panel); setNotice(panel, '');
        if (view.body) view.body.textContent = text;
        if (view.meta) view.meta.textContent = '';
        panel.dataset.tenkaiAiState = state;
        panel.setAttribute('aria-busy', 'false');
    }
    function renderNumericNotTarget(panel) { const view = ui(panel); hideNumeric(panel); setNotice(panel, ''); if (view.body) view.body.textContent = '進入変化のため対象外'; if (view.meta) view.meta.textContent = ''; panel.dataset.tenkaiAiState = 'not_target'; }
    function renderVenueUnsupported(panel) { const view = ui(panel); hideNumeric(panel); setNotice(panel, ''); if (view.body) view.body.textContent = 'この場は現在対象外'; if (view.meta) view.meta.textContent = ''; panel.dataset.tenkaiAiState = 'venue_not_supported'; panel.setAttribute('aria-busy', 'false'); }
    function renderNumeric(panel, wall, attack, formalCaptured) {
        const view = ui(panel); const values = {wall: wall.wall_probability, makuri: attack.probabilities.makuri_success, makurizashi: attack.probabilities.makurizashi_success, 'no-attack': attack.probabilities.no_attack_success}; let complete = true;
        Object.keys(values).forEach(key => { const node = view.grid ? view.grid.querySelector('[data-tenkai-ai-value="' + key + '"]') : null; if (!node || !finiteProbability(values[key])) complete = false; else node.textContent = percent(values[key]); });
        if (!complete || !view.grid || !view.footer) { renderUnavailable(panel); return; }
        setNotice(panel, ''); view.grid.hidden = false; view.footer.hidden = false;
        if (!formalCaptured) { if (view.body) view.body.textContent = '予測データ取得済み'; if (view.meta) view.meta.textContent = ''; panel.dataset.tenkaiAiState = 'captured'; }
    }
    async function fetchJson(url) { const response = await fetch(url, {cache: 'no-store', credentials: 'same-origin'}); let data; try { data = await response.json(); } catch (_) { throw new Error('MALFORMED_JSON'); } if (!response.ok) throw new Error('HTTP_ERROR'); return data; }
    function unavailableReason(data) { return String((data && (data.reason_code || data.reason_message)) || ''); }
    async function load(panel) {
        const raceCode = String(panel.dataset.raceCode || '').trim().toUpperCase(); const token = String(Number(panel.dataset.tenkaiAiRequest || '0') + 1); panel.dataset.tenkaiAiRequest = token; hideNumeric(panel); setNotice(panel, '');
        if (!validRaceCode(raceCode)) { renderUnavailable(panel); return; }
        try {
            const status = await fetchJson(STATUS_ENDPOINT + '?race_code=' + encodeURIComponent(raceCode)); if (!active(panel, token, raceCode)) return;
            if (!validStatusPayload(status, raceCode)) { renderUnavailable(panel); return; }
            const formalCaptured = renderStatus(panel, status);
            const pair = await Promise.all([fetchJson(WALL_ENDPOINT + '?race_code=' + encodeURIComponent(raceCode)), fetchJson(ATTACK_TYPE_ENDPOINT + '?race_code=' + encodeURIComponent(raceCode))]);
            if (!active(panel, token, raceCode)) return;
            const wall = pair[0]; const attack = pair[1];
            if (!validWallPayload(wall, raceCode) || !validAttackPayload(attack, raceCode)) { renderUnavailable(panel); return; }
            if (!wall.prediction_available || !attack.prediction_available) {
                const reasons = [unavailableReason(wall), unavailableReason(attack)];
                if (reasons.includes(VENUE_UNSUPPORTED_REASON)) renderVenueUnsupported(panel);
                else if (reasons.includes('NON_WAKUNARI')) renderNumericNotTarget(panel);
                else if (reasons.some(reason => EXHIBITION_WAIT_REASONS.has(reason))) renderNumericWaiting(panel, '展示データ待ち', 'waiting_exhibition');
                else if (reasons.some(reason => PREDICTION_WAIT_REASONS.has(reason))) renderNumericWaiting(panel, '予測データ待ち', 'waiting_prediction');
                else renderUnavailable(panel);
                return;
            }
            renderNumeric(panel, wall, attack, formalCaptured);
        } catch (_) { if (active(panel, token, raceCode)) renderUnavailable(panel); }
    }
    function mountAll() { document.querySelectorAll('[data-tenkai-ai-panel]').forEach(panel => { if (panel.dataset.tenkaiAiMounted === '1') return; panel.dataset.tenkaiAiMounted = '1'; load(panel); }); }
    root.Cc30cTenkaiAiStatus = Object.freeze({mountAll, load, validStatusPayload, validWallPayload, validAttackPayload, statusText: STATUS_TEXT});
    if (typeof document !== 'undefined') { if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mountAll); else mountAll(); }
}(typeof window !== 'undefined' ? window : globalThis));
