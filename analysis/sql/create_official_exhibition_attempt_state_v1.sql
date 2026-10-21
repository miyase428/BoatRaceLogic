-- Proposal only. Do not apply without explicit production approval.
-- One row per race allows atomic compare-and-swap claims across scheduler jobs.
CREATE TABLE IF NOT EXISTS boat_race.official_exhibition_attempt_state_v1 (
    race_code varchar(32) PRIMARY KEY,
    source varchar(64) NOT NULL CHECK (source = 'official_amagasaki'),
    outcome varchar(64) NOT NULL,
    attempt_started_at timestamptz,
    attempt_finished_at timestamptz,
    retry_not_before timestamptz,
    attempt_count integer NOT NULL CHECK (attempt_count BETWEEN 0 AND 2),
    terminal boolean NOT NULL,
    manual_review_required boolean NOT NULL,
    last_error_reason text,
    claim_token varchar(128),
    claim_expires_at timestamptz,
    version integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT NOW(),
    CHECK ((outcome <> 'in_progress') OR (claim_token IS NOT NULL AND claim_expires_at IS NOT NULL))
);

-- Claim only after reading the same version. Affected-row count must be one;
-- zero means another job owns the claim or the state changed, so do not fetch.
-- UPDATE boat_race.official_exhibition_attempt_state_v1
-- SET outcome = :outcome, attempt_started_at = :started_at,
--     attempt_count = :attempt_count, claim_token = :claim_token,
--     claim_expires_at = :claim_expires_at, version = version + 1, updated_at = NOW()
-- WHERE race_code = :race_code AND version = :expected_version
--   AND terminal = false AND attempt_count < 2 AND claim_token IS NULL;
