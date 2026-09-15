#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""桐生4Cのまくり系・1C脆弱性系候補を24か月で比較する。"""

from __future__ import annotations

import json
import sys
from collections import defaultdict
from datetime import date, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import analyze_tamagawa_boaters_hypothesis as base  # noqa: E402
import analyze_tamagawa_lane4_makurizashi_vulnerability as vuln  # noqa: E402
from analyze_all_venue_lane_signals import load_targets as load_lane_targets  # noqa: E402


PLACE = (sys.argv[3].upper() if len(sys.argv) > 3 else "KRY")
END = base.parse_date(sys.argv[1]) if len(sys.argv) > 1 else base.parse_date("2026-09-09")
START = base.parse_date(sys.argv[2]) if len(sys.argv) > 2 else base.months_ago(END, 24) + timedelta(days=1)


def pct(n: int, d: int) -> float:
    return 100.0 * n / d if d else 0.0


def stat(rows: list[dict]) -> dict:
    n = len(rows)
    w = sum(r["winner"] == 4 for r in rows)
    t2 = sum(4 in (r["winner"], r["second"]) for r in rows)
    t3 = sum(4 in (r["winner"], r["second"], r["third"]) for r in rows)
    return {"n": n, "first": pct(w, n), "top2": pct(t2, n), "top3": pct(t3, n)}


def periods() -> list[tuple[str, date, date]]:
    out = []
    for i in range(4):
        end = END if i == 0 else base.months_ago(END, 6 * i)
        start = START if i == 3 else base.months_ago(END, 6 * (i + 1)) + timedelta(days=1)
        out.append(("直近6か月" if i == 0 else f"{6 * (i + 1)}-{6 * i}か月前", start, end))
    return out


def main() -> None:
    base.VENUE_CODE = PLACE
    base.VENUE_NAME = PLACE
    races = load_lane_targets(START, END, (PLACE,))
    pids = sorted({b["player_id"] for r in races.values() for b in r["boats"]})
    racer = base.load_racer_results(base.required_terms(START, END))
    hist = base.TechniqueHistoryIndex(base.load_history(START, END, pids))
    vi = vuln.VulnerabilityIndex(vuln.load_lane1_vulnerability_history(START, END, pids))
    rows = []
    for code, race in races.items():
        boats = {int(b["course"]): b for b in race["boats"] if b.get("course") is not None}
        if set(boats) != set(range(1, 7)):
            continue
        term = base.term_info_for_date(race["date"])
        rr3 = racer.get((term, boats[3]["player_id"]))
        rr4 = racer.get((term, boats[4]["player_id"]))
        if not rr3 or not rr4 or rr3[3]["avg_rank"] is None or rr4[4]["avg_rank"] is None:
            continue
        p4 = hist.profile(boats[4]["player_id"], 4, race["date"], 12)
        p1 = vi.profile(boats[1]["player_id"], race["date"], 12)
        if not p4["n"] or not p1["n"]:
            continue
        m = 100.0 * p4["tech"].get("まくり", 0) / p4["n"]
        mz = 100.0 * p4["tech"].get("まくり差し", 0) / p4["n"]
        v = 100.0 * (p1["makurare"] + p1["makurarezashi"]) / p1["n"]
        rows.append({
            "date": race["date"], "winner": int(race["first"]), "second": int(race["second"]),
            "third": int(race["third"] or 0), "m": m, "mz": mz, "attack": m + mz,
            "vuln": v, "st_up": rr4[4]["avg_rank"] < rr3[3]["avg_rank"],
        })

    candidates = {}
    for kind, values in (("makuri", (10, 15, 20)), ("makurizashi", (5, 10, 15)), ("attack", (10, 12, 15, 18, 20)), ("vulnerability", (10, 15, 20))):
        for threshold in values:
            for st in (False, True):
                key = f"{kind}>={threshold:g}" + ("+ST上" if st else "")
                feature_key = {"makuri": "m", "makurizashi": "mz", "attack": "attack", "vulnerability": "vuln"}[kind]
                candidates[key] = lambda r, k=feature_key, t=threshold, s=st: (r[k] >= t and (not s or r["st_up"]))
    for first, second in (("attack", "vulnerability"), ("makurizashi", "vulnerability"), ("makuri", "vulnerability")):
        for a in ((15,) if first == "attack" else (10, 15)):
            for b in (15, 20):
                for st in (False, True):
                    key = f"{first}>={a:g}&1C脆弱>={b:g}" + ("+ST上" if st else "")
                    feature_key = {"makuri": "m", "makurizashi": "mz", "attack": "attack"}[first]
                    candidates[key] = lambda r, f=feature_key, aa=a, bb=b, s=st: (r[f] >= aa and r["vuln"] >= bb and (not s or r["st_up"]))

    all_periods = periods()
    result = []
    for key, fn in candidates.items():
        overall_rows = [r for r in rows if fn(r)]
        if len(overall_rows) < 100:
            continue
        ps = []
        valid = True
        for label, start, end in all_periods:
            rs = [r for r in rows if start <= r["date"] <= end]
            cs = [r for r in rs if fn(r)]
            bs = stat(rs); ss = stat(cs)
            ps.append({"period": label, **ss, "base_first": bs["first"], "base_top3": bs["top3"], "delta_first": ss["first"] - bs["first"], "delta_top3": ss["top3"] - bs["top3"]})
            if ss["n"] < 20 or ps[-1]["delta_first"] < 0 or ps[-1]["delta_top3"] < 1:
                valid = False
        recent_n = ps[0]["n"]
        if recent_n < 10:
            valid = False
        result.append({"condition": key, "stable": valid, "overall": stat(overall_rows), "periods": ps})

    result.sort(key=lambda x: (x["stable"], min(p["first"] for p in x["periods"]), x["overall"]["n"]), reverse=True)
    print(f"{PLACE} 4C候補比較 {START}～{END} / 対象N={len(rows)} / 基準1着率={stat(rows)['first']:.2f}%")
    for x in result[:30]:
        p = x["periods"]
        print(f"{'採用候補' if x['stable'] else '不採用':4} {x['condition']:<35} N={x['overall']['n']:4d} 1着={x['overall']['first']:5.1f}% 3連={x['overall']['top3']:5.1f}% 期間Δ1着={[round(q['delta_first'],1) for q in p]} 期間Δ3連={[round(q['delta_top3'],1) for q in p]}")
    out = Path(__file__).resolve().parent / "output" / f"{PLACE.lower()}_lane4_candidate_conditions_{END:%Y%m%d}.json"
    out.write_text(json.dumps({"period": f"{START}～{END}", "rows": len(rows), "baseline": stat(rows), "candidates": result}, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"出力: {out}")


if __name__ == "__main__":
    main()
