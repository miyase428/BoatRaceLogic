#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
配当サイン固定後の「穴頭」候補ルール比較。

目的
----
現行 PayoutSignalClassifier v1 相当の荒れ判定は変更せず、
primary=イン崩壊 かつ実際に1C以外が勝ったレースだけを対象に、
「荒れるとしたら誰が1着か」を少数の事前定義ルールで比較する。

前段の winner profile で再現した主な材料
-------------------------------------------
- AI3連対率: 単独で最も強い基準候補
- 補正後1着率 / 一次評価 / 二次評価: 自力・既存評価の補助
- 自艇6m攻め率: 自艇側の展開能力
- 内側最大攻め×自艇まくり差し: 展開受益の探索候補

重要な扱い
----------
- 内側最大攻め×自艇まくり差しは2Cでは構造上作れないため、
  欠損艇を候補から除外しない。欠損は「展開上乗せなし=0」として扱う。
- self_attack等の通常欠損は中立(0.5)として扱い、欠損だけで艇を落とさない。
- 各特徴はレース内0～1順位スコアへ変換し、尺度差を消して足し合わせる。
- 結果は順位作成には一切使わない。
- DEV/CONFIRMとも既に観察期間なので、ここでは最終採用を決めない。
  候補を少数へ絞った後、9/7以降の新しい未使用期間で前方検証する。
- 本命/対抗/PredictionLogic/本番買い目は変更しない。

Usage:
python3 analysis/compare_payout_signal_head_rules.py \
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
import trifecta_probability_order_compare as step3
import payout_signal_relative_features as rel
import analyze_payout_signal_winner_relative_profile as profile

# feature -> weight
# AI3を基準軸にして、他要素を足したときに頭精度が改善するかを見る。
METHODS = {
    "TRIO": {"trio_p": 1.0},
    "WIN": {"win_p": 1.0},
    "PRIMARY": {"primary": 1.0},
    "TRIO_WIN": {"trio_p": 1.0, "win_p": 1.0},
    "TRIO_PRIMARY": {"trio_p": 1.0, "primary": 1.0},
    "TRIO_SECONDARY": {"trio_p": 1.0, "secondary": 1.0},
    "TRIO_WIN_PRIMARY": {"trio_p": 1.0, "win_p": 1.0, "primary": 1.0},
    # 自艇の攻めをAI3へ軽く加える。
    "TRIO_SELF_ATTACK": {"trio_p": 2.0, "self_attack": 1.0},
    # 内側の攻めを受けて自艇のまくり差しが生きる仮説。AI3を主軸にする。
    "TRIO_RELATIVE_MZ": {"trio_p": 2.0, "inside_attack_x_self_mz": 1.0},
    # 展示/二次 + 自艇攻め + 展開受益。
    "TRIO_FLOW": {
        "trio_p": 2.0,
        "secondary": 1.0,
        "self_attack": 1.0,
        "inside_attack_x_self_mz": 1.0,
    },
    # 自力と展開を広く混ぜた比較用。採用前提ではない。
    "TRIO_BALANCED": {
        "trio_p": 2.0,
        "win_p": 1.0,
        "primary": 1.0,
        "secondary": 1.0,
        "self_attack": 1.0,
        "inside_attack_x_self_mz": 1.0,
    },
}

RELATION_FEATURES = {"inside_attack_x_self_mz"}


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


def target_rows(rows):
    return [r for r in rows if r["type"] == "イン崩壊" and r["in_failed"]]


def feature_percentiles(features: dict, feature: str, eligible: list[int]) -> dict[int, float]:
    """高いほど良い特徴をレース内0～1へ変換。tieは同値。"""
    vals = {}
    for lane in eligible:
        v = rel.safe_float(features.get(lane, {}).get(feature))
        if v is not None:
            vals[lane] = float(v)

    out = {}
    n = len(vals)
    for lane in eligible:
        if lane not in vals:
            # 展開受益は「適用なし=上乗せなし」。
            # それ以外の欠損は情報なしなので中立。
            out[lane] = 0.0 if feature in RELATION_FEATURES else 0.5
            continue
        if n <= 1:
            out[lane] = 0.5
            continue
        v = vals[lane]
        lower = sum(1 for x in vals.values() if x < v)
        equal = sum(1 for x in vals.values() if x == v)
        # 同値は順位区間の中央。
        out[lane] = (lower + 0.5 * max(equal - 1, 0)) / (n - 1)
    return out


