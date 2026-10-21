<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialAmagasakiAttemptState.php';

/**
 * Persistent counterpart of the pure attempt state machine.  All writes are
 * compare-and-swap operations; a lost claim or completion is fail-closed.
 */
interface OfficialAmagasakiAttemptRepository extends OfficialAmagasakiAttemptClaimStore
{
    /** @return array<string,mixed>|null */
    public function load(string $raceCode): ?array;

    /**
     * Completes only the exact in-progress claim represented by $before.
     * The caller must treat false as an indeterminate state and must not save
     * or re-fetch the race automatically.
     *
     * @param array<string,mixed> $before @param array<string,mixed> $after
     */
    public function complete(array $before, string $claimToken, array $after): bool;
}

/**
 * PostgreSQL adapter. Construction and loading are side-effect free; database
 * access occurs only in explicit repository methods, which are never invoked
 * by the dry-run runner or synthetic fixtures.
 */
final class PdoOfficialAmagasakiAttemptRepository implements OfficialAmagasakiAttemptRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function load(string $raceCode): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT race_code, source, outcome,
       to_char(attempt_started_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.MS"Z"') AS attempt_started_at,
       to_char(attempt_finished_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.MS"Z"') AS attempt_finished_at,
       to_char(retry_not_before AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.MS"Z"') AS retry_not_before,
       attempt_count, terminal, manual_review_required, last_error_reason, claim_token,
       to_char(claim_expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.MS"Z"') AS claim_expires_at,
       version
FROM boat_race.official_exhibition_attempt_state_v1
WHERE race_code = :race_code AND source = 'official_amagasaki'
SQL);
        $statement->execute([':race_code' => $raceCode]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        foreach (['terminal', 'manual_review_required'] as $field) {
            $row[$field] = $row[$field] === true || $row[$field] === 't' || $row[$field] === 1 || $row[$field] === '1';
        }
        foreach (['attempt_count', 'version'] as $field) $row[$field] = (int)$row[$field];
        return $row;
    }

    public function claim(string $raceCode, int $expectedVersion, array $state): bool
    {
        $params = $this->writeParameters($state);
        if ($expectedVersion === 0) {
            $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO boat_race.official_exhibition_attempt_state_v1
(race_code, source, outcome, attempt_started_at, attempt_finished_at, retry_not_before,
 attempt_count, terminal, manual_review_required, last_error_reason, claim_token,
 claim_expires_at, version, updated_at)
VALUES
(:race_code, :source, :outcome, CAST(:attempt_started_at AS timestamptz),
 CAST(:attempt_finished_at AS timestamptz), CAST(:retry_not_before AS timestamptz),
 :attempt_count, :terminal, :manual_review_required, :last_error_reason, :claim_token,
 CAST(:claim_expires_at AS timestamptz), :version, NOW())
ON CONFLICT (race_code) DO NOTHING
SQL);
            $insert->execute($params);
            if ($insert->rowCount() === 1) return true;
        }

        $params[':expected_version'] = $expectedVersion;
        $update = $this->pdo->prepare(<<<'SQL'
UPDATE boat_race.official_exhibition_attempt_state_v1
SET outcome = :outcome, attempt_started_at = CAST(:attempt_started_at AS timestamptz),
    attempt_finished_at = CAST(:attempt_finished_at AS timestamptz),
    retry_not_before = CAST(:retry_not_before AS timestamptz), attempt_count = :attempt_count,
    terminal = :terminal, manual_review_required = :manual_review_required,
    last_error_reason = :last_error_reason, claim_token = :claim_token,
    claim_expires_at = CAST(:claim_expires_at AS timestamptz), version = :version,
    updated_at = NOW()
WHERE race_code = :race_code AND source = :source AND version = :expected_version
  AND terminal = false AND attempt_count < 2 AND claim_token IS NULL
SQL);
        $update->execute($params);
        return $update->rowCount() === 1;
    }

    public function complete(array $before, string $claimToken, array $after): bool
    {
        $params = $this->writeParameters($after);
        $params[':expected_version'] = (int)($before['version'] ?? -1);
        $params[':expected_claim_token'] = $claimToken;
        $update = $this->pdo->prepare(<<<'SQL'
UPDATE boat_race.official_exhibition_attempt_state_v1
SET outcome = :outcome, attempt_started_at = CAST(:attempt_started_at AS timestamptz),
    attempt_finished_at = CAST(:attempt_finished_at AS timestamptz),
    retry_not_before = CAST(:retry_not_before AS timestamptz), attempt_count = :attempt_count,
    terminal = :terminal, manual_review_required = :manual_review_required,
    last_error_reason = :last_error_reason, claim_token = :claim_token,
    claim_expires_at = CAST(:claim_expires_at AS timestamptz), version = :version,
    updated_at = NOW()
WHERE race_code = :race_code AND source = :source AND version = :expected_version
  AND outcome = 'in_progress' AND claim_token = :expected_claim_token
SQL);
        $update->execute($params);
        return $update->rowCount() === 1;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function writeParameters(array $state): array
    {
        return [
            ':race_code' => (string)$state['race_code'], ':source' => (string)$state['source'], ':outcome' => (string)$state['outcome'],
            ':attempt_started_at' => $state['attempt_started_at'], ':attempt_finished_at' => $state['attempt_finished_at'],
            ':retry_not_before' => $state['retry_not_before'], ':attempt_count' => (int)$state['attempt_count'],
            // PDO PostgreSQL serialises PHP false as an empty string when
            // execute() receives a parameter array.  Use PostgreSQL's
            // explicit boolean literals so CAS INSERT/UPDATE cannot fail
            // before the state transition is evaluated.
            ':terminal' => (bool)$state['terminal'] ? 'true' : 'false',
            ':manual_review_required' => (bool)$state['manual_review_required'] ? 'true' : 'false',
            ':last_error_reason' => $state['last_error_reason'], ':claim_token' => $state['claim_token'],
            ':claim_expires_at' => $state['claim_expires_at'], ':version' => (int)$state['version'],
        ];
    }
}
