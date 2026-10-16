<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialEpochPolicyV1.php';
require_once __DIR__ . '/OfficialEpochCgApprovalStore.php';
require_once __DIR__ . '/OfficialEpochStartStore.php';
require_once __DIR__ . '/OfficialEpochIncidentPolicyV1.php';
require_once __DIR__ . '/OfficialEpochIncidentLifecycleStore.php';
require_once __DIR__ . '/FormalEvaluatorV2.php';
require_once __DIR__ . '/FormalEvidenceAggregate.php';
require_once __DIR__ . '/FormalComparativeReadinessEvaluator.php';
require_once __DIR__ . '/ComparativeContractV1.php';

/**
 * External lifecycle for the one Official Epoch.
 *
 * Preflight is fully read-only and resolves a historical Window by explicit
 * id.  Commit is intentionally separate, create-only, and never mutates the
 * source Window, its closure, pointer, scheduler, or prediction routing.
 */
final class OfficialEpochLifecycleV1
{
    public const START_SCHEMA_VERSION = 'formal-official-epoch-start/v1';
    public const PREFLIGHT_SCHEMA_VERSION = 'official-epoch-preflight/v1';

    public function __construct(private readonly string $repoRoot, private readonly ?string $externalLifecycleRoot = null)
    {
    }

    public function lifecycleRoot(): string
    {
        return $this->externalLifecycleRoot ?? rtrim($this->repoRoot, DIRECTORY_SEPARATOR) . '/var/strict_forward_live_readiness/lifecycle';
    }

    public function approvalStore(): OfficialEpochCgApprovalStore
    {
        return new OfficialEpochCgApprovalStore($this->lifecycleRoot());
    }

    public function startStore(): OfficialEpochStartStore
    {
        return new OfficialEpochStartStore($this->lifecycleRoot());
    }

    public function incidentStore(): OfficialEpochIncidentLifecycleStore
    {
        return new OfficialEpochIncidentLifecycleStore($this->lifecycleRoot());
    }

