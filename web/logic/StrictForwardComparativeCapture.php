<?php

declare(strict_types=1);

require_once __DIR__ . '/ComparativeContractV1.php';
require_once __DIR__ . '/FormalMeasurementWindowContext.php';
require_once __DIR__ . '/StrictForwardOpportunityLedger.php';
require_once __DIR__ . '/StrictForwardReadinessLedger.php';
require_once __DIR__ . '/StrictForwardAcceptedRace.php';
require_once __DIR__ . '/StrictForwardInputSnapshotStore.php';
require_once __DIR__ . '/StrictForwardComparativePairStore.php';

/** A normal per-race non-admission that must not terminate the venue batch. */
final class StrictForwardComparativeRaceLocalReject extends RuntimeException
{
}

/**
 * Creates the outcome-blind Comparative Contract evidence at the exact
 * Strict-Forward capture boundary.  It deliberately has no production UI
 * responsibility: callers opt in only from the scheduler-owned capture path.
 */
final class StrictForwardComparativeCapture
{
    public function __construct(
        private readonly FormalMeasurementWindowContext $windowContext,
        private readonly ComparativeContractV1 $contract,
        private readonly ?DateTimeZone $timezone = null,
        private readonly ?DateTimeImmutable $fixedNow = null,
    ) {
    }

