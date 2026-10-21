<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/OfficialEpochLifecycleV1.php';

$root = dirname(__DIR__);
$windowId = 'successor-v3-prepared-20261010215741-f5098693';
$windowRoot = $root . '/var/strict_forward_live_readiness/windows/' . $windowId;
$runtime = ['scheduler_held' => true, 'capture_process_count' => 0, 'scheduler_config_hash' => 'sha256:fixture-runtime'];
$total = 0;
$failures = [];

$assert = static function (bool $condition, string $name) use (&$total, &$failures): void {
    $total++;
    if (!$condition) $failures[] = $name;
};
$treeHash = static function (string $path): string {
    $entries = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) continue;
        $relative = substr($file->getPathname(), strlen(rtrim($path, DIRECTORY_SEPARATOR)) + 1);
        $entries[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative) . "\0" . hash_file('sha256', $file->getPathname());
    }
    sort($entries, SORT_STRING);
    return hash('sha256', implode("\n", $entries));
};
$remove = static function (string $path) use (&$remove): void {
    if (!file_exists($path)) return;
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $name) if ($name !== '.' && $name !== '..') $remove($path . '/' . $name);
        rmdir($path);
        return;
    }
    unlink($path);
};

$assert(count(OfficialEpochPolicyV1::hardGateNames()) === 14, 'hard_gate_count_14');
$assert(OfficialEpochPolicyV1::identity() === OfficialEpochPolicyV1::identity(), 'policy_identity_deterministic');
$signals = array_fill_keys(OfficialEpochPolicyV1::hardGateNames(), true);
$assert(OfficialEpochPolicyV1::evaluateHardGates($signals)['status'] === 'PASS', 'all_hard_gates_pass');
foreach (OfficialEpochPolicyV1::hardGateNames() as $name) {
    $bad = $signals; $bad[$name] = false;
    $assert(OfficialEpochPolicyV1::evaluateHardGates($bad)['status'] === 'FAIL', 'hard_gate_blocks_' . $name);
}
$numeric = [
    'expected_completeness' => 1.0, 'canonical_completeness' => 1.0, 'eligible_opportunity_capture_rate' => 1.0,
    'scheduler_opportunity_capture_rate' => 1.0, 'unclassified_missing_count' => 0, 'artifact_identity_completeness' => 1.0,
    'policy_identity_completeness' => 1.0, 'unresolved_conflict_count' => 0, 'post_cutoff_backfill_count' => 0,
    'source_failure_count' => 0, 'classified_source_failure_count' => 0,
];
$assert(OfficialEpochPolicyV1::evaluateNumericDataQuality($numeric)['status'] === 'PASS', 'numeric_zero_failure_na_pass');
foreach (['expected_completeness', 'scheduler_opportunity_capture_rate', 'artifact_identity_completeness'] as $field) {
    $bad = $numeric; $bad[$field] = 0.99;
    $assert(OfficialEpochPolicyV1::evaluateNumericDataQuality($bad)['status'] === 'HARD_FAIL', 'numeric_hard_block_' . $field);
}
$operational = $numeric + ['distinct_eligible_race_codes' => 2, 'live_strict_before_cutoff' => true, 'replay_or_backfill_count' => 0];
$assert(OfficialEpochPolicyV1::evaluateOperationalLive($operational)['status'] === 'PASS', 'operational_minimum_two_pass');
$bad = $operational; $bad['distinct_eligible_race_codes'] = 1;
$assert(OfficialEpochPolicyV1::evaluateOperationalLive($bad)['status'] === 'FAIL', 'operational_one_race_blocks');
$bad = $operational; $bad['replay_or_backfill_count'] = 1;
$assert(OfficialEpochPolicyV1::evaluateOperationalLive($bad)['status'] === 'FAIL', 'operational_backfill_blocks');

