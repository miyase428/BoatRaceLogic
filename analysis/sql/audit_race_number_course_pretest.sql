-- 場 × コース × R番号の再現性確認
--
-- 目的:
--   2026-03-28以降のTEST期間を一切使わず、
--   芦屋(ASY)・尼崎(AMG)について
--   「R番号によって各コースの1着/2連対/3連対率が変わるか」を確認する。
--
-- 期間:
--   TRAIN_EARLY : 2023-09-28 ～ 2024-09-27
--   TRAIN_LATE  : 2024-09-28 ～ 2025-09-27
--   VALID       : 2025-09-28 ～ 2026-03-27
--   PRETEST_ALL : 上記3期間の合算
--
-- 正解コースは race_result_detail.entry_course を使用する。
-- これは既存MLの load_prerace_targets() と同じ考え方。

\set ON_ERROR_STOP on

\if :{?output_csv}
\else
  \echo 'output_csv を -v output_csv=/path/to/file.csv で指定してください。'
  \quit
\endif

\o :output_csv

COPY (
WITH target_races AS MATERIALIZED (
    SELECT DISTINCT
        re.race_code,
        re.race_date,
        re.stadium_code AS stadium,
        RIGHT(re.race_code, 2)::integer AS race_number
    FROM boat_race.race_entry AS re
    WHERE re.race_date BETWEEN DATE '2023-09-28' AND DATE '2026-03-27'
      AND re.stadium_code IN ('ASY', 'AMG')
),

finish AS MATERIALIZED (
    SELECT
        rrd.race_code,

        MAX(rrd.entry_course::integer)
            FILTER (WHERE TRIM(rrd.rank::text) = '1') AS first_course,

        MAX(rrd.entry_course::integer)
            FILTER (WHERE TRIM(rrd.rank::text) = '2') AS second_course,

        MAX(rrd.entry_course::integer)
            FILTER (WHERE TRIM(rrd.rank::text) = '3') AS third_course

    FROM boat_race.race_result_detail AS rrd

    JOIN target_races AS tr
      ON tr.race_code = rrd.race_code

    GROUP BY rrd.race_code
),

race_targets AS (
    SELECT
        tr.race_code,
        tr.race_date,
        tr.stadium,
        tr.race_number,

        f.first_course,
        f.second_course,
        f.third_course,

        CASE
            WHEN tr.race_date BETWEEN DATE '2023-09-28' AND DATE '2024-09-27'
                THEN 'TRAIN_EARLY'

            WHEN tr.race_date BETWEEN DATE '2024-09-28' AND DATE '2025-09-27'
                THEN 'TRAIN_LATE'

            WHEN tr.race_date BETWEEN DATE '2025-09-28' AND DATE '2026-03-27'
                THEN 'VALID'
        END AS period

    FROM target_races AS tr

    JOIN finish AS f
      ON f.race_code = tr.race_code

    WHERE f.first_course BETWEEN 1 AND 6
      AND f.second_course BETWEEN 1 AND 6
      AND f.third_course BETWEEN 1 AND 6
),

expanded AS (
    SELECT
        rt.period,
        rt.race_code,
        rt.race_date,
        rt.stadium,
        rt.race_number,
        c.course,

        (c.course = rt.first_course)::integer AS first_hit,

        (
            c.course IN (
                rt.first_course,
                rt.second_course
            )
        )::integer AS top2_hit,

        (
            c.course IN (
                rt.first_course,
                rt.second_course,
                rt.third_course
            )
        )::integer AS top3_hit

    FROM race_targets AS rt

    CROSS JOIN LATERAL
        generate_series(1, 6) AS c(course)
),

all_periods AS (
    SELECT
        period,
        race_code,
        race_date,
        stadium,
        race_number,
        course,
        first_hit,
        top2_hit,
        top3_hit
    FROM expanded

    UNION ALL

    SELECT
        'PRETEST_ALL' AS period,
        race_code,
        race_date,
        stadium,
        race_number,
        course,
        first_hit,
        top2_hit,
        top3_hit
    FROM expanded
),

base AS (
    SELECT
        period,
        stadium,
        course,

        COUNT(*) AS base_n,

        ROUND(
            AVG(first_hit::numeric) * 100,
            2
        ) AS base_first_rate,

        ROUND(
            AVG(top2_hit::numeric) * 100,
            2
        ) AS base_top2_rate,

        ROUND(
            AVG(top3_hit::numeric) * 100,
            2
        ) AS base_top3_rate

    FROM all_periods

    GROUP BY
        period,
        stadium,
        course
),

detail AS (
    SELECT
        period,
        stadium,
        race_number,
        course,

        COUNT(*) AS n,

        ROUND(
            AVG(first_hit::numeric) * 100,
            2
        ) AS first_rate,

        ROUND(
            AVG(top2_hit::numeric) * 100,
            2
        ) AS top2_rate,

        ROUND(
            AVG(top3_hit::numeric) * 100,
            2
        ) AS top3_rate

    FROM all_periods

    GROUP BY
        period,
        stadium,
        race_number,
        course
)

SELECT
    d.period,
    d.stadium,
    d.race_number,
    d.course,
    d.n,

    d.first_rate,
    b.base_first_rate,
    ROUND(
        d.first_rate - b.base_first_rate,
        2
    ) AS first_lift_pt,

    d.top2_rate,
    b.base_top2_rate,
    ROUND(
        d.top2_rate - b.base_top2_rate,
        2
    ) AS top2_lift_pt,

    d.top3_rate,
    b.base_top3_rate,
    ROUND(
        d.top3_rate - b.base_top3_rate,
        2
    ) AS top3_lift_pt,

    b.base_n

FROM detail AS d

JOIN base AS b
  ON b.period = d.period
 AND b.stadium = d.stadium
 AND b.course = d.course

ORDER BY
    d.stadium,
    d.course,
    CASE d.period
        WHEN 'TRAIN_EARLY' THEN 1
        WHEN 'TRAIN_LATE'  THEN 2
        WHEN 'VALID'       THEN 3
        WHEN 'PRETEST_ALL' THEN 4
        ELSE 9
    END,
    d.race_number

) TO STDOUT WITH (
    FORMAT csv,
    HEADER true,
    ENCODING 'UTF8'
);

\o
