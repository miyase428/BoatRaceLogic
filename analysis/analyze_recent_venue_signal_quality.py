#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""全24場の直近120Rで一次★の実績と3C相手分布を確認する。"""

from __future__ import annotations

import json
import sys
from collections import Counter
from datetime import date, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from analyze_all_venue_lane_signals import PLACE_CODES  # noqa: E402
from analyze_tamagawa_boaters_hypothesis import months_ago, parse_date  # noqa: E402
from optimize_all_venue_primary_rules import load_feature_rows  # noqa: E402


def hit(row: dict, course: int, variant: str, rule: dict) -> bool:
    p = row["profiles"][course]
    t = float(rule["threshold"])
    if course == 1:
        return p["nige"] >= t
    if course == 2 and variant == "sashi":
        return p["sashi"] >= t
    if course == 2 and variant == "makuri":
        return p["makuri"] >= t and row["st21"] == "上"
    if course in (3, 5):
        return p["attack"] >= t
    if course == 4:
        return p["makuri"] >= t and row["st43"] == "上"
    return p["attack"] >= t and row["st65"] == "上"


def rates(rows: list[dict], course: int) -> dict:
    n = len(rows)
    return {
        "n": n,
        "first": round(100 * sum(r["first"] == course for r in rows) / n, 2) if n else 0.0,
        "top2": round(100 * sum(course in (r["first"], r["second"]) for r in rows) / n, 2) if n else 0.0,
        "top3": round(100 * sum(course in (r["first"], r["second"], r["third"]) for r in rows) / n, 2) if n else 0.0,
    }


def main() -> None:
    end = parse_date(sys.argv[1]) if len(sys.argv) > 1 else date(2026, 9, 15)
    start = months_ago(end, 12) + timedelta(days=1)
    config = json.loads((Path(__file__).resolve().parents[1] / "config/course_signal_rules.json").read_text(encoding="utf-8"))
    result = {"period": "", "venues": {}}
    for place in PLACE_CODES:
        rows = sorted(load_feature_rows(place, start, end), key=lambda r: (r["date"], r["race_code"]), reverse=True)[:120]
        venue = {"races": len(rows), "date_from": str(rows[-1]["date"]) if rows else None, "date_to": str(rows[0]["date"]) if rows else None, "courses": {}}
        q = config["places"].get(place, {})
        for course in range(1, 7):
            variants = ("sashi", "makuri") if course == 2 else ("main",)
            for variant in variants:
                rule = q.get(str(course), {}).get("primary_rules", {}).get(variant, {})
                if not rule.get("enabled"):
                    continue
                yes = [r for r in rows if hit(r, course, variant, rule)]
                no = [r for r in rows if r not in yes]
                item = {"threshold": rule["threshold"], "star": rates(yes, course), "no_star": rates(no, course)}
                item["delta_first"] = round(item["star"]["first"] - item["no_star"]["first"], 2)
                if course == 3:
                    chunks = [rows[i:i + 30] for i in range(0, len(rows), 30)]
                    item["period_first_rates"] = [rates([r for r in chunk if hit(r, course, variant, rule)], course)["first"] for chunk in chunks if chunk]
                    first3 = [r for r in yes if r["first"] == 3]
                    item["followers"] = {"second": dict(Counter(r["second"] for r in first3)), "third": dict(Counter(r["third"] for r in first3))}
                venue["courses"][f"{course}_{variant}"] = item
        result["venues"][place] = venue
        print(place, len(rows), flush=True)
    result["period"] = f"{start}～{end}"
    out = Path(__file__).resolve().parent / "output" / f"recent_venue_signal_quality_{end:%Y%m%d}.json"
    out.write_text(json.dumps(result, ensure_ascii=False, indent=2, default=str) + "\n", encoding="utf-8")
    print(f"wrote {out}")


if __name__ == "__main__":
    main()