    /**
     * A blocked result is measurement fail-loud evidence.  It is never thrown
     * through the production API response, so the existing prediction remains
     * fail-open.
     *
     * @param array<string,mixed> $runtime
     * @return array{status:string,reason:string,snapshots:int,pairs:int,duplicates:int,conflicts:int,race_local_rejects:int,global_fatals:int}
     */
    public function capture(array $runtime): array
    {
        $authorization = $this->windowContext->authorizeMeasurementWrite();
        if (!$authorization['accepted']) {
            $reason = (string)$authorization['reason'];
            return $this->result(
                $this->globalAuthorizationFailure($reason) ? 'GLOBAL_FATAL' : 'SKIPPED',
                $reason,
                globalFatals: $this->globalAuthorizationFailure($reason) ? 1 : 0,
            );
        }

        $date = trim((string)($runtime['date'] ?? ''));
        $venue = strtoupper(trim((string)($runtime['venue'] ?? '')));
        $phase = trim((string)($runtime['phase'] ?? ''));
        $ready = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => strtoupper(trim((string)$value)),
            (array)($runtime['ready_race_codes'] ?? []),
        ), static fn(string $race): bool => preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $race) === 1)));
        if (!$this->validDate($date) || !preg_match('/^[A-Z0-9]{3}$/', $venue) || $phase !== 'live' || $ready === []) {
            return $this->result('SKIPPED', 'INVALID_FORMAL_COMPARATIVE_CONTEXT');
        }

        $windowId = (string)$authorization['window_id'];
        $implementation = is_array($authorization['implementation'] ?? null) ? $authorization['implementation'] : [];
        $root = dirname($this->windowContext->windowManifestPath());
        $opportunity = new StrictForwardOpportunityLedger($this->windowContext->opportunityRoot(), $this->windowContext);
        $snapshots = new StrictForwardInputSnapshotStore($root);
        $pairs = new StrictForwardComparativePairStore($root, $this->contract);
        $inputs = is_array($runtime['input_by_race'] ?? null) ? $runtime['input_by_race'] : [];
        $mlDecisions = is_array($runtime['ml_decisions'] ?? null) ? $runtime['ml_decisions'] : [];
        $legacy = is_array($runtime['legacy_by_course'] ?? null) ? $runtime['legacy_by_course'] : [];
        $traditionalEvaluations = is_array($runtime['traditional_evaluation_by_course'] ?? null)
            ? $runtime['traditional_evaluation_by_course'] : [];
        $traditionalInput = is_array($runtime['traditional_runtime_input'] ?? null)
            ? $runtime['traditional_runtime_input'] : [];
        $now = $this->now();
        $ids = $this->contract->identities();
        $uses = array_values(array_filter(
            $this->contract->scope()['uses'],
            static fn(array $use): bool => ($use['venue'] ?? '') === $venue,
        ));
        $savedSnapshots = $savedPairs = $duplicates = $conflicts = $raceLocalRejects = 0;
        foreach ($ready as $raceCode) {
            try {
                if (substr($raceCode, 8, 3) !== $venue) throw new StrictForwardComparativeRaceLocalReject('RACE_VENUE_MISMATCH');
                if ($uses === []) throw new StrictForwardComparativeRaceLocalReject('NO_PRODUCTION_COMPARATIVE_USE');
                $schedule = $opportunity->formalMeasurementStatus($date, $raceCode);
                $effective = $opportunity->resolveEffectiveSchedule($date, $raceCode);
                if (!$schedule['accepted'] || !$this->beforeCutoff($now, (string)$schedule['cutoff_timestamp'])) {
                    throw new StrictForwardComparativeRaceLocalReject('FORMAL_SCHEDULE_OR_PROSPECTIVE_BOUNDARY_REJECTED');
                }
                $candidate = $this->candidateForRace($opportunity, $date, $raceCode);
                if ($candidate === []) throw new StrictForwardComparativeRaceLocalReject('PROSPECTIVE_CANDIDATE_MISSING');
                $eligibility = $this->eligibilityForRace($opportunity, $date, $raceCode);
                if (($eligibility['eligibility_status'] ?? '') !== 'ELIGIBLE_OBSERVED') {
                    throw new StrictForwardComparativeRaceLocalReject('FORMAL_ELIGIBILITY_NOT_ACCEPTED');
                }
                $expectedRows = $this->ledgerRowsForRace('expected', $date, $raceCode);
                $canonicalRows = $this->ledgerRowsForRace('records', $date, $raceCode);
                $acceptance = StrictForwardAcceptedRace::evaluate($expectedRows, $canonicalRows, [
                    'formal_schedule_accepted' => true,
                    'window_id' => $windowId,
                    'implementation' => $implementation,
                    'scheduler_candidate_key' => 'scheduler-opportunity|' . $raceCode,
                ]);
                if (empty($acceptance['provenance_valid'])) {
                    throw new RuntimeException('FORMAL_ACCEPTANCE_PROVENANCE_INVALID:' . (string)$acceptance['reason']);
                }
                if (empty($acceptance['accepted'])) {
                    throw new StrictForwardComparativeRaceLocalReject('FORMAL_EXPECTED_CANONICAL_NOT_READY:' . (string)$acceptance['reason']);
                }
                $input = is_array($inputs[$raceCode] ?? null) ? $inputs[$raceCode] : [];
                $mlChild = is_array($input['ml_child'] ?? null) ? $input['ml_child'] : [];
                if ($mlChild === [] || !is_array($input['entries'] ?? null) || !is_array($input['exhibition'] ?? null)) {
                    throw new StrictForwardComparativeRaceLocalReject('RUNTIME_INPUT_BUNDLE_MISSING');
                }

                // Validate every deployed decision before the first immutable
                // write. A race-local rejection therefore cannot leave an
                // orphan snapshot or a partial pair set.
                $raceDecisions = is_array($mlDecisions[$raceCode] ?? null) ? $mlDecisions[$raceCode] : [];
                $pairPlans = [];
                foreach ($uses as $use) {
                    $course = (int)$use['course'];
                    $target = (string)$use['target'];
                    $targetDecision = $raceDecisions[(string)$course]['targets'][$target] ?? null;
                    if (!is_array($targetDecision) || empty($targetDecision['decision_available']) || !is_bool($targetDecision['selected'] ?? null)) {
                        throw new StrictForwardComparativeRaceLocalReject('DEPLOYED_ML_DECISION_UNAVAILABLE:' . $course . ':' . $target);
                    }
                    $traditional = StrictForwardReadinessLedger::formalTraditionalDecision(
                        $legacy,
                        $traditionalEvaluations,
                        $course,
                        $raceCode,
                        self::targetLevel($target),
                    );
                    $traditionalDecision = $use['scope_class'] === 'ML_ONLY'
                        ? 'NONE'
                        : ['selected' => $traditional['selected'], 'status' => $traditional['status'], 'classification' => $traditional['classification']];
                    if ($use['scope_class'] === 'COMPARABLE' && !is_bool($traditional['selected'])) {
                        throw new StrictForwardComparativeRaceLocalReject('COMPARABLE_TRADITIONAL_DECISION_UNAVAILABLE:' . $course . ':' . $target);
                    }
                    $pairPlans[] = compact('use', 'course', 'target', 'targetDecision', 'traditionalDecision');
                }

                $snapshot = $snapshots->write([
                    'window_id' => $windowId,
                    'race_code' => $raceCode,
                    'venue' => $venue,
                    'cutoff_at' => (string)$schedule['cutoff_timestamp'],
                    'timezone' => 'Asia/Tokyo',
                    'schedule_semantic_fingerprint' => (string)($effective['semantic_fingerprint'] ?? ''),
                    'deadline_source_identity' => (string)($candidate['source_identity'] ?? ''),
                    'entries' => $input['entries'],
                    'exhibition' => $input['exhibition'],
                    'ml_child' => $mlChild,
                    'traditional_child' => [
                        'policy_identity' => (string)($runtime['traditional_policy_version'] ?? ''),
                        'execution_input' => $traditionalInput,
                        'legacy_by_course' => $legacy,
                        'evaluation_by_course' => $traditionalEvaluations,
                    ],
                    'missing_markers' => is_array($input['missing_markers'] ?? null) ? $input['missing_markers'] : [],
                    'recorded_at' => $now,
                ]);
                $snapshot['status'] === 'SAVED' ? $savedSnapshots++ : $duplicates++;

                $pairHashes = [];
                foreach ($pairPlans as $plan) {
                    $use = $plan['use'];
                    $course = $plan['course'];
                    $target = $plan['target'];
                    $targetDecision = $plan['targetDecision'];
                    $traditionalDecision = $plan['traditionalDecision'];
                    $pair = $pairs->write([
                        'race_code' => $raceCode,
                        'venue' => $venue,
                        'course' => $course,
                        'target' => $target,
                        'cutoff_at' => (string)$schedule['cutoff_timestamp'],
                        'recorded_at' => $now,
                        'timezone' => 'Asia/Tokyo',
                        'window_id' => $windowId,
                        'scheduler_candidate_key' => 'scheduler-opportunity|' . $raceCode,
                        'prospective' => true,
                        'measurement_implementation_identity' => (string)($implementation['implementation_identity'] ?? ''),
                        'input_snapshot_identity' => $snapshot['input_snapshot_identity'],
                        'snapshot_reference' => $this->relativeToRoot((string)$snapshot['path'], $root),
                        'comparative_scope_identity' => $ids['comparative_scope_identity'],
                        'comparative_policy_identity' => $ids['comparative_policy_identity'],
                        'ml_decision' => [
                            'selected' => $targetDecision['selected'],
                            'threshold' => $targetDecision['threshold'] ?? null,
                            'model_version' => $targetDecision['model_version'] ?? null,
                            'artifact_identifier' => $targetDecision['artifact_identifier'] ?? null,
                            'artifact_sha256' => $targetDecision['artifact_sha256'] ?? null,
                        ],
                        'traditional_decision' => $traditionalDecision,
                    ]);
                    $pair['status'] === 'SAVED' ? $savedPairs++ : $duplicates++;
                    $pairHashes[(string)$use['use_key']] = (string)($pair['record']['record_hash'] ?? '');
                }
                $this->writeCompletion($root, [
                    'window_id' => $windowId,
                    'race_code' => $raceCode,
                    'venue' => $venue,
                    'cutoff_at' => (string)$schedule['cutoff_timestamp'],
                    'recorded_at' => $now,
                    'input_snapshot_identity' => $snapshot['input_snapshot_identity'],
                    'snapshot_reference' => $this->relativeToRoot((string)$snapshot['path'], $root),
                    'comparative_scope_identity' => $ids['comparative_scope_identity'],
                    'comparative_policy_identity' => $ids['comparative_policy_identity'],
                    'required_use_keys' => array_values(array_map(static fn(array $use): string => (string)$use['use_key'], $uses)),
                    'pair_record_hashes' => $pairHashes,
                ]);
            } catch (StrictForwardComparativeRaceLocalReject $reject) {
                $raceLocalRejects++;
                $this->appendFailure($root, $date, [
                    'window_id' => $windowId,
                    'race_code' => $raceCode,
                    'venue' => $venue,
                    'recorded_at' => $now,
                    'status' => 'COMPARATIVE_RACE_LOCAL_REJECT',
                    'failure_scope' => 'RACE_LOCAL_REJECT',
                    'reason' => $reject->getMessage(),
                ]);
                continue;
            } catch (Throwable $error) {
                $conflict = str_contains($error->getMessage(), 'conflict');
                if ($conflict) $conflicts++;
                $this->appendFailure($root, $date, [
                    'window_id' => $windowId,
                    'race_code' => $raceCode,
                    'venue' => $venue,
                    'recorded_at' => $now,
                    'status' => $conflict ? 'COMPARATIVE_CAPTURE_CONFLICT' : 'COMPARATIVE_CAPTURE_GLOBAL_FATAL',
                    'failure_scope' => 'WINDOW_GLOBAL_FATAL',
                    'reason' => $error->getMessage(),
                ]);
                return $this->result($conflict ? 'CONFLICT' : 'GLOBAL_FATAL', $error->getMessage(), $savedSnapshots, $savedPairs, $duplicates, $conflicts, $raceLocalRejects, 1);
            }
        }
        return $this->result('COMPLETE', '', $savedSnapshots, $savedPairs, $duplicates, $conflicts, $raceLocalRejects);
    }

    private static function targetLevel(string $target): int
    {
        return match ($target) { 'top3' => 1, 'top2' => 2, 'first' => 3, default => throw new InvalidArgumentException('target_invalid') };
    }

    /** @return array<string,mixed> */
    private function candidateForRace(StrictForwardOpportunityLedger $ledger, string $date, string $raceCode): array
    {
        foreach ($ledger->candidateRows($date) as $candidate) if (($candidate['race_code'] ?? '') === $raceCode) return $candidate;
        return [];
    }

    /** @return array<string,mixed> */
    private function eligibilityForRace(StrictForwardOpportunityLedger $ledger, string $date, string $raceCode): array
    {
        foreach ($ledger->resolutionRows($date) as $resolution) {
            if (($resolution['race_code'] ?? '') === $raceCode) return $resolution;
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function ledgerRowsForRace(string $kind, string $date, string $raceCode): array
    {
        if (!in_array($kind, ['expected', 'records'], true)) throw new InvalidArgumentException('comparative_ledger_kind_invalid');
        $rows = [];
        $pattern = $this->windowContext->ledgerRoot() . '/' . $kind . '/' . str_replace('-', '', $date) . '/*.json';
        foreach (glob($pattern) ?: [] as $path) {
            try { $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
            catch (Throwable $error) { throw new RuntimeException('COMPARATIVE_LEDGER_JSON_INVALID:' . basename($path), 0, $error); }
            if (is_array($row) && ($row['race_code'] ?? '') === $raceCode) $rows[] = $row;
        }
        return $rows;
    }

    /** @param array<string,mixed> $record */
    private function writeCompletion(string $root, array $record): void
    {
        sort($record['required_use_keys'], SORT_STRING);
        ksort($record['pair_record_hashes'], SORT_STRING);
        $decision = $record;
        unset($decision['recorded_at'], $decision['completion_hash']);
        $record['completion_hash'] = 'sha256:' . hash('sha256', ComparativeContractV1::canonicalJson($decision));
        $date = substr((string)$record['race_code'], 0, 8);
        $dir = rtrim($root, DIRECTORY_SEPARATOR) . '/comparative/captures/' . $date;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('comparative_completion_directory_unavailable');
        $path = $dir . '/' . hash('sha256', 'COMPARATIVE_CAPTURE|' . $record['window_id'] . '|' . $record['race_code']) . '.json';
        $json = ComparativeContractV1::canonicalJson($record) . "\n";
        $handle = @fopen($path, 'x');
        if (is_resource($handle)) {
            $ok = @fwrite($handle, $json) !== false;
            @fclose($handle);
            if (!$ok) { @unlink($path); throw new RuntimeException('comparative_completion_write_failed'); }
            return;
        }
        $existing = json_decode((string)file_get_contents($path), true);
        if (!is_array($existing) || !hash_equals((string)($existing['completion_hash'] ?? ''), $record['completion_hash'])) {
            throw new RuntimeException('comparative_completion_conflict');
        }
    }

    /** @param array<string,mixed> $record */
    private function appendFailure(string $root, string $date, array $record): void
    {
        $dir = rtrim($root, DIRECTORY_SEPARATOR) . '/comparative/failures';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return;
        @file_put_contents($dir . '/' . str_replace('-', '', $date) . '.jsonl', ComparativeContractV1::canonicalJson($record) . "\n", FILE_APPEND | LOCK_EX);
    }

    private function relativeToRoot(string $path, string $root): string
    {
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    private function now(): string
    {
        return ($this->fixedNow ?? new DateTimeImmutable('now', $this->timezone ?? new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM);
    }
    private function validDate(string $date): bool { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date); }
    private function beforeCutoff(string $left, string $right): bool { try { return new DateTimeImmutable($left) < new DateTimeImmutable($right); } catch (Throwable) { return false; } }

    private function globalAuthorizationFailure(string $reason): bool
    {
        return str_contains($reason, 'IMPLEMENTATION')
            || str_contains($reason, 'IDENTITY')
            || str_contains($reason, 'PROVENANCE')
            || str_contains($reason, 'MANIFEST');
    }

    /** @return array{status:string,reason:string,snapshots:int,pairs:int,duplicates:int,conflicts:int,race_local_rejects:int,global_fatals:int} */
    private function result(string $status, string $reason, int $snapshots = 0, int $pairs = 0, int $duplicates = 0, int $conflicts = 0, int $raceLocalRejects = 0, int $globalFatals = 0): array
    {
        return [
            'status' => $status, 'reason' => $reason, 'snapshots' => $snapshots,
            'pairs' => $pairs, 'duplicates' => $duplicates, 'conflicts' => $conflicts,
            'race_local_rejects' => $raceLocalRejects, 'global_fatals' => $globalFatals,
        ];
    }
}
