<?php

declare(strict_types=1);

require_once __DIR__ . '/FormalMeasurementWindowContext.php';

/**
 * Filesystem-only read model for the provisional public "展開AI" status card.
 *
 * This deliberately projects only candidate / eligibility / capture state. It
 * never loads a model, connects to the DB, or invokes a producer endpoint.
 */
final class Cc30cDisplayStatusReadModel
{
    public const API_VERSION = 'tenkai-ai-status/v1';
    private const RACE_PATTERN = '/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/';

    private function __construct(private readonly string $windowRoot, private readonly string $windowId)
    {
    }

    public static function production(string $repoRoot): self
    {
        $context = FormalMeasurementWindowContext::production($repoRoot);
        $resolved = $context->resolve();
        if (empty($resolved['accepted'])) throw new RuntimeException('WINDOW_CONTEXT_UNAVAILABLE');
        return new self(dirname($context->windowManifestPath()), (string)$resolved['window_id']);
    }

    /** Fixture seam for tests; it has the same filesystem-only behavior. */
    public static function fromWindowRoot(string $windowRoot, string $windowId): self
    {
        return new self(rtrim($windowRoot, DIRECTORY_SEPARATOR), $windowId);
    }

    public static function validRaceCode(string $raceCode): bool
    {
        return preg_match(self::RACE_PATTERN, $raceCode) === 1;
    }

    /** @return array<string,mixed> */
    public function status(string $raceCode): array
    {
        $raceCode = strtoupper(trim($raceCode));
        if (!self::validRaceCode($raceCode)) throw new InvalidArgumentException('INVALID_RACE_CODE');
        $date = substr($raceCode, 0, 8);
        $candidate = $this->readJson($this->candidatePath($date, $raceCode));
        if ($candidate === null) return $this->result($raceCode, 'NOT_TARGET', false, null, false, null, null, null, null, 'NOT_IN_FROZEN_CANDIDATE_UNIVERSE');
        if (!$this->candidateValid($candidate, $raceCode)) return $this->result($raceCode, 'UNAVAILABLE', false, null, false, null, null, null, null, 'MALFORMED_CANDIDATE');

        $cutoff = (string)$candidate['cutoff_timestamp'];
        $source = (string)($candidate['source'] ?? '');
        $resolution = $this->readJson($this->resolutionPath($date, $raceCode));
        if ($resolution !== null && !$this->resolutionValid($resolution, $raceCode, $cutoff)) {
            return $this->result($raceCode, 'UNAVAILABLE', true, null, false, null, null, $cutoff, $source, 'MALFORMED_ELIGIBILITY');
        }
        $latestObservation = $this->latestObservation($date, $raceCode);
        if ($latestObservation['malformed']) {
            return $this->result($raceCode, 'UNAVAILABLE', true, null, false, null, null, $cutoff, $source, 'MALFORMED_ELIGIBILITY_OBSERVATION');
        }

        $eligibility = is_array($resolution) ? (string)($resolution['eligibility_status'] ?? '') : null;
        if ($eligibility === 'ELIGIBLE_OBSERVED') {
            $formal = $this->formalEvidence($date, $raceCode, $cutoff);
            if ($formal['malformed']) return $this->result($raceCode, 'UNAVAILABLE', true, $eligibility, false, null, null, $cutoff, $source, 'MALFORMED_FORMAL_EVIDENCE');
            // The ledger includes non-candidate comparative rows for the same
            // race. A single, valid formal-candidate record is the only
            // race-level provenance required by this status-only contract.
            if ($formal['valid_count'] > 0) {
                return $this->result($raceCode, 'FORMAL_CAPTURED', true, $eligibility, true, 'LIVE_STRICT', $formal['captured_at'], $cutoff, $source, null);
            }
            return $this->result($raceCode, 'WAITING_FORMAL_CAPTURE', true, $eligibility, false, null, null, $cutoff, $source, 'FORMAL_EVIDENCE_NOT_COMPLETE');
        }
        if ($eligibility === 'VALID_NON_ELIGIBLE_EXHIBITION_INCOMPLETE') {
            return $this->result($raceCode, 'NOT_TARGET', true, $eligibility, false, null, null, $cutoff, $source, (string)($resolution['resolution_reason'] ?? 'EXHIBITION_INCOMPLETE'));
        }
        if ($eligibility === 'ELIGIBILITY_UNRESOLVED') {
            return $this->result($raceCode, 'UNAVAILABLE', true, $eligibility, false, null, null, $cutoff, $source, (string)($resolution['resolution_reason'] ?? 'ELIGIBILITY_UNRESOLVED'));
        }
        if ($eligibility !== null && $eligibility !== '') {
            return $this->result($raceCode, 'UNAVAILABLE', true, $eligibility, false, null, null, $cutoff, $source, 'UNKNOWN_ELIGIBILITY_STATUS');
        }

        $observedStatus = (string)($latestObservation['row']['eligibility_status'] ?? '');
        if ($observedStatus === 'EXHIBITION_INCOMPLETE_OBSERVED') {
            return $this->result($raceCode, 'WAITING_EXHIBITION', true, $observedStatus, false, null, null, $cutoff, $source, (string)($latestObservation['row']['reason'] ?? 'EXHIBITION_INCOMPLETE_OBSERVED'));
        }
        return $this->result($raceCode, 'WAITING_ELIGIBILITY', true, null, false, null, null, $cutoff, $source, 'ELIGIBILITY_NOT_OBSERVED');
    }

