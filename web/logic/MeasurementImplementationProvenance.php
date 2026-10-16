<?php

declare(strict_types=1);

/**
 * Immutable, outcome-blind provenance for Strict Forward measurement code.
 *
 * The implementation identity is derived solely from a canonical manifest of
 * repo-relative measurement-critical paths and their raw-byte SHA256 values.
 * The accompanying source-freeze artifact is intentionally separate from the
 * identity: it makes dirty/untracked source bytes recoverable without adding
 * volatile archive metadata to the identity contract.
 */
final class MeasurementImplementationProvenance
{
    public const MANIFEST_SCHEMA_VERSION = 'measurement-implementation-manifest/v1';
    public const FREEZE_SCHEMA_VERSION = 'measurement-source-freeze/v1';

    /** @var list<string> */
    private const CRITICAL_PATHS = [
        'analysis/analyze_all_venue_lane_signals.py',
        'analysis/analyze_all_venue_secondary_signals.py',
        'analysis/analyze_tamagawa_boaters_hypothesis.py',
        'analysis/analyze_tamagawa_lane4_exacta_structure.py',
        'analysis/analyze_tamagawa_lane4_makurizashi_vulnerability.py',
        'analysis/analyze_tamagawa_lane4_strong_condition_second_eval.py',
        'analysis/audit_tamagawa_course_signals_zero_base_ml.py',
        'analysis/capture_all_race_forward.php',
        // The permanent collector supplies the pre-cutoff exhibition input
        // used by the formal capture path.  Its candidate/time guards are
        // therefore measurement-critical, even though it never writes
        // formal evidence itself.
        'analysis/collect_strict_forward_exhibition.php',
        'analysis/arm_operational_live_readiness.php',
        'analysis/declare_window_implementation_identity.php',
        'analysis/finalize_window_continuability_verification.php',
        'analysis/optimize_all_venue_secondary_rules.py',
        'analysis/slit_validate_v2.py',
        'common/db_config.py',
        'config/course_signal_rules.json',
        'forecast/tamagawa_center_signal_live_v1.py',
        'theories/course_correction/classify_slit_pattern.py',
        'theories/course_correction/predict_pattern.py',
        'web/logic/MeasurementImplementationProvenance.php',
        'web/logic/ComparativeContractV1.php',
        'web/logic/StrictForwardComparativeCapture.php',
        'web/logic/StrictForwardInputSnapshotStore.php',
        'web/logic/StrictForwardComparativePairStore.php',
        'web/logic/FormalComparativeReadinessEvaluator.php',
        'web/logic/FormalEvidenceAggregate.php',
        'web/logic/FormalWindowProvenanceResolver.php',
        'web/logic/IdentitySeparatedFormalCloseV3.php',
        'web/logic/FormalMeasurementWindowLifecycle.php',
        'web/logic/FormalMeasurementWindowContext.php',
        'web/logic/GateA1ProspectiveInventory.php',
        'web/logic/OfficialDeadlineLogic.php',
        'web/logic/PredictionForwardSnapshotStore.php',
        'web/logic/StrictForwardOpportunityLedger.php',
        'web/logic/StrictForwardAcceptedRace.php',
        'web/logic/StrictForwardReadinessLedger.php',
        'web/logic/TamagawaCenterSignalLogic.php',
        'web/logic/WindowActivationHold.php',
        'web/logic/WindowDeclaredImplementationIdentity.php',
        'web/tamagawa_lane4_star_api.php',
        'analysis/evaluate_operational_live_readiness.php',
        'analysis/evaluate_formal_measurement_v2.php',
        'analysis/close_formal_measurement_window_v3.php',
        'web/logic/FormalEvaluationRecordStore.php',
        'web/logic/FormalEvaluatorV2.php',
        'web/logic/OfficialEpochPolicyV1.php',
        'web/logic/OfficialEpochIncidentPolicyV1.php',
        'web/logic/OfficialEpochCgApprovalStore.php',
        'web/logic/OfficialEpochStartStore.php',
        'web/logic/OfficialEpochIncidentLifecycleStore.php',
        'web/logic/OfficialEpochRecoveryLifecycleV1.php',
        'web/logic/FormalMeasurementWindowPrepareOnlyV1.php',
        'web/logic/OfficialEpochSameEpochSuccessorValidator.php',
        'web/logic/OfficialEpochLifecycleV1.php',
        'web/logic/OfficialEpochWindowBindingV1.php',
        'web/logic/OfficialEpochWindowBindingStore.php',
        'web/logic/OfficialEpochWindowBindingResolver.php',
        'web/logic/OfficialEpochWindowBindingRuntimeAuthorization.php',
        // Recovery handoff is a lifecycle-critical authorization boundary.
        // Both the service and its only CLI entrypoint must therefore be
        // frozen into every future measurement implementation identity.
        'web/logic/OfficialEpochRecoveryPointerHandoffV1.php',
        'analysis/preflight_official_epoch_v1.php',
        'analysis/preflight_critical_incident_recovery.php',
        'analysis/prepare_official_epoch_recovery_successor.php',
        'analysis/handoff_official_epoch_recovery_pointer.php',
        'config/strict_forward_comparative_scope_v1.json',
        'config/strict_forward_comparative_policy_v1.json',
    ];

