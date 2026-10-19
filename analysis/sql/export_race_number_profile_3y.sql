\set ON_ERROR_STOP on

\if :{?output_csv}
\else
  \echo 'output_csv を指定してください'
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
    FROM boat_race.race_entry re
    WHERE re.race_date BETWEEN DATE '2023-09-28' AND DATE '2026-09-27'
),

finish AS MATERIALIZED (
    SELECT
        rrd.race_code,

        MAX(rrd.entry_course::integer)
            FILTER (WHERE TRIM(rrd.rank::text) = '1') AS first_course,

        MAX(rrd.entry_course::integer)
            FILTER (WHERE TRIM(rrd.rank::text) = '2') AS second_course,

        MAX(rrd.entry_course::integer)
            FILTER (WHERE TRIM(rrd.rank::text) = '3') AS third_course,

        MAX(rrd.technique)
            FILTER (WHERE TRIM(rrd.rank::text) = '1') AS winner_technique

    FROM boat_race.race_result_detail rrd

    JOIN target_races tr
      ON tr.race_code = rrd.race_code

    GROUP BY rrd.race_code
)

SELECT
    tr.race_code,
    tr.race_date,
    tr.stadium,
    tr.race_number,

    CASE
        WHEN tr.race_date <= DATE '2024-09-27'
            THEN 'YEAR1'
        WHEN tr.race_date <= DATE '2025-09-27'
            THEN 'YEAR2'
        ELSE 'YEAR3'
    END AS period,

    f.first_course,
    f.second_course,
    f.third_course,
    f.winner_technique,

    rp.exacta_combination,
    rp.exacta_payout,
    rp.trifecta_combination,
    rp.trifecta_payout

FROM target_races tr

LEFT JOIN finish f
  ON f.race_code = tr.race_code

LEFT JOIN boat_race.race_payouts rp
  ON rp.race_code = tr.race_code

WHERE rp.trifecta_payout IS NOT NULL

ORDER BY
    tr.race_date,
    tr.stadium,
    tr.race_number

) TO STDOUT WITH (
    FORMAT csv,
    HEADER true,
    ENCODING 'UTF8'
);

\o
