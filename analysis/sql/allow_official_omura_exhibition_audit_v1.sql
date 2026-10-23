-- Proposal only; it is not applied by application code.
ALTER TABLE boat_race.exhibition_source_audit_v1
    DROP CONSTRAINT IF EXISTS exhibition_source_audit_v1_source_check;
ALTER TABLE boat_race.exhibition_source_audit_v1
    ADD CONSTRAINT exhibition_source_audit_v1_source_check
    CHECK (source IN ('official_amagasaki', 'official_tamagawa', 'official_suminoe', 'official_omura', 'kyoteibiyori_nightly'));
