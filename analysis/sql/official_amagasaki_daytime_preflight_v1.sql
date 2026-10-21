-- Read-only preflight. Run with psql -v ON_ERROR_STOP=1 -f this_file.
BEGIN TRANSACTION READ ONLY;

SELECT current_database() AS database_name, current_user AS database_user, current_setting('server_version') AS server_version;
SELECT to_regclass('boat_race.exhibition_live') AS exhibition_live,
       to_regclass('boat_race.exhibition_source_audit_v1') AS audit_table,
       to_regclass('boat_race.official_exhibition_attempt_state_v1') AS attempt_table,
       to_regclass('boat_race.official_exhibition_control_v1') AS control_table;
-- Control-row inspection is intentionally performed after migrations by the runbook.
SELECT race_code,
       COUNT(DISTINCT entry_course) AS stored_courses,
       COUNT(DISTINCT entry_course) FILTER (WHERE player_id IS NOT NULL AND exhibition_time > 0 AND start_timing IS NOT NULL AND lap_time > 0 AND around_time > 0) AS complete_courses
FROM boat_race.exhibition_live
WHERE race_code >= '20261021AMG01' AND race_code < '20261022AMG01'
GROUP BY race_code
ORDER BY race_code;
ROLLBACK;
