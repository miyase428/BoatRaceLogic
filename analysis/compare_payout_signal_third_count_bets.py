#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
配当サイン固定後の穴目研究：頭候補と2着候補の検証結果を引き継ぎ、
120通りから P(3着 | 頭, 2着) を作って3着候補を絞る。

背景
----
前段 compare_payout_signal_second_count_bets.py では primary=イン崩壊 に対して、
- 頭: FIX2 / GAP2(<2ptだけTop3)
- 2着: P(2着|頭) Top1/Top2/Top3
を比較した。

結果として、
- S1は平均約7点まで絞れる一方、的中率が低い
- S2は平均約14点でDEV/CONFIRMともROI・的中のバランスが良い
- S3は平均約21点で的中率はさらに上がる
となった。

今回は3着をeligible全艇のままにせず、120通りの最終確率から
  P(3着艇 | head, second)
を直接計算し、2着ごとに3着Top1/Top2/Top3を選ぶ。

比較する実戦候補
----------------
S1_T3  : 2着Top1 × 3着Top3       （約6点目安）
S2_T2  : 2着Top2 × 3着Top2       （約8点目安）
S2_T3  : 2着Top2 × 3着Top3       （約12点目安）
S3_T2  : 2着Top3 × 3着Top2       （約12点目安）
S2_ALL : 2着Top2 × 3着eligible全艇（前段S2基準）
S3_ALL : 2着Top3 × 3着eligible全艇（前段S3基準）

頭はFIX2/GAP2の両方で同じ比較を行う。

重要
----
- PayoutSignalClassifier v1相当の荒れ判定は変更しない。
- AI3頭順位、2pt境界は変更しない。
- 2着順位は既存の P(2着|頭) を使用。
- 3着順位だけを P(3着|頭,2着) で追加する。
- 現行cutは固定。cut救済はしない。
- DEV/CONFIRMは既観察期間。ここで閾値探索はしない。
- 9/7以降は新しい未使用前方検証として残す。
- 本命/対抗/PredictionLogic/本番買い目には接続しない。

Usage:
python3 analysis/compare_payout_signal_third_count_bets.py \
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
from collections import Counter, defaultdict
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
import compare_payout_signal_second_count_bets as prev

HEAD_RULES = ("FIX2", "GAP2")

# label, second_count, third_count(None=eligible全艇)
PRACTICAL_SPECS = (
    ("S1_T3", 1, 3),
    ("S2_T2", 2, 2),
    ("S2_T3", 2, 3),
    ("S3_T2", 3, 2),
    ("S2_ALL", 2, None),
    ("S3_ALL", 3, None),
)
METHODS = tuple(f"{rule}_{label}" for rule in HEAD_RULES for label, _, _ in PRACTICAL_SPECS)


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


def outcome_third_scores(record: dict, head: int, second: int) -> dict[int, float]:
    """最終120通りから P(3着艇 | head, second) を計算する。"""
    head = int(head)
    second = int(second)
    probs = step3.order_adjusted_probs(record, b2.ORDER_DELTA, b2.ORDER_GAMMA)
    scores = {lane: 0.0 for lane in range(1, 7) if lane not in (head, second)}
    mass = 0.0

    for idx, lanes in enumerate(record["pattern_lanes"]):
        h, s, t = (int(lanes[0]), int(lanes[1]), int(lanes[2]))
        if h != head or s != second:
            continue
        p = float(probs[idx])
        mass += p
        if t not in (head, second):
            scores[t] = scores.get(t, 0.0) + p

    if mass > 0:
        scores = {lane: value / mass for lane, value in scores.items()}
    return scores


def make_one_head_bets(
    record: dict,
    boats: dict,
    head: int,
    second_count: int,
    third_count: int | None,
):
    head = int(head)
    cut = b4.current_cut(boats)
    cut.discard(head)
    eligible = [lane for lane in range(1, 7) if lane != head and lane not in cut]
    if len(eligible) < 2:
        return None

    second_scores = b2.outcome_second_scores(record, head)
    seconds = b2.select_score(second_scores, eligible, min(int(second_count), len(eligible)))
    if not seconds:
        return None

    bets = set()
    third_by_second = {}
    for second in seconds:
        third_eligible = [lane for lane in eligible if lane != int(second)]
        if not third_eligible:
            continue

        if third_count is None:
            thirds = list(third_eligible)
        else:
            third_scores = outcome_third_scores(record, head, int(second))
            thirds = b2.select_score(
                third_scores,
                third_eligible,
                min(int(third_count), len(third_eligible)),
            )

        third_by_second[int(second)] = tuple(int(x) for x in thirds)
        for third in thirds:
            if head == int(second) or head == int(third) or int(second) == int(third):
                continue
            bets.add((head, int(second), int(third)))

    if not bets:
        return None

    return {
        "head": head,
        "cut": set(cut),
        "eligible": tuple(int(x) for x in eligible),
        "second": tuple(int(x) for x in seconds),
        "third_by_second": third_by_second,
        "bets": bets,
    }


