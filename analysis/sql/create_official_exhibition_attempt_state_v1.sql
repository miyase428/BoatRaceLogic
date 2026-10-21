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
    CHECK (outcome IN ('not_attempted', 'in_progress', 'not_yet_published', 'saved',
        'http_restricted', 'server_error', 'parse_error', 'validation_error', 'source_unknown')),
    CHECK ((outcome <> 'in_progress') OR (claim_token IS NOT NULL AND claim_expires_at IS NOT NULL)),
    CHECK ((outcome = 'in_progress') OR (claim_token IS NULL AND claim_expires_at IS NULL))
);

-- Claim transaction: first insert the claimed v1 state with ON CONFLICT DO
-- NOTHING.  If it already exists, use the versioned UPDATE below.  The
-- affected-row count must be one; zero means another job owns the claim or the
-- state changed, so do not fetch.
-- INSERT INTO boat_race.official_exhibition_attempt_state_v1 (...) VALUES (...)
-- ON CONFLICT (race_code) DO NOTHING;
-- UPDATE boat_race.official_exhibition_attempt_state_v1
-- SET outcome = :outcome, attempt_started_at = :started_at,
--     attempt_count = :attempt_count, claim_token = :claim_token,
--     claim_expires_at = :claim_expires_at, version = version + 1, updated_at = NOW()
-- WHERE race_code = :race_code AND version = :expected_version
--   AND terminal = false AND attempt_count < 2 AND claim_token IS NULL;

-- Complete inside the same transaction as the six exhibition rows and source
-- audit.  This preserves the claim token and version ownership until commit.
-- UPDATE boat_race.official_exhibition_attempt_state_v1
-- SET outcome = :outcome, attempt_finished_at = :finished_at,
--     retry_not_before = :retry_not_before, terminal = :terminal,
--     manual_review_required = :manual_review_required,
--     last_error_reason = :last_error_reason, claim_token = NULL,
--     claim_expires_at = NULL, version = version + 1, updated_at = NOW()
-- WHERE race_code = :race_code AND source = 'official_amagasaki'
--   AND version = :claim_version AND outcome = 'in_progress'
--   AND claim_token = :claim_token;
