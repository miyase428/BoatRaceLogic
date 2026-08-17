#!/usr/bin/env python3
import sys
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(Path(__file__).resolve().parent))

from slit_racer_compare import load_races, build_finish
from slit_validate_v2 import connect_db

TYPE_BONUS = {
    "逃げ型": 1,
    "差し型": 1,
    "攻め型": 1,
    "脆い型": -1,
    "無色": 0,
}

METRICS = ("win", "top2", "top3")


def load_historical_kimarite(start_date: str, end_date: str):
    """対象レース日を基準に、その時点より前6ヶ月の同選手×同コース成績を集計する。

    public/kimarite_api.php の CASE 優先順位・分母定義を再現する。
    対象レースの今回コースは exhibition_live.entry_course を使用する。
    """
    sql = """
    WITH target AS (
        SELECT DISTINCT
            re.race_code,
            re.player_id::text AS player_id,
            el.entry_course::integer AS target_course,
            rm.race_date AS target_date
        FROM boat_race.race_entry re
        JOIN boat_race.exhibition_live el
          ON el.race_code = re.race_code
         AND el.player_id = re.player_id
        JOIN boat_race.race_master rm
          ON rm.race_code = re.race_code
        WHERE rm.race_date BETWEEN %s::date AND %s::date
          AND el.entry_course BETWEEN 1 AND 6
    ),
    winners AS (
        SELECT
            rrd.race_code,
            MAX(rrd.entry_course) FILTER (
                WHERE TRIM(rrd.rank::text) = '1'
            ) AS winner_course
        FROM boat_race.race_result_detail rrd
        JOIN boat_race.race_master rm
          ON rm.race_code = rrd.race_code
        WHERE rm.race_date >= %s::date - INTERVAL '6 months'
          AND rm.race_date <  %s::date + INTERVAL '1 day'
        GROUP BY rrd.race_code
    ),
    past AS (
        SELECT
            rrd.race_code,
            rrd.player_id::text AS player_id,
            rrd.entry_course::integer AS entry_course,
            TRIM(rrd.rank::text) AS rank,
            rrd.technique,
            rm.race_date,
            w.winner_course
        FROM boat_race.race_result_detail rrd
        JOIN boat_race.race_master rm
          ON rm.race_code = rrd.race_code
        LEFT JOIN winners w
          ON w.race_code = rrd.race_code
        WHERE rm.race_date >= %s::date - INTERVAL '6 months'
          AND rm.race_date <  %s::date + INTERVAL '1 day'
    )
    SELECT
        t.race_code,
        t.player_id,
        t.target_course,
        COUNT(p.race_code) AS total_cnt,
        COUNT(*) FILTER (
            WHERE p.entry_course = 1 AND p.rank = '1'
        ) AS nige_cnt,
        COUNT(*) FILTER (
            WHERE p.entry_course = 1 AND p.rank <> '1' AND p.technique = '差し'
        ) AS sasare_cnt,
        COUNT(*) FILTER (
            WHERE p.entry_course = 1 AND p.rank <> '1' AND p.technique = 'まくり'
        ) AS makurare_cnt,
        COUNT(*) FILTER (
            WHERE p.entry_course = 1 AND p.rank <> '1' AND p.technique = 'まくり差し'
        ) AS makurarezashi_cnt,
        COUNT(*) FILTER (
            WHERE p.entry_course = 2 AND p.rank <> '1' AND p.winner_course = 1
        ) AS nogashi_cnt,
        COUNT(*) FILTER (
            WHERE p.entry_course <> 1 AND p.rank = '1' AND p.technique = '差し'
        ) AS sashi_cnt,
        COUNT(*) FILTER (
            WHERE p.entry_course <> 1 AND p.rank = '1' AND p.technique = 'まくり'
        ) AS makuri_cnt,
        COUNT(*) FILTER (
            WHERE p.entry_course <> 1 AND p.rank = '1' AND p.technique = 'まくり差し'
        ) AS makurizashi_cnt
    FROM target t
    LEFT JOIN past p
      ON p.player_id = t.player_id
     AND p.entry_course = t.target_course
     AND p.race_date >= t.target_date - INTERVAL '6 months'
     AND p.race_date <  t.target_date
    GROUP BY t.race_code, t.player_id, t.target_course
    ORDER BY t.race_code, t.target_course
    """

    params = (
        start_date, end_date,
        start_date, end_date,
        start_date, end_date,
    )

    out = {}
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, params)
            for row in cur.fetchall():
                (
                    race_code, player_id, course, total,
                    nige, sasare, makurare, makurarezashi,
                    nogashi, sashi, makuri, makurizashi,
                ) = row
                total = int(total or 0)
                counts = {
                    "nige": int(nige or 0),
                    "sasare": int(sasare or 0),
                    "makurare": int(makurare or 0),
                    "makurarezashi": int(makurarezashi or 0),
                    "nogashi": int(nogashi or 0),
                    "sashi": int(sashi or 0),
                    "makuri": int(makuri or 0),
                    "makurizashi": int(makurizashi or 0),
                }
                rates = {
                    k: (v / total if total else 0.0)
                    for k, v in counts.items()
                }
                out[(str(race_code), str(player_id).strip())] = {
                    "course": int(course),
                    "total": total,
                    "counts": counts,
                    "rates": rates,
                }
    return out


