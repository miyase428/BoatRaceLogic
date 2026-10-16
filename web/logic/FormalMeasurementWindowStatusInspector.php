<?php

declare(strict_types=1);

require_once __DIR__ . '/FormalMeasurementWindowContext.php';
require_once __DIR__ . '/GateA1ProspectiveInventory.php';
require_once __DIR__ . '/StrictForwardOpportunityLedger.php';
require_once __DIR__ . '/StrictForwardAcceptedRace.php';
require_once __DIR__ . '/OfficialEpochLifecycleV1.php';

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
        // Epoch promotion is an external immutable lifecycle record.  A
        // CLOSED source Window must never be mutated merely to change a UI
        // projection, so the legacy manifest flag is only a compatibility
        // fallback and never the authoritative source for v1 Epoch state.
        $epochRecord = (new OfficialEpochLifecycleV1($this->repoRoot))->epochForSourceWindow($context->windowId());
        $officialEpoch = $epochRecord !== [] || strtoupper(trim((string)($manifest['official_epoch_started'] ?? 'NO'))) === 'YES';
        $lifecycle = $context->lifecycle()->effectiveState($context->windowId(), $manifest);
        if (($lifecycle['effective_state'] ?? '') === 'ABORTED') {
            $abort = is_array($lifecycle['abort'] ?? null) ? $lifecycle['abort'] : [];
            return [
                'ok' => true, 'phase' => 'window_aborted', 'window_id' => $context->windowId(),
                'window_state' => $windowState !== '' ? $windowState : 'UNKNOWN', 'effective_state' => 'ABORTED',
                'window_completion_status' => 'ABORTED', 'gate_c_status' => 'ABORTED',
                'gate_c_required_races' => self::REQUIRED_ELIGIBLE_RACES, 'eligible_distinct_races' => 0, 'accepted_samples' => 0,
                'blocked' => true, 'blocked_reason' => 'WINDOW_ABORTED', 'abort_reason' => (string)($abort['abort_reason'] ?? ''),
                'official_epoch' => false, 'declared_implementation_identity' => $declaredIdentity,
                'activation_implementation_identity' => $activationIdentity, 'current_implementation_identity' => $currentIdentity,
                'declared_activation_match' => $declaredActivationMatch, 'declared_current_match' => $declaredCurrentMatch,
                'closure_present' => false, 'abort_present' => true, 'abort_record_hash' => (string)($abort['abort_record_hash'] ?? ''),
                'last_updated_at' => $this->lastUpdatedAt(dirname($context->windowManifestPath())),
            ];
        }
        if ($windowState !== 'ACTIVE' || !$this->isTimestamp($armedAt)) {
            return [
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
                'official_epoch' => $officialEpoch,
                'declared_implementation_identity' => $declaredIdentity,
                'activation_implementation_identity' => $activationIdentity,
                'current_implementation_identity' => $currentIdentity,
                'declared_activation_match' => $declaredActivationMatch,
                'declared_current_match' => $declaredCurrentMatch,
                'closure_present' => !empty($lifecycle['closure']),
                'last_updated_at' => $this->lastUpdatedAt(dirname($context->windowManifestPath())),
            ];
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
        return [
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
            'official_epoch' => $officialEpoch,
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
        ];
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
