-- ChatGPT分析用CSV
-- 1 race_code × 1艇
-- 期間内の対象race_codeを最初に絞ってからJOINする軽量版

\set ON_ERROR_STOP on

\if :{?start_date}
\else
  \echo 'start_date を -v start_date=YYYY-MM-DD で指定してください。'
  \quit
\endif

\if :{?end_date}
\else
  \echo 'end_date を -v end_date=YYYY-MM-DD で指定してください。'
  \quit
\endif

\if :{?output_csv}
\else
  \echo 'output_csv を -v output_csv=/path/to/file.csv で指定してください。'
  \quit
\endif

\o :output_csv

COPY (
WITH target_entries AS MATERIALIZED (
    SELECT
        re.race_code,
        re.race_date,
        re.stadium_code,
        re.lane_number,
        re.boat_number,
        re.player_id,
        re.motor_number
    FROM boat_race.race_entry AS re
    WHERE re.race_date BETWEEN :'start_date'::date AND :'end_date'::date
),

target_races AS MATERIALIZED (
    SELECT DISTINCT race_code
    FROM target_entries
),

target_results AS MATERIALIZED (
    SELECT rrd.*
    FROM boat_race.race_result_detail AS rrd
    JOIN target_races AS tr
      ON tr.race_code = rrd.race_code
),

completed_races AS MATERIALIZED (
    SELECT DISTINCT race_code
    FROM target_results
    WHERE rank = '1'
),

latest_exhibition AS MATERIALIZED (
    SELECT DISTINCT ON (el.race_code, el.player_id)
        el.race_code,
        el.player_id,
        el.entry_course,
        el.exhibition_time,
        el.start_timing,
        el.lap_time,
        el.around_time,
        el.straight_time
    FROM boat_race.exhibition_live AS el
    JOIN target_entries AS te
      ON te.race_code = el.race_code
     AND te.player_id = el.player_id
    WHERE el.player_id IS NOT NULL
    ORDER BY
        el.race_code,
        el.player_id,
        el.created_date DESC NULLS LAST,
        el.entry_course
),

winner_result AS MATERIALIZED (
    SELECT DISTINCT ON (tr.race_code)
        tr.race_code,
        tr.entry_course AS winner_course,
        tr.technique AS kimarite
    FROM target_results AS tr
    WHERE tr.rank = '1'
    ORDER BY tr.race_code
)

SELECT
    te.race_code,
    te.race_date,
    te.stadium_code AS stadium,
    RIGHT(te.race_code, 2)::integer AS race_number,

    te.lane_number AS boat_number,
    te.boat_number AS boat_serial_number,
    COALESCE(rrd.entry_course, le.entry_course, te.lane_number) AS course,
    te.player_id,

    ps.national_win_rate,
    ps.local_win_rate,
    ps.national_exacta_rate AS national_2ren_rate,
    ps.local_exacta_rate AS local_2ren_rate,

    rr.average_start AS average_st,

    te.motor_number,
    es.motor_exacta_rate AS motor_2ren_rate,
    es.boat_exacta_rate AS boat_2ren_rate,

    le.entry_course AS exhibition_course,
    le.exhibition_time,
    le.start_timing AS exhibition_st,
    le.lap_time,
    le.around_time,
    le.straight_time,

    rrd.rank AS finish_order,
    wr.kimarite,
    wr.winner_course,

    rp.exacta_combination,
    rp.exacta_payout,
    rp.trifecta_combination,
    rp.trifecta_payout

FROM target_entries AS te

JOIN completed_races AS cr
  ON cr.race_code = te.race_code

LEFT JOIN target_results AS rrd
  ON rrd.race_code = te.race_code
 AND rrd.lane_number = te.lane_number

LEFT JOIN boat_race.player_stats AS ps
  ON ps.race_code = te.race_code
 AND ps.player_id = te.player_id

LEFT JOIN boat_race.engine_specs AS es
  ON es.race_code = te.race_code
 AND es.motor_number = te.motor_number

LEFT JOIN latest_exhibition AS le
  ON le.race_code = te.race_code
 AND le.player_id = te.player_id

LEFT JOIN boat_race.racer_results AS rr
  ON rr.player_id = te.player_id
 AND rr.term_info = CASE
      WHEN EXTRACT(MONTH FROM te.race_date) <= 4
        THEN TO_CHAR(te.race_date - INTERVAL '1 year', 'YY') || '10'
      WHEN EXTRACT(MONTH FROM te.race_date) <= 10
        THEN TO_CHAR(te.race_date, 'YY') || '04'
      ELSE
        TO_CHAR(te.race_date, 'YY') || '10'
    END

LEFT JOIN winner_result AS wr
  ON wr.race_code = te.race_code

LEFT JOIN boat_race.race_payouts AS rp
  ON rp.race_code = te.race_code

ORDER BY
    te.race_date,
    te.stadium_code,
    te.race_code,
    te.lane_number

) TO STDOUT WITH (
    FORMAT csv,
    HEADER true,
    ENCODING 'UTF8'
);

\o