    /**
     * @param list<string>|null $criticalPaths Repo-relative paths, fixture-injectable.
     */
    public function __construct(
        private readonly string $repoRoot,
        private readonly string $storageRoot,
        private readonly ?array $criticalPaths = null,
    ) {
    }

    /** @return list<string> */
    public static function criticalPaths(): array
    {
        return self::CRITICAL_PATHS;
    }

    public function repoRoot(): string
    {
        return $this->repoRoot;
    }

    public function storageRoot(): string
    {
        return $this->storageRoot;
    }

    /**
     * Computes the current critical-source identity without creating a
     * manifest or source freeze. Bootstrap closure uses this read-only form
     * because writing a new freeze into a legacy Window would mutate the very
     * evidence being closed.
     */
    public function currentIdentityReadOnly(): string
    {
        return 'sha256:' . hash('sha256', $this->canonicalJson([
            'schema_version' => self::MANIFEST_SCHEMA_VERSION,
            'files' => $this->manifestFiles(),
        ]));
    }

    /**
     * Creates the manifest and source freeze once for the current raw source
     * state. Existing artifacts are accepted only when their bytes validate.
     *
     * @return array{implementation_identity:string,manifest_schema_version:string,manifest_sha256:string,manifest_ref:string,source_freeze_sha256:string,source_freeze_ref:string}
     */
    public function freeze(): array
    {
        $files = $this->manifestFiles();
        $identityPayload = [
            'schema_version' => self::MANIFEST_SCHEMA_VERSION,
            'files' => $files,
        ];
        $identity = 'sha256:' . hash('sha256', $this->canonicalJson($identityPayload));
        $id = substr($identity, strlen('sha256:'));

        $manifest = [
            'schema_version' => self::MANIFEST_SCHEMA_VERSION,
            'implementation_identity' => $identity,
            'serialization_contract' => 'canonical JSON: object keys lexicographically sorted recursively; arrays retain order; JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE; raw source bytes SHA256',
            'files' => $files,
        ];
        $manifestBytes = $this->canonicalJson($manifest) . "\n";
        $manifestSha = 'sha256:' . hash('sha256', $manifestBytes);

        $freezeFiles = [];
        foreach ($files as $file) {
            $raw = @file_get_contents($this->absolutePath($file['path']));
            if (!is_string($raw)) {
                throw new RuntimeException('measurement_source_read_failed:' . $file['path']);
            }
            $freezeFiles[] = [
                'path' => $file['path'],
                'sha256' => $file['sha256'],
                'content_base64' => base64_encode($raw),
            ];
        }
        $freeze = [
            'schema_version' => self::FREEZE_SCHEMA_VERSION,
            'implementation_identity' => $identity,
            'manifest_sha256' => $manifestSha,
            'files' => $freezeFiles,
        ];
        $freezeBytes = $this->canonicalJson($freeze) . "\n";
        $freezeSha = 'sha256:' . hash('sha256', $freezeBytes);

        $manifestRef = 'implementation_manifests/' . $id . '.json';
        $freezeRef = 'implementation_sources/' . $id . '.json';
        $this->writeFirstOrVerify($manifestRef, $manifestBytes, $manifestSha);
        $this->writeFirstOrVerify($freezeRef, $freezeBytes, $freezeSha);

        return [
            'implementation_identity' => $identity,
            'manifest_schema_version' => self::MANIFEST_SCHEMA_VERSION,
            'manifest_sha256' => $manifestSha,
            'manifest_ref' => $manifestRef,
            'source_freeze_sha256' => $freezeSha,
            'source_freeze_ref' => $freezeRef,
        ];
    }

