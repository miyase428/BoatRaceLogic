<?php

declare(strict_types=1);

require_once __DIR__ . '/FormalMeasurementWindowStatusInspector.php';
require_once __DIR__ . '/FormalEvaluationRecordStore.php';

/**
 * Formal Evaluator v2.
 *
 * Evaluation is pure: it fixes an evidence snapshot, calculates A1/A2/B/C
 * against an explicit as-of time, and verifies that the same snapshot still
 * exists before returning. Persisting a decision is an opt-in operation of
 * FormalEvaluationRecordStore, outside the Window.
 */
final class FormalEvaluatorV2
{
    public const SPEC_VERSION = 'strict-forward-formal-evaluator/v2';
    private const REQUIRED_ELIGIBLE_RACES = 2;

    /** @var null|Closure():void */
    private readonly ?Closure $beforeEndSnapshot;

    /** @param null|callable():void $beforeEndSnapshot fixture-only race injector */
    public function __construct(private readonly string $repoRoot, ?callable $beforeEndSnapshot = null)
    {
        $this->beforeEndSnapshot = $beforeEndSnapshot === null ? null : Closure::fromCallable($beforeEndSnapshot);
    }

    /** @return array<string,mixed> */
    public function evaluateProduction(string $evaluationAsOf): array
    {
        // Establish production routing through the same pure projection used by
        // the status API.  It is deliberately not a write authorization and
        // its wall-clock gate values are not substituted for this evaluator's
        // explicit evaluation_as_of calculation below.
        $projection = (new FormalMeasurementWindowStatusInspector($this->repoRoot))->inspect();
        $context = FormalMeasurementWindowContext::production($this->repoRoot);
        if (!hash_equals((string)($projection['window_id'] ?? ''), $context->windowId())) {
            throw new RuntimeException('formal_evaluator_status_context_mismatch');
        }
        $evaluation = $this->evaluate($context, $evaluationAsOf);
        $evaluation['pure_status_projection_used'] = true;
        return $evaluation;
    }

