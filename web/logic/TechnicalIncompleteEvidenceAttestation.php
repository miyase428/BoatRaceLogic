<?php

declare(strict_types=1);

/**
 * Immutable evidence identity for a narrowly bounded technical-incomplete
 * Window.  This contract does not claim that the Window is complete.  It
 * proves that Expected and Candidate are complete and identical, that the
 * only incomplete set is an explicitly declared Eligibility-resolution set,
 * and that no unexpected Eligibility key exists.
 */
final class TechnicalIncompleteEvidenceAttestation
{
    public const SCHEMA_VERSION = 'technical-incomplete-evidence-attestation/v1';
    public const TERMINAL_REASON = 'FORMAL_EVIDENCE_INCOMPLETE_PRODUCER_DEFECT';
    public const COMPLETENESS_STATUS = 'INCOMPLETE';
    public const INCIDENT_TYPE = 'SCHEDULER_FAILURE_MISSED_OPPORTUNITY';
    public const SEVERITY = 'MAJOR';
    public const REPAIR_RECONSTRUCTIBILITY = 'PARTIAL';
    public const RECOVERY_STRATEGY = 'FREEZE_CURRENT_AND_START_CLEAN_WINDOW';

    public function __construct(private readonly string $repoRoot)
    {
    }

    public function path(string $windowId): string
    {
        return $this->repoRoot . '/var/strict_forward_live_readiness/lifecycle/technical_incomplete_evidence_attestations/'
            . rawurlencode($windowId) . '.json';
    }

    /** @return array<string,mixed> */
    public function read(string $windowId): array
    {
        $path = $this->path($windowId);
        if (!is_file($path)) return [];
        try { $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable) { return []; }
        return is_array($row) ? $row : [];
    }

