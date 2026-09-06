#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
配当サイン固定後の穴目研究：AI3穴頭候補数ルールを、実際のOUTCOME買い目まで
つないで点数・的中率・ROIを比較する。

背景
----
前段 compare_payout_signal_head_count_rules.py では primary=イン崩壊 に対して、
非インAI3連対率順位を使うと
- FIX_TOP2 は DEV/CONFIRM とも1C敗戦時頭捕捉が約70%
- FIX_TOP3 は約83～85%
- GAP2_TOP3_ELSE_TOP2 は AI3 1-2位差<2pt の時だけ3頭に広げ、
  平均頭数約2.1頭でFIX_TOP2より約+1.7pt頭捕捉を上積み
となった。

今回は頭捕捉だけでなく、各頭から既存の120通り P(2着|頭) を使った
OUTCOME買い目を作り、実戦点数と的中率/ROIまで比較する。

比較
----
CURRENT
    現行本命買い目（参考）
FIX_TOP2_OUTCOME
    非インAI3 1～2位を頭
GAP2_TOP3_ELSE_TOP2_OUTCOME
    AI3 1-2位差<2pt の時だけ1～3位、それ以外1～2位を頭
FIX_TOP3_OUTCOME
    非インAI3 1～3位を頭（捕捉上限の参考）

相手
----
各頭ごとに既存 b4.make_outcome_bets を使用。
- 2着: 120通り P(2着|頭) 上位最大3艇
- 3着: 現行cutを除く eligible
- 各頭の買い目を和集合

重要
----
- 荒れ判定は PayoutSignalClassifier v1 相当を固定。閾値変更なし。
- 2pt は既存穴頭信頼度研究の固定境界。今回の期間で掘らない。
- DEV/CONFIRM は既観察期間なので、ここで最終採用は決めない。
- 9/7以降を新しい未使用前方検証に残す。
- 本命/対抗/PredictionLogic/本番買い目は変更しない。

Usage:
python3 analysis/compare_payout_signal_head_count_bets.py \
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

METHODS = (
    "CURRENT",
    "FIX_TOP2_OUTCOME",
    "GAP2_TOP3_ELSE_TOP2_OUTCOME",
    "FIX_TOP3_OUTCOME",
)


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


def trio_gap_pt(trio: dict, order: list[int]) -> float:
    if len(order) < 2:
        return 0.0
    return (float(trio[order[0]]) - float(trio[order[1]])) * 100.0


