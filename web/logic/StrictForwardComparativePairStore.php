<?php

declare(strict_types=1);

require_once __DIR__ . '/ComparativeContractV1.php';

/** Immutable pair evidence; deliberately separate from the legacy readiness ledger. */
final class StrictForwardComparativePairStore
{
    public function __construct(private readonly string $rootPath, private readonly ComparativeContractV1 $contract) {}

    /** @param array<string,mixed> $input @return array{status:string,record:array<string,mixed>} */
    public function write(array $input): array
    {
        $venue = strtoupper(trim((string)($input['venue'] ?? '')));
        $course = (int)($input['course'] ?? 0); $target = (string)($input['target'] ?? '');
        $use = $this->contract->use($venue, $course, $target);
        $ids = $this->contract->identities();
        if (!hash_equals($ids['comparative_scope_identity'], (string)($input['comparative_scope_identity'] ?? $ids['comparative_scope_identity']))
            || !hash_equals($ids['comparative_policy_identity'], (string)($input['comparative_policy_identity'] ?? $ids['comparative_policy_identity']))) {
            throw new RuntimeException('pair_contract_identity_mismatch');
        }
        $ml = $input['ml_decision'] ?? null; $traditional = $input['traditional_decision'] ?? null;
        if (!is_array($ml) || !is_bool($ml['selected'] ?? null)) throw new InvalidArgumentException('pair_ml_decision_missing');
        $scope = (string)$use['scope_class'];
        if ($scope === 'COMPARABLE') {
            if (!is_array($traditional) || !is_bool($traditional['selected'] ?? null)) throw new RuntimeException('pair_comparable_traditional_missing');
            $status = 'PAIRED';
        } else {
            if ($traditional !== 'NONE') throw new RuntimeException('pair_ml_only_traditional_forbidden');
            $status = 'ML_ONLY_EXCLUDED';
        }
        $race = strtoupper(trim((string)($input['race_code'] ?? ''))); $window = trim((string)($input['window_id'] ?? ''));
        $snapshot = trim((string)($input['input_snapshot_identity'] ?? ''));
        if (!preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $race) || $window === '' || !preg_match('/^sha256:[a-f0-9]{64}$/', $snapshot)) throw new InvalidArgumentException('pair_identity_invalid');
        $record = [
            'record_version' => ComparativeContractV1::PAIR_SCHEMA,
            'stable_unique_key' => 'COMPARATIVE_PAIR|' . $window . '|' . $race . '|' . $venue . '|' . $course . '|' . $target,
            'race_code' => $race, 'venue' => $venue, 'course' => $course, 'target' => $target, 'use_key' => $use['use_key'],
            'cutoff_at' => (string)($input['cutoff_at'] ?? ''), 'recorded_at' => (string)($input['recorded_at'] ?? ''), 'timezone' => (string)($input['timezone'] ?? 'Asia/Tokyo'),
            'window_id' => $window, 'scheduler_candidate_key' => (string)($input['scheduler_candidate_key'] ?? ''), 'prospective' => (bool)($input['prospective'] ?? false),
            'measurement_implementation_identity' => (string)($input['measurement_implementation_identity'] ?? ''),
            'input_snapshot_identity' => $snapshot, 'snapshot_reference' => (string)($input['snapshot_reference'] ?? ''),
            'comparative_scope_identity' => $ids['comparative_scope_identity'], 'comparative_policy_identity' => $ids['comparative_policy_identity'],
            'scope_class' => $scope, 'ml_decision' => $ml, 'traditional_decision' => $traditional, 'pair_status' => $status,
        ];
        $decision = $record; unset($decision['recorded_at'], $decision['record_hash'], $decision['decision_hash']);
        $record['decision_hash'] = hash('sha256', ComparativeContractV1::canonicalJson($decision));
        $record['record_hash'] = hash('sha256', ComparativeContractV1::canonicalJson($record));
        $dir = rtrim($this->rootPath, DIRECTORY_SEPARATOR) . '/comparative/pairs/' . substr($race, 0, 8);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('pair_directory_unavailable');
        $path = $dir . '/' . hash('sha256', $record['stable_unique_key']) . '.json';
        $handle = @fopen($path, 'x');
        if (is_resource($handle)) {
            $ok = @fwrite($handle, ComparativeContractV1::canonicalJson($record) . "\n") !== false; @fclose($handle);
            if (!$ok) { @unlink($path); throw new RuntimeException('pair_write_failed'); }
            return ['status' => 'SAVED', 'record' => $record];
        }
        try { $existing = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { $existing = null; }
        if (is_array($existing) && hash_equals((string)($existing['decision_hash'] ?? ''), $record['decision_hash'])) return ['status' => 'IDEMPOTENT_SUCCESS', 'record' => $existing];
        throw new RuntimeException('pair_conflict');
    }
}