def make_union(
    record: dict,
    boats: dict,
    heads: tuple[int, ...],
    second_count: int,
    third_count: int | None,
):
    bets = set()
    per_head = {}
    valid_heads = []
    for head in dict.fromkeys(int(h) for h in heads):
        one = make_one_head_bets(record, boats, head, second_count, third_count)
        if one is None:
            continue
        valid_heads.append(head)
        per_head[head] = one
        bets |= set(one["bets"])
    if not bets or not valid_heads:
        return None
    return {
        "heads": tuple(valid_heads),
        "per_head": per_head,
        "bets": bets,
    }


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

    scenarios = {}
    for rule in HEAD_RULES:
        heads = prev.heads_for(order, trio, rule)
        for label, second_count, third_count in PRACTICAL_SPECS:
            name = f"{rule}_{label}"
            scenario = make_union(record, boats, heads, second_count, third_count)
            if scenario is None:
                return None, f"{name}_missing"
            scenarios[name] = scenario

    return {
        "race_code": str(record["race_code"]),
        "actual": tuple(int(x) for x in actual),
        "actual_first": int(actual[0]),
        "actual_second": int(actual[1]),
        "actual_third": int(actual[2]),
        "payout": int(payout),
        "in_lane": in_lane,
        "in_failed": int(actual[0]) != in_lane,
        "trio_gap_pt": prev.trio_gap_pt(trio, order),
        "order": tuple(int(x) for x in order),
        "scenarios": scenarios,
    }, None


def build_rows(records, boats_map, payouts, kimarite_map):
    rows = []
    skip = defaultdict(int)
    for record in records:
        code = str(record["race_code"])
        kimarite = kimarite_map.get(code)
        if kimarite is None:
            skip["kimarite_missing"] += 1
            continue
        row, reason = build_row(record, boats_map.get(code), payouts.get(code), kimarite)
        if row is None:
            skip[str(reason or "not_ready")] += 1
            continue
        rows.append(row)
        skip["ready_in_collapse"] += 1
    return rows, skip


def evaluate(rows: list[dict], method: str) -> dict:
    n = len(rows)
    head_hit = pair_hit = exact_hit = 0
    fail_n = fail_head_hit = 0
    total_points = total_heads = 0
    invest100 = ret100 = 0.0
    invest_fixed = ret_fixed = 0.0
    hit_payout_sum = 0.0
    bands = Counter()

    for row in rows:
        scenario = row["scenarios"][method]
        heads = tuple(scenario["heads"])
        actual = tuple(row["actual"])
        first, second, _ = actual

        total_heads += len(heads)
        hh = first in heads
        head_hit += 1 if hh else 0
        if row["in_failed"]:
            fail_n += 1
            fail_head_hit += 1 if hh else 0

        ph = False
        if hh:
            per = scenario["per_head"].get(first)
            if per is not None and second in per["second"]:
                ph = True
        pair_hit += 1 if ph else 0

        bets = set(scenario["bets"])
        cnt = len(bets)
        total_points += cnt
        invest100 += cnt * 100.0
        invest_fixed += 1000.0
        if cnt <= 6:
            bands["<=6"] += 1
        elif cnt <= 12:
            bands["7-12"] += 1
        elif cnt <= 18:
            bands["13-18"] += 1
        else:
            bands[">18"] += 1

        if actual in bets:
            exact_hit += 1
            payout = float(row["payout"])
            hit_payout_sum += payout
            ret100 += payout
            ret_fixed += payout * ((1000.0 / cnt) / 100.0)

    return {
        "n": n,
        "avg_heads": total_heads / n if n else 0.0,
        "avg_points": total_points / n if n else 0.0,
        "head_rate": pct(head_hit, n),
        "fail_head_rate": pct(fail_head_hit, fail_n),
        "pair_hits": pair_hit,
        "pair_rate": pct(pair_hit, n),
        "exact_hits": exact_hit,
        "exact_rate": pct(exact_hit, n),
        "third_given_pair": pct(exact_hit, pair_hit),
        "roi100": 100.0 * ret100 / invest100 if invest100 else 0.0,
        "roi_fixed": 100.0 * ret_fixed / invest_fixed if invest_fixed else 0.0,
        "avg_hit_payout": hit_payout_sum / exact_hit if exact_hit else 0.0,
        "bands": bands,
    }


