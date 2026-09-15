#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""全場一次条件の集計CSVから表示用の場別採用設定を作る。"""

from __future__ import annotations

import csv
import json
import sys
from pathlib import Path

PLACE_CODES = (
    "KRY", "TDA", "EDG", "HWJ", "TMG", "HMN", "GMG", "TKN", "TSU", "MKN", "BWK", "SME",
    "AMG", "NRT", "MRG", "KJM", "MYJ", "TKY", "SMS", "WKM", "ASY", "FKO", "KRT", "OMR",
)


def as_float(value: str) -> float:
    return float(value or 0.0)


def main() -> None:
    end_label = sys.argv[1] if len(sys.argv) > 1 else "20260909"
    root = Path(__file__).resolve().parents[1]
    rows_by_place = {}
    for place in PLACE_CODES:
        path = root / "analysis" / "output" / f"all_venue_lane_signals_{place}_{end_label}.csv"
        if not path.exists():
            continue
        with path.open(encoding="utf-8-sig", newline="") as fp:
            rows_by_place[place] = list(csv.DictReader(fp))

    rules = {}
    for place, rows in rows_by_place.items():
        rules[place] = {}
        for course in range(1, 7):
            selected = [r for r in rows if r["course"] == str(course)]
            star = next((r for r in selected if r["scope"] == "24m_star"), None)
            base = next((r for r in selected if r["scope"] == "24m_base"), None)
            blocks = [r for r in selected if r["scope"].endswith("_star") and not r["scope"].startswith("24m_")]
            base_blocks = {r["scope"].replace("_star", "_base"): r for r in selected if r["scope"].endswith("_base") and not r["scope"].startswith("24m_")}
            stable = 0
            for row in blocks:
                b = base_blocks.get(row["scope"].replace("_star", "_base"))
                if b and int(row["n"]) >= 30 and as_float(row["top3_rate"]) >= as_float(b["top3_rate"]):
                    stable += 1
            n = int(star["n"]) if star else 0
            delta_top3 = as_float(star["delta_top3"]) if star else 0.0
            rules[place][str(course)] = {
                "primary_enabled": bool(star and n >= 100 and stable >= 3 and delta_top3 >= 3.0),
                "primary_n": n,
                "primary_stability_blocks": stable,
                "primary_delta_top3": round(delta_top3, 2),
                "primary_stats": {
                    "period": "2024-09-10～2026-09-09",
                    "n": n,
                    "first_rate": as_float(star["first_rate"]) if star else 0.0,
                    "top2_rate": as_float(star["top2_rate"]) if star else 0.0,
                    "top3_rate": as_float(star["top3_rate"]) if star else 0.0,
                    "baseline_n": int(base["n"]) if base else 0,
                    "baseline_first_rate": as_float(base["first_rate"]) if base else 0.0,
                    "baseline_top2_rate": as_float(base["top2_rate"]) if base else 0.0,
                    "baseline_top3_rate": as_float(base["top3_rate"]) if base else 0.0,
                    "first_delta": as_float(star["delta_first"]) if star else 0.0,
                    "top2_delta": as_float(star["delta_top2"]) if star else 0.0,
                    "top3_delta": delta_top3,
                },
            }

        secondary_path = root / "analysis" / "output" / f"all_venue_secondary_{place}_{end_label}.json"
        if secondary_path.exists():
            secondary = json.loads(secondary_path.read_text(encoding="utf-8"))
            for key, item in (secondary.get("courses") or {}).items():
                course_text, variant = key.split("_", 1)
                course_rule = rules[place].get(course_text)
                if course_rule is None:
                    continue
                for level in ("double", "triple"):
                    value = item.get(level) or {}
                    n = int(value.get("n", 0))
                    stable = int(value.get("stability_blocks", 0))
                    delta = float(value.get("delta_top3", 0.0))
                    # 母数・4分割安定性・改善幅を同時に満たす場合だけ、非TMG場の昇格を許可。
                    course_rule.setdefault("secondary", {})[variant] = course_rule.get("secondary", {}).get(variant, {})
                    course_rule["secondary"][variant][f"{level}_enabled"] = bool(n >= (100 if level == "double" else 50) and stable >= 3 and delta >= 1.0)
                    course_rule["secondary"][variant][f"{level}_n"] = n
                    course_rule["secondary"][variant][f"{level}_stability_blocks"] = stable
                    course_rule["secondary"][variant][f"{level}_delta_top3"] = round(delta, 2)

    output = root / "config" / "course_signal_rules.json"
    output.write_text(json.dumps({
        "version": 1,
        "generated_at": end_label,
        "primary_rule": {
            "1": "逃げ率55%以上",
            "2": "差し率10%以上、またはまくり率5%以上かつ内側より平均ST順位上",
            "3": "攻め率15%以上",
            "4": "まくり率15%以上かつ3Cより平均ST順位上",
            "5": "攻め率10%以上",
            "6": "攻め率5%以上かつ5Cより平均ST順位上",
        },
        "places": rules,
    }, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"wrote {output} ({len(rules)} places)")


if __name__ == "__main__":
    main()
