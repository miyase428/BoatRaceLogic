-- Proposal only. Do not apply without explicit production approval.
-- This single row is the shared cut-over state for cron, Apache, retry, and
-- Strict Forward processes on every host.
CREATE TABLE IF NOT EXISTS boat_race.official_exhibition_control_v1 (
    control_key varchar(32) PRIMARY KEY CHECK (control_key = 'amagasaki'),
    route_enabled boolean NOT NULL DEFAULT false,
    automation_approved boolean NOT NULL DEFAULT false,
    nightly_backfill_enabled boolean NOT NULL DEFAULT false,
    retry_interval_seconds integer,
    version integer NOT NULL DEFAULT 1 CHECK (version >= 1),
    updated_at timestamptz NOT NULL DEFAULT NOW(),
    updated_by text,
    CHECK (retry_interval_seconds IS NULL OR retry_interval_seconds BETWEEN 0 AND 1800)
);

-- Install disabled. A NULL retry interval intentionally means an unpublished
-- first attempt becomes terminal; it never invents a retry schedule.
INSERT INTO boat_race.official_exhibition_control_v1
    (control_key, route_enabled, automation_approved, retry_interval_seconds, updated_by)
VALUES ('amagasaki', false, false, NULL, 'migration')
ON CONFLICT (control_key) DO NOTHING;

-- Approved cut-over example, to be run manually only after the runbook gates:
-- UPDATE boat_race.official_exhibition_control_v1
-- SET route_enabled = true, automation_approved = true,
--     retry_interval_seconds = :approved_seconds, version = version + 1,
--     updated_at = NOW(), updated_by = :operator
-- WHERE control_key = 'amagasaki';