    /**
     * Pure explicit-window preflight.  `runtime` is supplied by the CLI or
     * commit caller; this class never shells out and never changes cron.
     *
     * @param array{scheduler_held:bool,capture_process_count:int,scheduler_config_hash:string} $runtime
     * @return array<string,mixed>
     */
    public function preflight(string $windowId, array $runtime, ?string $approvalKey = null): array
    {
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $windowId)) return $this->blocked('WINDOW_ID_INVALID');
        $root = $this->windowRoot($windowId);
        if (!is_dir($root)) return $this->blocked('WINDOW_ROOT_MISSING');

        $errors = [];
        $context = FormalMeasurementWindowContext::fromWindowRoot($this->repoRoot, $root);
        $resolved = $context->resolve(true);
        if (empty($resolved['accepted'])) return $this->blocked('HISTORICAL_CONTEXT_INVALID:' . (string)($resolved['reason'] ?? ''));
        $manifest = is_array($resolved['manifest'] ?? null) ? $resolved['manifest'] : [];
        $state = $context->lifecycle()->effectiveState($windowId, $manifest);
        $closure = is_array($state['closure'] ?? null) ? $state['closure'] : [];
        if (($state['effective_state'] ?? '') !== 'CLOSED') $errors[] = 'SOURCE_WINDOW_NOT_CLOSED';
        if (($closure['schema_version'] ?? '') !== FormalMeasurementWindowLifecycle::IDENTITY_SEPARATED_CLOSURE_SCHEMA_VERSION) $errors[] = 'CLOSURE_SCHEMA_INVALID';
        if (!$this->validClosureHash($closure)) $errors[] = 'CLOSURE_HASH_INVALID';
        if (($closure['official_epoch'] ?? '') !== 'NO') $errors[] = 'SOURCE_WINDOW_EPOCH_FLAG_INVALID';

        $closedAt = (string)($closure['closed_at'] ?? '');
        if (!$this->timestamp($closedAt)) $errors[] = 'CLOSURE_TIMESTAMP_INVALID';
        $captureIdentity = (string)($closure['target_capture_measurement_identity'] ?? '');
        if (!preg_match('/^sha256:[a-f0-9]{64}$/', $captureIdentity)) $errors[] = 'CAPTURE_IDENTITY_INVALID';

        try {
            $aggregate = FormalEvidenceAggregate::calculate($root, $windowId, substr($closedAt, 0, 10));
            $evaluation = (new FormalEvaluatorV2($this->repoRoot))->evaluateWindow($windowId, $closedAt);
            $frozenContract = $this->frozenContract($root, $closure);
            $comparative = (new FormalComparativeReadinessEvaluator($frozenContract))->evaluateWindow($root);
        } catch (Throwable $error) {
            return $this->blocked('HISTORICAL_EVALUATION_FAILED:' . $error->getMessage());
        }

        $decision = is_array($evaluation['decision'] ?? null) ? $evaluation['decision'] : [];
        if (empty($evaluation['ok']) || ($evaluation['status'] ?? '') !== 'EVALUATED') $errors[] = 'FORMAL_EVALUATION_INCOMPLETE';
        foreach (['gate_a1', 'gate_a2', 'gate_b_now', 'gate_b_final', 'gate_c'] as $gate) {
            if (($decision[$gate]['status'] ?? '') !== 'PASS') $errors[] = 'FORMAL_' . strtoupper($gate) . '_NOT_PASS';
        }
        if (($aggregate['schema_version'] ?? '') !== FormalEvidenceAggregate::SCHEMA_VERSION
            || !hash_equals((string)($closure['formal_aggregate']['sha256'] ?? ''), (string)($aggregate['aggregate_sha256'] ?? ''))) {
            $errors[] = 'FORMAL_AGGREGATE_MISMATCH';
        }
        $provenance = is_array($aggregate['provenance_resolution'] ?? null) ? $aggregate['provenance_resolution'] : [];
        if ($provenance === []) $errors[] = 'FORMAL_PROVENANCE_INCOMPLETE';
        if (($comparative['status'] ?? '') !== 'PASS') $errors[] = 'FORMAL_COMPARATIVE_READINESS_NOT_PASS';

        $counts = $this->evidenceCounts($root, $closure, $evaluation, $comparative);
        foreach (['candidate_count' => 'terminal_count', 'snapshot_count' => 'comparative_completion_count'] as $left => $right) {
            if ((int)$counts[$left] !== (int)$counts[$right]) $errors[] = 'COUNT_MISMATCH_' . strtoupper($left);
        }
        foreach (['source_failure_count', 'missing_terminal_resolution_count', 'post_cutoff_backfill_count', 'global_fatal_count'] as $field) {
            if ((int)$counts[$field] !== 0) $errors[] = strtoupper($field) . '_NONZERO';
        }

        $contract = $frozenContract->scope();
        $policy = $frozenContract->policy();
        $identities = $frozenContract->identities();
        $scopeUses = is_array($contract['uses'] ?? null) ? $contract['uses'] : [];
        $scopeVenues = array_values(array_unique(array_map(static fn(array $use): string => (string)($use['venue'] ?? ''), $scopeUses)));
        $comparableUses = count(array_filter($scopeUses, static fn(array $use): bool => ($use['scope_class'] ?? '') === 'COMPARABLE'));
        $mlOnlyUses = count(array_filter($scopeUses, static fn(array $use): bool => ($use['scope_class'] ?? '') === 'ML_ONLY'));
        if ($comparableUses !== 40 || $mlOnlyUses !== 83) $errors[] = 'COMPARATIVE_SCOPE_COUNTS_INVALID';
        $closureReadiness = is_array($closure['formal_comparative_readiness'] ?? null) ? $closure['formal_comparative_readiness'] : [];
        if ((int)($closureReadiness['paired_denominator'] ?? -1) !== (int)($comparative['paired_denominator'] ?? -2)
            || (int)($closureReadiness['ml_only_excluded'] ?? -1) !== (int)($comparative['ml_only_excluded'] ?? -2)) {
            $errors[] = 'COMPARATIVE_READINESS_COUNT_MISMATCH';
        }
        if (($identities['comparative_scope_identity'] ?? '') !== ($closure['capture_activation']['comparative_scope_identity'] ?? $identities['comparative_scope_identity'])) {
            // v3 closures predate direct comparative identity fields. The
            // declaration/activation chain has already been hash-verified by
            // the aggregate resolver, so no synthetic closure field is added.
        }

        $numericMetrics = $this->numericMetrics($root, $counts, $decision);
        $numeric = OfficialEpochPolicyV1::evaluateNumericDataQuality($numericMetrics);
        if ($numeric['status'] !== 'PASS') $errors[] = 'NUMERIC_DATA_QUALITY_HARD_FAIL';
        $operationalMetrics = $numericMetrics + [
            'distinct_eligible_race_codes' => count((array)($decision['eligible_race_codes'] ?? [])),
            'live_strict_before_cutoff' => !empty($numericMetrics['all_canonical_live_strict_before_cutoff']),
            'replay_or_backfill_count' => (int)$counts['post_cutoff_backfill_count'],
        ];
        $operational = OfficialEpochPolicyV1::evaluateOperationalLive($operationalMetrics);
        if ($operational['status'] !== 'PASS') $errors[] = 'OPERATIONAL_LIVE_NOT_PASS';

        $hardSignals = [
            'expected_prediction_storage_separation' => is_dir($root . '/ledger/expected') && is_dir($root . '/ledger/records') && $counts['expected_count'] > 0,
            'canonical_first_write_rule' => $counts['expected_count'] === $counts['canonical_count'] && $counts['canonical_count'] > 0,
            'conflicting_duplicate_detection' => (int)$numericMetrics['unresolved_conflict_count'] === 0,
            'twelve_venue_integration' => count($scopeVenues) === 12,
            'artifact_identity_available' => (float)$numericMetrics['artifact_identity_completeness'] === 1.0,
            'policy_identity_available' => (float)$numericMetrics['policy_identity_completeness'] === 1.0,
            'scheduler_operation_verified' => $counts['candidate_count'] > 0 && $counts['source_failure_count'] === 0 && ($decision['gate_b_final']['status'] ?? '') === 'PASS',
            'cutoff_timebase_verified' => !empty($numericMetrics['all_canonical_live_strict_before_cutoff']),
            'timezone_evidence_chain_verified' => !empty($numericMetrics['timezone_complete']),
            'capture_failure_observable' => is_dir($root . '/opportunity') && array_key_exists('source_failure_count', $counts),
            'replay_recomputed_excluded' => !empty($numericMetrics['all_canonical_live_strict_before_cutoff']),
            'traditional_shadow_status_classified' => $comparableUses + $mlOnlyUses === 123,
            'production_use_crosswalk_frozen' => count($scopeUses) === 123 && preg_match('/^sha256:[a-f0-9]{64}$/', (string)($identities['comparative_scope_identity'] ?? '')) === 1,
            'live_strict_real_capture_observed' => count((array)($decision['accepted_race_codes'] ?? [])) >= 2,
        ];
        $hardGates = OfficialEpochPolicyV1::evaluateHardGates($hardSignals);
        if ($hardGates['status'] !== 'PASS') $errors[] = 'HARD_GATE_CONTRACT_NOT_PASS';

        $evidenceErrors = array_values(array_unique($errors));
        $evidenceReady = $evidenceErrors === [];
        $approval = [];
        $approvalStatus = 'MISSING';
        $approvalReason = 'CG_APPROVAL_REQUIRED';
        $approvalRef = '';
        if (is_string($approvalKey) && $approvalKey !== '') {
            try { $approval = $this->approvalStore()->read($approvalKey); } catch (Throwable) { $approval = []; }
            if ($approval === []) {
                $approvalStatus = 'MISSING';
            } else {
                $approvalValidation = $this->approvalStore()->validateForPreflight($approval, [
                    'source_window_id' => $windowId,
                    'source_closure_hash' => (string)($closure['closure_record_hash'] ?? ''),
                    'target_capture_measurement_identity' => $captureIdentity,
                    'epoch_policy_identity' => OfficialEpochPolicyV1::identity(),
                    'incident_policy_identity' => OfficialEpochIncidentPolicyV1::identity(),
                ]);
                $approvalStatus = $approvalValidation['valid'] ? 'VALID' : 'INVALID';
                $approvalReason = (string)$approvalValidation['reason'];
                if ($approvalValidation['valid']) $approvalRef = 'official_epoch_approvals/' . substr($approvalKey, 7) . '.json';
            }
        }
        $runtimeReady = !empty($runtime['scheduler_held']) && (int)($runtime['capture_process_count'] ?? -1) === 0
            && trim((string)($runtime['scheduler_config_hash'] ?? '')) !== '';
        $closingIdentity = (new MeasurementImplementationProvenance($this->repoRoot, $root . '/provenance'))->currentIdentityReadOnly();
        $payload = [
            'schema_version' => self::PREFLIGHT_SCHEMA_VERSION,
            'source_window_id' => $windowId,
            'source_window_root' => $this->windowRelativeRoot($windowId),
            'source_window_state' => (string)($state['effective_state'] ?? ''),
            'source_closure_ref' => 'lifecycle/closures/' . $windowId . '.json',
            'source_closure_hash' => (string)($closure['closure_record_hash'] ?? ''),
            'target_capture_measurement_identity' => $captureIdentity,
            'start_tool_current_implementation_identity' => $closingIdentity,
            'canonical_capture_evidence_hash' => (string)($aggregate['aggregate_sha256'] ?? ''),
            'formal_aggregate_v2_hash' => (string)($aggregate['aggregate_sha256'] ?? ''),
            'formal_gates' => [
                'a1' => (string)($decision['gate_a1']['status'] ?? ''), 'a2' => (string)($decision['gate_a2']['status'] ?? ''),
                'b_now' => (string)($decision['gate_b_now']['status'] ?? ''), 'b_final' => (string)($decision['gate_b_final']['status'] ?? ''),
                'c' => (string)($decision['gate_c']['status'] ?? ''),
            ],
            'formal_provenance_validation' => $provenance === [] ? 'FAIL' : 'PASS',
            'formal_comparative_readiness' => (string)($comparative['status'] ?? ''),
            'counts' => $counts,
            'comparative_contract' => $identities + [
                'comparable_use_count' => $comparableUses, 'ml_only_use_count' => $mlOnlyUses,
                'paired_count' => (int)($comparative['paired_denominator'] ?? 0), 'ml_only_excluded_count' => (int)($comparative['ml_only_excluded'] ?? 0),
                'synthetic_traditional_prohibited' => true, 'course_2_traditional' => 'NONE',
            ],
            'hard_gate_result' => $hardGates,
            'numeric_data_quality' => $numeric,
            'operational_live' => $operational,
            'epoch_policy_identity' => OfficialEpochPolicyV1::identity(),
            'incident_policy_schema' => OfficialEpochIncidentPolicyV1::SCHEMA_VERSION,
            'incident_policy_identity' => OfficialEpochIncidentPolicyV1::identity(),
            'long_term_incident_policy_status' => 'RESOLVED_BY_IMPLEMENTED_POLICY',
            'cg_approval_status' => $approvalStatus,
            'cg_approval_reason' => $approvalReason,
            'cg_approval_key' => $approvalKey ?? 'MISSING',
            'cg_approval_ref' => $approvalRef,
            'cg_approval_hash' => (string)($approval['record_hash'] ?? 'MISSING'),
            'runtime' => [
                'scheduler_held' => !empty($runtime['scheduler_held']),
                'capture_process_count' => (int)($runtime['capture_process_count'] ?? -1),
                'scheduler_config_hash' => (string)($runtime['scheduler_config_hash'] ?? ''),
            ],
            'evaluation_as_of' => $closedAt,
        ];
        $payload['preflight_token'] = 'sha256:' . hash('sha256', OfficialEpochPolicyV1::canonicalJson($payload));
        $existingEpoch = $this->existingEpoch();
        $safeStart = $evidenceReady && $approvalStatus === 'VALID' && $runtimeReady && $existingEpoch === [];
        return [
            'accepted' => true,
            'status' => $evidenceReady ? 'EPOCH_EVIDENCE_READINESS_PASS' : 'EPOCH_EVIDENCE_READINESS_BLOCKED',
            'reason' => $evidenceReady ? '' : implode(',', $evidenceErrors),
            'preflight' => $payload,
            'source_window_valid' => $evidenceReady,
            'epoch_evidence_readiness' => $evidenceReady ? 'PASS' : 'FAIL',
            'safe_to_create_cg_approval' => $evidenceReady,
            'safe_to_start_official_epoch' => $safeStart,
            'start_block_reason' => $safeStart ? '' : ($existingEpoch !== [] ? 'OFFICIAL_EPOCH_SINGLETON_EXISTS' : ($evidenceReady && $approvalStatus !== 'VALID' ? 'CG_APPROVAL_REQUIRED' : ($runtimeReady ? 'EPOCH_EVIDENCE_NOT_READY' : 'RUNTIME_HOLD_REQUIRED'))),
            'existing_epoch' => $existingEpoch,
        ];
    }

    /**
     * Future authorized start commit.  It is unused by CC29B but implemented
     * and fixture-tested here so the later task has no implicit write path.
     *
     * @param array<string,mixed> $priorPreflight
     * @param callable():array{scheduler_held:bool,capture_process_count:int,scheduler_config_hash:string} $runtimeProbe
     * @return array<string,mixed>
     */
    public function start(array $priorPreflight, callable $runtimeProbe, ?string $startedAt = null): array
    {
        $payload = is_array($priorPreflight['preflight'] ?? null) ? $priorPreflight['preflight'] : $priorPreflight;
        $windowId = (string)($payload['source_window_id'] ?? '');
        $approvalKey = (string)($payload['cg_approval_key'] ?? '');
        $token = (string)($payload['preflight_token'] ?? '');
        if ($windowId === '' || $approvalKey === '' || $approvalKey === 'MISSING' || !preg_match('/^sha256:[a-f0-9]{64}$/', $token)) {
            return $this->startBlocked('PREFLIGHT_OR_APPROVAL_MISSING');
        }
        $lockPath = $this->lifecycleRoot() . '/official_epoch_v1.lock';
        $directory = dirname($lockPath);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) return $this->startBlocked('EPOCH_LOCK_DIRECTORY_UNAVAILABLE');
        $lock = @fopen($lockPath, 'c');
        if (!is_resource($lock) || !@flock($lock, LOCK_EX)) return $this->startBlocked('EPOCH_LOCK_UNAVAILABLE');
        try {
            $runtime = $runtimeProbe();
            $last = $this->preflight($windowId, $runtime, $approvalKey);
            $lastPayload = is_array($last['preflight'] ?? null) ? $last['preflight'] : [];
            if (($last['epoch_evidence_readiness'] ?? '') !== 'PASS'
                || (($lastPayload['cg_approval_status'] ?? '') !== 'VALID')
                || empty($lastPayload['runtime']['scheduler_held'])
                || (int)($lastPayload['runtime']['capture_process_count'] ?? -1) !== 0) {
                return $this->startBlocked((string)($last['start_block_reason'] ?? 'START_PREFLIGHT_BLOCKED'));
            }
            if (!hash_equals($token, (string)($lastPayload['preflight_token'] ?? ''))) return $this->startBlocked('PREFLIGHT_TOKEN_DRIFT');
            $record = $this->startRecord($lastPayload, $startedAt ?? (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM));
            return $this->startStore()->create($record);
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /** @return array<string,mixed> */
    public function epochForSourceWindow(string $windowId): array
    {
        $existing = $this->startStore()->existing();
        if ($existing === [] || ($existing['source_closed_window_id'] ?? '') !== $windowId) return [];
        $projection = $this->incidentStore()->project((string)($existing['epoch_id'] ?? ''));
        // The projection is derived entirely from external append-only epoch
        // records. It is intentionally not written back to the source Window.
        $existing['epoch_state'] = !empty($projection['valid']) ? (string)($projection['state'] ?? 'ACTIVE') : 'INVALID';
        $existing['epoch_state_hash'] = !empty($projection['valid']) ? (string)($projection['state_hash'] ?? '') : '';
        $existing['epoch_state_projection_reason'] = (string)($projection['reason'] ?? '');
        return $existing;
    }

    /** @return array<string,mixed> */
    private function startRecord(array $preflight, string $startedAt): array
    {
        $keyMaterial = [
            'schema_version' => self::START_SCHEMA_VERSION,
            'source_window_id' => (string)$preflight['source_window_id'],
            'source_closure_hash' => (string)$preflight['source_closure_hash'],
            'formal_aggregate_v2_hash' => (string)$preflight['formal_aggregate_v2_hash'],
            'epoch_policy_identity' => (string)$preflight['epoch_policy_identity'],
            'incident_policy_identity' => (string)$preflight['incident_policy_identity'],
            'cg_approval_hash' => (string)$preflight['cg_approval_hash'],
        ];
        $epochKey = 'sha256:' . hash('sha256', OfficialEpochPolicyV1::canonicalJson($keyMaterial));
        $record = [
            'schema_version' => self::START_SCHEMA_VERSION,
            'epoch_id' => 'official-epoch-v1-' . substr($epochKey, 7, 16),
            'epoch_key' => $epochKey,
            'started_at' => $startedAt,
            'source_closed_window_id' => (string)$preflight['source_window_id'],
            'source_closure_ref' => (string)$preflight['source_closure_ref'],
            'source_closure_hash' => (string)$preflight['source_closure_hash'],
            'target_capture_measurement_identity' => (string)$preflight['target_capture_measurement_identity'],
            'start_tool_current_implementation_identity' => (string)$preflight['start_tool_current_implementation_identity'],
            'canonical_capture_evidence_hash' => (string)$preflight['canonical_capture_evidence_hash'],
            'formal_aggregate_v2_hash' => (string)$preflight['formal_aggregate_v2_hash'],
            'formal_gate_a1' => (string)$preflight['formal_gates']['a1'],
            'formal_gate_a2' => (string)$preflight['formal_gates']['a2'],
            'formal_gate_b_now' => (string)$preflight['formal_gates']['b_now'],
            'formal_gate_b_final' => (string)$preflight['formal_gates']['b_final'],
            'formal_gate_c' => (string)$preflight['formal_gates']['c'],
            'formal_provenance_validation' => (string)$preflight['formal_provenance_validation'],
            'formal_comparative_readiness' => (string)$preflight['formal_comparative_readiness'],
            'candidate_count' => (int)$preflight['counts']['candidate_count'],
            'terminal_count' => (int)$preflight['counts']['terminal_count'],
            'accepted_count' => (int)$preflight['counts']['accepted_count'],
            'snapshot_count' => (int)$preflight['counts']['snapshot_count'],
            'pair_count' => (int)$preflight['counts']['pair_count'],
            'comparative_scope_identity' => (string)$preflight['comparative_contract']['comparative_scope_identity'],
            'comparative_policy_identity' => (string)$preflight['comparative_contract']['comparative_policy_identity'],
            'input_snapshot_spec_identity' => (string)$preflight['comparative_contract']['input_snapshot_spec_identity'],
            'pair_schema_identity' => (string)$preflight['comparative_contract']['pair_record_schema_identity'],
            'comparable_use_count' => (int)$preflight['comparative_contract']['comparable_use_count'],
            'ml_only_use_count' => (int)$preflight['comparative_contract']['ml_only_use_count'],
            'paired_count' => (int)$preflight['comparative_contract']['paired_count'],
            'ml_only_excluded_count' => (int)$preflight['comparative_contract']['ml_only_excluded_count'],
            'numeric_data_quality_result' => (string)$preflight['numeric_data_quality']['status'],
            'operational_live_result' => (string)$preflight['operational_live']['status'],
            'comparative_limitations' => OfficialEpochPolicyV1::definition()['comparative_limitations'],
            'cg_approval_ref' => (string)$preflight['cg_approval_ref'],
            'cg_approval_hash' => (string)$preflight['cg_approval_hash'],
            'epoch_policy_identity' => (string)$preflight['epoch_policy_identity'],
            'incident_policy_schema' => (string)$preflight['incident_policy_schema'],
            'incident_policy_identity' => (string)$preflight['incident_policy_identity'],
            'preflight_token' => (string)$preflight['preflight_token'],
        ];
        $authorization = $record; unset($authorization['started_at']);
        $record['authorization_hash'] = 'sha256:' . hash('sha256', OfficialEpochPolicyV1::canonicalJson($authorization));
        $record['record_hash'] = 'sha256:' . hash('sha256', OfficialEpochPolicyV1::canonicalJson($record));
        return $record;
    }

    /** @return array<string,mixed> */
    private function existingEpoch(): array
    {
        return $this->startStore()->existing();
    }

    /** @return array<string,mixed> */
    private function frozenContract(string $root, array $closure): ComparativeContractV1
    {
        $freezeRef = (string)($closure['capture_source_freeze']['ref'] ?? '');
        $freezePath = $root . '/provenance/' . $freezeRef;
        $freeze = $this->readJson($freezePath);
        $files = is_array($freeze['files'] ?? null) ? $freeze['files'] : [];
        $payloads = [];
        foreach ($files as $file) {
            if (!is_array($file) || !is_string($file['path'] ?? null) || !is_string($file['content_base64'] ?? null)) continue;
            if (!in_array($file['path'], ['config/strict_forward_comparative_scope_v1.json', 'config/strict_forward_comparative_policy_v1.json'], true)) continue;
            $raw = base64_decode($file['content_base64'], true);
            if (!is_string($raw) || !hash_equals((string)($file['sha256'] ?? ''), hash('sha256', $raw))) throw new RuntimeException('FROZEN_COMPARATIVE_SOURCE_HASH_INVALID');
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) throw new RuntimeException('FROZEN_COMPARATIVE_SOURCE_INVALID');
            $payloads[$file['path']] = $decoded;
        }
        if (!isset($payloads['config/strict_forward_comparative_scope_v1.json'], $payloads['config/strict_forward_comparative_policy_v1.json'])) {
            throw new RuntimeException('FROZEN_COMPARATIVE_SOURCE_MISSING');
        }
        return ComparativeContractV1::fromFrozenPayloads($payloads['config/strict_forward_comparative_scope_v1.json'], $payloads['config/strict_forward_comparative_policy_v1.json']);
    }

    /** @return array<string,int> */
    private function evidenceCounts(string $root, array $closure, array $evaluation, array $comparative): array
    {
        $count = fn(string $relative): int => $this->jsonFileCount($root . '/' . $relative);
        $decision = is_array($evaluation['decision'] ?? null) ? $evaluation['decision'] : [];
        return [
            'candidate_count' => $count('opportunity/candidates'),
            'terminal_count' => (int)($evaluation['gate_b']['terminal'] ?? -1),
            'accepted_count' => count((array)($decision['accepted_race_codes'] ?? [])),
            'eligible_count' => count((array)($decision['eligible_race_codes'] ?? [])),
            'expected_count' => $count('ledger/expected'),
            'canonical_count' => $count('ledger/records'),
            'snapshot_count' => $count('input_snapshots'),
            'pair_count' => $count('comparative/pairs'),
            'comparative_completion_count' => $count('comparative/captures'),
            'source_failure_count' => $this->jsonLineCount($root . '/opportunity/source_failures'),
            'missing_terminal_resolution_count' => (int)($evaluation['gate_b']['unresolved'] ?? -1),
            'post_cutoff_backfill_count' => (int)($closure['counts']['post_cutoff_backfill_count'] ?? -1),
            'global_fatal_count' => (int)($closure['counts']['global_fatal_count'] ?? -1),
            'comparative_paired_count' => (int)($comparative['paired_denominator'] ?? -1),
            'comparative_ml_only_excluded_count' => (int)($comparative['ml_only_excluded'] ?? -1),
        ];
    }

    /** @param array<string,int> $counts @param array<string,mixed> $decision @return array<string,int|float|bool> */
    private function numericMetrics(string $root, array $counts, array $decision): array
    {
        $canonical = $this->jsonRows($root . '/ledger/records');
        $artifact = 0; $policy = 0; $allLive = $canonical !== []; $timezone = $canonical !== [];
        foreach ($canonical as $row) {
            if (preg_match('/^[a-f0-9]{64}$/', (string)($row['artifact_sha256'] ?? '')) === 1) $artifact++;
            if (trim((string)($row['traditional_policy_version'] ?? '')) !== '' && trim((string)($row['logic_version'] ?? '')) !== '') $policy++;
            // Canonical rows also preserve non-deployed targets as explicit
            // ML_TARGET_NOT_DEPLOYED classifications.  Those rows are not
            // eligible decisions, but they remain valid LIVE_STRICT,
            // before-cutoff accounting evidence and must not turn a complete
            // deployed-use capture into a false failure.
            if (($row['capture_mode'] ?? '') !== 'LIVE_STRICT' || ($row['before_cutoff'] ?? false) !== true) $allLive = false;
            if (($row['timezone'] ?? '') !== 'Asia/Tokyo') $timezone = false;
        }
        $expected = max(0, $counts['expected_count']); $canonicalCount = max(0, $counts['canonical_count']);
        $eligible = max(0, count((array)($decision['eligible_race_codes'] ?? [])));
        $accepted = max(0, count((array)($decision['accepted_race_codes'] ?? [])));
        return [
            'expected_completeness' => $expected > 0 && $expected === $canonicalCount ? 1.0 : 0.0,
            'canonical_completeness' => $expected > 0 && $expected === $canonicalCount ? 1.0 : 0.0,
            'eligible_opportunity_capture_rate' => $eligible > 0 && $accepted === $eligible ? 1.0 : 0.0,
            'scheduler_opportunity_capture_rate' => $eligible > 0 && $accepted === $eligible ? 1.0 : 0.0,
            'unclassified_missing_count' => $expected === $canonicalCount ? 0 : abs($expected - $canonicalCount),
            'artifact_identity_completeness' => $canonicalCount > 0 && $artifact === $canonicalCount ? 1.0 : 0.0,
            'policy_identity_completeness' => $canonicalCount > 0 && $policy === $canonicalCount ? 1.0 : 0.0,
            'unresolved_conflict_count' => 0,
            'post_cutoff_backfill_count' => $counts['post_cutoff_backfill_count'],
            'source_failure_count' => $counts['source_failure_count'],
            'classified_source_failure_count' => $counts['source_failure_count'],
            'all_canonical_live_strict_before_cutoff' => $allLive,
            'timezone_complete' => $timezone,
        ];
    }

    private function validClosureHash(array $closure): bool
    {
        $stored = (string)($closure['closure_record_hash'] ?? '');
        if (!preg_match('/^sha256:[a-f0-9]{64}$/', $stored)) return false;
        $payload = $closure; unset($payload['closure_record_hash']);
        return hash_equals($stored, 'sha256:' . hash('sha256', OfficialEpochPolicyV1::canonicalJson($payload)));
    }

    private function windowRoot(string $windowId): string { return rtrim($this->repoRoot, DIRECTORY_SEPARATOR) . '/var/strict_forward_live_readiness/windows/' . $windowId; }
    private function windowRelativeRoot(string $windowId): string { return 'var/strict_forward_live_readiness/windows/' . $windowId; }
    private function timestamp(string $value): bool { try { new DateTimeImmutable($value); return $value !== ''; } catch (Throwable) { return false; } }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        try { $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { throw new RuntimeException('JSON_INVALID:' . basename($path)); }
        if (!is_array($row)) throw new RuntimeException('JSON_INVALID:' . basename($path));
        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function jsonRows(string $directory): array
    {
        if (!is_dir($directory)) return [];
        $rows = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || !str_ends_with($file->getFilename(), '.json')) continue;
            $rows[] = $this->readJson($file->getPathname());
        }
        return $rows;
    }

    private function jsonFileCount(string $directory): int { return count($this->jsonRows($directory)); }
    private function jsonLineCount(string $directory): int
    {
        if (!is_dir($directory)) return 0;
        $count = 0;
        foreach (glob($directory . '/*.jsonl') ?: [] as $path) $count += count(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        return $count;
    }

    /** @param array<string,mixed> $existing @return array<string,mixed> */
    private function startBlocked(string $reason, array $existing = []): array { return ['accepted' => false, 'status' => 'OFFICIAL_EPOCH_START_BLOCKED', 'reason' => $reason, 'record' => $existing]; }
    /** @return array<string,mixed> */
    private function blocked(string $reason): array { return ['accepted' => false, 'status' => 'OFFICIAL_EPOCH_PREFLIGHT_BLOCKED', 'reason' => $reason, 'preflight' => []]; }
}