    /**
     * Read-only builder.  It never writes below the Window or lifecycle tree.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function build(string $windowRoot, array $input): array
    {
        $required = [
            'window_id', 'epoch_id', 'attested_at', 'implementation_identity',
            'window_start_date', 'capture_through_date', 'boundary_contract_path',
            'boundary_contract_sha256', 'incident_id', 'incident_hash',
        ];
        foreach ($required as $field) {
            if (!is_string($input[$field] ?? null) || trim((string)$input[$field]) === '') {
                throw new RuntimeException('ATTESTATION_FIELD_MISSING:' . $field);
            }
        }
        foreach (['implementation_identity','boundary_contract_sha256','incident_hash'] as $field) {
            if (!self::validHash((string)$input[$field])) throw new RuntimeException('ATTESTATION_HASH_INVALID:' . $field);
        }
        $windowId = (string)$input['window_id'];
        $start = (string)$input['window_start_date'];
        $through = (string)$input['capture_through_date'];
        if (preg_match('/^[A-Za-z0-9._-]+$/', $windowId) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $through) !== 1
            || $start > $through) {
            throw new RuntimeException('ATTESTATION_INPUT_INVALID');
        }
        if (($input['incident_type'] ?? '') !== self::INCIDENT_TYPE
            || ($input['severity'] ?? '') !== self::SEVERITY
            || ($input['repair_reconstructibility'] ?? '') !== self::REPAIR_RECONSTRUCTIBILITY
            || ($input['recovery_strategy'] ?? '') !== self::RECOVERY_STRATEGY) {
            throw new RuntimeException('ATTESTATION_ROOT_CAUSE_UNVERIFIED');
        }
        if (!empty($input['historical_repair_performed']) || !empty($input['reconstruction_marker_detected'])) {
            throw new RuntimeException('ATTESTATION_HISTORICAL_REPAIR_DETECTED');
        }
        if (!empty($input['manual_contamination_detected']) || !empty($input['fixture_contamination_detected'])) {
            throw new RuntimeException('ATTESTATION_MANUAL_OR_FIXTURE_CONTAMINATION');
        }

        $requiredDates = self::normalizeDates($input['required_inventory_dates'] ?? null);
        if ($requiredDates !== self::inclusiveDates($start, $through)) {
            throw new RuntimeException('ATTESTATION_REQUIRED_DATE_RANGE_INVALID');
        }
        $boundaryRelative = str_replace('\\', '/', (string)$input['boundary_contract_path']);
        if ($boundaryRelative === '' || str_starts_with($boundaryRelative, '/') || str_contains($boundaryRelative, '..')) {
            throw new RuntimeException('ATTESTATION_BOUNDARY_CONTRACT_PATH_INVALID');
        }
        $boundaryPath = rtrim($this->repoRoot, DIRECTORY_SEPARATOR) . '/' . $boundaryRelative;
        if (!is_file($boundaryPath)
            || !hash_equals((string)$input['boundary_contract_sha256'], self::fileHash($boundaryPath))) {
            throw new RuntimeException('ATTESTATION_BOUNDARY_CONTRACT_IDENTITY_MISMATCH');
        }
        $boundaryContract = self::readJson($boundaryPath, 'ATTESTATION_BOUNDARY_CONTRACT_INVALID');
        if (self::normalizeDates($boundaryContract['required_dates'] ?? null) !== $requiredDates) {
            throw new RuntimeException('ATTESTATION_BOUNDARY_CONTRACT_DATES_MISMATCH');
        }
        $root = rtrim($windowRoot, DIRECTORY_SEPARATOR);
        if (!is_dir($root)) throw new RuntimeException('ATTESTATION_WINDOW_ROOT_MISSING');
        foreach (['validation_window_manifest.json','activation_record.json','declared_implementation_identity.json'] as $relative) {
            if (!is_file($root . '/' . $relative)) throw new RuntimeException('ATTESTATION_REQUIRED_WINDOW_FILE_MISSING:' . $relative);
        }
        $manifest = self::readJson($root . '/validation_window_manifest.json', 'ATTESTATION_MANIFEST_INVALID');
        $activation = self::readJson($root . '/activation_record.json', 'ATTESTATION_ACTIVATION_INVALID');
        $declaration = self::readJson($root . '/declared_implementation_identity.json', 'ATTESTATION_DECLARATION_INVALID');
        if (($manifest['validation_window_id'] ?? '') !== $windowId
            || ($manifest['activation_state'] ?? '') !== 'ACTIVE'
            || ($manifest['status'] ?? '') !== 'ACTIVE'
            || ($activation['window_id'] ?? '') !== $windowId
            || ($declaration['window_id'] ?? '') !== $windowId
            || !hash_equals((string)$input['implementation_identity'], (string)($activation['implementation_identity'] ?? ''))
            || !hash_equals((string)$input['implementation_identity'], (string)($declaration['implementation_identity'] ?? ''))) {
            throw new RuntimeException('ATTESTATION_WINDOW_OR_IMPLEMENTATION_IDENTITY_MISMATCH');
        }

        $expected = [];
        $inventorySources = [];
        foreach ($requiredDates as $date) {
            $compact = str_replace('-', '', $date);
            $path = $root . '/opportunity/expected_inventory/' . $compact . '.json';
            if (!is_file($path)) throw new RuntimeException('ATTESTATION_INVENTORY_INCOMPLETE:' . $date);
            $row = self::readJson($path, 'ATTESTATION_INVENTORY_INVALID:' . $date);
            if (($row['target_date'] ?? '') !== $date || ($row['window_id'] ?? '') !== $windowId
                || !is_array($row['expected_entries'] ?? null)) {
                throw new RuntimeException('ATTESTATION_INVENTORY_BINDING_INVALID:' . $date);
            }
            $relative = 'opportunity/expected_inventory/' . $compact . '.json';
            $inventorySources[] = ['target_date' => $date, 'relative_path' => $relative, 'sha256' => self::fileHash($path)];
            foreach ($row['expected_entries'] as $entry) {
                if (!is_array($entry)) throw new RuntimeException('ATTESTATION_EXPECTED_ENTRY_INVALID');
                $key = trim((string)($entry['stable_candidate_key'] ?? ''));
                $race = strtoupper(trim((string)($entry['race_code'] ?? '')));
                if ($key === '' || !self::validRaceCode($race) || isset($expected[$key])) {
                    throw new RuntimeException('ATTESTATION_EXPECTED_KEYSET_INVALID');
                }
                $expected[$key] = ['race_code' => $race, 'target_date' => $date];
            }
        }
        self::assertNoInventoryWithinRangeOutsideContract($root, $requiredDates, $start, $through);

        [$candidate, $candidateSources] = self::resolutionRows($root, 'opportunity/candidates', $requiredDates, 'CANDIDATE');
        [$eligibility, $eligibilitySources] = self::resolutionRows($root, 'opportunity/eligibility', $requiredDates, 'ELIGIBILITY');
        $expectedKeys = array_keys($expected); $candidateKeys = array_keys($candidate); $eligibilityKeys = array_keys($eligibility);
        sort($expectedKeys, SORT_STRING); sort($candidateKeys, SORT_STRING); sort($eligibilityKeys, SORT_STRING);
        if ($expectedKeys !== $candidateKeys) throw new RuntimeException('ATTESTATION_EXPECTED_CANDIDATE_MISMATCH');

        $expectedMissing = array_values(array_diff($expectedKeys, $eligibilityKeys));
        $candidateMissing = array_values(array_diff($candidateKeys, $eligibilityKeys));
        $eligibilityNotExpected = array_values(array_diff($eligibilityKeys, $expectedKeys));
        $eligibilityNotCandidate = array_values(array_diff($eligibilityKeys, $candidateKeys));
        sort($expectedMissing, SORT_STRING); sort($candidateMissing, SORT_STRING);
        sort($eligibilityNotExpected, SORT_STRING); sort($eligibilityNotCandidate, SORT_STRING);
        if ($eligibilityNotExpected !== [] || $eligibilityNotCandidate !== []) {
            throw new RuntimeException('ATTESTATION_UNEXPECTED_ELIGIBILITY_EXTRA');
        }
        $declaredMissing = self::normalizeStrings($input['missing_resolution_keys'] ?? null);
        if ($expectedMissing !== $candidateMissing || $expectedMissing !== $declaredMissing) {
            throw new RuntimeException('ATTESTATION_MISSING_SET_MISMATCH');
        }
        $missingRaces = [];
        foreach ($expectedMissing as $key) $missingRaces[] = $expected[$key]['race_code'];
        sort($missingRaces, SORT_STRING);
        $declaredRaces = self::normalizeRaceCodes($input['missing_resolution_race_codes'] ?? null);
        if ($missingRaces !== $declaredRaces || $missingRaces === []) {
            throw new RuntimeException('ATTESTATION_MISSING_RACE_SET_MISMATCH');
        }

        $physicalEntries = self::allFileEntries($root);
        [$formalEntries, $rawEntries, $authoritativeProvenance, $excludedAuxiliary] = self::classifyEvidence($root, $physicalEntries);
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'window_id' => $windowId,
            'epoch_id' => (string)$input['epoch_id'],
            'attested_at' => (string)$input['attested_at'],
            'implementation_identity' => (string)$input['implementation_identity'],
            'window_start_date' => $start,
            'capture_through_date' => $through,
            'required_inventory_dates' => $requiredDates,
            'boundary_contract_path' => $boundaryRelative,
            'boundary_contract_sha256' => (string)$input['boundary_contract_sha256'],
            'terminal_reason' => self::TERMINAL_REASON,
            'completeness_status' => self::COMPLETENESS_STATUS,
            'inventory_identity' => [
                'expected' => self::identity($expectedKeys, $inventorySources),
                'candidate' => self::identity($candidateKeys, $candidateSources),
                'eligibility_resolution' => self::identity($eligibilityKeys, $eligibilitySources),
            ],
            'missing_resolution_count' => count($expectedMissing),
            'missing_resolution_keys' => $expectedMissing,
            'missing_resolution_keyset_sha256' => self::keysetHash($expectedMissing),
            'missing_resolution_race_codes' => $missingRaces,
            'expected_minus_eligibility_keys' => $expectedMissing,
            'candidate_minus_eligibility_keys' => $candidateMissing,
            'eligibility_not_expected_count' => 0,
            'eligibility_not_candidate_count' => 0,
            'unexpected_extra_keys' => [],
            'root_cause_binding' => [
                'incident_id' => (string)$input['incident_id'],
                'incident_hash' => (string)$input['incident_hash'],
                'incident_type' => self::INCIDENT_TYPE,
                'severity' => self::SEVERITY,
                'repair_reconstructibility' => self::REPAIR_RECONSTRUCTIBILITY,
                'historical_repair_performed' => false,
                'recovery_strategy' => self::RECOVERY_STRATEGY,
            ],
            'evidence_identity' => [
                'formal_evidence' => self::entriesIdentity($formalEntries),
                'raw_evidence' => self::entriesIdentity($rawEntries),
                'authoritative_provenance' => self::entriesIdentity($authoritativeProvenance),
                'excluded_auxiliary_provenance' => self::entriesIdentity($excludedAuxiliary),
                'physical_tree' => self::entriesIdentity($physicalEntries),
            ],
            'contamination_guard' => [
                'historical_repair_performed' => false,
                'reconstruction_marker_detected' => false,
                'manual_contamination_detected' => false,
                'fixture_contamination_detected' => false,
            ],
            'generated_for_verification_only' => true,
        ];
        $payload['attestation_sha256'] = 'sha256:' . hash('sha256', self::canonicalJson($payload));
        return $payload;
    }

    /** @param array<string,mixed> $attestation */
    public function valid(array $attestation, ?string $windowId = null): bool
    {
        if (($attestation['schema_version'] ?? '') !== self::SCHEMA_VERSION
            || ($attestation['terminal_reason'] ?? '') !== self::TERMINAL_REASON
            || ($attestation['completeness_status'] ?? '') !== self::COMPLETENESS_STATUS
            || !self::validHash((string)($attestation['attestation_sha256'] ?? ''))
            || ($windowId !== null && ($attestation['window_id'] ?? '') !== $windowId)
            || (int)($attestation['missing_resolution_count'] ?? -1) <= 0
            || !is_array($attestation['missing_resolution_keys'] ?? null)
            || !is_array($attestation['missing_resolution_race_codes'] ?? null)
            || (int)$attestation['missing_resolution_count'] !== count($attestation['missing_resolution_keys'])
            || !hash_equals((string)($attestation['missing_resolution_keyset_sha256'] ?? ''), self::keysetHash($attestation['missing_resolution_keys']))
            || ($attestation['eligibility_not_expected_count'] ?? -1) !== 0
            || ($attestation['eligibility_not_candidate_count'] ?? -1) !== 0
            || ($attestation['unexpected_extra_keys'] ?? null) !== []
            || (($attestation['root_cause_binding']['incident_type'] ?? '') !== self::INCIDENT_TYPE)
            || (($attestation['root_cause_binding']['severity'] ?? '') !== self::SEVERITY)
            || (($attestation['root_cause_binding']['repair_reconstructibility'] ?? '') !== self::REPAIR_RECONSTRUCTIBILITY)
            || (($attestation['root_cause_binding']['recovery_strategy'] ?? '') !== self::RECOVERY_STRATEGY)
            || !empty($attestation['root_cause_binding']['historical_repair_performed'])) return false;
        $copy = $attestation; $hash = (string)$copy['attestation_sha256']; unset($copy['attestation_sha256']);
        return hash_equals($hash, 'sha256:' . hash('sha256', self::canonicalJson($copy)));
    }

