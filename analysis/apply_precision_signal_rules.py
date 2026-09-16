#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""一次・二次サインの精度優先探索結果を本番設定へ反映する。

画面の表示ロジックは変更せず、場別の判定条件と説明用メタデータだけを更新する。
一次★は ``all_venue_primary_optimized_ALL_*.json``、二次条件は全場集計の
``all_venue_secondary_optimized_ALL_*.json`` を入力にする。多摩川の既存二次条件と
蒲郡2Cまくりの個別検証条件は上書きしない。
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

PLACE_CODES = (
    "KRY", "TDA", "EDG", "HWJ", "TMG", "HMN", "GMG", "TKN", "TSU", "MKN", "BWK", "SME",
    "AMG", "NRT", "MRG", "KJM", "MYJ", "TKY", "SMS", "WKM", "ASY", "FKO", "KRT", "OMR",
)

# 4Cは単純なまくり率だけでなく、停止解除候補の「1C脆弱性＋ST上」を優先する。
# makurizashi単独候補はAPIの一次パラメータと一致しないため採用しない。
LANE4_RULES = {
    "KRY": ("makuri_rate", 15.0, 20.0),
    "TDA": ("makuri_rate", 10.0, 20.0),
    "EDG": ("attack_rate", 15.0, 20.0),
    "HWJ": ("makuri_rate", 10.0, 20.0),
    "HMN": ("attack_rate", 15.0, 20.0),
    "MKN": ("attack_rate", 15.0, 20.0),
    "BWK": ("attack_rate", 15.0, 20.0),
    "KJM": ("attack_rate", 15.0, None),
    "MYJ": ("makuri_rate", 10.0, 15.0),
    "SMS": ("attack_rate", 15.0, 20.0),
    "WKM": ("attack_rate", 20.0, None),
    "KRT": ("attack_rate", 20.0, None),
}

LANE4_CANDIDATES = {
    "KRY": ("kry", "makuri>=15&1C脆弱>=20+ST上"),
    "TDA": ("tda", "makuri>=10&1C脆弱>=20+ST上"),
    "EDG": ("edg", "attack>=15&1C脆弱>=20+ST上"),
    "HWJ": ("hwj", "makuri>=10&1C脆弱>=20+ST上"),
    "HMN": ("hmn", "attack>=15&1C脆弱>=20+ST上"),
    "MKN": ("mkn", "attack>=15&1C脆弱>=20+ST上"),
    "BWK": ("bwk", "attack>=15&1C脆弱>=20+ST上"),
    "KJM": ("kjm", "attack>=15+ST上"),
    "MYJ": ("myj", "makuri>=10&1C脆弱>=15+ST上"),
    "SMS": ("sms", "attack>=15&1C脆弱>=20+ST上"),
    "WKM": ("wkm", "attack>=20+ST上"),
    "KRT": ("krt", "attack>=20+ST上"),
}