def decide_type(course: int, rates: dict):
    # web/logic/PredictionLogic.php の優先順位をそのまま再現
    if course == 1 and rates["nige"] >= 0.20:
        return "逃げ型", 1
    if rates["sashi"] >= 0.05:
        return "差し型", 1
    if rates["makuri"] >= 0.05 or rates["makurizashi"] >= 0.05:
        return "攻め型", 1
    if rates["sasare"] >= 0.20 or rates["makurarezashi"] >= 0.20:
        return "脆い型", -1
    return "無色", 0


def flags(rank: float):
    return {
        "win": 1 if rank == 1.0 else 0,
        "top2": 1 if rank <= 2.0 else 0,
        "top3": 1 if rank <= 3.0 else 0,
    }


def blank_stat():
    return {
        "n": 0,
        "win": 0,
        "top2": 0,
        "top3": 0,
        "rank_sum": 0.0,
    }


def add_stat(stat, rank):
    f = flags(rank)
    stat["n"] += 1
    stat["rank_sum"] += rank
    for m in METRICS:
        stat[m] += f[m]


def history_bucket(n):
    if n == 0:
        return "0"
    if n <= 4:
        return "1-4"
    if n <= 9:
        return "5-9"
    if n <= 19:
        return "10-19"
    return "20+"


def rate(stat, metric):
    return stat[metric] / stat["n"] if stat["n"] else 0.0


def avg_rank(stat):
    return stat["rank_sum"] / stat["n"] if stat["n"] else 0.0


def pct(v):
    return f"{v * 100:6.2f}%"


