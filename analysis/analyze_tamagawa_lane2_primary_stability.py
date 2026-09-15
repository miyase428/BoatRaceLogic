#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""多摩川2コースの一次条件を探索し、時系列安定性を確認する。

条件に使うのは対象日前の決まり手履歴、期別平均ST順位、対象日展示から
算出した二次評価。着順は成績集計だけに使用し、払戻・オッズは使わない。
"""

from __future__ import annotations

import bisect
import csv
import sys
from collections import Counter
from datetime import date, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from analyze_tamagawa_boaters_hypothesis import (  # noqa: E402
    HISTORY_MONTHS, TechniqueHistoryIndex, load_history, load_racer_results,
    months_ago, parse_date, relation_label, required_terms, term_info_for_date,
)
from analyze_tamagawa_lane4_exacta_structure import load_targets, pct  # noqa: E402
from analyze_tamagawa_lane4_strong_condition_second_eval import (  # noqa: E402
    build_second_scores, load_exhibition,
)

PROFILE_MONTHS = 12
LANE = 2

CONDITIONS = (
    ("BASE", "2C履歴あり"),
    ("W10", "2C過去1着率10%以上"),
    ("W15", "2C過去1着率15%以上"),
    ("S5", "2C差し率5%以上"),
    ("S10", "2C差し率10%以上"),
    ("A10", "2C攻め率10%以上"),
    ("A15", "2C攻め率15%以上"),
    ("ST_UP", "2Cが1Cより平均ST順位上"),
    ("S10_ST", "差し率10%以上×ST上"),
    ("A10_ST", "攻め率10%以上×ST上"),
    ("A15_ST", "攻め率15%以上×ST上"),
    ("S10_S15", "差し率10%以上×2C過去1着率15%以上"),
)


def rates(profile: dict) -> dict[str, float]:
    n = int(profile["n"])
    if n <= 0:
        return {"n": 0, "win": 0.0, "sashi": 0.0, "makuri": 0.0,
                "makurizashi": 0.0, "attack": 0.0}
    sashi = pct(int(profile["tech"].get("差し", 0)), n)
    makuri = pct(int(profile["tech"].get("まくり", 0)), n)
    makurizashi = pct(int(profile["tech"].get("まくり差し", 0)), n)
    return {"n": n, "win": pct(int(profile["win"]), n),
            "sashi": sashi, "makuri": makuri,
            "makurizashi": makurizashi, "attack": makuri + makurizashi}


class RollingExhibitionAverage:
    """対象日を含めない、場全体の過去6ヶ月展示タイム平均。"""

    def __init__(self, exhibition: dict[str, list[dict]]):
        items = []
        for code, boats in exhibition.items():
            try:
                d = date(int(code[:4]), int(code[4:6]), int(code[6:8]))
            except (TypeError, ValueError):
                continue
            for boat in boats:
                try:
                    v = float(boat["exhibition_time"])
                except (TypeError, ValueError):
                    continue
                if v > 0:
                    items.append((d, v))
        items.sort()
        self.dates = [x[0] for x in items]
        self.prefix = [0.0]
        for _, v in items:
            self.prefix.append(self.prefix[-1] + v)

    def value(self, target_date: date) -> float | None:
        lo = bisect.bisect_left(self.dates, months_ago(target_date, 6))
        hi = bisect.bisect_left(self.dates, target_date)
        n = hi - lo
        return (self.prefix[hi] - self.prefix[lo]) / n if n else None


def calc_windows(end: date):
    anchor = end + timedelta(days=1)
    long = [("直近12ヶ月", months_ago(anchor, 12), end),
            ("直近18ヶ月", months_ago(anchor, 18), end),
            ("直近24ヶ月", months_ago(anchor, 24), end)]
    labels = ("直近0-6ヶ月", "6-12ヶ月前", "12-18ヶ月前", "18-24ヶ月前")
    short = []
    for i, label in enumerate(labels):
        short.append((label, months_ago(anchor, (i + 1) * 6),
                      months_ago(anchor, i * 6) - timedelta(days=1)))
    return long, short


def matches(row: dict, key: str, months: int = PROFILE_MONTHS) -> bool:
    p = row[f"p2_{months}"]
    if key == "BASE":
        return p["n"] > 0
    if p["n"] <= 0:
        return False
    st_up = row["st21"] == "内側より上"
    return {
        "W10": p["win"] >= 10.0, "W15": p["win"] >= 15.0,
        "S5": p["sashi"] >= 5.0, "S10": p["sashi"] >= 10.0,
        "A10": p["attack"] >= 10.0, "A15": p["attack"] >= 15.0,
        "ST_UP": st_up, "S10_ST": p["sashi"] >= 10.0 and st_up,
        "A10_ST": p["attack"] >= 10.0 and st_up,
        "A15_ST": p["attack"] >= 15.0 and st_up,
        "S10_S15": p["sashi"] >= 10.0 and p["win"] >= 15.0,
    }.get(key, False)


def blank():
    return {"n": 0, "first": 0, "second": 0, "third": 0}


def add(stat: dict, row: dict):
    stat["n"] += 1
    stat["first"] += row["first"] == LANE
    stat["second"] += row["second"] == LANE
    stat["third"] += row["third"] == LANE


def summary(stat: dict):
    n = stat["n"]
    return {"n": n, "head": pct(stat["first"], n),
            "top2": pct(stat["first"] + stat["second"], n),
            "top3": pct(stat["first"] + stat["second"] + stat["third"], n)}


def aggregate(rows, start, end, months=PROFILE_MONTHS):
    stats = {key: blank() for key, _ in CONDITIONS}
    for row in rows:
        if not start <= row["race_date"] <= end:
            continue
        for key, _ in CONDITIONS:
            if matches(row, key, months):
                add(stats[key], row)
    return {key: summary(value) for key, value in stats.items()}


def print_window(label, start, end, rows, months=PROFILE_MONTHS):
    values = aggregate(rows, start, end, months)
    base = values["BASE"]
    print(f"\n■ {label} {start}～{end} / 過去{months}ヶ月profile")
    for key, title in CONDITIONS:
        s = values[key]
        small = " [N小]" if 0 < s["n"] < 20 else ""
        print(f"{key:<9} {title:<27} N={s['n']:4d}  "
              f"2頭={s['head']:6.2f}% ({s['head']-base['head']:+6.2f}pt)  "
              f"2連対={s['top2']:6.2f}% ({s['top2']-base['top2']:+6.2f}pt)  "
              f"3連対={s['top3']:6.2f}% ({s['top3']-base['top3']:+6.2f}pt){small}")
    return values


def build_rows(start: date, end: date):
    races = load_targets(start, end)
    pids = sorted({b["player_id"] for r in races.values() for b in r["boats"]})
    hist = TechniqueHistoryIndex(load_history(start, end, pids))
    racer = load_racer_results(required_terms(start, end))
    exhibition = load_exhibition(months_ago(start, 6), end)
    rolling = RollingExhibitionAverage(exhibition)
    rows, skips, rolling_values = [], Counter(), []
    for code, race in races.items():
        by_course = {}
        for boat in race["boats"]:
            try:
                c = int(boat["course"])
            except (TypeError, ValueError):
                continue
            if c not in range(1, 7) or c in by_course:
                by_course = {}
                break
            by_course[c] = boat
        if set(by_course) != set(range(1, 7)):
            skips["bad_entry_course"] += 1
            continue
        term = term_info_for_date(race["date"])
        avg = {}
        for c in (1, 2):
            rr = racer.get((term, by_course[c]["player_id"]))
            avg[c] = None if rr is None else rr[c]["avg_rank"]
        st21 = None if avg[1] is None or avg[2] is None else relation_label(float(avg[2]), float(avg[1]))
        profiles = {f"p2_{m}": rates(hist.profile(by_course[2]["player_id"], 2, race["date"], m))
                    for m in HISTORY_MONTHS}
        av = rolling.value(race["date"])
        if av is not None:
            rolling_values.append(av)
        second = None if av is None else build_second_scores(exhibition.get(code, []), av)
        second = None if second is None else second.get(by_course[2]["player_id"])
        row = {"race_code": code, "race_date": race["date"], "st21": st21,
               "rank1": avg[1], "rank2": avg[2], "first": int(race["first"]),
               "second": int(race["second"]), "third": int(race["third"]) if race["third"] is not None else None,
               "second_score": None, "second_rank": None, "gap": None,
               "attack_potential": None, "st_score": None, "straight_score": None,
               "ex_score": None, "lap_score": None, "mawari_score": None, **profiles}
        if second is not None:
            for k, source in (("second_score", "final_2nd_score"), ("second_rank", "second_rank"),
                              ("gap", "gap_to_top"), ("attack_potential", "attack_potential"),
                              ("st_score", "st_score"), ("straight_score", "straight_score"),
                              ("ex_score", "ex_score"), ("lap_score", "lap_score"), ("mawari_score", "mawari_score")):
                row[k] = float(second[source]) if k != "second_rank" else int(second[source])
        rows.append(row)
    return races, rows, skips, rolling_values


def write_csv(end: date, rows: list[dict]):
    path = Path(__file__).resolve().parent / "output" / f"tamagawa_lane2_primary_stability_{end:%Y%m%d}.csv"
    fields = ["race_code", "race_date", "st21", "rank1", "rank2"]
    for m in HISTORY_MONTHS:
        fields += [f"p2_{m}_{x}" for x in ("n", "win", "sashi", "makuri", "makurizashi", "attack")]
    fields += ["second_score", "second_rank", "gap", "attack_potential", "st_score", "straight_score", "ex_score", "lap_score", "mawari_score", "first", "second", "third"]
    with path.open("w", encoding="utf-8-sig", newline="") as f:
        w = csv.DictWriter(f, fieldnames=fields); w.writeheader()
        for row in rows:
            out = {k: row.get(k, "") for k in fields}; out["race_date"] = row["race_date"].isoformat()
            for m in HISTORY_MONTHS:
                for x in ("n", "win", "sashi", "makuri", "makurizashi", "attack"):
                    out[f"p2_{m}_{x}"] = row[f"p2_{m}"][x]
            w.writerow(out)
    return path


def main():
    if len(sys.argv) != 2:
        raise SystemExit("Usage: python3 analysis/analyze_tamagawa_lane2_primary_stability.py END_DATE")
    end = parse_date(sys.argv[1]); long, short = calc_windows(end)
    start = min(x[1] for x in long + short)
    races, rows, skips, vals = build_rows(start, end)
    print("=" * 170); print("多摩川2コース：主軸サイン候補比較と時系列安定性"); print("=" * 170)
    print(f"対象={start}～{end} / レース={len(races)} / 採用行={len(rows)} / 時点別展示平均="
          f"{sum(vals)/len(vals):.3f}（{min(vals):.3f}～{max(vals):.3f}）" if vals else "展示平均なし")
    if skips: print("スキップ: " + ", ".join(f"{k}={v}" for k, v in skips.items()))
    print("\n【同一12ヶ月対象でprofile長を比較】")
    for m in HISTORY_MONTHS: print_window("直近12ヶ月", long[0][1], long[0][2], rows, m)
    print("\n【長期：過去12ヶ月profile固定】")
    long_values = {label: print_window(label, st, en, rows) for label, st, en in long[1:]}
    print("\n【短期：6ヶ月×4非重複ブロック】")
    short_values = {label: print_window(label, st, en, rows) for label, st, en in short}
    print("\n【再現性まとめ】")
    for key, title in CONDITIONS[1:]:
        vals2 = [v for v in short_values.values() if v[key]["n"] > 0]
        wins = sum(v[key]["head"] > v["BASE"]["head"] for v in vals2)
        n24 = long_values["直近24ヶ月"][key]["n"]
        d24 = long_values["直近24ヶ月"][key]["head"] - long_values["直近24ヶ月"]["BASE"]["head"]
        print(f"{key:<9} {title:<27} 6ヶ月基準超え={wins}/{len(vals2)} N24={n24:4d} 24ヶ月差={d24:+.2f}pt")
    print(f"\nCSV出力: {write_csv(end, rows)}")
    print("注意: 展示6ヶ月平均は対象日を除外したpoint-in-time集計。")


if __name__ == "__main__":
    main()
