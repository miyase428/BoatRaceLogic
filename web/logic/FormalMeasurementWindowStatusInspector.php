<?php

declare(strict_types=1);

require_once __DIR__ . '/FormalMeasurementWindowContext.php';
require_once __DIR__ . '/GateA1ProspectiveInventory.php';
require_once __DIR__ . '/StrictForwardOpportunityLedger.php';
require_once __DIR__ . '/StrictForwardAcceptedRace.php';
require_once __DIR__ . '/StrictForwardMaterializationDiagnostic.php';
require_once __DIR__ . '/OfficialEpochLifecycleV1.php';
require_once __DIR__ . '/OfficialEpochWindowBindingResolver.php';
require_once __DIR__ . '/OfficialEpochWindowBindingRuntimeAuthorization.php';

/**
 * Side-effect-free projection of a Formal Measurement Window.
 *
 * This class must remain separate from write authorization: it never calls
 * authorizeFormalWrite(), authorizeMeasurementWrite(), assertCurrent(), or
 * freeze().  Current source identity is calculated only in memory.
 */
final class FormalMeasurementWindowStatusInspector
{
    private const REQUIRED_ELIGIBLE_RACES = 2;
    // The production scheduler runs every two minutes. Two full cycles allow
    // the Expected and Canonical first-write sequence to finish while still
    // making a silently stalled partial matrix visible as a real failure.
    private const SCHEDULER_CADENCE_SECONDS = 120;
    private const MATERIALIZATION_MAX_CYCLES = 2;

    public function __construct(private readonly string $repoRoot)
    {
    }