def pp(v):
    return f"{v * 100:+6.2f}pt"


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/kimarite_bonus_validate.py YYYY-MM-DD YYYY-MM-DD")
        sys.exit(1)

    start_date, end_date = sys.argv[1:3]
    # 形式チェック
    datetime.strptime(start_date, "%Y-%m-%d")
    datetime.strptime(end_date, "%Y-%m-%d")

    print("決まり手履歴をレース日基準で集計しています...")
    hist = load_historical_kimarite(start_date, end_date)

    races = load_races(start_date.replace("-", ""), end_date.replace("-", ""))

    baseline_by_course = {c: blank_stat() for c in range(1, 7)}
    bonus_stats = {1: blank_stat(), 0: blank_stat(), -1: blank_stat()}
    type_stats = {t: blank_stat() for t in TYPE_BONUS}
    bonus_course = {b: {c: blank_stat() for c in range(1, 7)} for b in (1, 0, -1)}
    type_course = {t: {c: blank_stat() for c in range(1, 7)} for t in TYPE_BONUS}
    history_dist = {b: Counter() for b in (1, 0, -1)}
    type_history_dist = {t: Counter() for t in TYPE_BONUS}

    observations = []
    skip = Counter()
    processed_races = 0

    for race_code in sorted(races):
        boats = races[race_code]
        if len(boats) != 6 or len({b["player_id"] for b in boats}) != 6:
            skip["not_6_entry"] += 1
            continue

        by_course = {}
        bad_course = False
        for b in boats:
            c = b["course"]
            if c not in range(1, 7) or c in by_course:
                bad_course = True
                break
            by_course[c] = b
        if bad_course or set(by_course) != set(range(1, 7)):
            skip["not_6_exhibition_course"] += 1
            continue

        finish = build_finish(by_course)
        if finish is None:
            skip["bad_result"] += 1
            continue

        race_obs = []
        missing = False
        for c in range(1, 7):
            b = by_course[c]
            h = hist.get((race_code, b["player_id"]))
            if h is None:
                missing = True
                break
            # SQL側 target_course とこちらの展示進入が一致することも確認
            if h["course"] != c:
                missing = True
                break
            typ, bonus = decide_type(c, h["rates"])
            race_obs.append((c, finish[c], typ, bonus, h["total"]))

        if missing:
            skip["missing_historical_profile"] += 1
            continue

        processed_races += 1
        observations.extend(race_obs)
        for c, rank, typ, bonus, hist_n in race_obs:
            add_stat(baseline_by_course[c], rank)
            add_stat(bonus_stats[bonus], rank)
            add_stat(type_stats[typ], rank)
            add_stat(bonus_course[bonus][c], rank)
            add_stat(type_course[typ][c], rank)
            history_dist[bonus][history_bucket(hist_n)] += 1
            type_history_dist[typ][history_bucket(hist_n)] += 1

    # コース基準との差を各観測単位で平均（コース構成の偏りを除く）
    bonus_lifts = {b: {m: 0.0 for m in METRICS} | {"rank": 0.0, "n": 0} for b in (1, 0, -1)}
    type_lifts = {t: {m: 0.0 for m in METRICS} | {"rank": 0.0, "n": 0} for t in TYPE_BONUS}

    base_rates = {
        c: {m: rate(baseline_by_course[c], m) for m in METRICS}
        | {"rank": avg_rank(baseline_by_course[c])}
        for c in range(1, 7)
    }

    for c, rank, typ, bonus, _ in observations:
        f = flags(rank)
        bonus_lifts[bonus]["n"] += 1
        type_lifts[typ]["n"] += 1
        for m in METRICS:
            bonus_lifts[bonus][m] += f[m] - base_rates[c][m]
            type_lifts[typ][m] += f[m] - base_rates[c][m]
        # 正なら「基準より着順が良い」
        bonus_lifts[bonus]["rank"] += base_rates[c]["rank"] - rank
        type_lifts[typ]["rank"] += base_rates[c]["rank"] - rank

    print("=" * 118)
    print("決まり手補正 健康診断（レース当時の直前6ヶ月を再現）")
    print("=" * 118)
    print(f"期間         : {start_date} ～ {end_date}")
    print(f"処理レース   : {processed_races}")
    print(f"処理艇数     : {len(observations)}")
    print("今回コース   : exhibition_live.entry_course")
    print("履歴期間     : 各レース日より前6ヶ月（対象レース当日は除外）")
    print("本番変更     : なし")
    print("\n【skip】")
    for key in ["not_6_entry", "not_6_exhibition_course", "bad_result", "missing_historical_profile"]:
        print(f"{key:<29}: {skip[key]}")

    print("\n【コース基準】")
    print("C      N      1着      2連対      3連対    平均着順")
    for c in range(1, 7):
        s = baseline_by_course[c]
        print(
            f"{c}C {s['n']:>6}  {pct(rate(s,'win'))}  {pct(rate(s,'top2'))}  "
            f"{pct(rate(s,'top3'))}    {avg_rank(s):.3f}"
        )

    print("\n【typeBonus別 実成績】")
    print("補正      N      1着      2連対      3連対    平均着順 | course-adjusted lift 1着/2連/3連/着順")
    for b in (1, 0, -1):
        s = bonus_stats[b]
        l = bonus_lifts[b]
        n = l["n"] or 1
        print(
            f"{b:+2d}   {s['n']:>6}  {pct(rate(s,'win'))}  {pct(rate(s,'top2'))}  {pct(rate(s,'top3'))}    {avg_rank(s):.3f} | "
            f"{pp(l['win']/n)} / {pp(l['top2']/n)} / {pp(l['top3']/n)} / {l['rank']/n:+.3f}着"
        )

    print("\n【決まり手タイプ別 実成績】")
    print("タイプ       B      N      1着      2連対      3連対    平均着順 | course-adjusted lift 1着/3連/着順")
    for typ in ("逃げ型", "差し型", "攻め型", "脆い型", "無色"):
        s = type_stats[typ]
        l = type_lifts[typ]
        n = l["n"] or 1
        print(
            f"{typ:<6} {TYPE_BONUS[typ]:+2d} {s['n']:>6}  {pct(rate(s,'win'))}  {pct(rate(s,'top2'))}  "
            f"{pct(rate(s,'top3'))}    {avg_rank(s):.3f} | "
            f"{pp(l['win']/n)} / {pp(l['top3']/n)} / {l['rank']/n:+.3f}着"
        )

    print("\n【typeBonus × コース】")
    print("補正 C      N      1着      3連対    1着lift   3連lift")
    for b in (1, 0, -1):
        for c in range(1, 7):
            s = bonus_course[b][c]
            if s["n"] == 0:
                continue
            print(
                f"{b:+2d}  {c}C {s['n']:>6}  {pct(rate(s,'win'))}  {pct(rate(s,'top3'))}  "
                f"{pp(rate(s,'win')-base_rates[c]['win'])}  {pp(rate(s,'top3')-base_rates[c]['top3'])}"
            )

    print("\n【過去同コース走数の分布：typeBonus別】")
    print("補正       N=0      1-4      5-9     10-19      20+")
    for b in (1, 0, -1):
        d = history_dist[b]
        print(f"{b:+2d}    {d['0']:>7}  {d['1-4']:>7}  {d['5-9']:>7}  {d['10-19']:>8}  {d['20+']:>8}")

    print("\n【判定の見方】")
    print("・+1が有効なら course-adjusted の1着/3連liftがプラス方向で出るのが理想")
    print("・-1が有効なら course-adjusted liftがマイナス方向で出るのが理想")
    print("・タイプ別で一部だけ逆方向なら、+1/-1を一括で与える設計を次段階で疑う")
    print("・過去同コース走数が少ない+1が多い場合、5%/20%閾値の母数問題を別途検証する")
    print("=" * 118)


if __name__ == "__main__":
    main()