def heads_for(order: list[int], trio: dict, method: str) -> tuple[int, ...]:
    if method == "FIX_TOP2_OUTCOME":
        return tuple(order[:2])
    if method == "FIX_TOP3_OUTCOME":
        return tuple(order[:3])
    if method == "GAP2_TOP3_ELSE_TOP2_OUTCOME":
        k = 3 if trio_gap_pt(trio, order) < 2.0 else 2
        return tuple(order[:k])
    raise ValueError(method)


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

    current = b4.current_bets(boats)
    if current is None or not current.get("bets"):
        return None, "current_bets_missing"

    scenarios = {
        "CURRENT": {
            "heads": (int(f["current_head"]),),
            "bets": set(current["bets"]),
        }
    }

    for method in METHODS:
        if method == "CURRENT":
            continue
        heads = heads_for(order, trio, method)
        s = c4.make_outcome_union(record, boats, heads)
        if s is None or not s.get("bets"):
            return None, f"{method}_missing"
        scenarios[method] = {
            "heads": tuple(int(x) for x in s["heads"]),
            "bets": set(s["bets"]),
        }

    return {
        "race_code": str(record["race_code"]),
        "actual": tuple(int(x) for x in actual),
        "actual_first": int(actual[0]),
        "payout": int(payout),
        "in_lane": in_lane,
        "in_failed": int(actual[0]) != in_lane,
        "trio_gap_pt": trio_gap_pt(trio, order),
        "order": tuple(int(x) for x in order),
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


def evaluate_heads(rows, method):
    n = len(rows)
    fail = [r for r in rows if r["in_failed"]]
    hit_all = hit_fail = total_heads = 0
    head_count = Counter()

    for r in rows:
        heads = tuple(r["scenarios"][method]["heads"])
        total_heads += len(heads)
        head_count[len(heads)] += 1
        h = int(r["actual_first"]) in heads
        hit_all += 1 if h else 0
        if r["in_failed"] and h:
            hit_fail += 1

    return {
        "n": n,
        "fail_n": len(fail),
        "avg_heads": total_heads / n if n else 0.0,
        "count": head_count,
        "all_rate": pct(hit_all, n),
        "fail_rate": pct(hit_fail, len(fail)),
    }


def evaluate_bets(rows, method):
    n = hits = points = 0
    invest100 = ret100 = 0.0
    invest_fixed = ret_fixed = 0.0
    hit_payout_sum = 0.0
    point_bands = Counter()

    for r in rows:
        bets = set(r["scenarios"][method]["bets"])
        cnt = len(bets)
        if cnt <= 0:
            continue
        n += 1
        points += cnt
        invest100 += cnt * 100.0
        invest_fixed += 1000.0
        if cnt <= 12:
            point_bands["<=12"] += 1
        elif cnt <= 18:
            point_bands["13-18"] += 1
        else:
            point_bands[">18"] += 1

        if r["actual"] in bets:
            hits += 1
            payout = float(r["payout"])
            hit_payout_sum += payout
            ret100 += payout
            ret_fixed += payout * ((1000.0 / cnt) / 100.0)

    return {
        "n": n,
        "hits": hits,
        "hit_rate": pct(hits, n),
        "avg_points": points / n if n else 0.0,
        "roi100": 100.0 * ret100 / invest100 if invest100 else 0.0,
        "roi_fixed": 100.0 * ret_fixed / invest_fixed if invest_fixed else 0.0,
        "avg_hit_payout": hit_payout_sum / hits if hits else 0.0,
        "bands": point_bands,
    }


def compare(rows, base, test):
    changed = gained = lost = both = neither = 0
    for r in rows:
        bb = set(r["scenarios"][base]["bets"])
        tb = set(r["scenarios"][test]["bets"])
        changed += 1 if bb != tb else 0
        bh = r["actual"] in bb
        th = r["actual"] in tb
        if th and not bh:
            gained += 1
        elif bh and not th:
            lost += 1
        elif bh and th:
            both += 1
        else:
            neither += 1
    return {"changed": changed, "gained": gained, "lost": lost, "both": both, "neither": neither}


def print_head_table(title, rows):
    print(f"\n【{title}: 頭候補】")
    print("方式                               平均頭数  1/2/3頭構成        全R頭捕捉  1C敗戦時頭捕捉")
    print("-" * 104)
    out = {}
    for method in ("FIX_TOP2_OUTCOME", "GAP2_TOP3_ELSE_TOP2_OUTCOME", "FIX_TOP3_OUTCOME"):
        m = evaluate_heads(rows, method)
        out[method] = m
        c = m["count"]
        comp = f"{c.get(1,0):>3d}/{c.get(2,0):>3d}/{c.get(3,0):>3d}"
        print(
            f"{method:<36} {m['avg_heads']:>6.3f}頭   {comp:<15} "
            f"{m['all_rate']:>7.2f}%      {m['fail_rate']:>7.2f}%"
        )
    return out


def print_bet_table(title, rows):
    print(f"\n【{title}: 3連単買い目】")
    print("方式                               平均点数  的中率  100円/点ROI  1000円均等ROI  的中平均払戻   <=12/13-18/>18")
    print("-" * 128)
    out = {}
    for method in METHODS:
        m = evaluate_bets(rows, method)
        out[method] = m
        b = m["bands"]
        band = f"{b.get('<=12',0)}/{b.get('13-18',0)}/{b.get('>18',0)}"
        print(
            f"{method:<36} {m['avg_points']:>7.2f}  {m['hit_rate']:>6.2f}%   "
            f"{m['roi100']:>8.2f}%      {m['roi_fixed']:>8.2f}%      "
            f"{m['avg_hit_payout']:>8.0f}円   {band}"
        )

    print("\nFIX_TOP2_OUTCOMEからの的中差")
    print("方式                               買目変更   拾い   失い   両方的中")
    print("-" * 78)
    for method in ("GAP2_TOP3_ELSE_TOP2_OUTCOME", "FIX_TOP3_OUTCOME"):
        c = compare(rows, "FIX_TOP2_OUTCOME", method)
        print(
            f"{method:<36} {c['changed']:>8d}  {c['gained']:>4d}  {c['lost']:>4d}  {c['both']:>8d}"
        )
    return out


def print_weak_band(title, rows):
    part = [r for r in rows if float(r["trio_gap_pt"]) < 2.0]
    print(f"\n【{title}: AI3 1-2位差<2ptだけ={len(part)}R】")
    print("方式                               平均点数  頭捕捉  3連単的中")
    print("-" * 82)
    for method in ("FIX_TOP2_OUTCOME", "GAP2_TOP3_ELSE_TOP2_OUTCOME"):
        h = evaluate_heads(part, method)
        b = evaluate_bets(part, method)
        print(
            f"{method:<36} {b['avg_points']:>7.2f}  {h['all_rate']:>6.2f}%   {b['hit_rate']:>7.2f}%"
        )


def main():
    if len(sys.argv) != 7:
        print(
            "Usage: python3 analysis/compare_payout_signal_head_count_bets.py "
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

    dev, dev_skip = build_rows(dev_records, boats_map, payouts, kimarite_map)
    confirm, confirm_skip = build_rows(confirm_records, boats_map, payouts, kimarite_map)

    print("=" * 124)
    print("配当サイン固定：穴頭候補数ルールをOUTCOME買い目まで接続して比較")
    print("=" * 124)
    print(f"DEV     : {dev_common['p1_start']} ～ {dev_common['p2_end']}")
    print(f"CONFIRM : {confirm_common['p2_start']} ～ {confirm_common['p2_end']} ※既観察期間")
    print("対象     : primary=イン崩壊")
    print("相手     : 各頭ごとに120通り P(2着|頭) 上位最大3艇 / 現行cut維持")
    print("候補     : FIX_TOP2 と GAP2(<2ptだけTop3) を主比較、FIX_TOP3は上限参考")
    print("本番変更 : なし。穴目表示・本命・対抗・PredictionLogicには未接続")

    dev_h = print_head_table("DEV", dev)
    conf_h = print_head_table("CONFIRM", confirm)
    dev_b = print_bet_table("DEV", dev)
    conf_b = print_bet_table("CONFIRM", confirm)
    print_weak_band("DEV", dev)
    print_weak_band("CONFIRM", confirm)

    print("\n【DEV→CONFIRM 主比較】")
    print("方式                               DEV頭捕捉 CONF頭捕捉  DEV点数 CONF点数  DEV的中 CONF的中")
    print("-" * 108)
    for method in ("FIX_TOP2_OUTCOME", "GAP2_TOP3_ELSE_TOP2_OUTCOME", "FIX_TOP3_OUTCOME"):
        print(
            f"{method:<36} {dev_h[method]['all_rate']:>7.2f}%  {conf_h[method]['all_rate']:>7.2f}%  "
            f"{dev_b[method]['avg_points']:>7.2f} {conf_b[method]['avg_points']:>7.2f}  "
            f"{dev_b[method]['hit_rate']:>7.2f}% {conf_b[method]['hit_rate']:>7.2f}%"
        )

    print("\n【判断方針】")
    print("1. 頭精度の主候補はFIX_TOP2とGAP2_TOP3_ELSE_TOP2の2方式に絞る")
    print("2. <2pt帯だけ第3頭を足す追加点数に対して、3連単的中の純増が再現するかを見る")
    print("3. FIX_TOP3は捕捉上限の参考。点数増が大きければ本番候補にはしない")
    print("4. ROIは既観察期間なので参考。最終判断は9/7以降の未使用前方検証")
    print("5. 頭方式が固まった後、次に2着候補数をTop1/Top2/Top3で絞って6～12点を目指す")
    print("6. 本命/対抗ロジックにはまだ接続しない")

    print("\njoin/skip DEV    :", dict(dev_skip))
    print("join/skip CONFIRM:", dict(confirm_skip))
    print("=" * 124)
    print(f"総所要時間 : {format_elapsed(time.perf_counter()-t0)}")


if __name__ == "__main__":
    main()
