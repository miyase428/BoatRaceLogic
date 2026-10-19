<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/ComparativeContractV1.php';

/** Builds the v1 contract only after validating its audit inventory against current deployment artifacts. */
$root = dirname(__DIR__);
$crosswalk = $root . '/analysis/output/ml_forward_epoch_launch_criteria/production_use_slot_crosswalk.csv';
$direct = $root . '/analysis/output/ml_forward_comparative_inventory/direct_comparative_scope.csv';
$mlOnly = $root . '/analysis/output/ml_forward_comparative_inventory/ml_only_scope.csv';
$rules = $root . '/config/course_signal_rules.json';
$api = $root . '/web/tamagawa_lane4_star_api.php';
$ledger = $root . '/web/logic/StrictForwardReadinessLedger.php';
foreach ([$crosswalk, $direct, $mlOnly, $rules, $api, $ledger] as $path) if (!is_file($path)) throw new RuntimeException('contract_source_missing:' . $path);

/** @return list<array<string,string>> */
$readCsv = static function (string $path): array {
    $handle = fopen($path, 'rb'); if (!is_resource($handle)) throw new RuntimeException('contract_csv_open_failed');
    $header = fgetcsv($handle); if (!is_array($header)) throw new RuntimeException('contract_csv_header_failed');
    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) !== count($header)) throw new RuntimeException('contract_csv_row_invalid');
        $rows[] = array_combine($header, $row);
    }
    fclose($handle); return $rows;
};
$cross = $readCsv($crosswalk); $directRows = $readCsv($direct); $mlOnlyRows = $readCsv($mlOnly);
$index = static function (array $row): string { return strtoupper($row['venue']) . '|' . (int)$row['course'] . '|' . $row['target']; };
if (count($cross) !== 123 || count($directRows) !== 40 || count($mlOnlyRows) !== 83) throw new RuntimeException('contract_source_count_invalid');
$directByKey = []; foreach ($directRows as $row) $directByKey[$index($row)] = $row;
$mlOnlyByKey = []; foreach ($mlOnlyRows as $row) $mlOnlyByKey[$index($row)] = $row;
if (count($directByKey) !== 40 || count($mlOnlyByKey) !== 83 || array_intersect_key($directByKey, $mlOnlyByKey) !== []) throw new RuntimeException('contract_source_duplicate_or_overlap');

