-- Proposal only. Do not apply without explicit production approval.
-- Extends the shared cut-over control and provenance audit for the one
-- explicitly permitted 23:00 JST Kyotei Biyori backfill route.
ALTER TABLE boat_race.official_exhibition_control_v1
    ADD COLUMN IF NOT EXISTS nightly_backfill_enabled boolean NOT NULL DEFAULT false;

ALTER TABLE boat_race.exhibition_source_audit_v1
    DROP CONSTRAINT IF EXISTS exhibition_source_audit_v1_source_check;
ALTER TABLE boat_race.exhibition_source_audit_v1
    ADD CONSTRAINT exhibition_source_audit_v1_source_check
    CHECK (source IN ('official_amagasaki', 'kyoteibiyori_nightly'));

-- Leave disabled after migration. It is separate from official-site approval.
UPDATE boat_race.official_exhibition_control_v1
SET nightly_backfill_enabled = false
WHERE control_key = 'amagasaki' AND nightly_backfill_enabled IS NULL;
