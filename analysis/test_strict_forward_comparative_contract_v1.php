<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/ComparativeContractV1.php';
require_once __DIR__ . '/../web/logic/StrictForwardInputSnapshotStore.php';
require_once __DIR__ . '/../web/logic/StrictForwardComparativePairStore.php';
require_once __DIR__ . '/../web/logic/FormalComparativeReadinessEvaluator.php';
require_once __DIR__ . '/../web/logic/FormalEvidenceAggregate.php';
require_once __DIR__ . '/../web/logic/FormalMeasurementWindowContext.php';

$root = dirname(__DIR__); $passed = 0; $failed = 0; $names = [];
$ok = static function (bool $condition, string $name) use (&$passed, &$failed, &$names): void { $names[] = [$name, $condition]; $condition ? $passed++ : $failed++; };
$throws = static function (callable $fn): bool { try { $fn(); } catch (Throwable) { return true; } return false; };
$contract = ComparativeContractV1::production($root); $scope = $contract->scope(); $policy = $contract->policy(); $ids = $contract->identities();
$ok(count($scope['uses']) === 123 && count(array_filter($scope['uses'], static fn($u): bool => $u['scope_class'] === 'COMPARABLE')) === 40, 'scope_40_83_123');
$mutated = $scope['uses']; $mutated[0]['ml_only_reason'] = 'MUTATED';
$ok(!hash_equals(ComparativeContractV1::identity(['schema_version' => ComparativeContractV1::SCOPE_SCHEMA, 'uses' => $scope['uses']]), ComparativeContractV1::identity(['schema_version' => ComparativeContractV1::SCOPE_SCHEMA, 'uses' => $mutated])), 'scope_change_identity');
$changedPolicy = $policy; unset($changedPolicy['comparative_policy_identity'], $changedPolicy['provenance']); $changedPolicy['decision_normalization']['missing_is_not_false'] = false;
$ok(!hash_equals($policy['comparative_policy_identity'], ComparativeContractV1::identity($changedPolicy)), 'policy_change_identity');

$payload = ['z' => 1.25, 'a' => ['list' => [true, null, 2.5], 'word' => '日本語']];
$descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open(['python3', __DIR__ . '/test_strict_forward_comparative_canonical_v1.py'], $descriptor, $pipes, $root);
$python = ''; if (is_resource($proc)) { fwrite($pipes[0], json_encode($payload, JSON_UNESCAPED_UNICODE)); fclose($pipes[0]); $python = stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($proc); } else $code = 1;
$ok(trim($python) === ComparativeContractV1::canonicalJson($payload), 'php_python_canonicalization');
$ok($throws(static fn() => ComparativeContractV1::canonicalJson(['x' => INF])), 'non_finite_rejected');

$tmp = sys_get_temp_dir() . '/comparative-v1-' . bin2hex(random_bytes(6)); mkdir($tmp, 0775, true);
$snapshotStore = new StrictForwardInputSnapshotStore($tmp);
$baseSnapshot = [
    'window_id' => 'fixture-window', 'race_code' => '20261010TMG01', 'venue' => 'TMG', 'cutoff_at' => '2026-10-10T10:00:00+09:00', 'timezone' => 'Asia/Tokyo',
    'schedule_semantic_fingerprint' => 'sha256:' . str_repeat('a', 64), 'deadline_source_identity' => 'official-cache:fixture',
    'entries' => [['course' => 1, 'player_id' => '1'], ['course' => 2, 'player_id' => '2']], 'exhibition' => [['course' => 1, 'time' => 6.70]],
    'ml_child' => ['feature_schema' => 'v1', 'features' => [['name' => 'c1_x', 'value' => 1.25]]],
    'traditional_child' => ['policy' => 'fixture', 'inputs' => [['name' => 'nige_rate', 'value' => 50.0]]], 'missing_markers' => [], 'recorded_at' => '2026-10-10T09:50:00+09:00',
];
$snapshot = $snapshotStore->write($baseSnapshot); $again = $snapshotStore->write($baseSnapshot);
$ok($snapshot['status'] === 'SAVED' && $again['status'] === 'IDEMPOTENT_SUCCESS', 'snapshot_first_write_idempotent');
$changed = $baseSnapshot; $changed['ml_child']['features'][0]['value'] = 1.5;
$ok($throws(static fn() => $snapshotStore->write($changed)), 'snapshot_conflict_and_input_change');
$withResult = $baseSnapshot; $withResult['result'] = 1;
$ok($throws(static fn() => $snapshotStore->write($withResult)), 'outcome_field_rejected');
$ok($snapshot['record']['ml_child']['features'][0]['value'] === 1.25 && $snapshot['record']['traditional_child']['inputs'][0]['value'] === 50.0, 'parent_binds_ml_and_traditional_children');

