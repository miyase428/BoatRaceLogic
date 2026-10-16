<?php

declare(strict_types=1);

require_once __DIR__ . '/FormalEvaluatorV2.php';
require_once __DIR__ . '/FormalComparativeReadinessEvaluator.php';

/**
 * Identity-separated formal close for an already captured Window.
 *
 * Preflight is deliberately pure: it reads only the explicit Window and the
 * immutable evidence reachable from it.  The later commit path writes only
 * external lifecycle records; it never writes into the Window it closes.
 */
final class IdentitySeparatedFormalCloseV3
{
    public const CLOSURE_SCHEMA_VERSION = 'formal-measurement-window-closure/v3';
    public const PREFLIGHT_SCHEMA_VERSION = 'identity-separated-close-preflight/v3';
    public const CLOSING_MANIFEST_SCHEMA_VERSION = 'closing-tool-implementation-manifest/v1';
    public const CLOSING_FREEZE_SCHEMA_VERSION = 'closing-tool-source-freeze/v1';

    public function __construct(private readonly string $repoRoot) {}

    /**
     * @param array{scheduler_held:bool,capture_process_count:int,scheduler_config_hash:string,capture_lock_path?:string} $runtime
     * @return array<string,mixed>
     */
    public function preflight(string $windowId, array $runtime, string $asOf, array $failedCloseArtifactPaths = []): array
    {
        $errors = [];
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $windowId)) return $this->blocked('WINDOW_ID_INVALID');
        $root = $this->windowRoot($windowId);
        if (!is_dir($root)) return $this->blocked('WINDOW_ROOT_MISSING');

        $pointer = $this->pointer();
        if (($pointer['window_id'] ?? '') !== $windowId || ($pointer['window_root'] ?? '') !== $this->windowRelativeRoot($windowId)) {
            $errors[] = 'POINTER_WINDOW_MISMATCH';
        }

        $context = FormalMeasurementWindowContext::fromWindowRoot($this->repoRoot, $root);
        $resolved = $context->resolve(true);
        if (empty($resolved['accepted'])) return $this->blocked('HISTORICAL_CONTEXT_INVALID:' . (string)($resolved['reason'] ?? ''));
        $manifest = is_array($resolved['manifest'] ?? null) ? $resolved['manifest'] : [];
        $state = $context->lifecycle()->effectiveState($windowId, $manifest);
        if (($state['effective_state'] ?? '') !== 'ACTIVE') $errors[] = 'WINDOW_NOT_ACTIVE';
        if (($manifest['status'] ?? '') !== 'ACTIVE' || ($manifest['activation_state'] ?? '') !== 'ACTIVE') $errors[] = 'WINDOW_MANIFEST_NOT_ACTIVE';
        if (!empty($state['closure'])) $errors[] = 'CLOSURE_ALREADY_EXISTS';
        if (!empty($context->lifecycle()->readAbort($windowId))) $errors[] = 'ABORT_ALREADY_EXISTS';

        if (empty($runtime['scheduler_held'])) $errors[] = 'SCHEDULER_NOT_HELD';
        if ((int)($runtime['capture_process_count'] ?? -1) !== 0) $errors[] = 'CAPTURE_PROCESS_PRESENT';
        if (!is_string($runtime['scheduler_config_hash'] ?? null) || $runtime['scheduler_config_hash'] === '') $errors[] = 'SCHEDULER_STATE_UNATTESTED';

        $declarationRead = $context->declaration()->read(true);
        if (empty($declarationRead['accepted'])) return $this->blocked('CAPTURE_DECLARATION_INVALID:' . (string)($declarationRead['reason'] ?? ''));
        $declaration = is_array($declarationRead['declaration'] ?? null) ? $declarationRead['declaration'] : [];
        $activation = $context->activationHold()->readActivation();
        $capture = $this->captureBinding($root, $context, $declaration, $activation, $errors);

        $closingIdentity = (new MeasurementImplementationProvenance(
            $this->repoRoot,
            $context->declaration()->provenanceStorageRoot(),
        ))->currentIdentityReadOnly();

        try {
            $aggregate = FormalEvidenceAggregate::calculate($root, $windowId, substr($asOf, 0, 10));
            $evaluation = (new FormalEvaluatorV2($this->repoRoot))->evaluateWindow($windowId, $asOf);
            $comparative = (new FormalComparativeReadinessEvaluator($this->frozenContract($root, $declaration)))->evaluateWindow($root);
        } catch (Throwable $error) {
            return $this->blocked('FORMAL_READONLY_EVALUATION_FAILED:' . $error->getMessage());
        }

        $decision = is_array($evaluation['decision'] ?? null) ? $evaluation['decision'] : [];
        if (empty($evaluation['ok']) || ($evaluation['status'] ?? '') !== 'EVALUATED') $errors[] = 'FORMAL_EVALUATION_NOT_COMPLETE';
        foreach (['gate_a1', 'gate_a2', 'gate_b_now', 'gate_b_final', 'gate_c'] as $gate) {
            $value = $decision[$gate]['status'] ?? null;
            if ($value !== 'PASS') $errors[] = 'GATE_NOT_PASS_' . strtoupper($gate);
        }
        if (($comparative['status'] ?? '') !== 'PASS') $errors[] = 'COMPARATIVE_READINESS_NOT_PASS';
        if (($evaluation['gate_b']['pre_cutoff_pending'] ?? -1) !== 0) $errors[] = 'PENDING_CANDIDATES_PRESENT';
        if (($evaluation['gate_b']['unresolved'] ?? -1) !== 0) $errors[] = 'MISSING_TERMINAL_RESOLUTION';

        $counts = $this->evidenceCounts($root, $evaluation, $comparative);
        if ($counts['candidate_count'] !== $counts['terminal_count']) $errors[] = 'TERMINAL_CANDIDATE_COUNT_INCOMPLETE';
        if ($counts['source_failure_count'] !== 0) $errors[] = 'SOURCE_FAILURE_PRESENT';
        if ($counts['missing_terminal_resolution_count'] !== 0) $errors[] = 'MISSING_TERMINAL_RESOLUTION';
        if ($counts['post_cutoff_backfill_count'] !== 0) $errors[] = 'POST_CUTOFF_BACKFILL_PRESENT';
        if ($counts['global_fatal_count'] !== 0) $errors[] = 'GLOBAL_FATAL_PRESENT';

        try {
            $failedArtifacts = $this->failedCloseArtifacts($root, $aggregate, $capture['identity'], $failedCloseArtifactPaths);
        } catch (Throwable $error) {
            $errors[] = $error->getMessage();
            $failedArtifacts = [];
        }
        if (($aggregate['aggregate_sha256'] ?? '') === '' || ($aggregate['schema_version'] ?? '') !== FormalEvidenceAggregate::SCHEMA_VERSION) {
            $errors[] = 'FORMAL_AGGREGATE_INVALID';
        }
        if (($capture['identity'] ?? '') === '' || ($capture['activation_identity'] ?? '') !== ($capture['identity'] ?? '')) {
            $errors[] = 'CAPTURE_IDENTITY_BINDING_INVALID';
        }

        $payload = [
            'schema_version' => self::PREFLIGHT_SCHEMA_VERSION,
            'window_id' => $windowId,
            'window_root' => $this->windowRelativeRoot($windowId),
            'state' => (string)($state['effective_state'] ?? ''),
            'armed_at' => (string)($manifest['armed_at'] ?? ''),
            'pointer' => $pointer,
            'pointer_sha256' => (string)($pointer['_sha256'] ?? ''),
            'runtime' => [
                'scheduler_held' => !empty($runtime['scheduler_held']),
                'capture_process_count' => (int)($runtime['capture_process_count'] ?? -1),
                'scheduler_config_hash' => (string)($runtime['scheduler_config_hash'] ?? ''),
            ],
            'capture' => $capture,
            'closing_tool_measurement_identity' => $closingIdentity,
            'formal_aggregate' => [
                'schema_version' => (string)($aggregate['schema_version'] ?? ''),
                'sha256' => (string)($aggregate['aggregate_sha256'] ?? ''),
                'file_count' => (int)($aggregate['file_count'] ?? 0),
                'provenance_validation' => 'PASS',
            ],
            'formal_gates' => [
                'a1' => (string)($decision['gate_a1']['status'] ?? ''),
                'a2' => (string)($decision['gate_a2']['status'] ?? ''),
                'b_now' => (string)($decision['gate_b_now']['status'] ?? ''),
                'b_final' => (string)($decision['gate_b_final']['status'] ?? ''),
                'c' => (string)($decision['gate_c']['status'] ?? ''),
            ],
            'counts' => $counts,
            'formal_comparative_readiness' => [
                'status' => (string)($comparative['status'] ?? ''),
                'paired_denominator' => (int)($comparative['paired_denominator'] ?? 0),
                'ml_only_excluded' => (int)($comparative['ml_only_excluded'] ?? 0),
                'errors' => array_values((array)($comparative['errors'] ?? [])),
            ],
            'failed_close_artifacts' => $failedArtifacts,
            'evaluation_as_of' => $asOf,
            'closure_absent' => empty($state['closure']),
            'abort_absent' => empty($context->lifecycle()->readAbort($windowId)),
        ];
        $payload['preflight_token'] = 'sha256:' . hash('sha256', self::canonicalJson($payload));
        return [
            'accepted' => $errors === [], 'status' => $errors === [] ? 'CLOSE_V3_PREFLIGHT_PASS' : 'CLOSE_V3_PREFLIGHT_BLOCKED',
            'reason' => $errors === [] ? '' : implode(',', array_values(array_unique($errors))),
            'preflight' => $payload,
        ];
    }

    /**
     * Commit exists for the separately authorized CC27C step.  It is never
     * called by the CC27B dry-run command.
     *
     * @param array<string,mixed> $priorPreflight
     * @param callable():array{scheduler_held:bool,capture_process_count:int,scheduler_config_hash:string,capture_lock_path?:string} $runtimeProbe
     * @return array<string,mixed>
     */
    public function commit(array $priorPreflight, callable $runtimeProbe, ?string $closedAt = null): array
    {
        $windowId = (string)($priorPreflight['window_id'] ?? '');
        $token = (string)($priorPreflight['preflight_token'] ?? '');
        if ($windowId === '' || $token === '') return ['accepted' => false, 'status' => 'CLOSE_V3_BLOCKED', 'reason' => 'PREFLIGHT_TOKEN_MISSING'];
        $runtime = $runtimeProbe();
        $captureLockPath = (string)($runtime['capture_lock_path'] ?? '/tmp/boatrace_all_race_forward_capture.lock');
        if ($captureLockPath === '' || str_contains($captureLockPath, "\0")) return ['accepted' => false, 'status' => 'CLOSE_V3_BLOCKED', 'reason' => 'CAPTURE_LOCK_PATH_INVALID'];
        $captureLock = @fopen($captureLockPath, 'c');
        if (!is_resource($captureLock) || !@flock($captureLock, LOCK_EX | LOCK_NB)) {
            if (is_resource($captureLock)) @fclose($captureLock);
            return ['accepted' => false, 'status' => 'CLOSE_V3_BLOCKED', 'reason' => 'CAPTURE_LOCK_UNAVAILABLE'];
        }
        $closurePath = $this->closurePath($windowId);
        $lockPath = $closurePath . '.v3.lock';
        if (!is_dir(dirname($lockPath)) && !@mkdir(dirname($lockPath), 0775, true) && !is_dir(dirname($lockPath))) {
            @flock($captureLock, LOCK_UN);
            @fclose($captureLock);
            return ['accepted' => false, 'status' => 'CLOSE_V3_BLOCKED', 'reason' => 'CLOSURE_LOCK_DIRECTORY_UNAVAILABLE'];
        }
        $lock = @fopen($lockPath, 'c');
        if (!is_resource($lock) || !@flock($lock, LOCK_EX)) {
            if (is_resource($lock)) @fclose($lock);
            @flock($captureLock, LOCK_UN);
            @fclose($captureLock);
            return ['accepted' => false, 'status' => 'CLOSE_V3_BLOCKED', 'reason' => 'CLOSURE_LOCK_UNAVAILABLE'];
        }
        try {
            $existing = $this->readJson($closurePath);
            if ($existing !== []) {
                if (($existing['schema_version'] ?? '') === self::CLOSURE_SCHEMA_VERSION
                    && hash_equals((string)($existing['preflight_token'] ?? ''), $token)) {
                    return ['accepted' => true, 'status' => 'CLOSURE_ALREADY_EXISTS', 'reason' => '', 'closure' => $existing];
                }
                return ['accepted' => false, 'status' => 'CLOSE_V3_BLOCKED', 'reason' => 'BLOCKED_CLOSURE_CONFLICT'];
            }
            $artifactPaths = array_values(array_map(static fn(array $row): string => (string)($row['path'] ?? ''), (array)($priorPreflight['failed_close_artifacts'] ?? [])));
            $fresh = $this->preflight($windowId, $runtime, (string)($priorPreflight['evaluation_as_of'] ?? ''), $artifactPaths);
            if (empty($fresh['accepted'])) return ['accepted' => false, 'status' => 'CLOSE_V3_BLOCKED', 'reason' => 'COMMIT_PREFLIGHT_FAILED:' . (string)($fresh['reason'] ?? '')];
            $next = $fresh['preflight'];
            if (!hash_equals($token, (string)($next['preflight_token'] ?? ''))) {
                return ['accepted' => false, 'status' => 'CLOSE_V3_BLOCKED', 'reason' => 'PREFLIGHT_TOKEN_DRIFT'];
            }
            $provenance = $this->freezeClosingToolProvenance((string)$next['closing_tool_measurement_identity']);
            $closure = [
                'schema_version' => self::CLOSURE_SCHEMA_VERSION,
                'window_id' => $windowId,
                'armed_at' => (string)$next['armed_at'],
                'closed_at' => $closedAt ?? (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM),
                'close_reason' => 'NORMAL_CAPTURE_COMPLETE',
                'target_capture_measurement_identity' => (string)$next['capture']['identity'],
                'closing_tool_measurement_identity' => (string)$next['closing_tool_measurement_identity'],
                'capture_declaration' => $next['capture']['declaration'],
                'capture_activation' => $next['capture']['activation'],
                'capture_measurement_manifest' => $next['capture']['measurement_manifest'],
                'capture_source_freeze' => $next['capture']['source_freeze'],
                'closing_tool_provenance' => $provenance,
                'formal_aggregate' => $next['formal_aggregate'],
                'formal_gates' => $next['formal_gates'],
                'counts' => $next['counts'],
                'formal_comparative_readiness' => $next['formal_comparative_readiness'],
                'canonical_capture_evidence_hash' => (string)$next['formal_aggregate']['sha256'],
                'canonical_capture_evidence_count' => (int)$next['formal_aggregate']['file_count'],
                'failed_close_artifacts' => $next['failed_close_artifacts'],
                'pointer_sha256' => (string)$next['pointer_sha256'],
                'preflight_token' => $token,
                'official_epoch' => 'NO',
            ];
            $closure['closure_record_hash'] = 'sha256:' . hash('sha256', self::canonicalJson($closure));
            if (!$this->writeCreateOnly($closurePath, self::canonicalJson($closure) . "\n")) {
                return ['accepted' => false, 'status' => 'CLOSE_V3_BLOCKED', 'reason' => 'CLOSURE_CREATE_FAILED'];
            }
            return ['accepted' => true, 'status' => 'CLOSURE_CREATED', 'reason' => '', 'closure' => $closure];
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
            @flock($captureLock, LOCK_UN);
            @fclose($captureLock);
        }
    }

    /** @return array<string,mixed> */
    private function captureBinding(string $root, FormalMeasurementWindowContext $context, array $declaration, array $activation, array &$errors): array
    {
        $fields = ['implementation_identity', 'manifest_schema_version', 'manifest_sha256', 'manifest_ref', 'source_freeze_sha256', 'source_freeze_ref'];
        foreach ($fields as $field) {
            $declared = (string)($declaration[$field] ?? '');
            $activated = (string)($activation[$field] ?? '');
            if ($declared === '' || $activated === '') $errors[] = 'CAPTURE_BINDING_FIELD_MISSING_' . strtoupper($field);
            elseif (!hash_equals($declared, $activated)) $errors[] = 'DECLARATION_ACTIVATION_MISMATCH_' . strtoupper($field);
        }
        if (($declaration['window_id'] ?? '') !== ($activation['window_id'] ?? '')) $errors[] = 'DECLARATION_ACTIVATION_WINDOW_MISMATCH';
        $manifestPath = $context->declaration()->declarationPath();
        $activationPath = dirname($context->windowManifestPath()) . '/activation_record.json';
        $manifestRef = (string)($declaration['manifest_ref'] ?? '');
        $freezeRef = (string)($declaration['source_freeze_ref'] ?? '');
        $storage = $root . '/provenance';
        $measurementManifestPath = $storage . '/' . $manifestRef;
        $freezePath = $storage . '/' . $freezeRef;
        if (!is_file($measurementManifestPath) || !is_file($freezePath)) $errors[] = 'CAPTURE_PROVENANCE_FILE_MISSING';
        $manifestHash = $this->fileHash($measurementManifestPath);
        $freezeHash = $this->fileHash($freezePath);
        if ($manifestHash === '' || !hash_equals((string)($declaration['manifest_sha256'] ?? ''), $manifestHash)) $errors[] = 'CAPTURE_MANIFEST_HASH_INVALID';
        if ($freezeHash === '' || !hash_equals((string)($declaration['source_freeze_sha256'] ?? ''), $freezeHash)) $errors[] = 'CAPTURE_SOURCE_FREEZE_HASH_INVALID';
        return [
            'identity' => (string)($declaration['implementation_identity'] ?? ''),
            'activation_identity' => (string)($activation['implementation_identity'] ?? ''),
            'declaration' => ['ref' => basename($manifestPath), 'sha256' => $this->fileHash($manifestPath)],
            'activation' => ['ref' => basename($activationPath), 'sha256' => $this->fileHash($activationPath)],
            'measurement_manifest' => ['ref' => $manifestRef, 'sha256' => $manifestHash],
            'source_freeze' => ['ref' => $freezeRef, 'sha256' => $freezeHash],
        ];
    }

    private function frozenContract(string $root, array $declaration): ComparativeContractV1
    {
        $freeze = $this->readJson($root . '/provenance/' . (string)($declaration['source_freeze_ref'] ?? ''));
        $sources = [];
        foreach ((array)($freeze['files'] ?? []) as $row) if (is_array($row)) $sources[(string)($row['path'] ?? '')] = $row;
        $load = static function (string $path) use ($sources): array {
            $row = $sources[$path] ?? null;
            $bytes = is_array($row) ? base64_decode((string)($row['content_base64'] ?? ''), true) : false;
            if (!is_string($bytes) || !hash_equals((string)($row['sha256'] ?? ''), hash('sha256', $bytes))) throw new RuntimeException('FROZEN_COMPARATIVE_SOURCE_INVALID:' . $path);
            $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) throw new RuntimeException('FROZEN_COMPARATIVE_SOURCE_INVALID:' . $path);
            return $decoded;
        };
        $contract = ComparativeContractV1::fromFrozenPayloads(
            $load('config/strict_forward_comparative_scope_v1.json'),
            $load('config/strict_forward_comparative_policy_v1.json'),
        );
        $expected = is_array($declaration['comparative_contract'] ?? null) ? $declaration['comparative_contract'] : [];
        if ($contract->identities() !== $expected) throw new RuntimeException('FROZEN_COMPARATIVE_CONTRACT_MISMATCH');
        return $contract;
    }

    /** @return array<string,int> */
    private function evidenceCounts(string $root, array $evaluation, array $comparative): array
    {
        $rows = $this->jsonRows($root . '/opportunity/candidates');
        $sourceFailures = $this->jsonLineRows($root . '/opportunity/source_failures');
        $all = array_merge($this->jsonRows($root . '/opportunity'), $this->jsonRows($root . '/ledger'), $this->jsonRows($root . '/input_snapshots'), $this->jsonRows($root . '/comparative'));
        $backfills = 0;
        foreach ($all as $row) if (!empty($row['backfill']) || !empty($row['post_cutoff_backfill']) || !empty($row['backfill_reason'])) $backfills++;
        $globalFatal = 0;
        foreach ($this->jsonLineRows($root . '/comparative/failures') as $row) {
            if (in_array((string)($row['status'] ?? ''), ['COMPARATIVE_CAPTURE_CONFLICT', 'WINDOW_GLOBAL_FATAL', 'GLOBAL_FATAL'], true)) $globalFatal++;
        }
        $decision = is_array($evaluation['decision'] ?? null) ? $evaluation['decision'] : [];
        return [
            'candidate_count' => count($rows),
            'terminal_count' => (int)($evaluation['gate_b']['terminal'] ?? 0),
            'pending_count' => (int)($evaluation['gate_b']['pre_cutoff_pending'] ?? 0),
            'accepted_count' => count((array)($decision['accepted_race_codes'] ?? [])),
            'snapshot_count' => count($this->jsonRows($root . '/input_snapshots')),
            'pair_count' => count($this->jsonRows($root . '/comparative/pairs')),
            'source_failure_count' => count($sourceFailures),
            'missing_terminal_resolution_count' => (int)($evaluation['gate_b']['unresolved'] ?? 0),
            'post_cutoff_backfill_count' => $backfills,
            'global_fatal_count' => $globalFatal,
            'comparative_completion_count' => (int)($comparative['completion_count'] ?? 0),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function failedCloseArtifacts(string $root, array $aggregate, string $captureIdentity, array $declaredPaths): array
    {
        $result = [];
        $excluded = array_values((array)($aggregate['excluded_auxiliary_provenance'] ?? []));
        sort($excluded, SORT_STRING);
        $declared = array_values(array_unique(array_filter(array_map('strval', $declaredPaths), static fn(string $path): bool => $path !== '')));
        sort($declared, SORT_STRING);
        if ($excluded !== $declared) throw new RuntimeException('FAILED_CLOSE_ARTIFACT_DECLARATION_MISMATCH');
        foreach ($declared as $relative) {
            if (!str_starts_with($relative, 'provenance/')) throw new RuntimeException('FAILED_CLOSE_ARTIFACT_PATH_INVALID');
            $path = $root . '/' . $relative;
            $row = $this->readJson($path);
            $identity = (string)($row['implementation_identity'] ?? '');
            if ($identity === '' || $identity === $captureIdentity || in_array($identity, (array)($aggregate['referenced_implementation_identities'] ?? []), true)) {
                throw new RuntimeException('FAILED_CLOSE_ARTIFACT_CAPTURE_CHAIN_AMBIGUOUS');
            }
            $result[] = [
                'status' => 'FAILED_CLOSE_OPERATIONAL_ARTIFACT',
                'path' => $relative,
                'content_sha256' => $this->fileHash($path),
                'measurement_identity' => $identity,
                'capture_chain_referenced' => false,
            ];
        }
        usort($result, static fn(array $a, array $b): int => $a['path'] <=> $b['path']);
        return $result;
    }

    /** @return array<string,mixed> */
    private function pointer(): array
    {
        $path = $this->repoRoot . '/' . FormalMeasurementWindowContext::POINTER_RELATIVE_PATH;
        $bytes = @file_get_contents($path);
        $row = is_string($bytes) ? json_decode($bytes, true) : null;
        if (!is_array($row) || !FormalMeasurementWindowContext::validPointerPayload($row)) return [];
        $row['_sha256'] = 'sha256:' . hash('sha256', $bytes);
        return $row;
    }

    /** @return array<string,mixed> */
    private function blocked(string $reason): array { return ['accepted' => false, 'status' => 'CLOSE_V3_PREFLIGHT_BLOCKED', 'reason' => $reason, 'preflight' => []]; }
    private function windowRelativeRoot(string $id): string { return 'var/strict_forward_live_readiness/windows/' . $id; }
    private function windowRoot(string $id): string { return rtrim($this->repoRoot, DIRECTORY_SEPARATOR) . '/' . $this->windowRelativeRoot($id); }
    private function closurePath(string $id): string { return rtrim($this->repoRoot, DIRECTORY_SEPARATOR) . '/var/strict_forward_live_readiness/lifecycle/closures/' . rawurlencode($id) . '.json'; }
    private function fileHash(string $path): string { $hash = is_file($path) ? hash_file('sha256', $path) : false; return is_string($hash) ? 'sha256:' . $hash : ''; }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        if (!is_file($path)) return [];
        try { $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable) { return []; }
        return is_array($row) ? $row : [];
    }

    /** @return list<array<string,mixed>> */
    private function jsonRows(string $directory): array
    {
        if (!is_dir($directory)) return [];
        $rows = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || !str_ends_with($file->getFilename(), '.json')) continue;
            $row = $this->readJson($file->getPathname()); if ($row !== []) $rows[] = $row;
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function jsonLineRows(string $directory): array
    {
        if (!is_dir($directory)) return [];
        $rows = [];
        foreach (glob($directory . '/*.jsonl') ?: [] as $path) foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true); if (is_array($row)) $rows[] = $row;
        }
        return $rows;
    }

    /** @return array<string,string> */
    private function freezeClosingToolProvenance(string $expectedIdentity): array
    {
        $root = $this->repoRoot . '/var/strict_forward_live_readiness/lifecycle/closure_provenance';
        $provenance = new MeasurementImplementationProvenance($this->repoRoot, $root);
        $frozen = $provenance->freeze();
        if (!hash_equals($expectedIdentity, (string)$frozen['implementation_identity'])) throw new RuntimeException('CLOSING_TOOL_IDENTITY_DRIFT');
        return $frozen;
    }

    private function writeCreateOnly(string $path, string $bytes): bool
    {
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) return false;
        $handle = @fopen($path, 'x');
        if (!is_resource($handle)) return false;
        $ok = @fwrite($handle, $bytes) !== false;
        @fclose($handle);
        return $ok;
    }

    private static function canonicalJson(mixed $value): string
    {
        return json_encode(self::sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function sort(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map(static fn(mixed $item): mixed => self::sort($item), $value);
        ksort($value, SORT_STRING); foreach ($value as $key => $item) $value[$key] = self::sort($item);
        return $value;
    }
}