    /** @return array<string,mixed> */
    private function result(string $raceCode, string $status, bool $candidate, ?string $eligibility, bool $formal, ?string $mode, ?string $capturedAt, ?string $cutoff, ?string $source, ?string $reason): array
    {
        return [
            'api_version' => self::API_VERSION,
            'race_code' => $raceCode,
            'status' => $status,
            'is_candidate' => $candidate,
            'eligibility_status' => $eligibility,
            'formal_evidence_available' => $formal,
            'capture_mode' => $mode,
            'captured_at' => $capturedAt,
            'cutoff_timestamp' => $cutoff,
            'source' => $source,
            'reason' => $reason,
        ];
    }

    /** @return array{malformed:bool,row:array<string,mixed>} */
    private function latestObservation(string $date, string $raceCode): array
    {
        $path = $this->windowRoot . '/opportunity/observations/' . $date . '.jsonl';
        if (!is_file($path)) return ['malformed' => false, 'row' => []];
        $latest = []; $latestAt = null;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            try { $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { return ['malformed' => true, 'row' => []]; }
            if (!is_array($row) || ($row['race_code'] ?? '') !== $raceCode) continue;
            $at = $this->time((string)($row['observed_at'] ?? ''));
            if ($at === null) return ['malformed' => true, 'row' => []];
            if ($latestAt === null || $at >= $latestAt) { $latest = $row; $latestAt = $at; }
        }
        return ['malformed' => false, 'row' => $latest];
    }

    /** @return array{malformed:bool,expected_count:int,valid_count:int,captured_at:?string} */
    private function formalEvidence(string $date, string $raceCode, string $cutoff): array
    {
        $expected = 0; $valid = 0; $captured = null;
        foreach (glob($this->windowRoot . '/ledger/expected/' . $date . '/*.json') ?: [] as $path) {
            $row = $this->readJson($path);
            if ($row === null || $row === []) return ['malformed' => true, 'expected_count' => $expected, 'valid_count' => $valid, 'captured_at' => $captured];
            if (($row['race_code'] ?? '') === $raceCode) $expected++;
        }
        foreach (glob($this->windowRoot . '/ledger/records/' . $date . '/*.json') ?: [] as $path) {
            $row = $this->readJson($path);
            if ($row === null || $row === []) return ['malformed' => true, 'expected_count' => $expected, 'valid_count' => $valid, 'captured_at' => $captured];
            if (($row['race_code'] ?? '') !== $raceCode) continue;
            $capturedAt = (string)($row['captured_at'] ?? '');
            $capturedTime = $this->time($capturedAt);
            $cutoffTime = $this->time($cutoff);
            // Comparative rows are intentionally retained in the same ledger
            // but are not formal evidence for this display projection.
            if (empty($row['formal_candidate'])) continue;
            $validRow = ($row['window_id'] ?? '') === $this->windowId
                && ($row['capture_mode'] ?? '') === 'LIVE_STRICT'
                && ($row['cutoff_timestamp'] ?? '') === $cutoff
                && $capturedTime !== null && $cutoffTime !== null && $capturedTime < $cutoffTime;
            if (!$validRow) return ['malformed' => true, 'expected_count' => $expected, 'valid_count' => $valid, 'captured_at' => $captured];
            $valid++;
            if ($captured === null || $capturedAt < $captured) $captured = $capturedAt;
        }
        return ['malformed' => false, 'expected_count' => $expected, 'valid_count' => $valid, 'captured_at' => $captured];
    }

    private function candidateValid(array $row, string $raceCode): bool
    {
        return ($row['race_code'] ?? '') === $raceCode && ($row['record_type'] ?? '') === 'SCHEDULER_CANDIDATE_UNIVERSE'
            && $this->time((string)($row['cutoff_timestamp'] ?? '')) !== null && trim((string)($row['source'] ?? '')) !== '';
    }
    private function resolutionValid(array $row, string $raceCode, string $cutoff): bool
    {
        return ($row['race_code'] ?? '') === $raceCode && ($row['record_type'] ?? '') === 'SCHEDULER_ELIGIBILITY_RESOLUTION'
            && ($row['cutoff_timestamp'] ?? '') === $cutoff && trim((string)($row['eligibility_status'] ?? '')) !== '';
    }
    private function candidatePath(string $date, string $raceCode): string { return $this->windowRoot . '/opportunity/candidates/' . $date . '/' . hash('sha256', 'scheduler-opportunity|' . $raceCode) . '.json'; }
    private function resolutionPath(string $date, string $raceCode): string { return $this->windowRoot . '/opportunity/eligibility/' . $date . '/' . hash('sha256', 'scheduler-opportunity|' . $raceCode) . '.json'; }
    private function time(string $value): ?DateTimeImmutable { try { return new DateTimeImmutable($value); } catch (Throwable) { return null; } }
    /** @return array<string,mixed>|null */
    private function readJson(string $path): ?array {
        if (!is_file($path)) return null;
        try {
            $value = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            return is_array($value) ? $value : [];
        } catch (Throwable) {
            return [];
        }
    }
}