def method_order(row: dict, method: str) -> list[int]:
    in_lane = int(row["in_lane"])
    eligible = [lane for lane in range(1, 7) if lane != in_lane]
    f = row["features"]
    weights = METHODS[method]

    per_feature = {
        name: feature_percentiles(f, name, eligible)
        for name in weights
    }

    scores = {}
    denom = sum(float(w) for w in weights.values()) or 1.0
    for lane in eligible:
        scores[lane] = sum(
            float(weights[name]) * float(per_feature[name][lane])
            for name in weights
        ) / denom

    # 同点時はAI3連対率→補正後1着率→内側コースを優先。
    return sorted(
        eligible,
        key=lambda lane: (
            -scores[lane],
            -float(rel.safe_float(f[lane].get("trio_p")) or 0.0),
            -float(rel.safe_float(f[lane].get("win_p")) or 0.0),
            int(f[lane].get("course") or 9),
            lane,
        ),
    )


def evaluate(rows: list[dict], method: str) -> dict:
    n = len(rows)
    top1 = 0
    top2 = 0
    rank_sum = 0.0
    by_course = defaultdict(lambda: {"n": 0, "top1": 0, "top2": 0})

    for r in rows:
        winner = int(r["actual_first"])
        order = method_order(r, method)
        rank = order.index(winner) + 1
        rank_sum += rank
        top1 += 1 if rank == 1 else 0
        top2 += 1 if rank <= 2 else 0

        c = int(r["actual_course"])
        b = by_course[c]
        b["n"] += 1
        b["top1"] += 1 if rank == 1 else 0
        b["top2"] += 1 if rank <= 2 else 0

    return {
        "n": n,
        "top1_n": top1,
        "top2_n": top2,
        "top1": pct(top1, n),
        "top2": pct(top2, n),
        "avg_rank": rank_sum / n if n else 0.0,
        "by_course": by_course,
    }


def compare_vs_trio(rows: list[dict], method: str) -> dict:
    if method == "TRIO":
        return {"g1": 0, "l1": 0, "g2": 0, "l2": 0}
    g1 = l1 = g2 = l2 = 0
    for r in rows:
        winner = int(r["actual_first"])
        base = method_order(r, "TRIO")
        test = method_order(r, method)
        b1 = base[0] == winner
        t1 = test[0] == winner
        b2 = winner in base[:2]
        t2 = winner in test[:2]
        g1 += 1 if (t1 and not b1) else 0
        l1 += 1 if (b1 and not t1) else 0
        g2 += 1 if (t2 and not b2) else 0
        l2 += 1 if (b2 and not t2) else 0
    return {"g1": g1, "l1": l1, "g2": g2, "l2": l2}


def print_table(title: str, rows: list[dict]) -> dict[str, dict]:
    print(f"\n【{title}: イン崩壊かつ実非イン頭={len(rows)}R】")
    print("方式                    Top1     Top2    平均順位   vsTRIO Top1(+/-)   vsTRIO Top2(+/-)")
    print("-" * 100)
    out = {}
    for method in METHODS:
        m = evaluate(rows, method)
        d = compare_vs_trio(rows, method)
        out[method] = m
        print(
            f"{method:<22} {m['top1']:>6.2f}%  {m['top2']:>6.2f}%   {m['avg_rank']:>6.3f}位"
            f"      +{d['g1']:>3d}/-{d['l1']:<3d}         +{d['g2']:>3d}/-{d['l2']:<3d}"
        )
    return out