def compare(rows: list[dict], base: str, test: str) -> dict:
    changed = gained = lost = both = neither = 0
    for row in rows:
        base_bets = set(row["scenarios"][base]["bets"])
        test_bets = set(row["scenarios"][test]["bets"])
        changed += 1 if base_bets != test_bets else 0
        base_hit = tuple(row["actual"]) in base_bets
        test_hit = tuple(row["actual"]) in test_bets
        if test_hit and not base_hit:
            gained += 1
        elif base_hit and not test_hit:
            lost += 1
        elif base_hit and test_hit:
            both += 1
        else:
            neither += 1
    return {
        "changed": changed,
        "gained": gained,
        "lost": lost,
        "both": both,
        "neither": neither,
        "net": gained - lost,
    }


def print_table(title: str, rows: list[dict]) -> dict[str, dict]:
    print(f"\n【{title}: 3着候補の実戦比較】")
    print(
        "方式             平均頭 平均点数 頭+2着捕捉 3連単的中  3着捕捉|頭2着  "
        "100円ROI 1000円ROI  <=6/7-12/13-18/>18"
    )
    print("-" * 124)
    out = {}
    for method in METHODS:
        m = evaluate(rows, method)
        out[method] = m
        b = m["bands"]
        band = f"{b.get('<=6',0)}/{b.get('7-12',0)}/{b.get('13-18',0)}/{b.get('>18',0)}"
        print(
            f"{method:<17} {m['avg_heads']:>5.3f}   {m['avg_points']:>6.2f}   "
            f"{m['pair_rate']:>7.2f}%   {m['exact_rate']:>7.2f}%      "
            f"{m['third_given_pair']:>7.2f}%   {m['roi100']:>7.2f}%   "
            f"{m['roi_fixed']:>8.2f}%   {band}"
        )
    return out


def print_practical_compare(title: str, rows: list[dict], rule: str) -> None:
    print(f"\n【{title}: {rule} 実戦候補の差】")
    print("比較                 買目変更  拾い 失い  純増  両方的中  平均点数差")
    print("-" * 86)
    pairs = (
        ("S1_T3", "S2_T2"),
        ("S2_T2", "S2_T3"),
        ("S2_T2", "S3_T2"),
        ("S2_T3", "S3_T2"),
        ("S2_T3", "S2_ALL"),
        ("S3_T2", "S3_ALL"),
    )
    for a, b in pairs:
        ma = f"{rule}_{a}"
        mb = f"{rule}_{b}"
        c = compare(rows, ma, mb)
        ea = evaluate(rows, ma)
        eb = evaluate(rows, mb)
        print(
            f"{a+' -> '+b:<21} {c['changed']:>7d} {c['gained']:>4d} {c['lost']:>4d} "
            f"{c['net']:>+5d} {c['both']:>8d}   {eb['avg_points']-ea['avg_points']:>+7.2f}点"
        )


def print_same_budget(title: str, rows: list[dict], rule: str) -> None:
    print(f"\n【{title}: {rule} 12点前後の配分比較】")
    print("方式       平均点数  頭+2着捕捉 3連単的中 3着捕捉|頭2着 1000円ROI")
    print("-" * 80)
    for label in ("S2_T3", "S3_T2"):
        m = evaluate(rows, f"{rule}_{label}")
        print(
            f"{label:<9} {m['avg_points']:>7.2f}    {m['pair_rate']:>7.2f}%   "
            f"{m['exact_rate']:>7.2f}%      {m['third_given_pair']:>7.2f}%   {m['roi_fixed']:>8.2f}%"
        )


