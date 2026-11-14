'use strict';

const assert = require('assert');
const statusUi = require('../web/assets/js/home_forward_status.js');

function payload(overrides) {
    return Object.assign({
        ok: true,
        gate_c_status: 'PASS',
        gate_c_required_races: 2,
        eligible_distinct_races: 28,
        accepted_samples: 28,
        blocked: false,
        official_epoch: true,
        official_epoch_state: 'ACTIVE',
        official_epoch_runtime_authorized: true,
        runtime_authorized: true,
        runtime_status: 'OFFICIAL_EPOCH_RUNTIME_AUTHORIZED',
        gate_c_materialization_state: 'IDLE',
        gate_c_processing: false,
        last_updated_at: '2026-11-14T16:20:00+09:00'
    }, overrides || {});
}

const checks = [];

let view = statusUi.buildViewModel(payload());
assert.strictEqual(view.tone, 'pass');
assert.strictEqual(view.label, 'Official Forward：稼働中');
assert.strictEqual(view.gateStatus, 'PASS');
assert.strictEqual(view.alertText, '異常なし');
assert.strictEqual(view.epochText, 'ACTIVE');
checks.push('active_epoch_gate_pass');

view = statusUi.buildViewModel(payload({
    gate_c_status: 'FAIL', blocked: true, accepted_samples: 27,
    gate_c_materialization_state: 'IN_PROGRESS', gate_c_processing: true
}));
assert.strictEqual(view.tone, 'pending');
assert.strictEqual(view.label, 'Official Forward：記録反映中');
assert.strictEqual(view.gateStatus, 'FAIL（記録反映中）');
assert.strictEqual(view.alertText, '異常なし（記録反映中）');
checks.push('materializing_is_not_red');

view = statusUi.buildViewModel(payload({gate_c_status: 'FAIL', blocked: true, accepted_samples: 27}));
assert.strictEqual(view.tone, 'blocked');
assert.strictEqual(view.alertText, '要確認');
assert.strictEqual(view.gateStatus, 'FAIL');
checks.push('true_fail_remains_red');

view = statusUi.buildViewModel(payload({
    gate_c_status: 'PASS', blocked: false,
    official_epoch_state: 'SUSPENDED', official_epoch_runtime_authorized: false,
    runtime_authorized: false, gate_c_materialization_state: 'IN_PROGRESS', gate_c_processing: true
}));
assert.strictEqual(view.tone, 'blocked');
assert.strictEqual(view.alertText, '要確認');
assert.strictEqual(view.epochText, 'SUSPENDED');
checks.push('suspended_epoch_visible');

view = statusUi.buildViewModel(payload({
    gate_c_status: 'FAIL', blocked: true, accepted_samples: 27,
    official_epoch_runtime_authorized: false, runtime_authorized: false,
    gate_c_materialization_state: 'FAILED', gate_c_processing: false
}));
assert.strictEqual(view.tone, 'blocked');
assert.strictEqual(view.alertText, '要確認');
checks.push('runtime_unauthorized_is_not_processing');

view = statusUi.buildViewModel(payload({official_epoch: false, official_epoch_state: 'NOT_STARTED'}));
assert.strictEqual(view.epochText, '未開始');
checks.push('epoch_not_started');

view = statusUi.buildViewModel(payload({official_epoch_state: 'CLOSED', official_epoch_runtime_authorized: false, runtime_authorized: false}));
assert.strictEqual(view.epochText, 'CLOSED');
checks.push('closed_epoch_visible');

console.log(JSON.stringify({status: 'PASS', case_count: checks.length, checks}, null, 2));
