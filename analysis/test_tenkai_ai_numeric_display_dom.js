'use strict';

// Dependency-free contract harness for the shared PC/PWA presentation code.
// It replaces fetch locally and never contacts production services.
const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync('web/assets/js/tenkai_ai_status.js', 'utf8');
const tests = [];
function check(condition, name, detail) {
    tests.push({name, pass: Boolean(condition), detail: detail || null});
    if (!condition) throw new Error('ASSERTION_FAILED:' + name + (detail ? ':' + detail : ''));
}
function node() {
    return {textContent: '', hidden: true, querySelectorAll() { return []; }};
}
function makePanel(raceCode) {
    const values = {wall: node(), makuri: node(), makurizashi: node(), 'no-attack': node()};
    const body = node(); const meta = node(); const notice = node(); const footer = node();
    const grid = Object.assign(node(), {querySelector(selector) {
        const match = selector.match(/^\[data-tenkai-ai-value="(.+)"\]$/);
        return match ? values[match[1]] || null : null;
    }, querySelectorAll(selector) { return selector === '[data-tenkai-ai-value]' ? Object.values(values) : []; }});
    return {
        dataset: {raceCode}, attributes: {'aria-busy': 'true'}, body, meta, notice, grid, footer, values,
        querySelector(selector) {
            return {
                '[data-tenkai-ai-status]': body,
                '[data-tenkai-ai-meta]': meta,
                '[data-tenkai-ai-numeric-notice]': notice,
                '[data-tenkai-ai-grid]': grid,
                '[data-tenkai-ai-footer]': footer
            }[selector] || null;
        },
        setAttribute(key, value) { this.attributes[key] = String(value); }
    };
}
function response(data, ok = true) { return {ok, json: async () => data}; }
function status(raceCode, statusName, extra) {
    return Object.assign({
        success: true, api_version: 'tenkai-ai-status/v1', race_code: raceCode,
        status: statusName, is_candidate: statusName !== 'NOT_TARGET',
        formal_evidence_available: statusName === 'FORMAL_CAPTURED',
        capture_mode: statusName === 'FORMAL_CAPTURED' ? 'LIVE_STRICT' : null,
        captured_at: statusName === 'FORMAL_CAPTURED' ? '2026-10-20T16:48:00+09:00' : null
    }, extra || {});
}
function wall(raceCode, available, reason) {
    return available ? {success: true, prediction_available: true, race_code: raceCode, stage: 'POST_EXHIBITION', wall_probability: 0.412} : {success: true, prediction_available: false, race_code: raceCode, stage: 'POST_EXHIBITION', reason_code: reason};
}
function attack(raceCode, available, reason) {
    return available ? {success: true, prediction_available: true, race_code: raceCode, stage: 'POST_EXHIBITION', probabilities: {no_attack_success: 0.766, makuri_success: 0.148, makurizashi_success: 0.086}} : {success: true, prediction_available: false, race_code: raceCode, stage: 'POST_EXHIBITION', reason_code: reason};
}
async function flush() { await new Promise(resolve => setImmediate(resolve)); await new Promise(resolve => setImmediate(resolve)); await new Promise(resolve => setImmediate(resolve)); }
async function boot(panels, fetchImpl) {
    const document = {readyState: 'complete', querySelectorAll(selector) { return selector === '[data-tenkai-ai-panel]' ? panels : []; }, addEventListener() {}};
    const context = vm.createContext({document, fetch: fetchImpl, console, Promise, Date, setTimeout});
    vm.runInContext(source, context, {filename: 'tenkai_ai_status.js'});
    await flush();
    return context;
}

