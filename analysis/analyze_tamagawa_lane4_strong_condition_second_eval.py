#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
多摩川4コース★強条件に、現行Webの二次評価がブラッシュアップ材料として使えるかを見る。

★強条件（対象レース時点の過去profile）:
- 4コースまくり率 >= 15.0%
- 4コース選手の平均ST順位が3コース選手より上（average_rankが小さい）

二次評価は analysis/second_eval_validate.php の現行本番再現ロジックに合わせる。
- 展示タイム
- 展示ST
- 周回
- 周り足
- 直線
- NULLは中立3点
- final_2nd_score = 展示総合（旧2・4固定+1は加算しない）

主な確認:
1) ★全体の4号艇 1着 / 2連対 / 3連対
2) ★ × 4号艇の二次順位（1～6位）
3) ★ × 4号艇の二次順位帯（TOP1 / TOP2 / TOP3 / 4位以下）
4) ★ × 二次トップとの差（0 / 1-2 / 3-5 / 6点以上）
5) ★ × 二次攻め成分（展示ST点 + 直線点）

対象レース当日は決まり手profileに含めない。
過去12ヶ月profile / 過去6ヶ月profileを別々に集計する。

Usage:
  python3 analysis/analyze_tamagawa_lane4_strong_condition_second_eval.py 2025-09-01 2026-09-09
"""

from __future__ import annotations

import csv
import math
import sys
from collections import Counter, defaultdict
from datetime import date
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from analyze_tamagawa_boaters_hypothesis import (  # noqa: E402
    HISTORY_MONTHS,
    TechniqueHistoryIndex,
    load_history,
    load_racer_results,
    parse_date,
    relation_label,
    required_terms,
    term_info_for_date,
)
from analyze_tamagawa_lane4_exacta_structure import load_targets, pct  # noqa: E402
from slit_validate_v2 import connect_db  # noqa: E402

STRONG_MAKURI_RATE = 15.0
STRONG_RELATION = "内側より上"
VENUE_NAME = "多摩川"


def as_float(value):
    if value is None or value == "":
        return None
    try:
        x = float(value)
    except (TypeError, ValueError):
        return None
    return x if math.isfinite(x) else None


def avg_non_null(values: list[float | None]) -> float | None:
    valid = [v for v in values if v is not None]
    if not valid:
        return None
    return sum(valid) / len(valid)


def calc_exhibition_score(diff: float) -> float:
    if diff <= -0.10:
        return 5.0
    if diff <= -0.05:
        return 4.0
    if diff <= 0.05:
        return 3.0
    if diff <= 0.10:
        return 2.0
    return 1.0


def calc_st_score(st: float) -> float:
    if st <= 0.00:
        return 3.0
    if st <= 0.12:
        return 5.0
    if st <= 0.20:
        return 3.0
    if st <= 0.30:
        return 2.0
    return 1.0


def calc_lap_score(value: float, avg_value: float) -> float:
    diff = value - avg_value
    if diff <= -0.30:
        return 5.0
    if diff <= -0.10:
        return 4.0
    if diff <= 0.10:
        return 3.0
    if diff <= 0.30:
        return 2.0
    return 1.0


def calc_mawari_score(value: float, avg_value: float) -> float:
    diff = value - avg_value
    if diff <= -0.20:
        return 5.0
    if diff <= -0.05:
        return 4.0
    if diff <= 0.05:
        return 3.0
    if diff <= 0.20:
        return 2.0
    return 1.0


def calc_straight_score(value: float, avg_value: float) -> float:
    diff = value - avg_value
    if diff <= -0.04:
        return 5.0
    if diff <= -0.01:
        return 4.0
    if diff <= 0.01:
        return 3.0
    if diff <= 0.04:
        return 2.0
    return 1.0


def calc_second_eval(row: dict, avg_exhibition: float, avg_lap: float | None,
                     avg_mawari: float | None, avg_straight: float | None) -> dict:
    exhibition = as_float(row.get("exhibition_time"))
    st = as_float(row.get("start_timing"))
    lap = as_float(row.get("lap_time"))
    mawari = as_float(row.get("around_time"))
    straight = as_float(row.get("straight_time"))

    ex_score = 3.0 if exhibition is None else calc_exhibition_score(exhibition - avg_exhibition)
    st_score = 3.0 if st is None else calc_st_score(st)
    lap_score = 3.0 if lap is None or avg_lap is None else calc_lap_score(lap, avg_lap)
    mawari_score = 3.0 if mawari is None or avg_mawari is None else calc_mawari_score(mawari, avg_mawari)
    straight_score = 3.0 if straight is None or avg_straight is None else calc_straight_score(straight, avg_straight)

    ex_total = ex_score + lap_score + mawari_score + straight_score
    attack_potential = st_score + straight_score
    stable_score = lap_score + mawari_score
    final_score = ex_total + attack_potential + stable_score

    return {
        "ex_score": ex_score,
        "st_score": st_score,
        "lap_score": lap_score,
        "mawari_score": mawari_score,
        "straight_score": straight_score,
        "attack_potential": attack_potential,
        "stable_score": stable_score,
        "final_2nd_score": final_score,
    }


def assign_competition_ranks(boats: list[dict]) -> None:
    ordered = sorted(boats, key=lambda b: (-float(b["final_2nd_score"]), int(b["lane"])))
    prev = None
    rank = 0
    for i, boat in enumerate(ordered, start=1):
        score = float(boat["final_2nd_score"])
        if prev is None or score != prev:
            rank = i
            prev = score
        boat["second_rank"] = rank


def load_avg_exhibition() -> float:
    sql = """
        SELECT avg_exhibition_time_6m
        FROM boat_race.exhibition_avg_6m
        WHERE stadium_name = %s
        LIMIT 1
    """
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (VENUE_NAME,))
            row = cur.fetchone()
    if not row or row[0] is None or float(row[0]) <= 0:
        raise RuntimeError("多摩川の avg_exhibition_time_6m を取得できません")
    return float(row[0])


def load_exhibition(start_date: date, end_date: date) -> dict[str, list[dict]]:
    sql = """