def print_gap2_band(title: str, rows: list[dict]) -> None:
    part = [r for r in rows if float(r["trio_gap_pt"]) < 2.0]
    print(f"\n【{title}: AI3 1-2位差<2ptだけ={len(part)}R】")
    print("方式             平均点数 頭+2着捕捉 3連単的中 1000円ROI")
    print("-" * 70)
    for label in ("S2_T2", "S2_T3", "S3_T2"):
        for rule in ("FIX2", "GAP2"):
            name = f"{rule}_{label}"
            m = evaluate(part, name)
            print(
                f"{name:<17} {m['avg_points']:>7.2f}   {m['pair_rate']:>7.2f}%   "
                f"{m['exact_rate']:>7.2f}%   {m['roi_fixed']:>8.2f}%"
            )
        print("-")


def print_reproduction(dev: dict[str, dict], confirm: dict[str, dict]) -> None:
    focus = (
        "FIX2_S1_T3", "FIX2_S2_T2", "FIX2_S2_T3", "FIX2_S3_T2",
        "GAP2_S1_T3", "GAP2_S2_T2", "GAP2_S2_T3", "GAP2_S3_T2",
    )
    print("\n【DEV→CONFIRM 実戦候補再現】")
    print("方式             DEV点数 CONF点数 DEV的中 CONF的中 DEV3着|頭2 CONF3着|頭2 DEV1000ROI CONF1000ROI")
    print("-" * 114)
    for method in focus:
        d = dev[method]
        c = confirm[method]
        print(
            f"{method:<17} {d['avg_points']:>6.2f}  {c['avg_points']:>6.2f}  "
            f"{d['exact_rate']:>6.2f}% {c['exact_rate']:>6.2f}%   "
            f"{d['third_given_pair']:>7.2f}%   {c['third_given_pair']:>7.2f}%   "
            f"{d['roi_fixed']:>8.2f}%   {c['roi_fixed']:>8.2f}%"
        )


def main() -> None:
    if len(sys.argv) != 7:
        print(
            "Usage: python3 analysis/compare_payout_signal_third_count_bets.py "
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
    print("配当サイン固定：3着をP(3着|頭,2着)で絞って6～12点の穴目を比較")
    print("=" * 124)
    print(f"DEV     : {dev_common['p1_start']} ～ {dev_common['p2_end']}")
    print(f"CONFIRM : {confirm_common['p2_start']} ～ {confirm_common['p2_end']} ※既観察期間")
    print("対象     : primary=イン崩壊")
    print("頭       : FIX2 / GAP2(<2ptだけTop3)")
    print("2着      : P(2着|頭) Top1/2/3")
    print("3着      : P(3着|頭,2着) Top2/3中心。ALLは前段基準")
    print("cut      : 現行固定、救済なし")
    print("狙い     : 6～12点程度で、S2_ALL/S3_ALLの的中をどこまで維持できるか")
    print("本番変更 : なし。9/7以降は未使用前方検証として残す")

    dev = print_table("DEV", dev_rows)
    confirm = print_table("CONFIRM", confirm_rows)

    print_practical_compare("DEV", dev_rows, "FIX2")
    print_practical_compare("CONFIRM", confirm_rows, "FIX2")
    print_practical_compare("DEV", dev_rows, "GAP2")
    print_practical_compare("CONFIRM", confirm_rows, "GAP2")

    print_same_budget("DEV", dev_rows, "FIX2")
    print_same_budget("CONFIRM", confirm_rows, "FIX2")
    print_same_budget("DEV", dev_rows, "GAP2")
    print_same_budget("CONFIRM", confirm_rows, "GAP2")

    print_gap2_band("DEV", dev_rows)
    print_gap2_band("CONFIRM", confirm_rows)
    print_reproduction(dev, confirm)

    print("\n【判断方針】")
    print("1. 前段からS2を主軸とし、3着条件付きTop2/Top3で平均6～12点へ落とせるかを見る")
    print("2. S2_T3とS3_T2は同程度の点数になるため、2着を広げるか3着を広げるかを直接比較する")
    print("3. 3着捕捉|頭2着がDEV→CONFIRMで再現する方式を優先する")
    print("4. ROIは既観察期間なので参考。的中・点数・再現性を先に見る")
    print("5. ここで1～2方式へ固定したら、9/7以降を新しい未使用前方検証に使う")
    print("6. 荒れ判定/本命/対抗/PredictionLogicには接続しない")

    print("\njoin/skip DEV    :", dict(dev_skip))
    print("join/skip CONFIRM:", dict(confirm_skip))
    print("=" * 124)
    print(f"総所要時間 : {format_elapsed(time.perf_counter()-t0)}")


if __name__ == "__main__":
    main()