    /**
     * Pure historical evaluation.  The supplied Window id is resolved below
     * the immutable Window store; current pointer/configuration are excluded.
     *
     * @return array<string,mixed>
     */
    public function evaluateWindow(string $windowId, string $evaluationAsOf): array
    {
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $windowId)) throw new InvalidArgumentException('formal_evaluation_window_id_invalid');
        $root = rtrim($this->repoRoot, DIRECTORY_SEPARATOR) . '/var/strict_forward_live_readiness/windows/' . $windowId;
        if (!is_dir($root)) throw new RuntimeException('formal_evaluation_window_missing');
        return $this->evaluateHistorical(FormalMeasurementWindowContext::fromWindowRoot($this->repoRoot, $root), $evaluationAsOf);
    }

    /** @return array<string,mixed> */
    public function evaluate(FormalMeasurementWindowContext $context, string $evaluationAsOf): array
    {
        return $this->evaluateContext($context, $evaluationAsOf, false);
    }

    /** @return array<string,mixed> */
    public function evaluateHistorical(FormalMeasurementWindowContext $context, string $evaluationAsOf): array
    {
        return $this->evaluateContext($context, $evaluationAsOf, true);
    }

    /** @return array<string,mixed> */
    private function evaluateContext(FormalMeasurementWindowContext $context, string $evaluationAsOf, bool $historical): array
    {
        if (!$this->timestamp($evaluationAsOf)) throw new InvalidArgumentException('formal_evaluation_as_of_invalid');
        $resolved = $context->resolve($historical);
        if (empty($resolved['accepted'])) throw new RuntimeException('formal_evaluation_context_unavailable');
        $windowId = (string)$resolved['window_id'];
        $manifest = is_array($resolved['manifest'] ?? null) ? $resolved['manifest'] : [];
        $lifecycle = $context->lifecycle()->effectiveState($windowId, $manifest);
        if (($lifecycle['effective_state'] ?? '') === 'ABORTED') {
            $abort = is_array($lifecycle['abort'] ?? null) ? $lifecycle['abort'] : [];
            return [
                'ok' => false, 'status' => 'WINDOW_ABORTED', 'reason' => (string)($abort['abort_reason'] ?? 'WINDOW_ABORTED'),
                'window_id' => $windowId, 'evaluation_as_of' => $evaluationAsOf,
                'window_completion_status' => 'ABORTED', 'operational_gate_c_status' => 'ABORTED',
                'gate_a1' => (string)($abort['gate_a1'] ?? ''), 'gate_a2' => (string)($abort['gate_a2'] ?? ''),
                'gate_b_final' => (string)($abort['gate_b_final'] ?? ''), 'gate_c' => 'ABORTED',
                'abort_record_hash' => (string)($abort['abort_record_hash'] ?? ''),
            ];
        }
        $armedAt = (string)($manifest['armed_at'] ?? '');
        if (!$this->timestamp($armedAt)) throw new RuntimeException('formal_evaluation_armed_at_invalid');

        $start = $this->snapshot($context, $evaluationAsOf, $historical);
        $result = $this->evaluateSnapshot($context, $evaluationAsOf, $start, $historical);
        if ($this->beforeEndSnapshot !== null) ($this->beforeEndSnapshot)();
        $end = $this->snapshot($context, $evaluationAsOf, $historical);
        if (!hash_equals((string)$start['snapshot_hash'], (string)$end['snapshot_hash'])) {
            return [
                'ok' => false, 'status' => 'EVIDENCE_SNAPSHOT_RACE', 'reason' => 'FORMAL_EVIDENCE_CHANGED_DURING_EVALUATION',
                'window_id' => $windowId, 'evaluation_as_of' => $evaluationAsOf,
                'snapshot_before' => $start['snapshot_hash'], 'snapshot_after' => $end['snapshot_hash'],
            ];
        }
        return $result;
    }

    /** @param array<string,mixed> $evaluation @return array{accepted:bool,status:string,reason:string,record:array<string,mixed>} */
    public function persist(array $evaluation): array
    {
        if (empty($evaluation['ok']) || ($evaluation['status'] ?? '') !== 'EVALUATED') {
            return ['accepted' => false, 'status' => 'FORMAL_EVALUATION_RECORD_BLOCKED', 'reason' => 'FORMAL_EVALUATION_NOT_COMPLETE', 'record' => []];
        }
        return (new FormalEvaluationRecordStore($this->repoRoot))->create($this->recordPayload($evaluation));
    }

    /** @return string */
    public static function evaluatorIdentity(string $repoRoot): string
    {
        $files = [];
        foreach ([
            'web/logic/FormalEvaluatorV2.php',
            'web/logic/FormalEvaluationRecordStore.php',
            'analysis/evaluate_formal_measurement_v2.php',
        ] as $relative) {
            $raw = @file_get_contents(rtrim($repoRoot, DIRECTORY_SEPARATOR) . '/' . $relative);
            if (!is_string($raw)) throw new RuntimeException('formal_evaluator_source_missing:' . $relative);
            $files[] = ['path' => $relative, 'sha256' => hash('sha256', $raw)];
        }
        return 'sha256:' . hash('sha256', self::canonicalJsonStatic(['spec_version' => self::SPEC_VERSION, 'files' => $files]));
    }

    /** @return array<string,mixed> */
    private function evaluateSnapshot(FormalMeasurementWindowContext $context, string $asOf, array $snapshot, bool $historical): array
    {
        $resolved = $context->resolve($historical);
        $manifest = $resolved['manifest'];
        $windowId = (string)$resolved['window_id'];
        $armedAt = (string)$manifest['armed_at'];
        $declaration = $context->declaration()->read($historical);
        $activation = $context->activationHold()->readActivation();
        $implementation = is_array($declaration['implementation'] ?? null) ? $declaration['implementation'] : [];
        $declaredIdentity = (string)($implementation['implementation_identity'] ?? '');
        $activationIdentity = (string)($activation['implementation_identity'] ?? '');
        $identityMatch = $declaredIdentity !== '' && hash_equals($declaredIdentity, $activationIdentity);
        $lifecycle = $context->lifecycle()->effectiveState($windowId, $manifest);
        $closure = is_array($lifecycle['closure'] ?? null) ? $lifecycle['closure'] : [];
        $captureThrough = (string)($closure['capture_through_date'] ?? substr($asOf, 0, 10));

        $gateA1 = $this->gateA1($context, $windowId, $armedAt);
        $rows = $this->rows($context, $windowId, $armedAt, $asOf);
        $gateA2 = $this->gateA2($context, $rows, $gateA1, $identityMatch);
        $gateB = $this->gateB($context, $rows, $asOf, $captureThrough, !empty($closure));
        $gateC = $this->gateC($windowId, $rows, $gateA1, $gateA2, $gateB, $implementation, $identityMatch);
        $completion = $gateA1['status'] === 'PASS' && $gateA2['status'] === 'PASS'
            && $gateB['final_status'] === 'PASS' && $gateC['status'] === 'PASS' ? 'PASS' : 'PENDING';

        $raceEvidence = ['eligible_race_codes' => $gateC['eligible_race_codes'], 'accepted_race_codes' => $gateC['accepted_race_codes'], 'reasons' => $gateC['race_reasons']];
        $raceEvidenceHash = 'sha256:' . hash('sha256', self::canonicalJsonStatic($raceEvidence));
        $evaluatorIdentity = self::evaluatorIdentity($this->repoRoot);
        $declaredComparative = is_array($declaration['declaration']['comparative_contract'] ?? null)
            ? $declaration['declaration']['comparative_contract'] : [];
        $comparativeIdentity = (string)($declaredComparative['comparative_policy_identity']
            ?? $closure['comparative_policy_identity'] ?? 'NOT_APPLICABLE_BOOTSTRAP');
        $keyMaterial = [
            'window_id' => $windowId, 'measurement_declared_identity' => $declaredIdentity,
            'comparative_policy_identity' => $comparativeIdentity, 'spec_version' => self::SPEC_VERSION,
            'formal_evidence_aggregate_sha256' => $snapshot['aggregate']['aggregate_sha256'],
            'evaluation_as_of' => $asOf,
        ];
        $evaluationKey = 'sha256:' . hash('sha256', self::canonicalJsonStatic($keyMaterial));
        $decision = [
            'schema_version' => FormalEvaluationRecordStore::SCHEMA_VERSION, 'spec_version' => self::SPEC_VERSION,
            'window_id' => $windowId, 'armed_at' => $armedAt, 'capture_through_date' => $captureThrough,
            'measurement_declared_identity' => $declaredIdentity,
            'measurement_activation_identity' => $activationIdentity,
            'evaluator_implementation_identity' => $evaluatorIdentity,
            'comparative_policy_identity' => $comparativeIdentity,
            'closure_record_hash' => (string)($closure['closure_record_hash'] ?? ''),
            'formal_evidence_aggregate_version' => (string)$snapshot['aggregate']['schema_version'],
            'formal_evidence_aggregate_sha256' => (string)$snapshot['aggregate']['aggregate_sha256'],
            'formal_evidence_file_count' => (int)$snapshot['aggregate']['file_count'],
            'evaluation_as_of' => $asOf,
            'gate_a1' => ['status' => $gateA1['status'], 'reason' => $gateA1['reason']],
            'gate_a2' => ['status' => $gateA2['status'], 'reason' => $gateA2['reason'], 'causal_binding' => $gateA2['causal_binding']],
            'gate_b_now' => ['status' => $gateB['now_status'], 'reason' => $gateB['now_reason']],
            'gate_b_final' => ['status' => $gateB['final_status'], 'reason' => $gateB['final_reason']],
            'gate_c' => ['status' => $gateC['status'], 'reason' => $gateC['reason']],
            'operational_gate_c_status' => $gateC['status'], 'window_completion_status' => $completion,
            'eligible_race_codes' => $gateC['eligible_race_codes'], 'accepted_race_codes' => $gateC['accepted_race_codes'],
            'race_evidence_hash' => $raceEvidenceHash, 'official_epoch' => 'NO',
            'evaluation_key' => $evaluationKey,
        ];
        $decision['decision_hash'] = 'sha256:' . hash('sha256', self::canonicalJsonStatic($decision));
        return [
            'ok' => true, 'status' => 'EVALUATED', 'reason' => '', 'decision' => $decision,
            'snapshot_hash' => $snapshot['snapshot_hash'], 'gate_a1' => $gateA1, 'gate_a2' => $gateA2,
            'gate_b' => $gateB, 'gate_c' => $gateC,
        ];
    }

    /** @return array{status:string,reason:string} */
    private function gateA1(FormalMeasurementWindowContext $context, string $windowId, string $armedAt): array
    {
        $base = (new GateA1ProspectiveInventory($context->opportunityRoot()))->compare($windowId, $armedAt);
        if (($base['status'] ?? '') !== 'PASS') return ['status' => (string)$base['status'], 'reason' => (string)$base['reason']];
        $expected = [];
        foreach ($base['expected_rows'] as $row) $expected[(string)$row['canonical_key']] = $row;
        foreach ($base['observed_rows'] as $row) {
            $key = (string)($row['canonical_key'] ?? '');
            $original = $expected[$key] ?? [];
            if ($original === [] || !hash_equals((string)$original['race_code'], (string)($row['race_code'] ?? ''))
                || !hash_equals((string)$original['venue'], (string)($row['venue'] ?? ''))
                || !hash_equals((string)$original['cutoff_timestamp'], (string)($row['cutoff_timestamp'] ?? ''))
                || !hash_equals((string)$original['stable_candidate_key'], (string)($row['stable_candidate_key'] ?? ''))) {
                return ['status' => 'FAIL', 'reason' => 'CANDIDATE_EXPECTED_CUTOFF_OR_IDENTITY_MISMATCH'];
            }
        }
        return ['status' => 'PASS', 'reason' => 'EXPECTED_CANDIDATE_CANONICAL_MATCH'];
    }

    /** @param array<string,mixed> $rows @param array{status:string,reason:string} $gateA1 @return array{status:string,reason:string,causal_binding:string} */
    private function gateA2(FormalMeasurementWindowContext $context, array $rows, array $gateA1, bool $identityMatch): array
    {
        if (!$identityMatch) return ['status' => 'FAIL', 'reason' => 'DECLARED_ACTIVATION_IMPLEMENTATION_MISMATCH', 'causal_binding' => 'BLOCKED_SOURCE_ORDER'];
        if ($gateA1['status'] !== 'PASS') return ['status' => 'FAIL', 'reason' => 'A1_NOT_PASS', 'causal_binding' => 'UNVERIFIED_SOURCE_ORDER'];
        if ($rows['source_failures'] !== []) return ['status' => 'FAIL', 'reason' => 'SOURCE_FAILURE_PRESENT', 'causal_binding' => 'UNVERIFIED_SOURCE_ORDER'];
        foreach ($rows['revision_events'] as $event) {
            if (in_array((string)($event['revision_type'] ?? ''), ['STALE_OR_OUT_OF_ORDER_SOURCE', 'SEMANTIC_REVISION_UNVERIFIED'], true)) {
                return ['status' => 'FAIL', 'reason' => 'SCHEDULE_REVISION_INTEGRITY_FAILURE', 'causal_binding' => 'UNVERIFIED_SOURCE_ORDER'];
            }
        }
        foreach ($rows['candidates'] as $candidate) {
            $source = (string)($candidate['source_identity'] ?? '');
            $generated = (string)($candidate['source_generated_at'] ?? '');
            $recorded = (string)($candidate['universe_recorded_at'] ?? '');
            $raceCode = (string)($candidate['race_code'] ?? '');
            if (!str_starts_with($source, 'sha256:') || !$this->timestamp($generated) || !$this->timestamp($recorded)
                || (string)($candidate['stable_candidate_key'] ?? '') !== 'scheduler-opportunity|' . $raceCode) {
                return ['status' => 'UNVERIFIED_SOURCE_ORDER', 'reason' => 'CAUSAL_BUNDLE_INCOMPLETE', 'causal_binding' => 'UNVERIFIED_SOURCE_ORDER'];
            }
            if (new DateTimeImmutable($generated) > new DateTimeImmutable($recorded)) {
                return ['status' => 'BLOCKED_SOURCE_ORDER', 'reason' => 'SOURCE_GENERATED_AFTER_UNIVERSE_RECORDED', 'causal_binding' => 'BLOCKED_SOURCE_ORDER'];
            }
            $effective = $rows['ledger']->resolveEffectiveSchedule((string)$candidate['target_date'], $raceCode);
            if ($effective === [] || (string)($effective['schedule_revision_integrity'] ?? '') !== 'PASS'
                || !str_starts_with((string)($effective['semantic_fingerprint'] ?? ''), 'sha256:')) {
                return ['status' => 'FAIL', 'reason' => 'EFFECTIVE_SCHEDULE_OR_PROVENANCE_INVALID', 'causal_binding' => 'UNVERIFIED_SOURCE_ORDER'];
            }
        }
        return ['status' => 'PASS', 'reason' => 'IMMUTABLE_CAUSAL_BUNDLE_VERIFIED', 'causal_binding' => 'CAUSAL_BINDING_PASS'];
    }

    /** @param array<string,mixed> $rows @return array{now_status:string,now_reason:string,final_status:string,final_reason:string,pre_cutoff_pending:int,terminal:int,unresolved:int} */
    private function gateB(FormalMeasurementWindowContext $context, array $rows, string $asOf, string $captureThrough, bool $closurePresent): array
    {
        $terminal = 0; $unresolved = 0; $gaps = 0; $pending = 0; $allPassed = true;
        foreach ($rows['candidates'] as $candidate) {
            $raceCode = (string)$candidate['race_code']; $date = (string)$candidate['target_date'];
            $effective = $rows['ledger']->resolveEffectiveSchedule($date, $raceCode);
            $cutoff = (string)($effective['effective_cutoff_timestamp'] ?? '');
            $status = (string)($effective['effective_official_status'] ?? '');
            if ($cutoff === '' || $status === '') { $gaps++; $allPassed = false; continue; }
            if ($this->before($asOf, $cutoff)) { $pending++; $allPassed = false; continue; }
            $eligibility = (string)($rows['resolution_by_race'][$raceCode]['eligibility_status'] ?? 'NONE');
            if ($status === 'ACTIVE' && $eligibility === 'ELIGIBILITY_UNRESOLVED') { $unresolved++; continue; }
            if ($status === 'ACTIVE' && $eligibility === 'NONE') { $gaps++; continue; }
            if ($eligibility !== 'NONE' && $eligibility !== 'ELIGIBILITY_UNRESOLVED') $terminal++;
        }
        $now = $unresolved === 0 && $gaps === 0 ? 'PASS' : 'FAIL';
        // B_FINAL is an evidence-completeness conclusion.  Lifecycle close is
        // a separate, explicit transition and must not be required merely to
        // read an already terminal capture set.
        $final = $allPassed && $unresolved === 0 && $gaps === 0 ? 'PASS' : 'PENDING_LIVE';
        return ['now_status' => $now, 'now_reason' => $now === 'PASS' ? 'PASSED_CANDIDATES_TERMINAL' : 'PASSED_CANDIDATE_UNRESOLVED_OR_GAP',
            'final_status' => $final, 'final_reason' => $final === 'PASS' ? 'CAPTURE_BOUNDARY_ALL_TERMINAL' : 'PRE_CUTOFF_OR_OPEN_BOUNDARY_PENDING',
            'pre_cutoff_pending' => $pending, 'terminal' => $terminal, 'unresolved' => $unresolved];
    }

    /** @param array<string,mixed> $rows @param array{status:string,reason:string} $a1 @param array{status:string,reason:string,causal_binding:string} $a2 @param array<string,mixed> $b @param array<string,string> $implementation @return array{status:string,reason:string,eligible_race_codes:list<string>,accepted_race_codes:list<string>,race_reasons:array<string,string>} */
    private function gateC(string $windowId, array $rows, array $a1, array $a2, array $b, array $implementation, bool $identityMatch): array
    {
        if ($a1['status'] !== 'PASS') return $this->gateCBlocked('BLOCKED_A1');
        if ($a2['status'] !== 'PASS') return $this->gateCBlocked('BLOCKED_A2');
        if (($b['now_status'] ?? '') !== 'PASS') return $this->gateCBlocked('BLOCKED_GATE_B_NOW');
        if (!$identityMatch) return $this->gateCBlocked('BLOCKED_IMPLEMENTATION_PROVENANCE');
        $eligible = []; $accepted = []; $reasons = [];
        foreach ($rows['candidates'] as $candidate) {
            $raceCode = (string)$candidate['race_code']; $date = (string)$candidate['target_date'];
            $eligibility = (string)($rows['resolution_by_race'][$raceCode]['eligibility_status'] ?? 'NONE');
            $schedule = $rows['ledger']->formalMeasurementStatus($date, $raceCode);
            if (empty($schedule['accepted']) || $eligibility !== 'ELIGIBLE_OBSERVED') continue;
            $eligible[] = $raceCode;
            $acceptedResult = StrictForwardAcceptedRace::evaluate($rows['expected_by_race'][$raceCode] ?? [], $rows['canonical_by_race'][$raceCode] ?? [], [
                'formal_schedule_accepted' => true, 'window_id' => $windowId, 'implementation' => $implementation,
                'scheduler_candidate_key' => 'scheduler-opportunity|' . $raceCode,
            ]);
            $reasons[$raceCode] = (string)$acceptedResult['reason'];
            if (!empty($acceptedResult['accepted'])) $accepted[] = $raceCode;
        }
        sort($eligible, SORT_STRING); sort($accepted, SORT_STRING); ksort($reasons, SORT_STRING);
        if (count($eligible) < self::REQUIRED_ELIGIBLE_RACES) return ['status' => 'PENDING_LIVE', 'reason' => 'INSUFFICIENT_DISTINCT_ELIGIBLE_RACES', 'eligible_race_codes' => $eligible, 'accepted_race_codes' => $accepted, 'race_reasons' => $reasons];
        if (count($accepted) !== count($eligible)) return ['status' => 'FAIL', 'reason' => 'ELIGIBLE_RACE_ACCEPTANCE_FAILURE', 'eligible_race_codes' => $eligible, 'accepted_race_codes' => $accepted, 'race_reasons' => $reasons];
        return ['status' => 'PASS', 'reason' => 'ALL_ELIGIBLE_RACES_ACCEPTED', 'eligible_race_codes' => $eligible, 'accepted_race_codes' => $accepted, 'race_reasons' => $reasons];
    }

    /** @return array{status:string,reason:string,eligible_race_codes:list<string>,accepted_race_codes:list<string>,race_reasons:array<string,string>} */
    private function gateCBlocked(string $reason): array { return ['status' => $reason, 'reason' => $reason, 'eligible_race_codes' => [], 'accepted_race_codes' => [], 'race_reasons' => []]; }

    /** @return array<string,mixed> */
    private function rows(FormalMeasurementWindowContext $context, string $windowId, string $armedAt, string $asOf): array
    {
        $opportunity = $context->opportunityRoot(); $ledgerRoot = $context->ledgerRoot();
        $a1 = (new GateA1ProspectiveInventory($opportunity))->compare($windowId, $armedAt);
        $candidates = is_array($a1['observed_rows'] ?? null) ? $a1['observed_rows'] : [];
        $inScope = fn(string $at): bool => $this->after($at, $armedAt) && !$this->before($asOf, $at);
        $resolutions = array_values(array_filter($this->jsonFiles($opportunity . '/eligibility/*/*.json'), fn(array $r): bool => $inScope((string)($r['first_eligible_observed_at'] ?? $r['resolved_at'] ?? ''))));
        $expected = array_values(array_filter($this->jsonFiles($ledgerRoot . '/expected/*/*.json'), fn(array $r): bool => $inScope((string)($r['expected_at'] ?? ''))));
        $canonical = array_values(array_filter($this->jsonFiles($ledgerRoot . '/records/*/*.json'), fn(array $r): bool => $inScope((string)($r['captured_at'] ?? '')) && (string)($r['capture_mode'] ?? '') === 'LIVE_STRICT'));
        $sourceFailures = array_values(array_filter($this->jsonLines($opportunity . '/source_failures/*.jsonl'), fn(array $r): bool => $inScope((string)($r['observed_at'] ?? ''))));
        $resolutionByRace = []; foreach ($resolutions as $r) $resolutionByRace[(string)($r['race_code'] ?? '')] = $r;
        $revisions = array_values(array_filter($this->jsonLines($opportunity . '/schedule_revisions/*/*.jsonl'), fn(array $r): bool => $inScope((string)($r['observed_at'] ?? ''))));
        return ['candidates' => $candidates, 'resolution_by_race' => $resolutionByRace, 'expected_by_race' => $this->group($expected), 'canonical_by_race' => $this->group($canonical), 'source_failures' => $sourceFailures, 'revision_events' => $revisions, 'ledger' => new StrictForwardOpportunityLedger($opportunity)];
    }

    /** @return array<string,mixed> */
    private function snapshot(FormalMeasurementWindowContext $context, string $asOf, bool $historical): array
    {
        $resolved = $context->resolve($historical); $windowId = (string)$resolved['window_id'];
        $lifecycle = $context->lifecycle()->effectiveState($windowId, $resolved['manifest']);
        $closure = is_array($lifecycle['closure'] ?? null) ? $lifecycle['closure'] : [];
        $through = (string)($closure['capture_through_date'] ?? substr($asOf, 0, 10));
        $aggregate = FormalEvidenceAggregate::calculate(dirname($context->windowManifestPath()), $windowId, $through);
        $payload = ['aggregate' => $aggregate['aggregate_sha256'], 'file_count' => $aggregate['file_count'], 'closure_record_hash' => $closure['closure_record_hash'] ?? '', 'as_of' => $asOf];
        return ['aggregate' => $aggregate, 'closure' => $closure, 'snapshot_hash' => 'sha256:' . hash('sha256', self::canonicalJsonStatic($payload))];
    }

    /** @param array<string,mixed> $evaluation @return array<string,mixed> */
    private function recordPayload(array $evaluation): array { return is_array($evaluation['decision'] ?? null) ? $evaluation['decision'] : []; }
    /** @return array<string,list<array<string,mixed>>> */
    private function group(array $rows): array { $out=[]; foreach ($rows as $r) { $race=(string)($r['race_code']??''); if($race!=='') $out[$race][]=$r; } return $out; }
    /** @return list<array<string,mixed>> */
    private function jsonFiles(string $pattern): array { $out=[]; foreach(glob($pattern)?:[] as $p){ try{$r=json_decode((string)file_get_contents($p),true,512,JSON_THROW_ON_ERROR);}catch(Throwable){throw new RuntimeException('formal_evaluator_json_invalid:'.basename($p));} if(is_array($r))$out[]=$r;} return $out; }
    /** @return list<array<string,mixed>> */
    private function jsonLines(string $pattern): array { $out=[]; foreach(glob($pattern)?:[] as $p) foreach(file($p,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){ try{$r=json_decode($line,true,512,JSON_THROW_ON_ERROR);}catch(Throwable){throw new RuntimeException('formal_evaluator_jsonl_invalid:'.basename($p));}if(is_array($r))$out[]=$r;}return $out; }
    private function timestamp(string $v): bool { try { new DateTimeImmutable($v); return $v!==''; } catch(Throwable) { return false; } }
    private function after(string $a,string $b): bool { try{return $a!==''&&$b!==''&&new DateTimeImmutable($a)>new DateTimeImmutable($b);}catch(Throwable){return false;} }
    private function before(string $a,string $b): bool { try{return $a!==''&&$b!==''&&new DateTimeImmutable($a)<new DateTimeImmutable($b);}catch(Throwable){return false;} }
    /** @param array<string,mixed> $value */
    private static function canonicalJsonStatic(array $value): string { $normal=self::sortStatic($value); $json=json_encode($normal,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); return is_string($json)?$json:'{}'; }
    private static function sortStatic(mixed $value): mixed { if(!is_array($value))return $value; if(array_is_list($value))return array_map(fn(mixed $v):mixed=>self::sortStatic($v),$value); ksort($value,SORT_STRING); foreach($value as $k=>$v)$value[$k]=self::sortStatic($v); return $value; }
}