$before = $treeHash($windowRoot);
$productionBefore = hash_file('sha256', $root . '/forecast/tamagawa_center_signal_live_v1.py');
// The pre-Epoch contract must not depend on the real host's now-active
// Epoch. Use an empty isolated lifecycle root for this deterministic case.
$preflightLifecycle = sys_get_temp_dir() . '/official_epoch_v1_preflight_' . bin2hex(random_bytes(8));
mkdir($preflightLifecycle, 0775, true);
try {
    $real = new OfficialEpochLifecycleV1($root, $preflightLifecycle);
    $preflight = $real->preflight($windowId, $runtime);
} finally {
    $remove($preflightLifecycle);
}
$after = $treeHash($windowRoot);
$assert(($preflight['epoch_evidence_readiness'] ?? '') === 'PASS', 'real_window_evidence_pass');
$assert(($preflight['preflight']['hard_gate_result']['status'] ?? '') === 'PASS', 'real_window_hard_gates_pass');
$assert(($preflight['preflight']['numeric_data_quality']['status'] ?? '') === 'PASS', 'real_window_numeric_pass');
$assert(($preflight['preflight']['operational_live']['status'] ?? '') === 'PASS', 'real_window_operational_pass');
$assert(($preflight['preflight']['formal_comparative_readiness'] ?? '') === 'PASS', 'real_window_comparative_pass');
$assert(($preflight['preflight']['cg_approval_status'] ?? '') === 'MISSING', 'missing_approval_is_normal_blocker');
$assert(($preflight['safe_to_create_cg_approval'] ?? false) === true, 'safe_to_create_approval');
$assert(($preflight['safe_to_start_official_epoch'] ?? true) === false, 'start_blocked_without_approval');
$assert(($preflight['start_block_reason'] ?? '') === 'CG_APPROVAL_REQUIRED', 'missing_approval_reason');
$assert($before === $after, 'preflight_zero_window_writes');
$assert($productionBefore === hash_file('sha256', $root . '/forecast/tamagawa_center_signal_live_v1.py'), 'preflight_no_production_routing_change');

