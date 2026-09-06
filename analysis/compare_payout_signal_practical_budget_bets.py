#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
配当サイン固定後の穴目研究：前段で残った実戦候補を、最終120通りの出目確率順に
6/8/10点へ絞り、実際の100円単位・1R最大1000円に近い形で比較する。

背景
----
compare_payout_signal_third_count_bets.py で、primary=イン崩壊に対し
おおむね12点前後の候補として以下が残った。

- S2_T3 : 2着Top2 × 3着条件付きTop3
- S3_T2 : 2着Top3 × 3着条件付きTop2

また8点前後の S2_T2 も実用下限として有望。
頭は FIX2 と GAP2(<2ptだけTop3追加) の両方をまだ比較対象に残す。

今回は買い目構造そのものを追加探索せず、各構造が作った候補集合の中だけで
STEP3最終120通り確率の高い順に6/8/10点を残す。

重要
----
- PayoutSignalClassifier v1相当は固定。荒れ判定を変更しない。
- 頭順位、2pt境界、2着/3着候補生成、現行cutは変更しない。
- 出目の並べ替えは既に固定済み STEP3 (delta=.25, gamma=.25) を使う。
- 実結果は評価にのみ使用し、買い目順位には使わない。
- 6/8/10点は100円単位の実用的な購入点数として事前定義し、期間を見て閾値を掘らない。
- DEV/CONFIRMは既観察期間。最終候補を固定した後、9/7以降を未使用前方検証にする。
- 本命/対抗/PredictionLogic/本番表示は変更しない。

Usage:
python3 analysis/compare_payout_signal_practical_budget_bets.py \
  analysis/output/final_prediction_boats_fast_cached_20260815_20260822.csv \
  analysis/output/final_prediction_boats_fast_cached_20260823_20260831.csv \
  analysis/output/final_prediction_boats_fast_cached_20260901_20260905.csv \
  analysis/output/kimarite_analysis_dataset_20260815_20260822.csv \
  analysis/output/kimarite_analysis_dataset_20260823_20260831.csv \
  analysis/output/kimarite_analysis_dataset_20260901_20260905.csv