    /**
     * First-write, immutable persistence.  Same payload is an idempotent no-op;
     * different payload for the same Window fails closed.
     *
     * @param array<string,mixed> $attestation
     * @return array{accepted:bool,status:string,reason:string,attestation:array<string,mixed>}
     */
    public function persistCreateOnly(array $attestation): array
    {
        $windowId = (string)($attestation['window_id'] ?? '');
        if (!$this->valid($attestation, $windowId) || $windowId === '') {
            return ['accepted' => false, 'status' => 'BLOCKED', 'reason' => 'ATTESTATION_INVALID', 'attestation' => []];
        }
        $path = $this->path($windowId);
        if (is_file($path)) {
            $existing = $this->read($windowId);
            if ($this->valid($existing, $windowId)
                && hash_equals((string)$existing['attestation_sha256'], (string)$attestation['attestation_sha256'])) {
                return ['accepted' => true, 'status' => 'ATTESTATION_ALREADY_EXISTS', 'reason' => '', 'attestation' => $existing];
            }
            return ['accepted' => false, 'status' => 'BLOCKED', 'reason' => 'ATTESTATION_CONFLICT', 'attestation' => $existing];
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['accepted' => false, 'status' => 'BLOCKED', 'reason' => 'ATTESTATION_CREATE_FAILED', 'attestation' => []];
        }
        $handle = @fopen($path, 'x');
        if (!is_resource($handle)) return ['accepted' => false, 'status' => 'BLOCKED', 'reason' => 'ATTESTATION_CREATE_FAILED', 'attestation' => []];
        $ok = @fwrite($handle, self::canonicalJson($attestation) . "\n") !== false; @fclose($handle);
        if (!$ok) return ['accepted' => false, 'status' => 'BLOCKED', 'reason' => 'ATTESTATION_CREATE_FAILED', 'attestation' => []];
        return ['accepted' => true, 'status' => 'ATTESTATION_CREATED', 'reason' => '', 'attestation' => $attestation];
    }