$temp = sys_get_temp_dir() . '/official_epoch_v1_fixture_' . bin2hex(random_bytes(8));
mkdir($temp, 0775, true);
try {
    $fixture = new OfficialEpochLifecycleV1($root, $temp);
    // Reuse the already read-only production-root preflight as immutable
    // binding material; this avoids a duplicate expensive replay before the
    // temp-only approval/store assertions below.
    $p = $preflight['preflight'];
    $approvalInput = [
        'approved' => true, 'approved_at' => '2026-10-12T15:00:00+09:00',
        'approver_authority' => 'FIXTURE_CG', 'approver_stable_identifier' => 'fixture-authority-1',
        'source_window_id' => $windowId, 'source_closure_ref' => $p['source_closure_ref'], 'source_closure_hash' => $p['source_closure_hash'],
        'target_capture_measurement_identity' => $p['target_capture_measurement_identity'], 'epoch_policy_identity' => $p['epoch_policy_identity'],
        'incident_policy_schema' => $p['incident_policy_schema'], 'incident_policy_identity' => $p['incident_policy_identity'],
        'operational_live_acceptance' => ['accepted' => true, 'accepted_races' => 48, 'distinct_races' => 48, 'venues' => 4, 'scheduler_capture_rate' => 1.0],
        'numeric_quality_acceptance' => ['accepted' => true, 'result' => 'PASS'],
        'comparative_limitations_acceptance' => ['accepted' => true, 'scope_identity' => $p['comparative_contract']['comparative_scope_identity'], 'policy_identity' => $p['comparative_contract']['comparative_policy_identity'], 'comparable_use_count' => 40, 'ml_only_use_count' => 83, 'synthetic_traditional_prohibited' => true],
        'approval_statement' => 'Fixture approval only; no production Epoch is started.',
    ];
    $store = $fixture->approvalStore();
    $approval = $store->create($approvalInput);
    $assert(($approval['accepted'] ?? false) === true, 'fixture_approval_create_only');
    $assert(($store->create($approvalInput)['status'] ?? '') === 'CG_APPROVAL_ALREADY_EXISTS', 'same_approval_payload_idempotent');
    $approvalKey = (string)($approval['record']['approval_key'] ?? '');
    $ready = $fixture->preflight($windowId, $runtime, $approvalKey);
    $assert(($ready['safe_to_start_official_epoch'] ?? false) === true, 'valid_approval_start_eligible');
    $assert(($ready['preflight']['cg_approval_status'] ?? '') === 'VALID', 'valid_approval_verified');

    $wrong = $approval['record']; $wrong['source_closure_hash'] = 'sha256:' . str_repeat('0', 64);
    $assert(($store->validateForPreflight($wrong, ['source_window_id' => $windowId, 'source_closure_hash' => $p['source_closure_hash'], 'target_capture_measurement_identity' => $p['target_capture_measurement_identity'], 'epoch_policy_identity' => $p['epoch_policy_identity'], 'incident_policy_identity' => $p['incident_policy_identity']])['valid'] ?? true) === false, 'wrong_closure_approval_blocks');
    $binding = ['source_window_id' => $windowId, 'source_closure_hash' => $p['source_closure_hash'], 'target_capture_measurement_identity' => $p['target_capture_measurement_identity'], 'epoch_policy_identity' => $p['epoch_policy_identity'], 'incident_policy_identity' => $p['incident_policy_identity']];
    $badBinding = $binding; $badBinding['source_window_id'] = 'other-closed-window';
    $assert(($store->validateForPreflight($approval['record'], $badBinding)['valid'] ?? true) === false, 'wrong_window_approval_blocks');
    $badBinding = $binding; $badBinding['target_capture_measurement_identity'] = 'sha256:' . str_repeat('b', 64);
    $assert(($store->validateForPreflight($approval['record'], $badBinding)['valid'] ?? true) === false, 'wrong_capture_identity_approval_blocks');
    $badBinding = $binding; $badBinding['epoch_policy_identity'] = 'sha256:' . str_repeat('c', 64);
    $assert(($store->validateForPreflight($approval['record'], $badBinding)['valid'] ?? true) === false, 'wrong_policy_identity_approval_blocks');
    $wrong = $approval['record']; $wrong['approved'] = false;
    $assert(($store->validateRecord($wrong)['valid'] ?? true) === false, 'approval_false_blocks');
    $wrong = $approval['record']; $wrong['approval_statement'] = 'altered';
    $assert(($store->validateRecord($wrong)['valid'] ?? true) === false, 'approval_statement_hash_blocks');

    $started = $fixture->start($ready, static fn(): array => $runtime, '2026-10-12T15:05:00+09:00');
    $assert(($started['accepted'] ?? false) === true && ($started['status'] ?? '') === 'OFFICIAL_EPOCH_STARTED', 'fixture_start_create_only');
    $again = $fixture->start($ready, static fn(): array => $runtime, '2026-10-12T15:06:00+09:00');
    $assert(($again['accepted'] ?? false) === true && ($again['status'] ?? '') === 'OFFICIAL_EPOCH_ALREADY_STARTED', 'same_start_payload_idempotent');
    $conflict = $started['record'];
    $conflict['authorization_hash'] = 'sha256:' . str_repeat('a', 64);
    $withoutHash = $conflict; unset($withoutHash['record_hash']);
    $conflict['record_hash'] = 'sha256:' . hash('sha256', OfficialEpochPolicyV1::canonicalJson($withoutHash));
    $assert(($fixture->startStore()->create($conflict)['reason'] ?? '') === 'OFFICIAL_EPOCH_SINGLETON_CONFLICT', 'different_start_payload_conflict');
    $assert($fixture->epochForSourceWindow($windowId) !== [], 'external_epoch_projection');
} finally {
    $remove($temp);
}

echo json_encode([
    'status' => $failures === [] ? 'PASS' : 'FAIL',
    'fixture_total' => $total,
    'fixture_pass' => $total - count($failures),
    'fixture_fail' => count($failures),
    'failures' => $failures,
    'production_epoch_record_created' => false,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