(async () => {
    const race = '20261020MKN12';
    const normal = makePanel(race);
    await boot([normal], async url => {
        if (url.startsWith('/web/tenkai_ai_status_api.php')) return response(status(race, 'FORMAL_CAPTURED'));
        if (url.startsWith('/web/course34_wall_api.php')) return response(wall(race, true));
        return response(attack(race, true));
    });
    check(normal.body.textContent === '正式データ取得済み', 'formal_status');
    check(normal.meta.textContent === '16:48取得', 'formal_timestamp', normal.meta.textContent);
    check(normal.grid.hidden === false && normal.footer.hidden === false, 'numeric_grid_visible');
    check(normal.values.wall.textContent === '41.2%', 'wall_probability_not_percentile', normal.values.wall.textContent);
    check(normal.values.makuri.textContent === '14.8%', 'makuri_probability', normal.values.makuri.textContent);
    check(normal.values.makurizashi.textContent === '8.6%', 'makurizashi_probability', normal.values.makurizashi.textContent);
    check(normal.values['no-attack'].textContent === '76.6%', 'no_attack_probability', normal.values['no-attack'].textContent);

    const waiting = makePanel('20261020KRY08'); let waitingNumericCalls = 0;
    await boot([waiting], async url => {
        if (!url.startsWith('/web/tenkai_ai_status_api.php')) waitingNumericCalls += 1;
        if (url.startsWith('/web/tenkai_ai_status_api.php')) return response(status('20261020KRY08', 'WAITING_EXHIBITION'));
        if (url.startsWith('/web/course34_wall_api.php')) return response(wall('20261020KRY08', false, 'EXHIBITION_NOT_COMPLETE'));
        return response(attack('20261020KRY08', false, 'EXHIBITION_NOT_COMPLETE'));
    });
    check(waiting.body.textContent === '展示データ待ち' && waiting.grid.hidden, 'waiting_has_no_numbers');
    check(waitingNumericCalls === 2, 'waiting_uses_prediction_contract');

    const nonWakunari = makePanel(race);
    await boot([nonWakunari], async url => {
        if (url.startsWith('/web/tenkai_ai_status_api.php')) return response(status(race, 'FORMAL_CAPTURED'));
        if (url.startsWith('/web/course34_wall_api.php')) return response(wall(race, false, 'NON_WAKUNARI'));
        return response(attack(race, true));
    });
    check(nonWakunari.body.textContent === '進入変化のため対象外' && nonWakunari.grid.hidden, 'non_wakunari_hides_grid');

    const snapshotWait = makePanel(race);
    await boot([snapshotWait], async url => {
        if (url.startsWith('/web/tenkai_ai_status_api.php')) return response(status(race, 'FORMAL_CAPTURED'));
        if (url.startsWith('/web/course34_wall_api.php')) return response(wall(race, false, 'PROSPECTIVE_SNAPSHOT_NOT_FOUND'));
        return response(attack(race, true));
    });
    check(snapshotWait.body.textContent === '予測データ待ち' && snapshotWait.notice.hidden && snapshotWait.grid.hidden, 'source_wait_fail_closed');

    const venueUnsupported = makePanel('20261021EDG01');
    await boot([venueUnsupported], async url => {
        if (url.startsWith('/web/tenkai_ai_status_api.php')) return response(status('20261021EDG01', 'NOT_TARGET'));
        if (url.startsWith('/web/course34_wall_api.php')) return response(wall('20261021EDG01', false, 'VENUE_NOT_SUPPORTED'));
        return response(attack('20261021EDG01', false, 'VENUE_NOT_SUPPORTED'));
    });
    check(venueUnsupported.body.textContent === 'この場は現在対象外' && venueUnsupported.grid.hidden && venueUnsupported.footer.hidden, 'venue_unsupported_hides_grid');

    const cc30cNotTarget = makePanel('20261021TKY01');
    await boot([cc30cNotTarget], async url => {
        if (url.startsWith('/web/tenkai_ai_status_api.php')) return response(status('20261021TKY01', 'NOT_TARGET'));
        if (url.startsWith('/web/course34_wall_api.php')) return response(wall('20261021TKY01', false, 'EXHIBITION_NOT_COMPLETE'));
        return response(attack('20261021TKY01', false, 'EXHIBITION_NOT_COMPLETE'));
    });
    check(cc30cNotTarget.body.textContent === '展示データ待ち' && cc30cNotTarget.grid.hidden, 'cc30c_not_target_does_not_mean_entry_change');

    const invalidSum = makePanel(race);
    await boot([invalidSum], async url => {
        if (url.startsWith('/web/tenkai_ai_status_api.php')) return response(status(race, 'FORMAL_CAPTURED'));
        if (url.startsWith('/web/course34_wall_api.php')) return response(wall(race, true));
        return response(Object.assign(attack(race, true), {probabilities: {no_attack_success: 0.7, makuri_success: 0.2, makurizashi_success: 0.2}}));
    });
    check(invalidSum.body.textContent === '展開AIを取得できませんでした' && invalidSum.grid.hidden, 'invalid_attack_sum_fail_closed');

    const httpError = makePanel(race);
    await boot([httpError], async () => response({}, false));
    check(httpError.body.textContent === '展開AIを取得できませんでした' && httpError.grid.hidden, 'http_error_fail_closed');

    const switchPanel = makePanel(race); const sequence = [];
    const context = await boot([switchPanel], url => new Promise(resolve => sequence.push({url, resolve})));
    check(sequence.length === 1, 'stale_initial_status_pending');
    switchPanel.dataset.raceCode = '20261020KRY08';
    const newer = context.Cc30cTenkaiAiStatus.load(switchPanel);
    check(sequence.length === 2, 'stale_new_status_pending');
    sequence[1].resolve(response(status('20261020KRY08', 'WAITING_EXHIBITION')));
    await flush();
    sequence[2].resolve(response(wall('20261020KRY08', false, 'EXHIBITION_NOT_COMPLETE')));
    sequence[3].resolve(response(attack('20261020KRY08', false, 'EXHIBITION_NOT_COMPLETE')));
    await newer;
    sequence[0].resolve(response(status(race, 'FORMAL_CAPTURED')));
    await flush();
    check(switchPanel.body.textContent === '展示データ待ち' && switchPanel.grid.hidden && switchPanel.meta.textContent === '', 'stale_race_response_ignored');

    const pcView = fs.readFileSync('web/views/index_view.php', 'utf8');
    const appView = fs.readFileSync('web/views/app_view.php', 'utf8');
    const partial = fs.readFileSync('web/views/tenkai_ai_status_panel.php', 'utf8');
    const css = fs.readFileSync('web/assets/css/tenkai_ai_status.css', 'utf8');
    const wallApi = fs.readFileSync('web/course34_wall_api.php', 'utf8');
    check(pcView.includes("tenkai_ai_status_panel.php") && appView.includes("tenkai_ai_status_panel.php"), 'pc_app_share_single_markup');
    check(partial.includes('data-tenkai-ai-value="wall"') && partial.includes('data-tenkai-ai-value="makuri"') && partial.includes('data-tenkai-ai-value="makurizashi"') && partial.includes('data-tenkai-ai-value="no-attack"'), 'only_four_allowed_metrics');
    check(css.includes('grid-template-columns: repeat(2, minmax(0, 1fr))'), 'two_by_two_grid');
    check(!wallApi.includes("'wall_score'") && !wallApi.includes('wall_score'), 'wall_score_never_public');
    console.log(JSON.stringify({kind: 'DOM_CONTRACT_HARNESS_NOT_BROWSER', tests}, null, 2));
})().catch(error => { console.error(error.stack || String(error)); process.exit(1); });