    /** @return array{0:array<string,array<string,string>>,1:list<array{relative_path:string,sha256:string}>} */
    private static function resolutionRows(string $root, string $relativeDirectory, array $dates, string $label): array
    {
        $rows = []; $sources = [];
        foreach ($dates as $date) {
            $directory = $root . '/' . $relativeDirectory . '/' . str_replace('-', '', $date);
            if (!is_dir($directory)) continue;
            foreach (glob($directory . '/*.json') ?: [] as $path) {
                $row = self::readJson($path, 'ATTESTATION_' . $label . '_INVALID');
                $key = trim((string)($row['stable_candidate_key'] ?? ''));
                $race = strtoupper(trim((string)($row['race_code'] ?? '')));
                if ($key === '' || !self::validRaceCode($race) || isset($rows[$key]) || ($row['target_date'] ?? '') !== $date) {
                    throw new RuntimeException('ATTESTATION_' . $label . '_KEYSET_INVALID');
                }
                $rows[$key] = ['race_code' => $race, 'target_date' => $date];
                $relative = str_replace('\\', '/', ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR));
                $sources[] = ['relative_path' => $relative, 'sha256' => self::fileHash($path)];
            }
        }
        ksort($rows, SORT_STRING); usort($sources, static fn(array $a, array $b): int => $a['relative_path'] <=> $b['relative_path']);
        return [$rows, $sources];
    }

    /** @param list<array{relative_path:string,sha256:string}> $physical @return array{0:array,1:array,2:array,3:array} */
    private static function classifyEvidence(string $root, array $physical): array
    {
        $formal = []; $raw = []; $provenanceAll = []; $references = [];
        foreach ($physical as $entry) {
            $relative = $entry['relative_path'];
            if (str_starts_with($relative, 'provenance/')) { $provenanceAll[$relative] = $entry; continue; }
            $formal[] = $entry;
            if (str_starts_with($relative, 'ledger/records/')
                || str_starts_with($relative, 'input_snapshots/')
                || str_starts_with($relative, 'comparative/captures/')) $raw[] = $entry;
            if (!str_ends_with($relative, '.json')) continue;
            try { $row = self::readJson($root . '/' . $relative, ''); } catch (Throwable) { continue; }
            self::collectProvenanceReferences($row, $references);
        }
        $authoritative = []; $excluded = [];
        foreach ($provenanceAll as $relative => $entry) {
            if (isset($references[$relative])) $authoritative[] = $entry; else $excluded[] = $entry;
        }
        return [$formal, $raw, $authoritative, $excluded];
    }

    /** @param array<string,bool> $references */
    private static function collectProvenanceReferences(mixed $value, array &$references): void
    {
        if (!is_array($value)) return;
        foreach ($value as $key => $child) {
            if (is_string($child) && in_array((string)$key, ['manifest_ref','source_freeze_ref','measurement_manifest_ref','measurement_source_freeze_ref'], true)) {
                $references['provenance/' . ltrim($child, '/')] = true;
            } elseif (is_array($child)) self::collectProvenanceReferences($child, $references);
        }
    }

    /** @return list<array{relative_path:string,sha256:string}> */
    private static function allFileEntries(string $root): array
    {
        $entries = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) continue;
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($file->getPathname(), strlen($root)), DIRECTORY_SEPARATOR));
            $entries[] = ['relative_path' => $relative, 'sha256' => self::fileHash($file->getPathname())];
        }
        usort($entries, static fn(array $a, array $b): int => $a['relative_path'] <=> $b['relative_path']);
        return $entries;
    }

    /** @param list<string> $keys @param list<array<string,string>> $sources */
    private static function identity(array $keys, array $sources): array
    {
        return ['count' => count($keys), 'stable_keys' => $keys, 'keyset_sha256' => self::keysetHash($keys),
            'source_count' => count($sources), 'source_files' => $sources, 'source_union_sha256' => self::entriesIdentity($sources)['framed_sha256']];
    }

    /** @param list<array{relative_path:string,sha256:string}> $entries */
    private static function entriesIdentity(array $entries): array
    {
        usort($entries, static fn(array $a, array $b): int => $a['relative_path'] <=> $b['relative_path']);
        $framed = '';
        foreach ($entries as $entry) $framed .= $entry['relative_path'] . "\0" . $entry['sha256'] . "\n";
        return ['count' => count($entries), 'framed_sha256' => 'sha256:' . hash('sha256', $framed), 'entries' => $entries];
    }

    /** @param list<string> $keys */
    public static function keysetHash(array $keys): string
    {
        sort($keys, SORT_STRING); return 'sha256:' . hash('sha256', implode("\n", $keys) . ($keys === [] ? '' : "\n"));
    }

    private static function assertNoInventoryWithinRangeOutsideContract(string $root, array $dates, string $start, string $through): void
    {
        $allowed = array_fill_keys($dates, true);
        foreach (glob($root . '/opportunity/expected_inventory/*.json') ?: [] as $path) {
            $row = self::readJson($path, 'ATTESTATION_INVENTORY_INVALID');
            $date = (string)($row['target_date'] ?? '');
            if ($date >= $start && $date <= $through && !isset($allowed[$date])) {
                throw new RuntimeException('ATTESTATION_UNDECLARED_INVENTORY_DATE:' . $date);
            }
        }
    }

    /** @return list<string> */
    private static function inclusiveDates(string $start, string $through): array
    {
        $from = new DateTimeImmutable($start, new DateTimeZone('Asia/Tokyo'));
        $to = new DateTimeImmutable($through, new DateTimeZone('Asia/Tokyo'));
        $dates = [];
        for ($date = $from; $date <= $to; $date = $date->modify('+1 day')) $dates[] = $date->format('Y-m-d');
        return $dates;
    }

    /** @return list<string> */
    private static function normalizeDates(mixed $values): array
    {
        if (!is_array($values) || $values === []) throw new RuntimeException('ATTESTATION_REQUIRED_DATES_INVALID');
        $out = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || isset($out[$value])) throw new RuntimeException('ATTESTATION_REQUIRED_DATES_INVALID');
            $out[$value] = true;
        }
        $dates = array_keys($out); sort($dates, SORT_STRING); return $dates;
    }

    /** @return list<string> */
    private static function normalizeStrings(mixed $values): array
    {
        if (!is_array($values)) throw new RuntimeException('ATTESTATION_MISSING_SET_INVALID');
        $out = [];
        foreach ($values as $value) { $value = trim((string)$value); if ($value === '' || isset($out[$value])) throw new RuntimeException('ATTESTATION_MISSING_SET_INVALID'); $out[$value] = true; }
        $values = array_keys($out); sort($values, SORT_STRING); return $values;
    }

    /** @return list<string> */
    private static function normalizeRaceCodes(mixed $values): array
    {
        $values = self::normalizeStrings($values); foreach ($values as $value) if (!self::validRaceCode($value)) throw new RuntimeException('ATTESTATION_MISSING_RACE_SET_INVALID'); return $values;
    }

    private static function validRaceCode(string $value): bool { return preg_match('/^\d{8}[A-Z0-9]{3}\d{2}$/', $value) === 1; }
    private static function validHash(string $value): bool { return preg_match('/^sha256:[a-f0-9]{64}$/', $value) === 1; }
    private static function fileHash(string $path): string { $hash = hash_file('sha256', $path); if (!is_string($hash)) throw new RuntimeException('ATTESTATION_HASH_FAILED'); return 'sha256:' . $hash; }
    /** @return array<string,mixed> */
    private static function readJson(string $path, string $error): array { try { $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { throw new RuntimeException($error === '' ? 'ATTESTATION_JSON_INVALID' : $error); } if (!is_array($row)) throw new RuntimeException($error === '' ? 'ATTESTATION_JSON_INVALID' : $error); return $row; }
    private static function canonicalJson(mixed $value): string { return (string)json_encode(self::canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR); }
    private static function canonicalize(mixed $value): mixed { if (!is_array($value)) return $value; if (array_is_list($value)) return array_map([self::class, 'canonicalize'], $value); ksort($value, SORT_STRING); foreach ($value as $key => $child) $value[$key] = self::canonicalize($child); return $value; }
}
