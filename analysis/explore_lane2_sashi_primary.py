#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""停止中の2C差し一次★を、事前に固定した交差条件で再検討する。

差し率だけでは精度優先の安定性を満たさなかった場について、次の
レース前情報だけを組み合わせる。

* 2C選手の過去12か月・2コース差し率
* 2Cと1Cの期別平均ST順位関係
* 1C選手の過去12か月・まくられ／まくられ差し率
* 1C選手の過去12か月・逃げ率

候補群は結果を見る前にこのファイル内で固定する。各対象レースの
当日結果は履歴特徴量に含めず、過去18か月の3ブロックで候補を確認し、
直近6か月を未使用ホールドアウトとして採否を決める。

Usage:
    python3 analysis/explore_lane2_sashi_primary.py END_DATE PLACE_CODE
"""

from __future__ import annotations

import json
import sys
from collections import defaultdict
from datetime import date, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from analyze_all_venue_lane_signals import (  # noqa: E402
    PLACE_CODES,
    load_prerace_targets,
    profile_rates,
    windows,
)
from analyze_tamagawa_boaters_hypothesis import (  # noqa: E402
    TechniqueHistoryIndex,
    load_history,
    load_racer_results,
    months_ago,
    parse_date,
    required_terms,
    term_info_for_date,
)
from analyze_tamagawa_lane4_makurizashi_vulnerability import (  # noqa: E402
    VulnerabilityIndex,
    load_lane1_vulnerability_history,
)


PLACE_NAMES = {
    "KRY": "桐生", "TDA": "戸田", "EDG": "江戸川", "HWJ": "平和島", "TMG": "多摩川", "HMN": "浜名湖",
    "GMG": "蒲郡", "TKN": "常滑", "TSU": "津", "MKN": "三国", "BWK": "びわこ", "SME": "住之江",
    "AMG": "尼崎", "NRT": "鳴門", "MRG": "丸亀", "KJM": "児島", "MYJ": "宮島", "TKY": "徳山",
    "SMS": "下関", "WKM": "若松", "ASY": "芦屋", "FKO": "福岡", "KRT": "唐津", "OMR": "大村",
}

# この候補群は、戸田の結果を見てから選んだものではない。2C差しの構造に
# 沿った、単独条件／ST補助／1C脆弱性補助／逃げ率補助と、その最小限の交差。
SASHI_MINS = (8.0, 10.0, 12.0, 15.0)
VULNERABILITY_MINS = (20.0, 25.0)
NIGE_MAXES = (55.0, 50.0)

# 一次★の精度優先ルール。既存の全場最適化と同一に保つ。
MIN_DELTA_TOP3 = 5.0
MIN_DELTA_FIRST = 3.0
MIN_OVERALL_N = 100
MIN_HOLDOUT_N = 30
MIN_TRAIN_BLOCK_N = 20
MIN_RECENT_N = 10
# 率を条件に使う以上、1～数走の偶然を「低逃げ率／高差し率」と扱わない。
# 現行の単独★には無かった保守的な下限で、今回の交差条件に共通適用する。
MIN_RATE_HISTORY_N = 10


def pct(numerator: int, denominator: int) -> float:
    return 100.0 * numerator / denominator if denominator else 0.0


def blank() -> dict[str, int]:
    return {"n": 0, "first": 0, "top2": 0, "top3": 0}


def add(stat: dict[str, int], row: dict) -> None:
    stat["n"] += 1
    stat["first"] += int(row["first"] == 2)
    stat["top2"] += int(2 in (row["first"], row["second"]))
    stat["top3"] += int(2 in (row["first"], row["second"], row["third"]))


def summary(stat: dict[str, int]) -> dict[str, float | int]:
    n = stat["n"]
    return {
        "n": n,
        "first": pct(stat["first"], n),
        "top2": pct(stat["top2"], n),
        "top3": pct(stat["top3"], n),
    }


def st_relation(rank2: float, rank1: float) -> str:
    if rank2 < rank1:
        return "up"
    if rank2 == rank1:
        return "same"
    return "down"


def load_rows(place: str, start: date, end: date) -> list[dict]:
    """結果評価行に、各時点までの事前特徴量だけを付与する。"""
    races = load_prerace_targets(start, end, (place,))
    player_ids = sorted({boat["player_id"] for race in races.values() for boat in race["boats"]})
    racer = load_racer_results(required_terms(start, end))
    history = TechniqueHistoryIndex(load_history(start, end, player_ids))
    vulnerability = VulnerabilityIndex(load_lane1_vulnerability_history(start, end, player_ids))

    rows: list[dict] = []
    for race_code, race in races.items():
        if len(race["boats"]) != 6:
            continue
        by_course = {int(boat["course"]): boat for boat in race["boats"] if boat["course"] is not None}
        if set(by_course) != set(range(1, 7)):
            continue

        term = term_info_for_date(race["date"])
        rank1 = racer.get((term, by_course[1]["player_id"]), {}).get(1, {}).get("avg_rank")
        rank2 = racer.get((term, by_course[2]["player_id"]), {}).get(2, {}).get("avg_rank")
        if rank1 is None or rank2 is None:
            continue
        rank1, rank2 = float(rank1), float(rank2)

        p1 = profile_rates(history.profile(by_course[1]["player_id"], 1, race["date"], 12))
        p2 = profile_rates(history.profile(by_course[2]["player_id"], 2, race["date"], 12))
        if p2["n"] <= 0:
            continue
        vulnerability_profile = vulnerability.profile(by_course[1]["player_id"], race["date"], 12)
        vulnerability_rate = None
        if vulnerability_profile["n"] > 0:
            vulnerability_rate = pct(
                vulnerability_profile["makurare"] + vulnerability_profile["makurarezashi"],
                vulnerability_profile["n"],
            )

        rows.append({
            "date": race["date"],
            "race_code": race_code,
            "first": race["first"],
            "second": race["second"],
            "third": race["third"],
            "sashi_rate": p2["sashi"],
            "sashi_history_n": p2["n"],
            "lane1_nige_rate": p1["nige"],
            "lane1_history_n": p1["n"],
            "lane1_vulnerability_rate": vulnerability_rate,
            "lane1_vulnerability_n": vulnerability_profile["n"],
            "st_relation": st_relation(rank2, rank1),
        })
    return rows


def candidate_label(candidate: dict) -> str:
    parts = [f"差し率{candidate['sashi_min']:.0f}%以上"]
    relation = candidate.get("st_relation")
    if relation == "up":
        parts.append("2Cが1Cより平均ST順位上")
    elif relation == "same_or_better":
        parts.append("2Cが1Cより平均ST順位同等以上")
    if candidate.get("vulnerability_min") is not None:
        parts.append(f"1C脆弱性{candidate['vulnerability_min']:.0f}%以上")
    if candidate.get("nige_max") is not None:
        parts.append(f"1C逃げ率{candidate['nige_max']:.0f}%以下")
    return "＋".join(parts)


def build_candidates() -> list[dict]:
    """候補を重複なく、構造別に固定列挙する。"""
    candidates: list[dict] = []
    seen: set[tuple] = set()

    def add_candidate(
        sashi_min: float,
        relation: str | None = None,
        vulnerability_min: float | None = None,
        nige_max: float | None = None,
    ) -> None:
        key = (sashi_min, relation, vulnerability_min, nige_max)
        if key in seen:
            return
        seen.add(key)
        candidates.append({
            "sashi_min": sashi_min,
            "st_relation": relation,
            "vulnerability_min": vulnerability_min,
            "nige_max": nige_max,
        })

    for sashi_min in SASHI_MINS:
        add_candidate(sashi_min)
        for relation in ("up", "same_or_better"):
            add_candidate(sashi_min, relation=relation)
        for vulnerability_min in VULNERABILITY_MINS:
            add_candidate(sashi_min, vulnerability_min=vulnerability_min)
        for nige_max in NIGE_MAXES:
            add_candidate(sashi_min, nige_max=nige_max)
        # 交差は「同等以上ST」を共通にし、弱い1Cを補助条件にする場合だけを確認する。
        for vulnerability_min in VULNERABILITY_MINS:
            add_candidate(sashi_min, relation="same_or_better", vulnerability_min=vulnerability_min)
        for nige_max in NIGE_MAXES:
            add_candidate(sashi_min, relation="same_or_better", nige_max=nige_max)
    return candidates


def matches(row: dict, candidate: dict) -> bool:
    if row["sashi_history_n"] < MIN_RATE_HISTORY_N:
        return False
    if row["sashi_rate"] < candidate["sashi_min"]:
        return False
    relation = candidate.get("st_relation")
    if relation == "up" and row["st_relation"] != "up":
        return False
    if relation == "same_or_better" and row["st_relation"] == "down":
        return False
    vulnerability_min = candidate.get("vulnerability_min")
    if vulnerability_min is not None:
        rate = row["lane1_vulnerability_rate"]
        if row["lane1_vulnerability_n"] < MIN_RATE_HISTORY_N or rate is None or rate < vulnerability_min:
            return False
    nige_max = candidate.get("nige_max")
    if nige_max is not None:
        if row["lane1_history_n"] < MIN_RATE_HISTORY_N or row["lane1_nige_rate"] > nige_max:
            return False
    return True


def evaluate(rows: list[dict], candidate: dict | None, start: date | None = None, end: date | None = None) -> dict:
    stat = blank()
    for row in rows:
        if start is not None and not start <= row["date"] <= end:
            continue
        if candidate is None or matches(row, candidate):
            add(stat, row)
    return summary(stat)


def delta(candidate_stat: dict, baseline_stat: dict) -> dict[str, float]:
    return {
        "first": candidate_stat["first"] - baseline_stat["first"],
        "top2": candidate_stat["top2"] - baseline_stat["top2"],
        "top3": candidate_stat["top3"] - baseline_stat["top3"],
    }


def evaluate_candidate(rows: list[dict], candidate: dict, end: date) -> dict:
    blocks = list(windows(end))
    overall = evaluate(rows, candidate)
    base_overall = evaluate(rows, None)
    recent_rows = sorted(rows, key=lambda row: (row["date"], row["race_code"]), reverse=True)[:120]
    recent = evaluate(recent_rows, candidate)
    recent_base = evaluate(recent_rows, None)

    periods: list[dict] = []
    for label, start, block_end in blocks:
        candidate_stat = evaluate(rows, candidate, start, block_end)
        baseline_stat = evaluate(rows, None, start, block_end)
        periods.append({
            "label": label,
            "start": start.isoformat(),
            "end": block_end.isoformat(),
            "candidate": candidate_stat,
            "baseline": baseline_stat,
            "delta": delta(candidate_stat, baseline_stat),
        })

    holdout = periods[0]
    training = periods[1:]
    train_pass = all(
        period["candidate"]["n"] >= MIN_TRAIN_BLOCK_N
        and period["delta"]["first"] >= MIN_DELTA_FIRST
        and period["delta"]["top3"] >= MIN_DELTA_TOP3
        for period in training
    )
    holdout_pass = (
        holdout["candidate"]["n"] >= MIN_HOLDOUT_N
        and holdout["delta"]["first"] >= MIN_DELTA_FIRST
        and holdout["delta"]["top3"] >= MIN_DELTA_TOP3
    )
    accepted = (
        overall["n"] >= MIN_OVERALL_N
        and recent["n"] >= MIN_RECENT_N
        and train_pass
        and holdout_pass
    )
    # 選択順位は未使用ホールドアウトを使わず、学習3区間の最弱値だけで付ける。
    train_score = min(period["candidate"]["first"] for period in training) + 0.1 * min(
        period["delta"]["top3"] for period in training
    )
    return {
        "condition": candidate_label(candidate),
        "params": candidate,
        "accepted": accepted,
        "training_pass": train_pass,
        "holdout_pass": holdout_pass,
        "overall": overall,
        "baseline": base_overall,
        "overall_delta": delta(overall, base_overall),
        "recent120": recent,
        "recent120_baseline": recent_base,
        "recent120_delta": delta(recent, recent_base),
        "periods": periods,
        "training_score": train_score,
    }


def print_result(result: dict) -> None:
    all_delta = result["overall_delta"]
    holdout = result["periods"][0]
    status = "採用候補" if result["accepted"] else "不採用"
    print(
        f"{status:<4} {result['condition']}  N={result['overall']['n']:4d} "
        f"1着={result['overall']['first']:5.2f}%({all_delta['first']:+5.2f}) "
        f"2連={result['overall']['top2']:5.2f}%({all_delta['top2']:+5.2f}) "
        f"3連={result['overall']['top3']:5.2f}%({all_delta['top3']:+5.2f}) "
        f"/ HO N={holdout['candidate']['n']:3d} Δ1={holdout['delta']['first']:+5.2f} Δ3={holdout['delta']['top3']:+5.2f}"
    )


def main() -> None:
    if len(sys.argv) != 3:
        raise SystemExit("Usage: python3 analysis/explore_lane2_sashi_primary.py END_DATE PLACE_CODE")
    end = parse_date(sys.argv[1])
    place = sys.argv[2].upper()
    if place not in PLACE_CODES:
        raise SystemExit(f"unknown place: {place}")
    start = months_ago(end, 24) + timedelta(days=1)

    print(f"{PLACE_NAMES[place]} 2C差し一次★ 再探索")
    print(f"対象期間: {start}～{end}（直近6か月は未使用ホールドアウト）")
    print("候補群: 差し率単独／ST順位補助／1C脆弱性補助／1C逃げ率補助／最小限の交差")
    rows = load_rows(place, start, end)
    print(f"評価可能レース: {len(rows)}")
    baseline = evaluate(rows, None)
    print(
        f"基準: N={baseline['n']} 1着={baseline['first']:.2f}% "
        f"2連={baseline['top2']:.2f}% 3連={baseline['top3']:.2f}%"
    )

    results = [evaluate_candidate(rows, candidate, end) for candidate in build_candidates()]
    adopted = sorted(
        (result for result in results if result["accepted"]),
        key=lambda result: (result["training_score"], result["overall"]["n"]),
        reverse=True,
    )
    print(f"\n採用基準通過: {len(adopted)} / {len(results)}候補")
    for result in adopted:
        print_result(result)

    if not adopted:
        # 不採用時も、最も近い候補を示して「何が足りないか」を確認可能にする。
        ranked = sorted(
            results,
            key=lambda result: (
                int(result["training_pass"]),
                int(result["holdout_pass"]),
                min(period["delta"]["top3"] for period in result["periods"]),
                min(period["delta"]["first"] for period in result["periods"]),
                result["overall"]["n"],
            ),
            reverse=True,
        )
        print("\n最も近い不採用候補（上位10件）")
        for result in ranked[:10]:
            print_result(result)

    output = {
        "place": place,
        "place_name": PLACE_NAMES[place],
        "course": 2,
        "variant": "sashi",
        "period": {"start": start.isoformat(), "end": end.isoformat()},
        "method": {
            "history_months": 12,
            "candidate_count": len(results),
            "selection": "3つの学習6か月区間のみで順位付け、直近6か月は未使用ホールドアウト",
            "acceptance": {
                "overall_n_min": MIN_OVERALL_N,
                "holdout_n_min": MIN_HOLDOUT_N,
                "train_block_n_min": MIN_TRAIN_BLOCK_N,
            "recent120_n_min": MIN_RECENT_N,
            "rate_history_n_min": MIN_RATE_HISTORY_N,
                "first_delta_min": MIN_DELTA_FIRST,
                "top3_delta_min": MIN_DELTA_TOP3,
            },
        },
        "baseline": baseline,
        "accepted": adopted,
        "candidates": results,
    }
    out = Path(__file__).resolve().parent / "output" / f"{place.lower()}_lane2_sashi_primary_candidates_{end:%Y%m%d}.json"
    out.write_text(json.dumps(output, ensure_ascii=False, indent=2, default=str) + "\n", encoding="utf-8")
    print(f"\n詳細: {out}")


if __name__ == "__main__":
    main()
