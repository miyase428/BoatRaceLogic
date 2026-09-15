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


def optimized_item(item: dict | None) -> dict | None:
    """探索結果を実戦採用判定に正規化する（改善幅1pt未満は停止）。"""
    if not isinstance(item, dict):
        return None
    train = [float(x) for x in (item.get("train_delta_top3") or [])]
    holdout = float(item.get("holdout_delta_top3", 0.0))
    enabled = bool(item.get("enabled")) and holdout >= 1.0 and bool(train) and min(train) >= 1.0
    normalized = dict(item)
    normalized["enabled"] = enabled
    if not enabled:
        normalized["disabled_reason"] = "改善幅1.0pt未満または安定性不足"
    return normalized


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
        optimized_path = root / "analysis" / "output" / f"all_venue_primary_optimized_{place}_{end_label}.json"
        optimized = {}
        if optimized_path.exists():
            optimized = (json.loads(optimized_path.read_text(encoding="utf-8")).get("places") or {}).get(place, {})
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
            legacy_enabled = bool(star and n >= 100 and stable >= 3 and delta_top3 >= 3.0)
            if course == 2:
                primary_candidates = {
                    "sashi": optimized_item(optimized.get("2_sashi")),
                    "makuri": optimized_item(optimized.get("2_makuri")),
                }
                primary_enabled = any(bool(item and item.get("enabled")) for item in primary_candidates.values()) or (not optimized)
            else:
                item = optimized_item(optimized.get(f"{course}_main"))
                primary_candidates = {"main": item} if item else {}
                primary_enabled = bool(item and item.get("enabled")) if optimized else legacy_enabled
            rules[place][str(course)] = {
                "primary_enabled": primary_enabled,
                "primary_rules": {
                    variant: {
                        "enabled": bool(item and item.get("enabled")),
                        "threshold": float(item.get("value")) if item and item.get("enabled") else None,
                        "parameter": ("sashi_rate" if course == 2 and variant == "sashi" else "makuri_rate" if course in (2, 4) else "nige_rate" if course == 1 else "attack_rate"),
                        "optimization": item,
                    }
                    for variant, item in primary_candidates.items()
                },
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
        optimized_secondary_path = root / "analysis" / "output" / f"all_venue_secondary_optimized_{place}_{end_label}.json"
        optimized_secondary = {}
        if optimized_secondary_path.exists():
            optimized_secondary = (json.loads(optimized_secondary_path.read_text(encoding="utf-8")).get("places") or {}).get(place, {})
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
        # 多摩川は既存の検証済み★★／★★★条件を公式採用しているため、
        # 今回の場別二次最適化を適用せず従来条件を維持する。
        if place == "TMG":
            optimized_secondary = {}
        for key, item in optimized_secondary.items():
            course_text, variant, level = key.split("_", 2)
            course_rule = rules[place].get(course_text)
            if course_rule is None:
                continue
            sec = course_rule.setdefault("secondary", {}).setdefault(variant, {})
            sec[f"{level}_optimized_enabled"] = bool(item.get("enabled"))
            sec[f"{level}_optimized"] = item
        # ★★★は★★を経由して表示するため、★★停止時は★★★も停止する。
        for sec in (value.get("secondary", {}).values() for value in rules[place].values()):
            for item in sec:
                if item.get("triple_optimized_enabled") and not item.get("double_optimized_enabled"):
                    item["triple_optimized_enabled"] = False
                    optimized = item.get("triple_optimized")
                    if isinstance(optimized, dict):
                        optimized["disabled_reason"] = "★★が停止のため★★★も停止"

    output = root / "config" / "course_signal_rules.json"
    output.write_text(json.dumps({
        "version": 2,
        "generated_at": end_label,
        "primary_rule": {
            "method": "場別候補閾値探索（24か月、直近6か月ホールドアウト、期間安定性）",
            "1": "逃げ率（場別最適閾値）",
            "2": "差し率／まくり率（場別最適閾値、まくりは内側より平均ST順位上）",
            "3": "攻め率（場別最適閾値）",
            "4": "まくり率（場別最適閾値、3Cより平均ST順位上）",
            "5": "攻め率（場別最適閾値）",
            "6": "攻め率（場別最適閾値、5Cより平均ST順位上）",
        },
        "places": rules,
    }, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"wrote {output} ({len(rules)} places)")


if __name__ == "__main__":
    main()
