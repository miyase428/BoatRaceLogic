<?php

declare(strict_types=1);

require_once __DIR__ . '/MeasurementImplementationProvenance.php';

/**
 * Read-only resolver for historical Strict Forward evidence.
 *
 * It intentionally accepts a concrete Window root rather than the mutable
 * production pointer.  Missing Pair/Snapshot fields are not fabricated: this
 * resolver proves them through the Window's declaration, activation and
 * create-only evidence chain, or fails closed.
 */
final class FormalWindowProvenanceResolver
{
    public const SCHEMA_VERSION = 'formal-window-provenance-resolution/v2';

    /** @var array<string,string> */
    private array $files = [];
    /** @var array<string,array<string,mixed>> */
    private array $snapshotByReference = [];
    /** @var array<string,array<string,mixed>> */
    private array $pairByRaceUse = [];

    public function __construct(private readonly string $windowRoot, private readonly string $windowId)
    {
    }

    /**
     * @param array<string,string> $aggregateFiles relative path => raw SHA256
     * @return array<string,mixed>
     */
    public function resolve(array $aggregateFiles): array
    {
        $this->files = $aggregateFiles;
        $root = rtrim($this->windowRoot, DIRECTORY_SEPARATOR);
        $manifest = $this->required('validation_window_manifest.json');
        $declared = $this->required('declared_implementation_identity.json');
        $activation = $this->required('activation_record.json');

        if (($manifest['validation_window_id'] ?? '') !== $this->windowId
            || ($declared['window_id'] ?? '') !== $this->windowId
            || ($activation['window_id'] ?? '') !== $this->windowId) {
            $this->fail('PROVENANCE_WINDOW_ID_MISMATCH');
        }
        if (($activation['activated_at'] ?? '') !== ($manifest['armed_at'] ?? '')) {
            $this->fail('PROVENANCE_ACTIVATION_ARMED_AT_MISMATCH');
        }

        $implementation = $this->implementation($declared, $activation);
        $contract = $this->comparativeContract($declared, $activation);
        $storage = new MeasurementImplementationProvenance('__historical_window__', $root . '/provenance');
        $verified = $storage->verify(
            $implementation['implementation_identity'],
            $implementation['manifest_ref'],
            $implementation['source_freeze_ref'],
        );
        if (!$verified['valid']
            || !hash_equals($implementation['manifest_sha256'], $verified['manifest_sha256'])
            || !hash_equals($implementation['source_freeze_sha256'], $verified['source_freeze_sha256'])) {
            $this->fail('PROVENANCE_MANIFEST_OR_SOURCE_FREEZE_INVALID');
        }
        $manifestRelative = 'provenance/' . $implementation['manifest_ref'];
        $freezeRelative = 'provenance/' . $implementation['source_freeze_ref'];
        $this->requireIncluded($manifestRelative);
        $this->requireIncluded($freezeRelative);
        $freeze = $this->required($freezeRelative);
        if (($freeze['implementation_identity'] ?? '') !== $implementation['implementation_identity']
            || !is_array($freeze['files'] ?? null) || count($freeze['files']) === 0) {
            $this->fail('PROVENANCE_SOURCE_FREEZE_CONTENT_INVALID');
        }

        $armedAt = (string)($manifest['armed_at'] ?? '');
        $inventory = $this->expectedInventory($armedAt);
        $expectedCanonical = $this->expectedCanonical($implementation, $contract);
        $snapshots = $this->snapshots($inventory, $implementation, $contract);
        $pairs = $this->pairs($inventory, $expectedCanonical, $snapshots, $implementation, $contract);
        $completions = $this->completions($snapshots, $pairs, $contract);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'target_window_id' => $this->windowId,
            'declaration' => $this->reference('declared_implementation_identity.json'),
            'activation' => $this->reference('activation_record.json'),
            'measurement_manifest' => $this->reference($manifestRelative, [
                'stored_hash' => $implementation['manifest_sha256'],
                'recomputed_hash' => $verified['manifest_sha256'],
                'implementation_identity' => $implementation['implementation_identity'],
            ]),
            'source_freeze' => $this->reference($freezeRelative, [
                'stored_hash' => $implementation['source_freeze_sha256'],
                'recomputed_hash' => $verified['source_freeze_sha256'],
                'implementation_identity' => $implementation['implementation_identity'],
                'frozen_source_file_count' => count($freeze['files']),
            ]),
            'resolved_implementation' => $implementation,
            'resolved_comparative_contract' => $contract,
            'expected_inventory' => $inventory['resolution'],
            'snapshot_resolution' => [
                'count' => count($snapshots),
                'resolved_measurement_from' => ['declaration', 'activation', 'expected_or_canonical'],
                'resolved_scope_from' => ['activation.comparative_contract', 'expected_or_canonical', 'completion_or_pair'],
                'resolved_policy_from' => ['activation.comparative_contract', 'expected_or_canonical', 'completion_or_pair'],
                'resolved_schema_from' => ['activation.comparative_contract.input_snapshot_spec_identity', 'snapshot.schema_version', 'snapshot.input_snapshot_identity'],
                'resolved_prospective_from' => ['expected_inventory', 'candidate', 'eligibility', 'manifest.armed_at'],
            ],
            'pair_resolution' => [
                'count' => count($pairs),
                'manifest_resolution_source' => 'declaration+activation',
                'source_freeze_resolution_source' => 'declaration+activation',
                'snapshot_resolution_source' => 'pair.snapshot_reference+snapshot.input_snapshot_identity',
            ],
            'completion_resolution' => ['count' => count($completions)],
        ];
    }

    /** @return array<string,string> */
    private function implementation(array $declared, array $activation): array
    {
        $fields = ['implementation_identity', 'manifest_schema_version', 'manifest_sha256', 'manifest_ref', 'source_freeze_sha256', 'source_freeze_ref'];
        $out = [];
        foreach ($fields as $field) {
            $value = trim((string)($declared[$field] ?? ''));
            if ($value === '' || !hash_equals($value, (string)($activation[$field] ?? ''))) $this->fail('PROVENANCE_DECLARED_ACTIVATION_MISMATCH:' . $field);
            $out[$field] = $value;
        }
        if ($out['manifest_schema_version'] !== MeasurementImplementationProvenance::MANIFEST_SCHEMA_VERSION) {
            $this->fail('PROVENANCE_MANIFEST_SCHEMA_INVALID');
        }
        return $out;
    }

    /** @return array<string,string> */
    private function comparativeContract(array $declared, array $activation): array
    {
        $left = $declared['comparative_contract'] ?? null;
        $right = $activation['comparative_contract'] ?? null;
        if (!is_array($left) || !is_array($right) || $left !== $right) $this->fail('PROVENANCE_COMPARATIVE_CONTRACT_MISMATCH');
        $fields = ['comparative_scope_identity', 'comparative_policy_identity', 'input_snapshot_spec_identity', 'pair_record_schema_identity'];
        $out = [];
        foreach ($fields as $field) {
            $value = trim((string)($left[$field] ?? ''));
            if (!preg_match('/^sha256:[a-f0-9]{64}$/', $value)) $this->fail('PROVENANCE_COMPARATIVE_CONTRACT_INVALID:' . $field);
            $out[$field] = $value;
        }
        return $out;
    }

    /** @return array{resolution:array<string,mixed>,candidate_by_race:array<string,array<string,mixed>>,eligibility_by_race:array<string,array<string,mixed>>} */
    private function expectedInventory(string $armedAt): array
    {
        $rows = $this->rows('opportunity/expected_inventory/', false);
        if (count($rows) !== 1) $this->fail('PROVENANCE_EXPECTED_INVENTORY_CARDINALITY_INVALID');
        $relative = array_key_first($rows); $inventory = $rows[$relative];
        if (($inventory['window_id'] ?? '') !== $this->windowId || ($inventory['armed_at'] ?? '') !== $armedAt || !is_array($inventory['expected_entries'] ?? null)) {
            $this->fail('PROVENANCE_EXPECTED_INVENTORY_BINDING_INVALID');
        }
        $expected = [];
        foreach ($inventory['expected_entries'] as $entry) {
            if (!is_array($entry)) $this->fail('PROVENANCE_EXPECTED_INVENTORY_ENTRY_INVALID');
            $race = (string)($entry['race_code'] ?? '');
            if ($race === '' || isset($expected[$race])) $this->fail('PROVENANCE_EXPECTED_INVENTORY_KEYSET_INVALID');
            $expected[$race] = $entry;
        }
        ksort($expected, SORT_STRING);
        $candidates = $this->rows('opportunity/candidates/', false);
        $eligibility = $this->rows('opportunity/eligibility/', false);
        $candidateByRace = $this->byRace($candidates, 'PROVENANCE_CANDIDATE_KEYSET_INVALID');
        $eligibilityByRace = $this->byRace($eligibility, 'PROVENANCE_ELIGIBILITY_KEYSET_INVALID');
        if (array_keys($expected) !== array_keys($candidateByRace) || array_keys($expected) !== array_keys($eligibilityByRace)) {
            $this->fail('PROVENANCE_EXPECTED_CANDIDATE_ELIGIBILITY_KEYSET_MISMATCH');
        }
        foreach ($expected as $race => $entry) {
            $candidate = $candidateByRace[$race]; $resolved = $eligibilityByRace[$race];
            foreach (['race_code', 'venue', 'target_date', 'cutoff_timestamp', 'stable_candidate_key'] as $field) {
                if (!hash_equals((string)($entry[$field] ?? ''), (string)($candidate[$field] ?? ''))
                    || !hash_equals((string)($candidate[$field] ?? ''), (string)($resolved[$field] ?? ''))) {
                    $this->fail('PROVENANCE_CANDIDATE_OR_ELIGIBILITY_BINDING_MISMATCH:' . $race . ':' . $field);
                }
            }
            foreach ([$candidate, $resolved] as $row) {
                if (($row['armed_at'] ?? '') !== $armedAt || !$this->after((string)($row['cutoff_timestamp'] ?? ''), $armedAt)) {
                    $this->fail('PROVENANCE_PROSPECTIVE_BOUNDARY_INVALID:' . $race);
                }
            }
        }
        return [
            'resolution' => $this->reference((string)$relative, [
                'expected_entry_count' => count($expected),
                'candidate_count' => count($candidateByRace),
                'eligibility_count' => count($eligibilityByRace),
                'armed_at' => $armedAt,
                'prospective_keyset_identity' => (string)($inventory['prospective_keyset_identity'] ?? ''),
            ]),
            'candidate_by_race' => $candidateByRace,
            'eligibility_by_race' => $eligibilityByRace,
        ];
    }

    /** @return array{expected_by_use:array<string,array<string,mixed>>,canonical_by_use:array<string,array<string,mixed>>} */
    private function expectedCanonical(array $implementation, array $contract): array
    {
        $expected = $this->byUse($this->rows('ledger/expected/', false), $implementation, $contract, 'EXPECTED');
        $canonical = $this->byUse($this->rows('ledger/records/', false), $implementation, $contract, 'CANONICAL');
        if (array_keys($expected) !== array_keys($canonical)) $this->fail('PROVENANCE_EXPECTED_CANONICAL_KEYSET_MISMATCH');
        return ['expected_by_use' => $expected, 'canonical_by_use' => $canonical];
    }

    /** @return array<string,array<string,mixed>> */
    private function snapshots(array $inventory, array $implementation, array $contract): array
    {
        $rows = $this->rows('input_snapshots/', false); $out = [];
        foreach ($rows as $relative => $row) {
            $race = (string)($row['race_code'] ?? '');
            if (($row['window_id'] ?? '') !== $this->windowId || $race === '' || isset($out[$race])) $this->fail('PROVENANCE_SNAPSHOT_WINDOW_OR_KEY_INVALID');
            $candidate = $inventory['candidate_by_race'][$race] ?? null; $eligibility = $inventory['eligibility_by_race'][$race] ?? null;
            if (!is_array($candidate) || !is_array($eligibility) || ($eligibility['eligibility_status'] ?? '') !== 'ELIGIBLE_OBSERVED') $this->fail('PROVENANCE_SNAPSHOT_ELIGIBILITY_INVALID:' . $race);
            if (!hash_equals((string)($row['cutoff_at'] ?? ''), (string)($candidate['cutoff_timestamp'] ?? ''))
                || !$this->before((string)($row['recorded_at'] ?? ''), (string)($candidate['cutoff_timestamp'] ?? ''))) $this->fail('PROVENANCE_SNAPSHOT_CUTOFF_INVALID:' . $race);
            if (!$this->before((string)($eligibility['first_eligible_observed_at'] ?? ''), (string)($candidate['cutoff_timestamp'] ?? ''))) $this->fail('PROVENANCE_SNAPSHOT_ELIGIBILITY_TIME_INVALID:' . $race);
            if (($row['schema_version'] ?? '') !== 'strict-forward-input-snapshot/v1') $this->fail('PROVENANCE_SNAPSHOT_SCHEMA_INVALID:' . $race);
            $stable = 'INPUT_SNAPSHOT|' . $this->windowId . '|' . $race;
            if (($row['snapshot_stable_key'] ?? '') !== $stable || basename($relative) !== hash('sha256', $stable) . '.json') $this->fail('PROVENANCE_SNAPSHOT_PATH_INVALID:' . $race);
            $payload = $row;
            unset($payload['input_snapshot_identity'], $payload['snapshot_stable_key'], $payload['recorded_at']);
            $identity = self::identity($payload);
            if (!hash_equals((string)($row['input_snapshot_identity'] ?? ''), $identity)) $this->fail('PROVENANCE_SNAPSHOT_IDENTITY_INVALID:' . $race);
            $out[$race] = $row + ['__relative_path' => $relative, '__implementation' => $implementation, '__contract' => $contract];
            $this->snapshotByReference[$relative] = $out[$race];
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @return array<string,array<string,mixed>> */
    private function pairs(array $inventory, array $expectedCanonical, array $snapshots, array $implementation, array $contract): array
    {
        $rows = $this->rows('comparative/pairs/', false); $out = [];
        foreach ($rows as $relative => $row) {
            $race = (string)($row['race_code'] ?? ''); $use = (string)($row['use_key'] ?? ''); $key = $race . "\0" . $use;
            $snapshot = $snapshots[$race] ?? null; $candidate = $inventory['candidate_by_race'][$race] ?? null; $eligibility = $inventory['eligibility_by_race'][$race] ?? null;
            if (($row['window_id'] ?? '') !== $this->windowId || $race === '' || $use === '' || isset($out[$key]) || !is_array($snapshot) || !is_array($candidate) || !is_array($eligibility)) $this->fail('PROVENANCE_PAIR_WINDOW_OR_KEY_INVALID');
            if (($row['measurement_implementation_identity'] ?? '') !== $implementation['implementation_identity']
                || ($row['comparative_scope_identity'] ?? '') !== $contract['comparative_scope_identity']
                || ($row['comparative_policy_identity'] ?? '') !== $contract['comparative_policy_identity']) $this->fail('PROVENANCE_PAIR_IDENTITY_MISMATCH:' . $race);
            if (empty($row['prospective']) || ($eligibility['eligibility_status'] ?? '') !== 'ELIGIBLE_OBSERVED'
                || !$this->before((string)($eligibility['first_eligible_observed_at'] ?? ''), (string)($candidate['cutoff_timestamp'] ?? ''))) $this->fail('PROVENANCE_PAIR_PROSPECTIVE_INVALID:' . $race);
            $snapshotReference = (string)($row['snapshot_reference'] ?? '');
            if (($snapshot['__relative_path'] ?? '') !== $snapshotReference || ($snapshot['input_snapshot_identity'] ?? '') !== ($row['input_snapshot_identity'] ?? '')
                || ($row['cutoff_at'] ?? '') !== ($snapshot['cutoff_at'] ?? '')) $this->fail('PROVENANCE_PAIR_SNAPSHOT_REFERENCE_INVALID:' . $race);
            $stable = 'COMPARATIVE_PAIR|' . $this->windowId . '|' . $race . '|' . (string)($row['venue'] ?? '') . '|' . (int)($row['course'] ?? 0) . '|' . (string)($row['target'] ?? '');
            if (($row['stable_unique_key'] ?? '') !== $stable || basename($relative) !== hash('sha256', $stable) . '.json') $this->fail('PROVENANCE_PAIR_PATH_INVALID:' . $race);
            $decision = $row; unset($decision['recorded_at'], $decision['record_hash'], $decision['decision_hash']);
            if (!hash_equals((string)($row['decision_hash'] ?? ''), hash('sha256', self::canonicalJson($decision)))) $this->fail('PROVENANCE_PAIR_DECISION_HASH_INVALID:' . $race);
            $record = $row; unset($record['record_hash']);
            if (!hash_equals((string)($row['record_hash'] ?? ''), hash('sha256', self::canonicalJson($record)))) $this->fail('PROVENANCE_PAIR_RECORD_HASH_INVALID:' . $race);
            $expectedKey = $race . "\0" . (int)($row['course'] ?? 0) . "\0" . (string)($row['target'] ?? '');
            if (!isset($expectedCanonical['expected_by_use'][$expectedKey], $expectedCanonical['canonical_by_use'][$expectedKey])) $this->fail('PROVENANCE_PAIR_EXPECTED_CANONICAL_MISSING:' . $race . ':' . $use);
            $out[$key] = $row + ['__relative_path' => $relative];
            $this->pairByRaceUse[$key] = $out[$key];
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @return array<string,array<string,mixed>> */
    private function completions(array $snapshots, array $pairs, array $contract): array
    {
        $rows = $this->rows('comparative/captures/', false); $out = [];
        foreach ($rows as $relative => $row) {
            $race = (string)($row['race_code'] ?? ''); $snapshot = $snapshots[$race] ?? null;
            if (($row['window_id'] ?? '') !== $this->windowId || $race === '' || isset($out[$race]) || !is_array($snapshot)) $this->fail('PROVENANCE_COMPLETION_WINDOW_OR_KEY_INVALID');
            if (($row['snapshot_reference'] ?? '') !== ($snapshot['__relative_path'] ?? '') || ($row['input_snapshot_identity'] ?? '') !== ($snapshot['input_snapshot_identity'] ?? '')
                || ($row['comparative_scope_identity'] ?? '') !== $contract['comparative_scope_identity'] || ($row['comparative_policy_identity'] ?? '') !== $contract['comparative_policy_identity']) $this->fail('PROVENANCE_COMPLETION_SNAPSHOT_OR_CONTRACT_INVALID:' . $race);
            $stable = 'COMPARATIVE_CAPTURE|' . $this->windowId . '|' . $race;
            if (basename($relative) !== hash('sha256', $stable) . '.json') $this->fail('PROVENANCE_COMPLETION_PATH_INVALID:' . $race);
            $decision = $row; unset($decision['recorded_at'], $decision['completion_hash']);
            if (!hash_equals((string)($row['completion_hash'] ?? ''), 'sha256:' . hash('sha256', self::canonicalJson($decision)))) $this->fail('PROVENANCE_COMPLETION_HASH_INVALID:' . $race);
            $required = $row['required_use_keys'] ?? null; $hashes = $row['pair_record_hashes'] ?? null;
            if (!is_array($required) || !is_array($hashes)) $this->fail('PROVENANCE_COMPLETION_PAIRSET_INVALID:' . $race);
            sort($required, SORT_STRING); $actual = [];
            foreach ($pairs as $key => $pair) if (str_starts_with($key, $race . "\0")) $actual[(string)$pair['use_key']] = (string)$pair['record_hash'];
            ksort($actual, SORT_STRING); ksort($hashes, SORT_STRING);
            if ($required !== array_keys($actual) || $hashes !== $actual) $this->fail('PROVENANCE_COMPLETION_PAIRSET_MISMATCH:' . $race);
            $out[$race] = $row + ['__relative_path' => $relative];
        }
        ksort($out, SORT_STRING);
        if (array_keys($out) !== array_keys($snapshots)) $this->fail('PROVENANCE_SNAPSHOT_COMPLETION_KEYSET_MISMATCH');
        return $out;
    }

    /** @return array<string,array<string,mixed>> */
    private function byUse(array $rows, array $implementation, array $contract, string $kind): array
    {
        $out = [];
        foreach ($rows as $relative => $row) {
            foreach (['window_id' => $this->windowId, 'measurement_implementation_identity' => $implementation['implementation_identity'], 'comparative_scope_identity' => $contract['comparative_scope_identity'], 'comparative_policy_identity' => $contract['comparative_policy_identity'], 'input_snapshot_spec_identity' => $contract['input_snapshot_spec_identity'], 'pair_record_schema_identity' => $contract['pair_record_schema_identity']] as $field => $value) {
                if (($row[$field] ?? '') !== $value) $this->fail('PROVENANCE_' . $kind . '_IDENTITY_MISMATCH:' . $field);
            }
            foreach (['measurement_manifest_schema_version' => $implementation['manifest_schema_version'], 'measurement_manifest_sha256' => $implementation['manifest_sha256'], 'measurement_manifest_ref' => $implementation['manifest_ref'], 'measurement_source_freeze_sha256' => $implementation['source_freeze_sha256'], 'measurement_source_freeze_ref' => $implementation['source_freeze_ref']] as $field => $value) {
                if (($row[$field] ?? '') !== $value) $this->fail('PROVENANCE_' . $kind . '_REFERENCE_MISMATCH:' . $field);
            }
            $key = (string)($row['race_code'] ?? '') . "\0" . (int)($row['course'] ?? 0) . "\0" . (string)($row['target'] ?? '');
            if ($key === "\0" . "\0" || isset($out[$key])) $this->fail('PROVENANCE_' . $kind . '_USE_KEY_INVALID');
            $out[$key] = $row + ['__relative_path' => $relative];
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @return array<string,array<string,mixed>> */
    private function rows(string $prefix, bool $required): array
    {
        $rows = [];
        foreach ($this->files as $relative => $_hash) {
            if (!str_starts_with($relative, $prefix) || !str_ends_with($relative, '.json')) continue;
            $rows[$relative] = $this->required($relative);
        }
        if ($required && $rows === []) $this->fail('PROVENANCE_REQUIRED_EVIDENCE_MISSING:' . $prefix);
        ksort($rows, SORT_STRING);
        return $rows;
    }

    /** @return array<string,array<string,mixed>> */
    private function byRace(array $rows, string $error): array
    {
        $out = [];
        foreach ($rows as $row) {
            $race = (string)($row['race_code'] ?? '');
            if ($race === '' || isset($out[$race])) $this->fail($error);
            $out[$race] = $row;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @return array<string,mixed> */
    private function required(string $relative): array
    {
        $this->requireIncluded($relative);
        try { $row = json_decode((string)file_get_contents($this->absolute($relative)), true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable) { $this->fail('PROVENANCE_JSON_INVALID:' . $relative); }
        if (!is_array($row)) $this->fail('PROVENANCE_JSON_INVALID:' . $relative);
        return $row;
    }

    private function requireIncluded(string $relative): void
    {
        if (!isset($this->files[$relative])) $this->fail('PROVENANCE_EVIDENCE_NOT_IN_AGGREGATE:' . $relative);
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function reference(string $relative, array $extra = []): array
    {
        $this->requireIncluded($relative);
        return ['relative_path' => $relative, 'sha256' => 'sha256:' . $this->files[$relative]] + $extra;
    }

    private function absolute(string $relative): string
    {
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..') || str_contains($relative, "\0")) $this->fail('PROVENANCE_REFERENCE_PATH_INVALID');
        return rtrim($this->windowRoot, DIRECTORY_SEPARATOR) . '/' . $relative;
    }

    private function after(string $left, string $right): bool
    {
        try { return $left !== '' && $right !== '' && new DateTimeImmutable($left) > new DateTimeImmutable($right); }
        catch (Throwable) { return false; }
    }

    private function before(string $left, string $right): bool
    {
        try { return $left !== '' && $right !== '' && new DateTimeImmutable($left) < new DateTimeImmutable($right); }
        catch (Throwable) { return false; }
    }

    private static function identity(array $payload): string { return 'sha256:' . hash('sha256', self::canonicalJson($payload)); }
    private static function canonicalJson(mixed $value): string { return json_encode(self::canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
    private static function canonicalize(mixed $value): mixed
    {
        if (is_float($value)) {
            if (!is_finite($value)) throw new InvalidArgumentException('provenance_non_finite_number');
            $text = sprintf('%.17g', $value); return ['__scalar_type' => 'float', 'value' => str_replace('E', 'e', $text)];
        }
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map(static fn(mixed $item): mixed => self::canonicalize($item), $value);
        ksort($value, SORT_STRING); foreach ($value as $key => $item) $value[(string)$key] = self::canonicalize($item);
        return $value;
    }

    private function fail(string $reason): never { throw new RuntimeException($reason); }
}
