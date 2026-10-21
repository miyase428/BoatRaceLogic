'use strict';

// Dependency-free DOM harness for the shared status-card script. This is not
// a browser substitute; it exercises its success, wait, failure and response
// isolation behavior without issuing any HTTP request to production.
const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync('web/assets/js/tenkai_ai_status.js', 'utf8');
const tests = [];
function check(condition, name, detail) {
    tests.push({name, pass: Boolean(condition), detail: detail || null});
    if (!condition) throw new Error('ASSERTION_FAILED:' + name);
}
function panel(raceCode) {
    const body = {textContent: '展開AIを確認中…'};
    const meta = {textContent: ''};
    return {
        dataset: {raceCode}, attributes: {'aria-busy': 'true'}, body, meta,
        querySelector(selector) {
            if (selector === '[data-tenkai-ai-status]') return body;
            if (selector === '[data-tenkai-ai-meta]') return meta;
            return null;
        },
        setAttribute(key, value) { this.attributes[key] = String(value); }
    };
}
function payload(raceCode, status, extra) {
    return Object.assign({
        success: true,
        api_version: 'tenkai-ai-status/v1',
        race_code: raceCode,
        status,
        is_candidate: status !== 'NOT_TARGET',
        formal_evidence_available: status === 'FORMAL_CAPTURED',
        capture_mode: status === 'FORMAL_CAPTURED' ? 'LIVE_STRICT' : null,
        captured_at: status === 'FORMAL_CAPTURED' ? '2026-10-20T14:16:59+09:00' : null
    }, extra || {});
}
async function flush() {
    await new Promise(resolve => setImmediate(resolve));
    await new Promise(resolve => setImmediate(resolve));
}
async function boot(panels, fetchImpl) {
    const document = {
        readyState: 'complete',
        querySelectorAll(selector) { return selector === '[data-tenkai-ai-panel]' ? panels : []; },
        addEventListener() {}
    };
    const context = vm.createContext({document, fetch: fetchImpl, console, setTimeout, Promise, Date});
    vm.runInContext(source, context, {filename: 'tenkai_ai_status.js'});
    await flush();
    return context;
}

(async () => {
    const formalPanel = panel('20261020MKN12');
    await boot([formalPanel], async () => ({ok: true, json: async () => payload('20261020MKN12', 'FORMAL_CAPTURED')}));
    check(formalPanel.body.textContent === '正式データ取得済み', 'formal_status_text', formalPanel.body.textContent);
    check(formalPanel.meta.textContent === '14:16取得', 'formal_capture_time', formalPanel.meta.textContent);
    check(formalPanel.attributes['aria-busy'] === 'false', 'formal_loading_completed');
    check(formalPanel.dataset.tenkaiAiState === 'captured', 'formal_state');

    const waitPanel = panel('20261020KRY08');
    await boot([waitPanel], async () => ({ok: true, json: async () => payload('20261020KRY08', 'WAITING_EXHIBITION')}));
    check(waitPanel.body.textContent === '展示データ待ち', 'wait_status_text', waitPanel.body.textContent);
    check(waitPanel.meta.textContent === '', 'wait_has_no_stale_time', waitPanel.meta.textContent);

    for (const scenario of [
        ['http_error', async () => ({ok: false, json: async () => ({})})],
        ['invalid_json', async () => ({ok: true, json: async () => { throw new Error('invalid'); }})],
        ['unknown_status', async () => ({ok: true, json: async () => payload('20261020MKN12', 'UNKNOWN')})],
        ['missing_required', async () => ({ok: true, json: async () => ({success: true, api_version: 'tenkai-ai-status/v1', race_code: '20261020MKN12', status: 'FORMAL_CAPTURED'})})]
    ]) {
        const errorPanel = panel('20261020MKN12');
        await boot([errorPanel], scenario[1]);
        check(errorPanel.body.textContent === '展開AIを取得できませんでした', 'failure_' + scenario[0], errorPanel.body.textContent);
        check(errorPanel.meta.textContent === '', 'failure_meta_' + scenario[0], errorPanel.meta.textContent);
    }

    const formalThenWait = panel('20261020MKN12');
    const rows = [
        {ok: true, json: async () => payload('20261020MKN12', 'FORMAL_CAPTURED')},
        {ok: true, json: async () => payload('20261020KRY08', 'WAITING_EXHIBITION')}
    ];
    const context = await boot([formalThenWait], async () => rows.shift());
    check(formalThenWait.meta.textContent === '14:16取得', 'switch_setup_formal');
    formalThenWait.dataset.raceCode = '20261020KRY08';
    delete formalThenWait.dataset.tenkaiAiMounted;
    context.Cc30cTenkaiAiStatus.mountAll();
    await flush();
    check(formalThenWait.body.textContent === '展示データ待ち' && formalThenWait.meta.textContent === '', 'race_switch_clears_prior_formal_time');

    const first = panel('20261020MKN12');
    const second = panel('20261020KRY08');
    const pending = [];
    await boot([first, second], () => new Promise(resolve => pending.push(resolve)));
    check(pending.length === 2, 'two_requests_started');
    pending[1]({ok: true, json: async () => payload('20261020KRY08', 'WAITING_EXHIBITION')});
    await flush();
    pending[0]({ok: true, json: async () => payload('20261020MKN12', 'FORMAL_CAPTURED')});
    await flush();
    check(first.body.textContent === '正式データ取得済み' && second.body.textContent === '展示データ待ち', 'out_of_order_responses_stay_panel_local');

    const css = fs.readFileSync('web/assets/css/tenkai_ai_status.css', 'utf8');
    check(css.includes('@media (max-width: 420px)') && !/(^|[;\s{])width\s*:\s*\d+px/m.test(css), 'responsive_css_contract');
    console.log(JSON.stringify({kind: 'DOM_HARNESS_NOT_BROWSER', tests}, null, 2));
})().catch(error => {
    console.error(error.stack || String(error));
    process.exit(1);
});