def print_course_table(title: str, rows: list[dict], methods: list[str]) -> None:
    print(f"\n【{title}: 実勝者コース別】")
    print("方式                    2C Top1/2      3C Top1/2      4C Top1/2      5-6C Top1/2")
    print("-" * 104)
    for method in methods:
        m = evaluate(rows, method)
        bc = m["by_course"]

        def cell(courses):
            n = sum(bc[c]["n"] for c in courses)
            t1 = sum(bc[c]["top1"] for c in courses)
            t2 = sum(bc[c]["top2"] for c in courses)
            return f"{pct(t1,n):5.1f}/{pct(t2,n):5.1f}"

        print(
            f"{method:<22} {cell((2,)):>12}   {cell((3,)):>12}   {cell((4,)):>12}   {cell((5,6)):>12}"
        )


def ranked_methods(results: dict[str, dict]) -> list[str]:
    return sorted(
        results,
        key=lambda name: (
            -results[name]["top1"],
            -results[name]["top2"],
            results[name]["avg_rank"],
            name,
        ),
    )


def main():
    if len(sys.argv) != 7:
        print(
            "Usage: python3 analysis/compare_payout_signal_head_rules.py "
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

    print("CSV/決まり手/モーター読込中...", flush=True)
    boats_map = b2.load_boats(p1_csv, p2_csv, p3_csv)
    kimarite_map = rel.load_kimarite(k1_csv, k2_csv, k3_csv)
    race_codes = [str(r["race_code"]) for r in dev_records + confirm_records]
    engine_map = rel.load_engine_map(race_codes)

    dev_all, dev_skip = profile.build_rows(dev_records, boats_map, kimarite_map, engine_map, "DEV")
    confirm_all, confirm_skip = profile.build_rows(
        confirm_records, boats_map, kimarite_map, engine_map, "CONFIRM"
    )
    dev = target_rows(dev_all)
    confirm = target_rows(confirm_all)

    print("=" * 120)
    print("配当サイン固定：イン崩壊時の穴頭候補ルール比較")
    print("=" * 120)
    print(f"DEV     : {dev_common['p1_start']} ～ {dev_common['p2_end']}")
    print(f"CONFIRM : {confirm_common['p2_start']} ～ {confirm_common['p2_end']} ※既観察期間")
    print("対象     : primary=イン崩壊 かつ実1C敗戦。非イン5艇から実1着艇を当てる")
    print("基準     : TRIO=AI3連対率。複合方式はレース内順位スコアで尺度を統一")
    print("欠損     : 展開受益なし=0 / その他の情報欠損=中立0.5。艇そのものは候補から除外しない")
    print("本番変更 : なし。本命/対抗にも未接続")

    dev_result = print_table("DEV", dev)
    confirm_result = print_table("CONFIRM", confirm)

    # DEV上位3方式 + 基準TRIOをコース別に見る。
    top_dev = ranked_methods(dev_result)[:3]
    focus = ["TRIO"] + [m for m in top_dev if m != "TRIO"]
    print_course_table("DEV", dev, focus)
    print_course_table("CONFIRM", confirm, focus)

    print("\n【DEV順位とCONFIRM再現】")
    print("DEV順位 方式                    DEV Top1/Top2       CONFIRM Top1/Top2")
    print("-" * 80)
    for i, method in enumerate(ranked_methods(dev_result), 1):
        d = dev_result[method]
        c = confirm_result[method]
        print(
            f"{i:>3d}    {method:<22} {d['top1']:>6.2f}/{d['top2']:>6.2f}%      "
            f"{c['top1']:>6.2f}/{c['top2']:>6.2f}%"
        )

    print("\n【判断方針】")
    print("1. TRIO単独を基準に、Top1を上げてもTop2を大きく落とす方式は採らない")
    print("2. DEVだけでなくCONFIRMでも同方向なら、穴頭候補として残す")
    print("3. 展開系は欠損艇除外による見かけのTop2上昇を禁止した今回の方式で判断する")
    print("4. コース別で2Cを悪化させて4Cだけ改善する等の偏りも確認する")
    print("5. ここで候補を1～2方式へ固定したら、9/7以降を新しい未使用前方検証にする")
    print("6. 穴目で固定できるまで、本命/対抗ロジックには接続しない")
    print("\njoin/skip DEV    :", dict(dev_skip))
    print("join/skip CONFIRM:", dict(confirm_skip))
    print("=" * 120)
    print(f"総所要時間 : {format_elapsed(time.perf_counter()-t0)}")


if __name__ == "__main__":
    main()
