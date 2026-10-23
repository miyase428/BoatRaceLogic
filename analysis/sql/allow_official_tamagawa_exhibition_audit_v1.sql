BEGIN;

ALTER TABLE boat_race.exhibition_source_audit_v1
    DROP CONSTRAINT IF EXISTS exhibition_source_audit_v1_source_check;

ALTER TABLE boat_race.exhibition_source_audit_v1
    ADD CONSTRAINT exhibition_source_audit_v1_source_check
    CHECK (source IN ('official_amagasaki', 'official_tamagawa', 'kyoteibiyori_nightly'));

COMMIT;