    /** @return array<string,mixed> */
    public function inspect(): array
    {
        $context = FormalMeasurementWindowContext::production($this->repoRoot);
        $resolved = $context->resolve();
        if (empty($resolved['accepted'])) throw new RuntimeException('forward_window_context_unavailable');

        $manifest = $context->activationHold()->readManifest();
        $activation = $context->activationHold()->readActivation();
        $declaration = $context->declaration()->read();
        if (empty($declaration['accepted'])) throw new RuntimeException('forward_window_declaration_unavailable');

        $implementation = is_array($declaration['implementation'] ?? null) ? $declaration['implementation'] : [];
        $declared = is_array($declaration['declaration'] ?? null) ? $declaration['declaration'] : [];
        $currentIdentity = (new MeasurementImplementationProvenance(
            $this->repoRoot,
            $context->declaration()->provenanceStorageRoot(),
        ))->currentIdentityReadOnly();
        $declaredIdentity = (string)($implementation['implementation_identity'] ?? '');
        $activationIdentity = (string)($activation['implementation_identity'] ?? '');
        $declaredActivationMatch = $declaredIdentity !== '' && hash_equals($declaredIdentity, $activationIdentity);
        $declaredCurrentMatch = $declaredIdentity !== '' && hash_equals($declaredIdentity, $currentIdentity);

        $windowState = trim((string)($manifest['status'] ?? ''));
        $armedAt = trim((string)($manifest['armed_at'] ?? ''));
        $epochStatus = $this->officialEpochStatus($context, $manifest);
        $officialEpoch = !empty($epochStatus['official_epoch'])
            && ($epochStatus['official_epoch_state'] ?? '') === 'ACTIVE';
        $lifecycle = $context->lifecycle()->effectiveState($context->windowId(), $manifest);
        if (($lifecycle['effective_state'] ?? '') === 'ABORTED') {
            $abort = is_array($lifecycle['abort'] ?? null) ? $lifecycle['abort'] : [];
            return array_merge([
                'ok' => true, 'phase' => 'window_aborted', 'window_id' => $context->windowId(),
                'window_state' => $windowState !== '' ? $windowState : 'UNKNOWN', 'effective_state' => 'ABORTED',
                'window_completion_status' => 'ABORTED', 'gate_c_status' => 'ABORTED',
                'gate_c_required_races' => self::REQUIRED_ELIGIBLE_RACES, 'eligible_distinct_races' => 0, 'accepted_samples' => 0,
                'blocked' => true, 'blocked_reason' => 'WINDOW_ABORTED', 'abort_reason' => (string)($abort['abort_reason'] ?? ''),
                'declared_implementation_identity' => $declaredIdentity,
                'activation_implementation_identity' => $activationIdentity, 'current_implementation_identity' => $currentIdentity,
                'declared_activation_match' => $declaredActivationMatch, 'declared_current_match' => $declaredCurrentMatch,
                'closure_present' => false, 'abort_present' => true, 'abort_record_hash' => (string)($abort['abort_record_hash'] ?? ''),
                'last_updated_at' => $this->lastUpdatedAt(dirname($context->windowManifestPath())),
            ], $epochStatus);
        }
        if ($windowState !== 'ACTIVE' || !$this->isTimestamp($armedAt)) {
            return array_merge([
                'ok' => true,
                'phase' => $officialEpoch ? 'official_forward' : 'window_prepared',
                'window_id' => $context->windowId(),
                'window_state' => $windowState !== '' ? $windowState : 'UNKNOWN',
                'effective_state' => (string)($lifecycle['effective_state'] ?? 'INVALID'),
                'gate_c_status' => 'PENDING_ACTIVATION',
                'gate_c_required_races' => self::REQUIRED_ELIGIBLE_RACES,
                'eligible_distinct_races' => 0,
                'accepted_samples' => 0,
                'blocked' => false,
                'blocked_reason' => null,
                'declared_implementation_identity' => $declaredIdentity,
                'activation_implementation_identity' => $activationIdentity,
                'current_implementation_identity' => $currentIdentity,
                'declared_activation_match' => $declaredActivationMatch,
                'declared_current_match' => $declaredCurrentMatch,
                'closure_present' => !empty($lifecycle['closure']),
                'last_updated_at' => $this->lastUpdatedAt(dirname($context->windowManifestPath())),
            ], $epochStatus);
        }

        $gateA1 = (new GateA1ProspectiveInventory($context->opportunityRoot()))->compare($context->windowId(), $armedAt);
        $candidates = is_array($gateA1['observed_rows'] ?? null) ? $gateA1['observed_rows'] : [];
        $candidateByRace = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) continue;
            $raceCode = trim((string)($candidate['race_code'] ?? ''));
            if ($raceCode !== '') $candidateByRace[$raceCode] = $candidate;
        }

        $opportunityRoot = $context->opportunityRoot();
        $ledgerRoot = $context->ledgerRoot();
        $ledger = new StrictForwardOpportunityLedger($opportunityRoot);
        $resolutions = array_values(array_filter($this->jsonFiles($opportunityRoot . '/eligibility/*/*.json'), fn(array $row): bool => $this->after((string)($row['first_eligible_observed_at'] ?? $row['resolved_at'] ?? ''), $armedAt)));
        $sourceFailures = array_values(array_filter($this->jsonLines($opportunityRoot . '/source_failures/*.jsonl'), fn(array $row): bool => $this->after((string)($row['observed_at'] ?? ''), $armedAt)));
        $expected = array_values(array_filter($this->jsonFiles($ledgerRoot . '/expected/*/*.json'), fn(array $row): bool => $this->after((string)($row['expected_at'] ?? ''), $armedAt)));
        $canonical = array_values(array_filter($this->jsonFiles($ledgerRoot . '/records/*/*.json'), fn(array $row): bool => $this->after((string)($row['captured_at'] ?? ''), $armedAt) && (string)($row['capture_mode'] ?? '') === 'LIVE_STRICT'));
        $revisionEvents = $this->jsonLines($opportunityRoot . '/schedule_revisions/*/*.jsonl');
        $operational = $this->jsonLines($opportunityRoot . '/operational/*.jsonl');
        $ledgerFailures = $this->jsonLines($ledgerRoot . '/failures/*.jsonl');
        $ledgerOperational = $this->jsonLines($ledgerRoot . '/operational/*.jsonl');
        $comparativeFailures = $this->jsonLines(dirname($ledgerRoot) . '/comparative/failures/*.jsonl');

        $resolutionByRace = [];
        foreach ($resolutions as $row) $resolutionByRace[(string)($row['race_code'] ?? '')] = $row;
        $expectedByRace = $this->groupByRace($expected);
        $canonicalByRace = $this->groupByRace($canonical);

        $oldUnaccounted = 0;
        foreach ($operational as $row) {
            if (($row['event'] ?? '') !== 'CANDIDATE_SOURCE_CONFLICT') continue;
            $raceCode = (string)($row['race_code'] ?? '');
            if (!isset($candidateByRace[$raceCode])) continue;
            if (trim((string)($row['original_cutoff_timestamp'] ?? '')) === '' || trim((string)($row['new_cutoff_timestamp'] ?? '')) === '') $oldUnaccounted++;
        }
        $unsafeRollback = 0;
        $unverifiedSemanticRevision = 0;
        foreach ($revisionEvents as $row) {
            if (($row['revision_type'] ?? '') === 'STALE_OR_OUT_OF_ORDER_SOURCE') $unsafeRollback++;
            if (($row['revision_type'] ?? '') === 'SEMANTIC_REVISION_UNVERIFIED') $unverifiedSemanticRevision++;
        }

        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM);
        $effectiveComplete = 0;
        $terminal = 0;
        $unresolved = 0;
        $instrumentationGap = 0;
        $eligibleRaces = [];
        $acceptedSamples = 0;
        $provenanceGap = 0;
        $unacceptedDiagnostics = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) continue;
            $raceCode = (string)($candidate['race_code'] ?? '');
            $date = (string)($candidate['target_date'] ?? '');
            if ($raceCode === '' || $date === '') continue;
            $effective = $ledger->resolveEffectiveSchedule($date, $raceCode);
            $formalSchedule = $ledger->formalMeasurementStatus($date, $raceCode);
            $cutoff = (string)($effective['effective_cutoff_timestamp'] ?? '');
            $officialStatus = (string)($effective['effective_official_status'] ?? '');
            $reconstructed = $cutoff !== '' && in_array($officialStatus, ['ACTIVE', 'CANCELLED', 'EXCLUDED'], true)
                && str_starts_with((string)($effective['semantic_fingerprint'] ?? ''), 'sha256:')
                && (string)($effective['schedule_revision_integrity'] ?? 'PASS') === 'PASS';
            if ($reconstructed) $effectiveComplete++;
            $cutoffPassed = $cutoff !== '' && !$this->before($now, $cutoff);
            $eligibility = (string)($resolutionByRace[$raceCode]['eligibility_status'] ?? 'NONE');
            if ($cutoffPassed && $officialStatus === 'ACTIVE' && $eligibility === 'ELIGIBILITY_UNRESOLVED') $unresolved++;
            if ($cutoffPassed && $officialStatus === 'ACTIVE' && $eligibility === 'NONE') $instrumentationGap++;
            if ($cutoffPassed && $eligibility !== 'NONE' && $eligibility !== 'ELIGIBILITY_UNRESOLVED') $terminal++;

            $isEligible = !empty($formalSchedule['accepted']) && $eligibility === 'ELIGIBLE_OBSERVED';
            if ($isEligible) $eligibleRaces[$raceCode] = true;
            $acceptance = StrictForwardAcceptedRace::evaluate($expectedByRace[$raceCode] ?? [], $canonicalByRace[$raceCode] ?? [], [
                'formal_schedule_accepted' => !empty($formalSchedule['accepted']) && $isEligible,
                'window_id' => $context->windowId(),
                'implementation' => $implementation,
                'scheduler_candidate_key' => 'scheduler-opportunity|' . $raceCode,
            ]);
            if ((isset($expectedByRace[$raceCode]) || isset($canonicalByRace[$raceCode])) && empty($acceptance['provenance_valid'])) $provenanceGap++;
            if (!empty($acceptance['accepted'])) $acceptedSamples++;
            elseif ($isEligible) {
                $progress = StrictForwardMaterializationDiagnostic::diagnose($expectedByRace[$raceCode] ?? [], $canonicalByRace[$raceCode] ?? [], [
                    'formal_schedule_accepted' => !empty($formalSchedule['accepted']),
                    'window_id' => $context->windowId(),
                    'implementation' => $implementation,
                    'scheduler_candidate_key' => 'scheduler-opportunity|' . $raceCode,
                ]);
                $eligibilityAt = trim((string)($resolutionByRace[$raceCode]['first_eligible_observed_at']
                    ?? $resolutionByRace[$raceCode]['resolved_at'] ?? ''));
                $latestAt = $this->latestTimestamp([$eligibilityAt, (string)($progress['latest_artifact_at'] ?? '')]);
                $explicitFailure = $this->hasRaceFailure($raceCode, $eligibilityAt, [
                    ...$sourceFailures, ...$ledgerFailures, ...$comparativeFailures,
                ], $ledgerOperational);
                $stale = $latestAt === null || $this->olderThan(
                    $latestAt,
                    self::SCHEDULER_CADENCE_SECONDS * self::MATERIALIZATION_MAX_CYCLES,
                );
                $unacceptedDiagnostics[$raceCode] = $progress + [
                    'eligibility_at' => $eligibilityAt !== '' ? $eligibilityAt : null,
                    'latest_progress_at' => $latestAt,
                    'explicit_failure' => $explicitFailure,
                    'stale' => $stale,
                ];
            }
        }

        $gateA = (string)($gateA1['status'] ?? 'BLOCKED_EXPECTED_INVENTORY_INVALID');
        $gateA2 = $oldUnaccounted === 0 && $effectiveComplete === count($candidates) && $unsafeRollback === 0 && $unverifiedSemanticRevision === 0 && $sourceFailures === [] ? 'PASS' : 'FAIL';
        $gateBNow = $unresolved === 0 && $instrumentationGap === 0 ? 'PASS' : 'FAIL';
        $gateBFinal = count($candidates) === $terminal && $unresolved === 0 && $instrumentationGap === 0 ? 'PASS' : 'PENDING_LIVE';
        $eligibleCount = count($eligibleRaces);
        if ($gateA !== 'PASS') $gateC = $gateA === 'PENDING_LIVE' ? 'PENDING_LIVE' : 'BLOCKED_A1';
        elseif ($gateA2 !== 'PASS') $gateC = 'BLOCKED_A2';
        elseif (!$declaredActivationMatch || $provenanceGap > 0) $gateC = 'BLOCKED_IMPLEMENTATION_PROVENANCE';
        elseif ($gateBNow !== 'PASS') $gateC = 'BLOCKED_GATE_B';
        elseif ($eligibleCount === 0 || ($acceptedSamples === $eligibleCount && $eligibleCount < self::REQUIRED_ELIGIBLE_RACES)) $gateC = 'PENDING_LIVE';
        elseif ($acceptedSamples === $eligibleCount) $gateC = 'PASS';
        else $gateC = 'FAIL';

        $blocked = str_starts_with($gateC, 'BLOCKED_') || $gateC === 'FAIL';
        $processingRaces = [];
        if ($gateC === 'FAIL' && $eligibleCount > $acceptedSamples && !empty($epochStatus['runtime_authorized'])) {
            foreach ($unacceptedDiagnostics as $raceCode => $diagnostic) {
                if (!StrictForwardMaterializationDiagnostic::displayAsInProgress(
                    $diagnostic,
                    !empty($diagnostic['explicit_failure']),
                    !empty($diagnostic['stale']),
                    !empty($epochStatus['runtime_authorized']),
                )) {
                    $processingRaces = [];
                    break;
                }
                $processingRaces[] = $raceCode;
            }
        }
        $processing = $processingRaces !== []
            && count($processingRaces) === ($eligibleCount - $acceptedSamples);
        $materializationState = $processing ? 'IN_PROGRESS'
            : ($eligibleCount > $acceptedSamples ? 'FAILED' : 'IDLE');

        return array_merge([
            'ok' => true,
            'phase' => $officialEpoch ? 'official_forward' : 'gate_c_live',
            'window_id' => $context->windowId(),
            'window_state' => $windowState,
            'effective_state' => (string)($lifecycle['effective_state'] ?? 'INVALID'),
            'closure_present' => !empty($lifecycle['closure']),
            'gate_c_status' => $gateC,
            'gate_c_required_races' => self::REQUIRED_ELIGIBLE_RACES,
            'eligible_distinct_races' => $eligibleCount,
            'accepted_samples' => $acceptedSamples,
            'candidate_count' => count($candidates),
            'terminal_candidate_count' => $terminal,
            'unresolved_count' => $unresolved,
            'expected_count' => count($expected),
            'canonical_count' => count($canonical),
            'blocked' => $blocked,
            'blocked_reason' => $blocked ? $gateC : null,
            'gate_c_materialization_state' => $materializationState,
            'gate_c_processing' => $processing,
            'gate_c_processing_races' => $processingRaces,
            'gate_c_unaccepted_races' => max(0, $eligibleCount - $acceptedSamples),
            'gate_a1_status' => $gateA,
            'gate_a2_status' => $gateA2,
            'gate_b_status' => $gateBNow,
            'gate_b_final_status' => $gateBFinal,
            'declared_implementation_identity' => $declaredIdentity,
            'activation_implementation_identity' => $activationIdentity,
            'current_implementation_identity' => $currentIdentity,
            'declared_activation_match' => $declaredActivationMatch,
            'declared_current_match' => $declaredCurrentMatch,
            'last_updated_at' => $this->lastUpdatedAt(dirname($context->windowManifestPath())),
        ], $epochStatus);
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    private function officialEpochStatus(FormalMeasurementWindowContext $context, array $manifest): array
    {
        $resolver = new OfficialEpochWindowBindingResolver($this->repoRoot);
        $relation = ['accepted' => false, 'reason' => 'OFFICIAL_EPOCH_NOT_STARTED'];
        try { $relation = $resolver->epochForBoundWindow($context->windowId()); }
        catch (Throwable $error) { $relation = ['accepted' => false, 'reason' => get_class($error)]; }

        $epochId = '';
        $epochState = 'NOT_STARTED';
        $windowKind = 'NONE';
        $resolutionValid = false;
        $bindingPresent = $resolver->bindingForWindow($context->windowId()) !== [];
        $startPaths = glob($resolver->store()->lifecycleRoot() . '/official_epochs/*/start.json') ?: [];

        if (!empty($relation['accepted'])) {
            $epoch = is_array($relation['epoch'] ?? null) ? $relation['epoch'] : [];
            $epochId = trim((string)($epoch['epoch_id'] ?? ''));
            $windowKind = (string)($relation['kind'] ?? 'UNKNOWN');
            $projection = $epochId !== ''
                ? (new OfficialEpochIncidentLifecycleStore($resolver->store()->lifecycleRoot()))->project($epochId)
                : [];
            $epochState = !empty($projection['valid']) ? (string)($projection['state'] ?? 'UNRESOLVED') : 'UNRESOLVED';
            $resolutionValid = !empty($projection['valid']);
            if ($windowKind === 'BOUND_SUCCESSOR_WINDOW') {
                $validated = $resolver->validateBinding($context->windowId(), ['require_pointer' => true]);
                $resolutionValid = $resolutionValid && !empty($validated['accepted']);
            }
        } elseif ($startPaths === [] && !$bindingPresent
            && strtoupper(trim((string)($manifest['official_epoch_started'] ?? 'NO'))) === 'YES') {
            // Compatibility is intentionally limited to repositories that do
            // not yet contain the v1 Epoch lifecycle. An invalid v1 binding
            // must never be hidden by this manifest flag.
            $epochState = 'ACTIVE';
            $windowKind = 'LEGACY_MANIFEST';
            $resolutionValid = true;
        } elseif ($startPaths !== [] || $bindingPresent) {
            $epochState = 'UNRESOLVED';
            $windowKind = $bindingPresent ? 'INVALID_BOUND_WINDOW' : 'UNBOUND_WINDOW';
        }

        try {
            $runtime = (new OfficialEpochWindowBindingRuntimeAuthorization($this->repoRoot))
                ->authorizeOperationalPointerWindow();
        } catch (Throwable $error) {
            $runtime = ['accepted' => false, 'status' => 'OFFICIAL_EPOCH_RUNTIME_BLOCKED', 'reason' => get_class($error)];
        }
        $runtimeAuthorized = !empty($runtime['accepted']) && ($runtime['kind'] ?? '') === 'ACTIVE'
            && hash_equals($context->windowId(), (string)($runtime['window_id'] ?? ''));
        $officialEpoch = $resolutionValid && $epochState !== 'NOT_STARTED';
        $officialRuntimeAuthorized = $officialEpoch && $epochState === 'ACTIVE' && $runtimeAuthorized
            && ($runtime['status'] ?? '') === 'OFFICIAL_EPOCH_RUNTIME_AUTHORIZED';

        return [
            'official_epoch' => $officialEpoch,
            'official_epoch_id' => $epochId !== '' ? $epochId : null,
            'official_epoch_state' => $epochState,
            'official_epoch_window_kind' => $windowKind,
            'official_epoch_runtime_authorized' => $officialRuntimeAuthorized,
            'runtime_authorized' => $runtimeAuthorized,
            'runtime_status' => (string)($runtime['status'] ?? 'UNKNOWN'),
            'runtime_reason' => (string)($runtime['reason'] ?? ''),
        ];
    }

    /** @param list<array<string,mixed>> $failureRows @param list<array<string,mixed>> $operationalRows */
    private function hasRaceFailure(string $raceCode, string $since, array $failureRows, array $operationalRows): bool
    {
        foreach ($failureRows as $row) {
            if (!$this->rowReferencesRace($row, $raceCode)) continue;
            $at = $this->rowTimestamp($row);
            if ($since === '' || $at === null || $this->atOrAfter($at, $since)) return true;
        }
        foreach ($operationalRows as $row) {
            if (($row['event'] ?? '') !== 'conflict' || !$this->rowReferencesRace($row, $raceCode)) continue;
            $at = $this->rowTimestamp($row);
            if ($since === '' || $at === null || $this->atOrAfter($at, $since)) return true;
        }
        return false;
    }

    /** @param array<string,mixed> $row */
    private function rowReferencesRace(array $row, string $raceCode): bool
    {
        if (($row['race_code'] ?? '') === $raceCode) return true;
        if (in_array($raceCode, array_map('strval', (array)($row['race_codes'] ?? [])), true)) return true;
        foreach (['intended_key', 'stable_unique_key', 'opportunity_identity'] as $field) {
            if (str_contains((string)($row[$field] ?? ''), '|' . $raceCode . '|')) return true;
        }
        foreach ((array)($row['intended_keys'] ?? []) as $key) {
            if (str_contains((string)$key, '|' . $raceCode . '|')) return true;
        }
        return false;
    }

    /** @param array<string,mixed> $row */
    private function rowTimestamp(array $row): ?string
    {
        foreach (['attempted_at', 'recorded_at', 'observed_at', 'timestamp'] as $field) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value !== '' && $this->isTimestamp($value)) return $value;
        }
        return null;
    }

    /** @param list<string> $values */
    private function latestTimestamp(array $values): ?string
    {
        $latest = null;
        foreach ($values as $value) {
            if (!$this->isTimestamp($value)) continue;
            $time = new DateTimeImmutable($value);
            if ($latest === null || $time > $latest) $latest = $time;
        }
        return $latest?->format(DATE_ATOM);
    }

    private function olderThan(string $timestamp, int $seconds): bool
    {
        try {
            $then = new DateTimeImmutable($timestamp);
            $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
            return $then->modify('+' . $seconds . ' seconds') < $now;
        } catch (Throwable) { return true; }
    }

    private function atOrAfter(string $left, string $right): bool
    {
        try { return new DateTimeImmutable($left) >= new DateTimeImmutable($right); }
        catch (Throwable) { return false; }
    }

    /** @return list<array<string,mixed>> */
    private function jsonFiles(string $pattern): array
    {
        $rows = [];
        foreach (glob($pattern) ?: [] as $path) {
            try { $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
            catch (Throwable) { throw new RuntimeException('forward_status_invalid_json:' . basename($path)); }
            if (is_array($row)) $rows[] = $row;
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function jsonLines(string $pattern): array
    {
        $rows = [];
        foreach (glob($pattern) ?: [] as $path) foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            try { $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR); }
            catch (Throwable) { throw new RuntimeException('forward_status_invalid_jsonl:' . basename($path)); }
            if (is_array($row)) $rows[] = $row;
        }
        return $rows;
    }

    /** @param list<array<string,mixed>> $rows @return array<string,list<array<string,mixed>>> */
    private function groupByRace(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) { $raceCode = (string)($row['race_code'] ?? ''); if ($raceCode !== '') $grouped[$raceCode][] = $row; }
        return $grouped;
    }

    private function lastUpdatedAt(string $windowRoot): string
    {
        $latest = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($windowRoot, FilesystemIterator::SKIP_DOTS)) as $file) if ($file->isFile()) $latest = max($latest, $file->getMTime());
        if ($latest <= 0) $latest = time();
        return (new DateTimeImmutable('@' . $latest))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format(DATE_ATOM);
    }

    private function isTimestamp(string $value): bool { try { new DateTimeImmutable($value); return $value !== ''; } catch (Throwable) { return false; } }
    private function after(string $left, string $right): bool { try { return $left !== '' && $right !== '' && new DateTimeImmutable($left) > new DateTimeImmutable($right); } catch (Throwable) { return false; } }
    private function before(string $left, string $right): bool { try { return $left !== '' && $right !== '' && new DateTimeImmutable($left) < new DateTimeImmutable($right); } catch (Throwable) { return false; } }
}