SELECT
    rm.race_code,
    re.lane_number::integer AS lane,
    re.player_id::text AS player_id,
    el.exhibition_time,
    el.start_timing,
    el.lap_time,
    el.around_time,
    el.straight_time
FROM boat_race.race_master rm
JOIN boat_race.race_entry re
  ON re.race_code = rm.race_code
JOIN boat_race.exhibition_live el
  ON el.race_code = re.race_code
 AND el.player_id = re.player_id
WHERE rm.race_date BETWEEN %s::date AND %s::date
  AND SUBSTRING(rm.race_code, 9, 3) = 'TMG'
ORDER BY rm.race_code, re.lane_number
    """
    out = defaultdict(list)
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (start_date, end_date))
            for race_code, lane, pid, ex, st, lap, mawari, straight in cur.fetchall():
                out[str(race_code)].append({
                    "lane": int(lane),
                    "player_id": str(pid).strip(),
                    "exhibition_time": ex,
                    "start_timing": st,
                    "lap_time": lap,
                    "around_time": mawari,
                    "straight_time": straight,
                })
    return out


def build_second_scores(ex_rows: list[dict], avg_exhibition: float) -> dict[str, dict] | None:
    if len(ex_rows) != 6:
        return None

    lanes = {int(r["lane"]) for r in ex_rows}
    if lanes != set(range(1, 7)):
        return None

    avg_lap = avg_non_null([as_float(r.get("lap_time")) for r in ex_rows])
    avg_mawari = avg_non_null([as_float(r.get("around_time")) for r in ex_rows])
    avg_straight = avg_non_null([as_float(r.get("straight_time")) for r in ex_rows])

    boats = []
    for row in ex_rows:
        score = calc_second_eval(row, avg_exhibition, avg_lap, avg_mawari, avg_straight)
        boats.append({**row, **score})

    assign_competition_ranks(boats)
    top_score = max(float(b["final_2nd_score"]) for b in boats)
    out = {}
    for boat in boats:
        boat["gap_to_top"] = top_score - float(boat["final_2nd_score"])
        out[str(boat["player_id"])] = boat
    return out


def blank_stat() -> dict:
    return {
        "n": 0,
        "first": 0,
        "second": 0,
        "third": 0,
        "top2": 0,
        "top3": 0,
    }


def add_outcome(stat: dict, first: int, second: int, third: int | None) -> None:
    stat["n"] += 1
    if first == 4:
        stat["first"] += 1
    if second == 4:
        stat["second"] += 1
    if third == 4:
        stat["third"] += 1
    if first == 4 or second == 4:
        stat["top2"] += 1
    if first == 4 or second == 4 or third == 4:
        stat["top3"] += 1


def rank_band(rank: int) -> str:
    if rank == 1:
        return "二次1位"
    if rank <= 2:
        return "二次TOP2"
    if rank <= 3:
        return "二次TOP3"
    return "二次4位以下"


def gap_band(gap: float) -> str:
    if gap < 1e-9:
        return "TOP差0"
    if gap <= 2.0:
        return "TOP差1-2"
    if gap <= 5.0:
        return "TOP差3-5"
    return "TOP差6以上"


def attack_band(value: float) -> str:
    # st_score + straight_score = 2～10点
    if value >= 9.0:
        return "攻め成分9-10"
    if value >= 7.0:
        return "攻め成分7-8"
    if value >= 5.0:
        return "攻め成分5-6"
    return "攻め成分2-4"


def score_band(value: float) -> str:
    if value >= 30.0:
        return "二次30以上"
    if value >= 27.0:
        return "二次27-29"
    if value >= 24.0:
        return "二次24-26"
    return "二次23以下"


def print_stat(label: str, stat: dict) -> None:
    n = stat["n"]
    print(
        f"{label:<22} N={n:4d}  "
        f"4頭={pct(stat['first'], n):6.2f}%  "
        f"42着={pct(stat['second'], n):6.2f}%  "
        f"43着={pct(stat['third'], n):6.2f}%  "
        f"4-2連対={pct(stat['top2'], n):6.2f}%  "
        f"4-3連対={pct(stat['top3'], n):6.2f}%"
    )


def analyze(start_date: date, end_date: date):
    print("多摩川 対象レースを読み込み中...", flush=True)
    races = load_targets(start_date, end_date)
    print(f"  対象レース候補: {len(races)}", flush=True)

    target_pids = sorted({b["player_id"] for r in races.values() for b in r["boats"]})
    terms = required_terms(start_date, end_date)
    print(f"racer_results 読み込み中... terms={','.join(terms)}", flush=True)
    racer = load_racer_results(terms)

    print("決まり手履歴を一括読み込み中...", flush=True)
    history = load_history(start_date, end_date, target_pids)
    print(f"  履歴行: {len(history)}", flush=True)
    hist_index = TechniqueHistoryIndex(history)

    print("展示データを一括読み込み中...", flush=True)
    ex_by_race = load_exhibition(start_date, end_date)
    avg_exhibition = load_avg_exhibition()
    print(f"  展示レース: {len(ex_by_race)} / 多摩川6ヶ月平均展示={avg_exhibition:.3f}", flush=True)

    stats = {}
    for months in HISTORY_MONTHS:
        stats[months] = {
            "all": blank_stat(),
            "rank_exact": defaultdict(blank_stat),
            "rank_band": defaultdict(blank_stat),
            "gap_band": defaultdict(blank_stat),
            "attack_band": defaultdict(blank_stat),
            "score_band": defaultdict(blank_stat),
        }

    rows = []
    skips = Counter()
    processed = 0

    for race_code, race in races.items():
        boats = race["boats"]
        if len(boats) != 6:
            skips["not_6_boats"] += 1
            continue

        by_course = {}
        bad = False
        for boat in boats:
            c = boat["course"]
            if c not in range(1, 7) or c in by_course:
                bad = True
                break
            by_course[c] = boat
        if bad or set(by_course) != set(range(1, 7)):
            skips["bad_entry_course"] += 1
            continue

        race_date = race["date"]
        term = term_info_for_date(race_date)
        ranks = {}
        missing_rank = False
        for c in (3, 4):
            rr = racer.get((term, by_course[c]["player_id"]))
            if rr is None or rr[c]["avg_rank"] is None:
                missing_rank = True
                break
            ranks[c] = float(rr[c]["avg_rank"])
        if missing_rank:
            skips["missing_course_avg_rank"] += 1
            continue

        relation = relation_label(ranks[4], ranks[3])
        second_map = build_second_scores(ex_by_race.get(race_code, []), avg_exhibition)
        if second_map is None:
            skips["missing_or_bad_exhibition"] += 1
            continue

        pid4 = by_course[4]["player_id"]
        lane4_second = second_map.get(pid4)
        if lane4_second is None:
            skips["lane4_second_score_missing"] += 1
            continue

        first, second, third = race["first"], race["second"], race["third"]
        processed += 1

        for months in HISTORY_MONTHS:
            p4 = hist_index.profile(pid4, 4, race_date, months)
            if p4["n"] <= 0:
                skips[f"history0_{months}m"] += 1
                continue

            makuri_rate = pct(p4["tech"]["まくり"], p4["n"])
            if relation != STRONG_RELATION or makuri_rate < STRONG_MAKURI_RATE:
                continue

            second_rank = int(lane4_second["second_rank"])
            final_score = float(lane4_second["final_2nd_score"])
            gap = float(lane4_second["gap_to_top"])
            attack = float(lane4_second["attack_potential"])

            s = stats[months]
            add_outcome(s["all"], first, second, third)
            add_outcome(s["rank_exact"][str(second_rank)], first, second, third)
            add_outcome(s["rank_band"][rank_band(second_rank)], first, second, third)
            add_outcome(s["gap_band"][gap_band(gap)], first, second, third)
            add_outcome(s["attack_band"][attack_band(attack)], first, second, third)
            add_outcome(s["score_band"][score_band(final_score)], first, second, third)

            rows.append({
                "history_months": months,
                "race_code": race_code,
                "race_date": race_date.isoformat(),
                "lane4_player_id": pid4,
                "lane3_avg_rank": ranks[3],
                "lane4_avg_rank": ranks[4],
                "lane4_makuri_history_n": p4["n"],
                "lane4_makuri_rate": makuri_rate,
                "lane4_second_rank": second_rank,
                "lane4_second_score": final_score,
                "lane4_gap_to_top": gap,
                "lane4_ex_score": lane4_second["ex_score"],
                "lane4_st_score": lane4_second["st_score"],
                "lane4_lap_score": lane4_second["lap_score"],
                "lane4_mawari_score": lane4_second["mawari_score"],
                "lane4_straight_score": lane4_second["straight_score"],
                "lane4_attack_potential": attack,
                "lane4_stable_score": lane4_second["stable_score"],
                "first_course": first,
                "second_course": second,
                "third_course": third if third is not None else "",
            })

    return processed, skips, stats, rows


def print_month(months: int, s: dict) -> None:
    print("\n" + "-" * 128)
    print(f"【過去{months}ヶ月profile：★強条件 × 二次評価】")
    print("-" * 128)
    print("\n■ ★全体")
    print_stat("ALL", s["all"])

    print("\n■ 4号艇の二次順位（厳密順位）")
    for rank in map(str, range(1, 7)):
        print_stat(f"二次{rank}位", s["rank_exact"].get(rank, blank_stat()))

    print("\n■ 4号艇の二次順位帯")
    for key in ("二次1位", "二次TOP2", "二次TOP3", "二次4位以下"):
        print_stat(key, s["rank_band"].get(key, blank_stat()))

    print("\n■ 二次トップとの差")
    for key in ("TOP差0", "TOP差1-2", "TOP差3-5", "TOP差6以上"):
        print_stat(key, s["gap_band"].get(key, blank_stat()))

    print("\n■ 二次スコア帯")
    for key in ("二次30以上", "二次27-29", "二次24-26", "二次23以下"):
        print_stat(key, s["score_band"].get(key, blank_stat()))

    print("\n■ 攻め成分（展示ST点＋直線点）")
    for key in ("攻め成分9-10", "攻め成分7-8", "攻め成分5-6", "攻め成分2-4"):
        print_stat(key, s["attack_band"].get(key, blank_stat()))


def write_csv(start_date: date, end_date: date, rows: list[dict]) -> Path:
    out_dir = Path(__file__).resolve().parent / "output"
    out_dir.mkdir(parents=True, exist_ok=True)
    label = f"{start_date:%Y%m%d}_{end_date:%Y%m%d}"
    path = out_dir / f"tamagawa_lane4_strong_condition_second_eval_{label}.csv"
    fields = [
        "history_months", "race_code", "race_date", "lane4_player_id",
        "lane3_avg_rank", "lane4_avg_rank", "lane4_makuri_history_n", "lane4_makuri_rate",
        "lane4_second_rank", "lane4_second_score", "lane4_gap_to_top",
        "lane4_ex_score", "lane4_st_score", "lane4_lap_score", "lane4_mawari_score",
        "lane4_straight_score", "lane4_attack_potential", "lane4_stable_score",
        "first_course", "second_course", "third_course",
    ]
    with path.open("w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(f, fieldnames=fields)
        writer.writeheader()
        writer.writerows(rows)
    return path


def main() -> None:
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/analyze_tamagawa_lane4_strong_condition_second_eval.py START_DATE END_DATE",
            file=sys.stderr,
        )
        sys.exit(1)

    start_date = parse_date(sys.argv[1])
    end_date = parse_date(sys.argv[2])
    if start_date > end_date:
        raise RuntimeError("開始日が終了日より後です")

    processed, skips, stats, rows = analyze(start_date, end_date)

    print("\n" + "=" * 128)
    print("多摩川4コース：★強条件 × 現行二次評価 ブラッシュアップ検証")
    print("★ = 過去4コースまくり率15%以上 ＋ 4が3より平均ST順位上")
    print("二次 = second_eval_validate.php と同じ現行Web再現ロジック")
    print("=" * 128)
    print(f"対象期間   : {start_date} ～ {end_date}")
    print(f"採用候補   : {processed}")
    if skips:
        print("スキップ   : " + ", ".join(f"{k}={v}" for k, v in skips.items()))

    for months in HISTORY_MONTHS:
        print_month(months, stats[months])

    path = write_csv(start_date, end_date, rows)
    print("\n" + "=" * 128)
    print(f"CSV出力: {path}")
    print("※ まず二次評価に層別能力があるかを見る検証です。買い目や本命ロジックは変更しません。")
    print("=" * 128)


if __name__ == "__main__":
    main()