    /**
     * Verifies that identity -> manifest -> freeze -> raw source bytes forms a
     * complete and internally consistent chain. It reads only frozen files.
     *
     * @return array{valid:bool,reason:string,implementation_identity:string,manifest_sha256:string,source_freeze_sha256:string}
     */
    public function verify(string $identity, string $manifestRef, string $freezeRef): array
    {
        $empty = ['valid' => false, 'reason' => 'MEASUREMENT_IMPLEMENTATION_IDENTITY_MISSING', 'implementation_identity' => $identity, 'manifest_sha256' => '', 'source_freeze_sha256' => ''];
        if (!preg_match('/^sha256:[a-f0-9]{64}$/', $identity) || !$this->validReference($manifestRef, 'implementation_manifests/') || !$this->validReference($freezeRef, 'implementation_sources/')) {
            return $empty;
        }
        $manifestBytes = @file_get_contents($this->storagePath($manifestRef));
        $freezeBytes = @file_get_contents($this->storagePath($freezeRef));
        if (!is_string($manifestBytes) || !is_string($freezeBytes)) {
            return ['valid' => false, 'reason' => 'MEASUREMENT_IMPLEMENTATION_FREEZE_MISSING', 'implementation_identity' => $identity, 'manifest_sha256' => '', 'source_freeze_sha256' => ''];
        }
        $manifestSha = 'sha256:' . hash('sha256', $manifestBytes);
        $freezeSha = 'sha256:' . hash('sha256', $freezeBytes);
        try {
            $manifest = json_decode($manifestBytes, true, 512, JSON_THROW_ON_ERROR);
            $freeze = json_decode($freezeBytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ['valid' => false, 'reason' => 'MEASUREMENT_IMPLEMENTATION_FREEZE_INVALID_JSON', 'implementation_identity' => $identity, 'manifest_sha256' => $manifestSha, 'source_freeze_sha256' => $freezeSha];
        }
        if (!is_array($manifest) || !is_array($freeze)
            || ($manifest['schema_version'] ?? '') !== self::MANIFEST_SCHEMA_VERSION
            || ($freeze['schema_version'] ?? '') !== self::FREEZE_SCHEMA_VERSION
            || ($manifest['implementation_identity'] ?? '') !== $identity
            || ($freeze['implementation_identity'] ?? '') !== $identity
            || ($freeze['manifest_sha256'] ?? '') !== $manifestSha) {
            return ['valid' => false, 'reason' => 'MEASUREMENT_IMPLEMENTATION_FREEZE_CHAIN_INVALID', 'implementation_identity' => $identity, 'manifest_sha256' => $manifestSha, 'source_freeze_sha256' => $freezeSha];
        }
        $files = $manifest['files'] ?? null;
        $frozen = $freeze['files'] ?? null;
        if (!is_array($files) || !is_array($frozen) || count($files) === 0 || count($files) !== count($frozen)) {
            return ['valid' => false, 'reason' => 'MEASUREMENT_IMPLEMENTATION_FILESET_INVALID', 'implementation_identity' => $identity, 'manifest_sha256' => $manifestSha, 'source_freeze_sha256' => $freezeSha];
        }
        foreach ($files as $index => $file) {
            $source = $frozen[$index] ?? null;
            if (!is_array($file) || !is_array($source)
                || !is_string($file['path'] ?? null) || !is_string($file['sha256'] ?? null)
                || ($source['path'] ?? '') !== $file['path'] || ($source['sha256'] ?? '') !== $file['sha256']
                || !is_string($source['content_base64'] ?? null)) {
                return ['valid' => false, 'reason' => 'MEASUREMENT_IMPLEMENTATION_FILESET_MISMATCH', 'implementation_identity' => $identity, 'manifest_sha256' => $manifestSha, 'source_freeze_sha256' => $freezeSha];
            }
            $raw = base64_decode($source['content_base64'], true);
            if (!is_string($raw) || !hash_equals($file['sha256'], hash('sha256', $raw))) {
                return ['valid' => false, 'reason' => 'MEASUREMENT_IMPLEMENTATION_RAW_HASH_MISMATCH', 'implementation_identity' => $identity, 'manifest_sha256' => $manifestSha, 'source_freeze_sha256' => $freezeSha];
            }
        }
        $identityPayload = ['schema_version' => self::MANIFEST_SCHEMA_VERSION, 'files' => $files];
        if (!hash_equals($identity, 'sha256:' . hash('sha256', $this->canonicalJson($identityPayload)))) {
            return ['valid' => false, 'reason' => 'MEASUREMENT_IMPLEMENTATION_IDENTITY_MISMATCH', 'implementation_identity' => $identity, 'manifest_sha256' => $manifestSha, 'source_freeze_sha256' => $freezeSha];
        }
        return ['valid' => true, 'reason' => 'OK', 'implementation_identity' => $identity, 'manifest_sha256' => $manifestSha, 'source_freeze_sha256' => $freezeSha];
    }

    /** @return list<array{path:string,sha256:string}> */
    public function manifestFiles(): array
    {
        $paths = $this->criticalPaths ?? self::CRITICAL_PATHS;
        $paths = array_values(array_unique(array_map(static fn(mixed $path): string => str_replace('\\', '/', trim((string)$path)), $paths)));
        sort($paths, SORT_STRING);
        if ($paths === []) {
            throw new RuntimeException('measurement_critical_scope_empty');
        }
        $files = [];
        foreach ($paths as $path) {
            if (!$this->validRepoRelativePath($path) || !is_file($this->absolutePath($path))) {
                throw new RuntimeException('measurement_critical_source_missing:' . $path);
            }
            $sha = hash_file('sha256', $this->absolutePath($path));
            if (!is_string($sha)) {
                throw new RuntimeException('measurement_critical_source_hash_failed:' . $path);
            }
            $files[] = ['path' => $path, 'sha256' => $sha];
        }
        return $files;
    }

    private function writeFirstOrVerify(string $reference, string $bytes, string $sha256): void
    {
        $path = $this->storagePath($reference);
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('measurement_implementation_storage_unavailable');
        }
        $handle = @fopen($path, 'x');
        if (is_resource($handle)) {
            $ok = @fwrite($handle, $bytes) !== false;
            @fclose($handle);
            if (!$ok) {
                @unlink($path);
                throw new RuntimeException('measurement_implementation_freeze_write_failed');
            }
            return;
        }
        $existing = @file_get_contents($path);
        if (!is_string($existing) || !hash_equals($sha256, 'sha256:' . hash('sha256', $existing))) {
            throw new RuntimeException('measurement_implementation_freeze_conflict');
        }
    }

    private function absolutePath(string $repoRelative): string
    {
        return rtrim($this->repoRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $repoRelative);
    }

    private function storagePath(string $reference): string
    {
        return rtrim($this->storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $reference);
    }

    private function validReference(string $reference, string $prefix): bool
    {
        return str_starts_with($reference, $prefix)
            && preg_match('#^' . preg_quote($prefix, '#') . '[a-f0-9]{64}\\.json$#', $reference) === 1;
    }

    private function validRepoRelativePath(string $path): bool
    {
        return $path !== '' && !str_starts_with($path, '/') && !str_contains($path, '..') && !str_contains($path, "\0");
    }

    private function canonicalJson(array $value): string
    {
        $canonical = $this->canonicalize($value);
        return json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }
}