$pairStore = new StrictForwardComparativePairStore($tmp, $contract);
$comparable = array_values(array_filter($scope['uses'], static fn(array $u): bool => $u['scope_class'] === 'COMPARABLE'))[0];
$common = ['race_code' => '20261010TMG01', 'venue' => $comparable['venue'], 'course' => $comparable['course'], 'target' => $comparable['target'], 'cutoff_at' => '2026-10-10T10:00:00+09:00', 'recorded_at' => '2026-10-10T09:50:00+09:00', 'window_id' => 'fixture-window', 'scheduler_candidate_key' => 'fixture', 'prospective' => true, 'measurement_implementation_identity' => 'sha256:' . str_repeat('b', 64), 'input_snapshot_identity' => $snapshot['input_snapshot_identity'], 'snapshot_reference' => $snapshot['path'], 'ml_decision' => ['selected' => true, 'artifact' => 'fixture'], 'traditional_decision' => ['selected' => false, 'policy' => 'fixture']];
$pair = $pairStore->write($common); $pairAgain = $pairStore->write($common);
$ok($pair['status'] === 'SAVED' && $pairAgain['status'] === 'IDEMPOTENT_SUCCESS' && $pair['record']['pair_status'] === 'PAIRED', 'comparable_pair_idempotent');
$ok($throws(static fn() => (new StrictForwardComparativePairStore('/dev/null', $contract))->write($common)), 'pair_partial_write_never_silent_success');
$different = $common; $different['ml_decision']['selected'] = false;
$ok($throws(static fn() => $pairStore->write($different)), 'pair_conflict');
$ok($throws(static function () use ($pairStore, $common): void { $bad = $common; $bad['traditional_decision'] = null; $pairStore->write($bad); }), 'comparable_traditional_missing_block');
$two = $contract->use('AMG', 2, 'first');
$mlOnly = $common; $mlOnly['venue'] = 'AMG'; $mlOnly['course'] = 2; $mlOnly['target'] = 'first'; $mlOnly['traditional_decision'] = 'NONE';
$pairMlOnly = $pairStore->write($mlOnly);
$ok($two['scope_class'] === 'ML_ONLY' && $pairMlOnly['record']['pair_status'] === 'ML_ONLY_EXCLUDED', 'ml_only_none_allowed_and_2c_no_synthetic');
$ok($throws(static function () use ($pairStore, $mlOnly): void { $bad = $mlOnly; $bad['race_code'] = '20261010AMG02'; $bad['traditional_decision'] = ['selected' => true]; $pairStore->write($bad); }), 'ml_only_traditional_block');
$ok($throws(static function () use ($pairStore, $common): void { $bad = $common; $bad['input_snapshot_identity'] = 'sha256:' . str_repeat('c', 64); $pairStore->write($bad); }), 'pair_snapshot_change_conflict');
$eval = (new FormalComparativeReadinessEvaluator($contract))->evaluate([$pair['record'], $pairMlOnly['record']]);
$ok($eval['status'] === 'PASS' && $eval['paired_denominator'] === 1 && $eval['ml_only_excluded'] === 1, 'denominator_comparable_paired_only');

$oldRoot = $root . '/var/strict_forward_live_readiness/windows/successor-v3-prepared-20261008133853-c88c4f10';
$old = FormalEvidenceAggregate::calculate($oldRoot, 'successor-v3-prepared-20261008133853-c88c4f10', '2026-10-08');
$ok($old['file_count'] === 334 && $old['aggregate_sha256'] === 'sha256:90f47ff2b86153f895ccfc814f6e716576ab742074fda2b985dbb5bca09e5d24', 'old_window_aggregate_unchanged');
$oldContext = FormalMeasurementWindowContext::fromWindowRoot($root, $oldRoot);
$ok(!(bool)($oldContext->authorizeMeasurementWrite()['accepted'] ?? true), 'closed_window_writer_rejected');
$ok(is_file($root . '/var/strict_forward_live_readiness/lifecycle/closures/successor-v3-prepared-20261008133853-c88c4f10.json'), 'old_closure_physical_unchanged');
$ledgerSource = (string)file_get_contents($root . '/web/logic/StrictForwardReadinessLedger.php');
$ok(str_contains($ledgerSource, 'COMPARATIVE_CONTRACT_2C_SYNTHETIC_FORBIDDEN') && !str_contains($ledgerSource, "'status' => 'OBSERVED_LEGACY_COMPONENT_SIGNAL'"), 'formal_2c_or_promotion_removed');
$apiSource = (string)file_get_contents($root . '/web/tamagawa_lane4_star_api.php');
$ok(str_contains($apiSource, 'StrictForwardReadinessLedger::captureLiveFromContext(['), 'production_api_fail_open_boundary_retained');
$ok(!str_contains(ComparativeContractV1::canonicalJson($policy), 'measurement_implementation_identity'), 'policy_identity_has_no_measurement_cycle');
$ok($contract->use('AMG', 2, 'first')['traditional_comparator'] === 'NONE', 'two_course_contract_none');

$remove = static function (string $path) use (&$remove): void { if (is_dir($path)) { foreach (scandir($path) ?: [] as $item) if ($item !== '.' && $item !== '..') $remove($path . '/' . $item); rmdir($path); } elseif (is_file($path)) unlink($path); };
$remove($tmp);
echo json_encode(['total' => $passed + $failed, 'pass' => $passed, 'fail' => $failed, 'tests' => $names, 'identities' => $ids], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($failed === 0 ? 0 : 1);
