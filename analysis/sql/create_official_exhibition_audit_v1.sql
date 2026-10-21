-- Proposal only.  Do not apply this file to production without explicit approval.
-- This table records provenance; it never re-labels pre-existing exhibition_live rows.
CREATE TABLE IF NOT EXISTS boat_race.exhibition_source_audit_v1 (
    audit_id varchar(64) PRIMARY KEY,
    race_code varchar(32) NOT NULL,
    source varchar(64) NOT NULL,
    source_url text NOT NULL,
    fetched_at timestamptz NOT NULL,
    page_fetched_at jsonb NOT NULL,
    db_saved_at timestamptz NOT NULL DEFAULT NOW(),
    before_rows jsonb NOT NULL,
    after_rows jsonb NOT NULL,
    comparison_status varchar(64) NOT NULL,
    result_status varchar(64) NOT NULL,
    error_reason text,
    created_at timestamptz NOT NULL DEFAULT NOW(),
    CONSTRAINT exhibition_source_audit_v1_source_check CHECK (source IN ('official_amagasaki'))
);

CREATE INDEX IF NOT EXISTS exhibition_source_audit_v1_race_code_idx
    ON boat_race.exhibition_source_audit_v1 (race_code, created_at DESC);