def load(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def set_primary(rule: dict, item: dict | None, course: int, variant: str) -> None:
    item = item if isinstance(item, dict) else {"enabled": False, "reason": "no_stable_candidate"}
    enabled = bool(item.get("enabled"))
    existing = rule.setdefault("primary_rules", {}).setdefault(variant, {})
    existing["enabled"] = enabled
    existing["threshold"] = float(item["value"]) if enabled and isinstance(item.get("value"), (int, float)) else None
    existing["parameter"] = (
        "sashi_rate" if course == 2 and variant == "sashi" else
        "makuri_rate" if course in (2, 4) else
        "nige_rate" if course == 1 else "attack_rate"
    )
    existing["optimization"] = item


def apply_lane4_override(rule: dict, place: str, root: Path) -> None:
    override = LANE4_RULES.get(place)
    if override is None:
        return
    parameter, threshold, vuln = override
    p = rule.setdefault("primary_rules", {}).setdefault("main", {})
    p.update({
        "enabled": True,
        "threshold": threshold,
        "parameter": parameter,
        "vulnerability_threshold": vuln,
        "vulnerability_parameter": "lane1_vulnerability_rate" if vuln is not None else None,
        # APIの4C判定は従来どおり3CよりST順位が上。
        "st_relation": "up",
    })
    source = LANE4_CANDIDATES.get(place)
    if source is None:
        return
    source_path = root / "analysis" / "output" / f"{source[0]}_lane4_candidate_conditions_20260909.json"
    if not source_path.exists():
        return
    data = load(source_path)
    candidate = next((x for x in data.get("candidates", []) if x.get("condition") == source[1]), None)
    if not isinstance(candidate, dict):
        return
    # レース画面の実績欄が、旧条件ではなく今回選んだ候補の値を表示するようにする。
    overall = candidate.get("overall") or {}
    baseline = data.get("baseline") or {}
    # 2連対率を出力していない旧形式の候補は、画面の実績欄を0%にしない。
    # 条件自体は反映するが、実績欄は既存の集計値を使う。
    if "top2" not in overall or "top2" not in baseline:
        return
    p["optimization"] = {
        "enabled": True,
        "value": threshold,
        "condition": source[1],
        "overall": overall,
        "baseline": baseline,
        "precision_candidate": True,
    }


def apply_secondary(rule: dict, optimized: dict) -> None:
    secondary = rule.setdefault("secondary", {})
    for key, item in optimized.items():
        match = re.fullmatch(r"(\d+)_(sashi|makuri|main)_(double|triple)", key)
        if not match:
            continue
        course, variant, level = match.groups()
        sec = secondary.setdefault(variant, {})
        enabled = bool(item.get("enabled"))
        sec[f"{level}_enabled"] = enabled
        sec[f"{level}_optimized_enabled"] = enabled
        sec[f"{level}_optimized"] = item
    # ★★★は★★の条件を経由するため、★★停止時は必ず停止。
    for sec in secondary.values():
        if sec.get("triple_optimized_enabled") and not sec.get("double_optimized_enabled"):
            sec["triple_optimized_enabled"] = False
            sec["triple_enabled"] = False
            obj = sec.get("triple_optimized")
            if isinstance(obj, dict):
                obj["enabled"] = False
                obj["disabled_reason"] = "★★が停止のため★★★も停止"


def main() -> None:
    end = sys.argv[1] if len(sys.argv) > 1 else "20260909"
    root = Path(__file__).resolve().parents[1]
    config_path = root / "config" / "course_signal_rules.json"
    config = load(config_path)
    primary_path = root / "analysis" / "output" / f"all_venue_primary_optimized_ALL_{end}.json"
    primary = (load(primary_path).get("places") or {})
    secondary_all_path = root / "analysis" / "output" / f"all_venue_secondary_optimized_ALL_{end}.json"
    secondary_all = (load(secondary_all_path).get("places") or {}) if secondary_all_path.exists() else {}

    for place in PLACE_CODES:
        venue = config.setdefault("places", {}).setdefault(place, {})
        pitems = primary.get(place, {})
        for course in range(1, 7):
            crule = venue.setdefault(str(course), {})
            variants = {"sashi": pitems.get("2_sashi")} if course == 2 else {}
            if course == 2:
                variants["makuri"] = pitems.get("2_makuri")
            else:
                variants["main"] = pitems.get(f"{course}_main")
            for variant, item in variants.items():
                # 蒲郡2Cまくりは、同等以上ST＋1C脆弱性25%以上の個別検証を維持。
                if place == "GMG" and course == 2 and variant == "makuri":
                    continue
                set_primary(crule, item, course, variant)

            if course == 4:
                apply_lane4_override(crule, place, root)
            crule["primary_enabled"] = any(
                bool(v.get("enabled")) for v in crule.get("primary_rules", {}).values()
                if isinstance(v, dict)
            )

        # 場別二次最適化結果を適用。多摩川は既存の検証済み条件を維持。
        if place != "TMG":
            optimized = secondary_all.get(place, {})
            if optimized:
                # 各コースに分配
                for course in range(1, 7):
                    crule = config["places"][place].setdefault(str(course), {})
                    subset = {k: v for k, v in optimized.items() if k.startswith(f"{course}_")}
                    apply_secondary(crule, subset)

    config["generated_at"] = end
    config.setdefault("precision_policy", {})
    config["precision_policy"].update({
        "mode": "出現頻度より精度優先",
        "primary_min_delta_first": 3.0,
        "primary_min_delta_top3": 5.0,
        "secondary_min_delta_first": 3.0,
        "secondary_min_delta_top3": 5.0,
        "top_display_unchanged": True,
    })
    config_path.write_text(json.dumps(config, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"wrote {config_path}")


if __name__ == "__main__":
    main()