$modelFiles = [
    'amagasaki_course_signal_v1' => 'amagasaki_course_signal_v1.json', 'ashiya_course_signal_v1' => 'ashiya_course_signal_v1.json',
    'biwako_course_signal_v1' => 'biwako_course_signal_v1.json', 'edogawa_course_signal_v1' => 'edogawa_course_signal_v1.json',
    'heiwajima_course_signal_v1' => 'heiwajima_course_signal_v1.json', 'kiryuu_course_signal_v1' => 'kiryuu_course_signal_v1.json',
    'mikuni_course_signal_v1' => 'mikuni_course_signal_v1.json', 'omura_course_signal_v1' => 'omura_course_signal_v1.json',
    'shimonoseki_course_signal_v1' => 'shimonoseki_course_signal_v1.json', 'suminoe_course_signal_v1' => 'suminoe_course_signal_v1.json',
    'tamagawa_course_signal_v1' => 'tamagawa_center_signal_v1.json', 'toda_course_signal_v1' => 'toda_course_signal_v1.json',
];
$apiText = file_get_contents($api); if (!is_string($apiText) || !str_contains($apiText, '$strictForwardVenueMlEnabled')) throw new RuntimeException('runtime_routing_source_invalid');
$uses = [];
foreach ($cross as $row) {
    if (($row['production_ml_enabled'] ?? '') !== 'YES') throw new RuntimeException('non_ml_crosswalk_row');
    $venue = strtoupper($row['venue']); $course = (int)$row['course']; $target = $row['target']; $key = $index($row);
    $classification = isset($directByKey[$key]) ? 'COMPARABLE' : (isset($mlOnlyByKey[$key]) ? 'ML_ONLY' : '');
    if ($classification === '') throw new RuntimeException('crosswalk_inventory_gap');
    $version = $row['production_model_version']; $manifestName = $modelFiles[$version] ?? null;
    if ($manifestName === null) throw new RuntimeException('unknown_model_version:' . $version);
    $manifestPath = $root . '/forecast/models/' . $manifestName;
    $manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || ($manifest['version'] ?? '') !== $version || !hash_equals((string)($manifest['sha256'] ?? ''), (string)$row['artifact_sha256'])) throw new RuntimeException('artifact_manifest_mismatch:' . $version);
    $inventory = $classification === 'COMPARABLE' ? $directByKey[$key] : $mlOnlyByKey[$key];
    $uses[] = [
        'use_key' => ComparativeContractV1::useKey($venue, $course, $target), 'venue' => $venue, 'course' => $course, 'target' => $target,
        'scope_class' => $classification,
        'ml_reference' => ['model_version' => $version, 'artifact_sha256' => 'sha256:' . $row['artifact_sha256']],
        'traditional_comparator' => $classification === 'COMPARABLE'
            ? ['kind' => 'legacy_single_course', 'variant' => (string)$inventory['traditional_policy_variant'], 'policy_version' => (string)$row['traditional_policy_version']]
            : 'NONE',
        'ml_only_reason' => $classification === 'ML_ONLY' ? 'NO_SINGLE_TRADITIONAL_TRUE_FALSE_POLICY' : null,
    ];
}
$uses = ComparativeContractV1::sortUses($uses);
$scopePayload = ['schema_version' => ComparativeContractV1::SCOPE_SCHEMA, 'uses' => $uses];
$scope = $scopePayload + [
    'comparative_scope_identity' => ComparativeContractV1::identity($scopePayload),
    'provenance' => ['validated_sources' => [
        'production_crosswalk_sha256' => 'sha256:' . hash_file('sha256', $crosswalk),
        'direct_scope_sha256' => 'sha256:' . hash_file('sha256', $direct), 'ml_only_scope_sha256' => 'sha256:' . hash_file('sha256', $mlOnly),
        'traditional_rules_sha256' => 'sha256:' . hash_file('sha256', $rules), 'runtime_api_sha256' => 'sha256:' . hash_file('sha256', $api), 'legacy_ledger_sha256' => 'sha256:' . hash_file('sha256', $ledger),
    ]],
];
$policyPayload = [
    'schema_version' => ComparativeContractV1::POLICY_SCHEMA,
    'comparative_scope_identity' => $scope['comparative_scope_identity'],
    'pairing_contract' => ['comparable_requires' => ['ml_boolean', 'traditional_boolean', 'same_parent_snapshot'], 'ml_only_requires' => ['traditional' => 'NONE', 'pair_status' => 'ML_ONLY_EXCLUDED'], 'denominator' => 'COMPARABLE_AND_PAIRED'],
    'decision_normalization' => ['boolean_only' => true, 'missing_is_not_false' => true, 'non_finite_number' => 'REJECT_FAIL_LOUD'],
    'two_course_rule' => ['formal_traditional_comparator' => 'NONE', 'synthetic_operations_forbidden' => ['OR', 'AND', 'fallback', 'proxy', 'nearest', 'majority', 'component_synthesis']],
    'ml_only_exclusion' => ['traditional_decision' => 'NONE_ONLY', 'denominator_inclusion' => 'BLOCK'],
    'input_snapshot_normalization' => ['schema' => ComparativeContractV1::SNAPSHOT_SCHEMA, 'float' => 'tagged_%.17g_lowercase_e', 'object_keys' => 'lexicographic', 'arrays' => 'preserve_order', 'nan_infinity' => 'REJECT'],
    'source_hashes' => ['traditional_rules' => 'sha256:' . hash_file('sha256', $rules), 'runtime_adapter' => 'sha256:' . hash_file('sha256', $api), 'legacy_ledger' => 'sha256:' . hash_file('sha256', $ledger)],
];
$policy = $policyPayload + ['comparative_policy_identity' => ComparativeContractV1::identity($policyPayload), 'provenance' => ['scope_identity_bound' => $scope['comparative_scope_identity']]];
$write = static function (string $path, array $value): void {
    $bytes = ComparativeContractV1::canonicalJson($value) . "\n";
    if (file_put_contents($path, $bytes) === false) throw new RuntimeException('contract_write_failed:' . $path);
};
$write($root . '/config/strict_forward_comparative_scope_v1.json', $scope);
$write($root . '/config/strict_forward_comparative_policy_v1.json', $policy);
echo json_encode(['scope' => $scope['comparative_scope_identity'], 'policy' => $policy['comparative_policy_identity'], 'comparable' => 40, 'ml_only' => 83], JSON_UNESCAPED_SLASHES) . "\n";
