-- ChatGPT分析用: PostgreSQLから「1 race_code × 1艇」のCSVを出力する。
--
-- 実行例（このSQL自体は実行しない）:
-- psql "$DATABASE_URL" -X -v ON_ERROR_STOP=1 \
--   -v start_date='2026-09-21' \
--   -v end_date='2026-09-27' \
--   -v output_csv='/tmp/chat_analysis_20260921_20260927.csv' \
--   -f analysis/sql/export_chat_analysis_dataset.sql
--
-- boat_number は枠番（race_entry.lane_number、1～6）として出力する。
-- 機器としてのボート番号は boat_serial_number に別出力する。
-- 結果テーブルをINNER JOINするため、結果確定済みの艇だけが対象となる。

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

\copy (
WITH latest_exhibition AS (
    -- exhibition_live は race_code + entry_course が主キーのため、選手IDで
    -- 1艇1行へ結合する前に race_code + player_id で最新記録1件へ固定する。
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
    WHERE el.player_id IS NOT NULL
    ORDER BY el.race_code, el.player_id, el.created_date DESC NULLS LAST, el.entry_course
),
winner_result AS (
    -- 決まり手と勝者進入コースは、rank = '1' の結果1行からレース単位で取得する。
    SELECT
        rrd.race_code,
        rrd.entry_course AS winner_course,
        rrd.technique AS kimarite
    FROM boat_race.race_result_detail AS rrd
    WHERE rrd.rank = '1'
)
SELECT
    re.race_code,
    re.race_date,
    re.stadium_code AS stadium,
    RIGHT(re.race_code, 2)::integer AS race_number,

    re.lane_number AS boat_number,
    re.boat_number AS boat_serial_number,
    COALESCE(rrd.entry_course, le.entry_course, re.lane_number) AS course,
    re.player_id,

    ps.national_win_rate,
    ps.local_win_rate,
    ps.national_exacta_rate AS national_2ren_rate,
    ps.local_exacta_rate AS local_2ren_rate,
    rr.average_start AS average_st,
    re.motor_number,
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
    rp.exacta_combination AS exacta_combination,
    rp.exacta_payout,
    rp.trifecta_combination,
    rp.trifecta_payout
FROM boat_race.race_entry AS re
JOIN boat_race.race_result_detail AS rrd
  ON rrd.race_code = re.race_code
 AND rrd.lane_number = re.lane_number
LEFT JOIN boat_race.player_stats AS ps
  ON ps.race_code = re.race_code
 AND ps.player_id = re.player_id
LEFT JOIN boat_race.engine_specs AS es
  ON es.race_code = re.race_code
 AND es.motor_number = re.motor_number
LEFT JOIN latest_exhibition AS le
  ON le.race_code = re.race_code
 AND le.player_id = re.player_id
LEFT JOIN boat_race.racer_results AS rr
  ON rr.player_id = re.player_id
 AND rr.term_info = CASE
    WHEN EXTRACT(MONTH FROM re.race_date) <= 4
      THEN TO_CHAR(re.race_date - INTERVAL '1 year', 'YY') || '10'
    WHEN EXTRACT(MONTH FROM re.race_date) <= 10
      THEN TO_CHAR(re.race_date, 'YY') || '04'
    ELSE TO_CHAR(re.race_date, 'YY') || '10'
 END
LEFT JOIN winner_result AS wr
  ON wr.race_code = re.race_code
LEFT JOIN boat_race.race_payouts AS rp
  ON rp.race_code = re.race_code
WHERE re.race_date BETWEEN :'start_date'::date AND :'end_date'::date
ORDER BY re.race_date, re.stadium_code, re.race_code, re.lane_number
) TO :'output_csv' WITH (FORMAT csv, HEADER true, ENCODING 'UTF8')
