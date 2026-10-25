<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/web/logic/TenkaiAiPostDeadlineReadModel.php';

$checks = [];
$check = static function (bool $condition, string $name) use (&$checks): void {
    $checks[$name] = $condition;
    if (!$condition) throw new RuntimeException('ASSERTION_FAILED:' . $name);
};
$root = sys_get_temp_dir() . '/tenkai-postdeadline-' . bin2hex(random_bytes(5));
mkdir($root . '/deadlines', 0700, true);
mkdir($root . '/formal', 0700, true);
file_put_contents($root . '/deadlines/deadlines_20261023.json', json_encode(['deadlines' => ['20261023TMG02' => '11:00']], JSON_THROW_ON_ERROR));
$referenceCalls = 0;
$reference = static function (string $race, string $deadline, string $variant) use (&$referenceCalls): array {
    $referenceCalls++;
    return ['calculated_at' => '2026-10-23T11:05:00Z', 'model_context' => 'CURRENT_MODEL_AT_CALCULATION', 'input_provenance' => ['result_tables_read' => false, 'exhibition_observed_at' => '2026-10-23T11:04:00Z', 'exhibition_source' => 'official_tamagawa'], 'components' => [
        'wall' => ['prediction_available' => false, 'reason_code' => 'PROSPECTIVE_SNAPSHOT_NOT_FOUND'],
        'attack' => ['prediction_available' => true, 'probabilities' => ['no_attack_success' => .7, 'makuri_success' => .2, 'makurizashi_success' => .1], 'logic_version' => 'reference-v1'],
    ]];
};
$before = new TenkaiAiPostDeadlineReadModel(dirname(__DIR__), static fn() => new DateTimeImmutable('2026-10-23T10:30:00+09:00'), $reference, $root . '/deadlines', $root . '/formal');
$row = $before->display('20261023TMG02');
$check($row['phase'] === 'PRE_DEADLINE' && $referenceCalls === 0, 'predeadline_preserves_live_path');

$after = new TenkaiAiPostDeadlineReadModel(dirname(__DIR__), static fn() => new DateTimeImmutable('2026-10-23T20:05:00+09:00'), $reference, $root . '/deadlines', $root . '/formal');
$row = $after->display('20261023TMG02');
$check($row['display_mode'] === 'POST_RACE_REFERENCE' && $row['strict_forward_eligible'] === false && $row['persisted'] === false, 'reference_is_nonformal_nonpersistent');
$check($row['components']['wall']['reason_code'] === 'PROSPECTIVE_SNAPSHOT_NOT_FOUND' && $row['components']['attack']['kind'] === 'POST_RACE_REFERENCE', 'partial_component_result');
$check($row['reference']['calculated_at'] === '2026-10-23T20:05:00+09:00' && $row['reference']['exhibition_fetched_at'] === '2026-10-23T20:04:00+09:00'
    && $row['reference']['exhibition_source'] === 'official_tamagawa' && $row['reference']['model_context'] === 'CURRENT_MODEL_AT_CALCULATION'
    && !array_key_exists('input_provenance', $row['reference']), 'public_reference_metadata_is_trusted_and_minimal');

$snapshot = [
    'store_version' => 'course4-attack-type-forward-snapshot/v1', 'sample_kind' => 'STRICT_FORWARD', 'race_code' => '20261023TMG02',
    'prediction_available' => true, 'prediction_cutoff' => '2026-10-23T10:50:00+09:00', 'prediction_generated_at' => '2026-10-23T10:50:01+09:00',
    'capture_generated_at' => '2026-10-23T10:50:01+09:00', 'official_deadline' => '2026-10-23T11:00:00+09:00',
    'p_no_attack_success' => .8, 'p_makuri_success' => .15, 'p_makurizashi_success' => .05,
    'logic_version' => 'formal-v1', 'artifact_sha256' => str_repeat('a', 64),
];
$canonical = static function (mixed $value) use (&$canonical): mixed {
    if (!is_array($value)) return $value;
    if (array_is_list($value)) return array_map($canonical, $value);
    ksort($value, SORT_STRING); foreach ($value as $key => $child) $value[$key] = $canonical($child); return $value;
};
$hashBody = $snapshot; unset($hashBody['prediction_generated_at'], $hashBody['capture_generated_at']);
$snapshot['snapshot_hash'] = hash('sha256', json_encode($canonical($hashBody), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
file_put_contents($root . '/formal/20261023TMG02__POST_EXHIBITION__formal-v1.json', json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
$row = $after->display('20261023TMG02');
$check($row['display_mode'] === 'FORMAL_REDISPLAY' && $row['components']['attack']['kind'] === 'FORMAL_PREDICTION', 'formal_values_take_precedence');
$check(abs($row['components']['attack']['probabilities']['makuri_success'] - .15) < 1e-12, 'formal_probability_not_recomputed');
$withoutCache = new TenkaiAiPostDeadlineReadModel(dirname(__DIR__), static fn() => new DateTimeImmutable('2026-10-23T20:05:00+09:00'), $reference, $root . '/missing-deadlines', $root . '/formal');
$row = $withoutCache->display('20261023TMG02');
$check($row['display_mode'] === 'FORMAL_REDISPLAY', 'formal_snapshot_supplies_verified_deadline_when_cache_missing');

$snapshot['p_makuri_success'] = .99;
file_put_contents($root . '/formal/20261023TMG02__POST_EXHIBITION__formal-v1.json', json_encode($snapshot));
$row = $after->display('20261023TMG02');
$check($row['display_mode'] === 'POST_RACE_REFERENCE', 'tampered_formal_snapshot_rejected');

$slowScript = $root . '/slow-reference.py';
file_put_contents($slowScript, "import time\ntime.sleep(2)\n");
$started = microtime(true);
$timed = (new TenkaiAiPostDeadlineReadModel(dirname(__DIR__), static fn() => new DateTimeImmutable('2026-10-23T20:05:00+09:00'), null,
    $root . '/deadlines', $root . '/formal', $slowScript, 0.05))->display('20261023TMG02');
$check($timed['display_mode'] === 'DATA_INSUFFICIENT' && microtime(true) - $started < 1.0, 'reference_timeout_fails_closed');

@unlink($root . '/formal/20261023TMG02__POST_EXHIBITION__formal-v1.json');
@unlink($root . '/deadlines/deadlines_20261023.json');
@unlink($slowScript);
@rmdir($root . '/formal'); @rmdir($root . '/deadlines'); @rmdir($root);
echo json_encode(['passed' => count(array_filter($checks)), 'total' => count($checks), 'checks' => $checks], JSON_UNESCAPED_SLASHES), PHP_EOL;