"""

from __future__ import annotations

import sys
import time
from collections import defaultdict
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import final_prediction_ai_opponent_compare as b2
import final_prediction_ai_bet_integration_compare as b4
import trifecta_probability_order_compare as step3
import upset_probability_validate as c1
import upset_top2_bet_validate as c4
import validate_payout_signal_hole_bet_candidates as signal_mod
import payout_signal_relative_features as rel
import compare_payout_signal_second_count_bets as second_mod
import compare_payout_signal_third_count_bets as third_mod

CAPS = (6, 8, 10)
BASE_SPECS = {
    "FIX2_S2_T2": ("FIX2", 2, 2),
    "FIX2_S2_T3": ("FIX2", 2, 3),
    "FIX2_S3_T2": ("FIX2", 3, 2),
    "GAP2_S2_T2": ("GAP2", 2, 2),
    "GAP2_S2_T3": ("GAP2", 2, 3),
    "GAP2_S3_T2": ("GAP2", 3, 2),
}


def pct(n: int, d: int) -> float:
    return 100.0 * n / d if d else 0.0


def format_elapsed(seconds: float) -> str:
    sec = max(0, int(round(seconds)))
    m, s = divmod(sec, 60)
    h, m = divmod(m, 60)
    if h:
        return f"{h}時間{m}分{s}秒"
    if m:
        return f"{m}分{s}秒"
    return f"{s}秒"


def exact_prob_map(record: dict) -> dict[tuple[int, int, int], float]:
    probs = step3.order_adjusted_probs(record, b2.ORDER_DELTA, b2.ORDER_GAMMA)
    out: dict[tuple[int, int, int], float] = defaultdict(float)
    for idx, lanes in enumerate(record["pattern_lanes"]):
        bet = (int(lanes[0]), int(lanes[1]), int(lanes[2]))
        out[bet] += float(probs[idx])
    return dict(out)


def ranked_subset(bets, prob_map, cap: int):
    order = sorted(
        set(tuple(int(x) for x in bet) for bet in bets),
        key=lambda bet: (-float(prob_map.get(bet, 0.0)), bet),
    )
    return tuple(order[: min(int(cap), len(order))])


def build_row(record: dict, boats: dict, payout: int, kimarite_row: dict):
    if boats is None or set(boats) != set(range(1, 7)):
        return None, "boats_missing"
    if payout is None or int(payout) <= 0:
        return None, "payout_missing"

    signal = signal_mod.classify_kimarite_row(kimarite_row)
    if signal is None:
        return None, "signal_not_ready"
    if str(signal.get("primary") or "平常") != "イン崩壊":
        return None, "not_in_collapse"

    actual = b4.actual_trifecta(boats)
    if actual is None:
        return None, "actual_missing"

    f = c1.make_features(record, boats)
    if f is None:
        return None, "feature_missing"

    in_lane = int(f["in_lane"])
    trio = {int(k): float(v) for k, v in f["trio"].items()}
    order = c4.ranked_outer(trio, in_lane)
    if len(order) < 3:
        return None, "trio_rank_missing"

    prob_map = exact_prob_map(record)
    scenarios = {}
    for name, (head_rule, second_count, third_count) in BASE_SPECS.items():
        heads = second_mod.heads_for(order, trio, head_rule)
        s = third_mod.make_union(record, boats, heads, second_count, third_count)
        if s is None or not s.get("bets"):
            return None, f"{name}_missing"
        full = set(tuple(int(x) for x in bet) for bet in s["bets"])
        full_mass = sum(float(prob_map.get(bet, 0.0)) for bet in full)
        cap_sets = {cap: set(ranked_subset(full, prob_map, cap)) for cap in CAPS}
        cap_mass = {
            cap: sum(float(prob_map.get(bet, 0.0)) for bet in cap_sets[cap])
            for cap in CAPS
        }
        scenarios[name] = {
            "full": full,
            "full_mass": full_mass,
            "cap_sets": cap_sets,
            "cap_mass": cap_mass,
        }

    return {
        "race_code": str(record["race_code"]),
        "actual": tuple(int(x) for x in actual),
        "payout": int(payout),
        "in_lane": in_lane,
        "in_failed": int(actual[0]) != in_lane,
        "trio_gap_pt": second_mod.trio_gap_pt(trio, order),
        "scenarios": scenarios,
    }, None


def build_rows(records, boats_map, payouts, kimarite_map):
    rows = []
    skip = defaultdict(int)
    for record in records:
        code = str(record["race_code"])
        k = kimarite_map.get(code)
        if k is None:
            skip["kimarite_missing"] += 1
            continue
        row, reason = build_row(record, boats_map.get(code), payouts.get(code), k)
        if row is None:
            skip[str(reason or "not_ready")] += 1
            continue
        rows.append(row)
        skip["ready_in_collapse"] += 1
    return rows, skip


def get_bets(row: dict, base: str, cap: int | None):
    s = row["scenarios"][base]
    if cap is None:
        return set(s["full"])
    return set(s["cap_sets"][int(cap)])


def evaluate(rows: list[dict], base: str, cap: int | None):
    n = hits = points = 0
    invest = ret = 0.0
    mass_sum = 0.0
    mass_ratio_sum = 0.0
    full_hit = kept_hit = 0

    for row in rows:
        s = row["scenarios"][base]
        bets = get_bets(row, base, cap)
        cnt = len(bets)
        if cnt <= 0:
            continue

        n += 1
        points += cnt
        invest += cnt * 100.0
        actual = tuple(row["actual"])
        full_has = actual in s["full"]
        hit = actual in bets
        full_hit += 1 if full_has else 0
        kept_hit += 1 if hit else 0
        if hit:
            hits += 1
            ret += float(row["payout"])

        if cap is None:
            selected_mass = float(s["full_mass"])
        else:
            selected_mass = float(s["cap_mass"][int(cap)])
        full_mass = float(s["full_mass"])
        mass_sum += selected_mass
        mass_ratio_sum += selected_mass / full_mass if full_mass > 0 else 0.0

    return {
        "n": n,
        "avg_points": points / n if n else 0.0,
        "avg_invest": invest / n if n else 0.0,
        "hits": hits,
        "hit_rate": pct(hits, n),
        "roi": 100.0 * ret / invest if invest > 0 else 0.0,
        "avg_mass": mass_sum / n if n else 0.0,
        "mass_retention": 100.0 * mass_ratio_sum / n if n else 0.0,
        "full_hits": full_hit,
        "kept_hits": kept_hit,
        "hit_retention": 100.0 * kept_hit / full_hit if full_hit else 0.0,
    }


def compare_caps(rows: list[dict], base: str, a: int, b: int):
    gained = lost = both = neither = 0
    for row in rows:
        actual = tuple(row["actual"])
        ah = actual in get_bets(row, base, a)
        bh = actual in get_bets(row, base, b)
        if bh and not ah:
            gained += 1
        elif ah and not bh:
            lost += 1
        elif ah and bh:
            both += 1
        else:
            neither += 1
    return gained, lost, both, neither


def print_table(title: str, rows: list[dict]):
    print(f"\n【{title}: 120通り確率順で6/8/10点へ圧縮】")
    print("構造            上限   平均点  平均投資  的中率 100円/点ROI  確率mass保持  元構造的中保持")
    print("-" * 100)
    out = {}
    for base in BASE_SPECS:
        for cap in (*CAPS, None):
            m = evaluate(rows, base, cap)
            out[(base, cap)] = m
            cap_label = "ALL" if cap is None else str(cap)
            print(
                f"{base:<15} {cap_label:>4}  {m['avg_points']:>7.2f}  {m['avg_invest']:>7.0f}円  "
                f"{m['hit_rate']:>6.2f}%    {m['roi']:>8.2f}%      "
                f"{m['mass_retention']:>7.2f}%        {m['hit_retention']:>7.2f}%"
            )
        print("-")
    return out


def print_increment(title: str, rows: list[dict]):
    print(f"\n【{title}: 6→8→10点の的中純増】")
    print("構造            6→8 拾い/失い   8→10 拾い/失い")
    print("-" * 62)
    for base in BASE_SPECS:
        g68, l68, _, _ = compare_caps(rows, base, 6, 8)
        g810, l810, _, _ = compare_caps(rows, base, 8, 10)
        print(f"{base:<15}   +{g68:>3}/-{l68:<3}        +{g810:>3}/-{l810:<3}")


def print_main_compare(title: str, results):
    print(f"\n【{title}: 実用上限10点の主比較】")
    print("構造            平均点   的中率   ROI   mass保持  元構造的中保持")
    print("-" * 76)
    for base in BASE_SPECS:
        m = results[(base, 10)]
        print(
            f"{base:<15} {m['avg_points']:>7.2f}  {m['hit_rate']:>7.2f}%  {m['roi']:>7.2f}%  "
            f"{m['mass_retention']:>7.2f}%      {m['hit_retention']:>7.2f}%"
        )


def print_reproduction(dev, confirm):
    print("\n【DEV→CONFIRM: 上限10点再現】")
    print("構造            DEV的中 CONF的中  DEV ROI CONF ROI  DEV元的中保持 CONF元的中保持")
    print("-" * 94)
    for base in BASE_SPECS:
        d = dev[(base, 10)]
        c = confirm[(base, 10)]
        print(
            f"{base:<15} {d['hit_rate']:>7.2f}% {c['hit_rate']:>7.2f}%  "
            f"{d['roi']:>7.2f}% {c['roi']:>7.2f}%      "
            f"{d['hit_retention']:>7.2f}%          {c['hit_retention']:>7.2f}%"
        )


def main():
    if len(sys.argv) != 7:
        print(
            "Usage: python3 analysis/compare_payout_signal_practical_budget_bets.py "
            "DEV1_BOATS DEV2_BOATS CONFIRM_BOATS DEV1_KIMARITE DEV2_KIMARITE CONFIRM_KIMARITE"
        )
        sys.exit(1)

    p1_csv, p2_csv, p3_csv, k1_csv, k2_csv, k3_csv = sys.argv[1:]
    t0 = time.perf_counter()
    print(f"開始時刻 : {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}", flush=True)
    print("共通レコード構築中...", flush=True)

    dev_common = step3.build_common_records(p1_csv, p2_csv)
    confirm_common = step3.build_common_records(p2_csv, p3_csv)
    dev_records = list(dev_common["records"]["P1"]) + list(dev_common["records"]["P2"])
    confirm_records = list(confirm_common["records"]["P2"])

    print("CSV/決まり手/払戻読込中...", flush=True)
    boats_map = b2.load_boats(p1_csv, p2_csv, p3_csv)
    kimarite_map = rel.load_kimarite(k1_csv, k2_csv, k3_csv)
    payouts = b4.load_payouts(dev_common["p1_start"], confirm_common["p2_end"])

    dev_rows, dev_skip = build_rows(dev_records, boats_map, payouts, kimarite_map)
    confirm_rows, confirm_skip = build_rows(confirm_records, boats_map, payouts, kimarite_map)

    print("=" * 124)
    print("配当サイン固定：候補買い目を最終120通り確率順に6/8/10点へ圧縮")
    print("=" * 124)
    print(f"DEV     : {dev_common['p1_start']} ～ {dev_common['p2_end']}")
    print(f"CONFIRM : {confirm_common['p2_start']} ～ {confirm_common['p2_end']} ※既観察期間")
    print("対象     : primary=イン崩壊")
    print("候補構造 : FIX2/GAP2 × S2_T2/S2_T3/S3_T2")
    print("順位     : 各候補集合内を固定済みSTEP3最終120通り確率で降順")
    print("上限     : 6/8/10点（100円単位、10点なら最大1000円）")
    print("本番変更 : なし。9/7以降は未使用前方検証として温存")

    dev_result = print_table("DEV", dev_rows)
    confirm_result = print_table("CONFIRM", confirm_rows)
    print_increment("DEV", dev_rows)
    print_increment("CONFIRM", confirm_rows)
    print_main_compare("DEV", dev_result)
    print_main_compare("CONFIRM", confirm_result)
    print_reproduction(dev_result, confirm_result)

    print("\n【判断方針】")
    print("1. まず上限10点で、元の約12点構造の的中をどこまで保てるかを見る")
    print("2. S2_T3 vs S3_T2は同じ点数上限で直接比較する")
    print("3. GAP2が10点上限でもFIX2より純増するかを見る")
    print("4. 6/8点はさらに点数を削る場合の実用下限として比較する")
    print("5. ROIは既観察期間なので参考。的中率・的中保持・DEV→CONFIRM再現を優先する")
    print("6. ここで最終1～2方式を固定し、9/7以降は条件を変えず未使用前方検証する")

    print("\njoin/skip DEV    :", dict(dev_skip))
    print("join/skip CONFIRM:", dict(confirm_skip))
    print("=" * 124)
    print(f"総所要時間 : {format_elapsed(time.perf_counter()-t0)}")


if __name__ == "__main__":
    main()
