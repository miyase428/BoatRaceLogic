#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
配当サイン固定後の穴頭研究：AI3連対率4～5位から勝った艇と、
素直な救済候補であるAI3 3位艇との差を診断する。

目的
----
前段では、primary=イン崩壊かつ実1C敗戦時にAI3 Top2が約70%を捕捉し、
Top2外では「AI3 3位をそのまま足す」がDEV/CONFIRMとも強い基準だった。
一方、単一の展開特徴で3位艇を常時置き換える方式は安定して上回らなかった。

そこで今回は、Top2外のうち実勝者がAI3 4～5位だったケースに絞り、
「実勝者はAI3 3位艇より何が強かったのか」をレース前特徴で比較する。
また、各救済候補がAI3 3位艇と異なる艇を選んだ時だけの勝敗を出し、
条件付き置換に使える材料があるかを見る。

重要
----
- 荒れ判定は変更しない。
- AI3 Top2も変更しない。
- 実結果は評価ラベルにのみ使う。
- DEV/CONFIRMは既観察期間なので、ここで閾値は固定しない。
- 9/7以降を新しい未使用前方検証に残す。
- 本命/対抗/PredictionLogic/本番買い目は変更しない。

Usage:
python3 analysis/analyze_payout_signal_rank45_vs_rank3.py \
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
import trifecta_probability_order_compare as step3
import payout_signal_relative_features as rel
import analyze_payout_signal_winner_relative_profile as profile
import analyze_payout_signal_trio_miss_relations as miss


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


def target_rows(rows: list[dict]) -> list[dict]:
    return [r for r in rows if r["type"] == "イン崩壊" and r["in_failed"]]


def trio_rank(row: dict, lane: int) -> int:
    order = miss.trio_order(row)
    return order.index(int(lane)) + 1


def top2_misses(rows: list[dict]) -> list[dict]:
    out = []
    for r in rows:
        winner = int(r["actual_first"])
        if trio_rank(r, winner) >= 3:
            out.append(r)
    return out


def rank45_rows(rows: list[dict]) -> list[dict]:
    return [r for r in top2_misses(rows) if trio_rank(r, int(r["actual_first"])) >= 4]


# AI3 4～5位勝者とAI3 3位艇を直接比較する特徴。
PAIR_FEATURES = (
    ("win_p", "補正後1着率"),
    ("primary", "一次評価"),
    ("motor", "モーター2連率"),
    ("self_move", "自艇の攻め力"),
    ("inner_move_max", "内側(1C除外)攻め最大"),
    ("inner_move_count20", "内側20%以上攻め艇数"),
    ("outer_safety_max", "外側最大攻めが弱い"),
    ("inner_x_self_mz", "内側最大攻め×自艇まくり差し"),
    ("inner_x_self_finish", "内側最大攻め×自艇差し/まくり差し"),
    ("self_minus_outer", "自艇攻め-外側最大攻め"),
)

# 「3位艇から別艇へ置換する」候補。前段で比較した中から意味の異なるものを残す。
RESCUE_FEATURES = (
    ("win_p", "補正後1着率"),
    ("motor", "モーター2連率"),
    ("self_move", "自艇の攻め力"),
    ("inner_move_count20", "内側20%以上攻め艇数"),
    ("inner_x_self_mz", "内側最大攻め×自艇まくり差し"),
    ("self_minus_outer", "自艇攻め-外側最大攻め"),
)


def pair_compare(rows: list[dict], feature: str) -> dict:
    valid = higher = equal = lower = 0
    diffs = []
    by_winner_course = defaultdict(lambda: {"n": 0, "higher": 0})

    for r in rows:
        winner = int(r["actual_first"])
        order = miss.trio_order(r)
        rank3 = int(order[2])

        wf = miss.relation_features(r, winner).get(feature)
        bf = miss.relation_features(r, rank3).get(feature)
        if wf is None or bf is None:
            continue

        valid += 1
        d = float(wf) - float(bf)
        diffs.append(d)
        c = int(r["actual_course"])
        by_winner_course[c]["n"] += 1
        if d > 1e-12:
            higher += 1
            by_winner_course[c]["higher"] += 1
        elif d < -1e-12:
            lower += 1
        else:
            equal += 1

    return {
        "n": valid,
        "higher": higher,
        "equal": equal,
        "lower": lower,
        "higher_rate": pct(higher, valid),
        "mean_diff": sum(diffs) / len(diffs) if diffs else 0.0,
        "by_course": by_winner_course,
    }


def disagreement_compare(rows: list[dict], feature: str) -> dict:
    misses = top2_misses(rows)
    disagree = base_hit = cand_hit = neither = both = 0
    cand_rank = Counter()
    by_winner_course = defaultdict(lambda: {"n": 0, "base": 0, "cand": 0})

    for r in misses:
        winner = int(r["actual_first"])
        base = miss.trio_order(r)
        pool = base[2:]
        baseline = int(pool[0])
        cand_order = miss.rescue_order(r, feature, pool)
        candidate = int(cand_order[0])

        if candidate == baseline:
            continue

        disagree += 1
        b = winner == baseline
        c = winner == candidate
        base_hit += 1 if b else 0
        cand_hit += 1 if c else 0
        both += 1 if (b and c) else 0
        neither += 1 if (not b and not c) else 0
        cand_rank[base.index(candidate) + 1] += 1

        wc = int(r["actual_course"])
        bucket = by_winner_course[wc]
        bucket["n"] += 1
        bucket["base"] += 1 if b else 0
        bucket["cand"] += 1 if c else 0

    return {
        "n": disagree,
        "base_hit": base_hit,
        "cand_hit": cand_hit,
        "neither": neither,
        "both": both,
        "net": cand_hit - base_hit,
        "base_rate": pct(base_hit, disagree),
        "cand_rate": pct(cand_hit, disagree),
        "cand_rank": cand_rank,
        "by_course": by_winner_course,
    }


def print_rank_distribution(title: str, rows: list[dict]) -> None:
    misses = top2_misses(rows)
    c = Counter(trio_rank(r, int(r["actual_first"])) for r in misses)
    print(f"\n【{title}: AI3 Top2外={len(misses)}R】")
    print(" / ".join(
        f"{rank}位={c.get(rank,0)}R({pct(c.get(rank,0),len(misses)):.1f}%)"
        for rank in (3, 4, 5)
    ))


def print_pair_table(title: str, rows: list[dict]) -> None:
    r45 = rank45_rows(rows)
    print(f"\n【{title}: 実勝者AI3 4～5位={len(r45)}R / 実勝者 vs AI3 3位艇】")
    print("特徴                               有効R   勝者>3位艇   同値   勝者<3位艇    平均差")
    print("-" * 100)
    for feature, label in PAIR_FEATURES:
        m = pair_compare(r45, feature)
        print(
            f"{label:<34} {m['n']:>5d}    {m['higher_rate']:>7.2f}%   "
            f"{m['equal']:>4d}    {m['lower']:>5d}   {m['mean_diff']:>+9.4f}"
        )


def print_disagreement_table(title: str, rows: list[dict]) -> None:
    print(f"\n【{title}: AI3 3位艇と救済候補が異なった時だけ】")
    print("救済候補                         不一致R   3位艇勝ち   候補勝ち    差    どちらも外れ   候補AI3順位")
    print("-" * 112)
    for feature, label in RESCUE_FEATURES:
        m = disagreement_compare(rows, feature)
        ranks = "/".join(f"{k}位:{v}" for k, v in sorted(m["cand_rank"].items())) or "-"
        print(
            f"{label:<30} {m['n']:>6d}    {m['base_rate']:>7.2f}%   {m['cand_rate']:>7.2f}%  "
            f"{m['net']:>+4d}       {m['neither']:>5d}      {ranks}"
        )


def print_course_disagreement(title: str, rows: list[dict], features: tuple[str, ...]) -> None:
    print(f"\n【{title}: 不一致時の実勝者コース別 3位艇勝ち/救済候補勝ち】")
    print("方式                           2C            3C            4C           5-6C")
    print("-" * 102)

    label_map = dict(RESCUE_FEATURES)
    for feature in features:
        m = disagreement_compare(rows, feature)
        bc = m["by_course"]

        def cell(courses):
            n = sum(bc[c]["n"] for c in courses)
            b = sum(bc[c]["base"] for c in courses)
            d = sum(bc[c]["cand"] for c in courses)
            return f"{pct(b,n):4.0f}/{pct(d,n):4.0f}({n:2d})" if n else "   -   "

        print(
            f"{label_map[feature]:<30} {cell((2,)):>12}   {cell((3,)):>12}   "
            f"{cell((4,)):>12}   {cell((5,6)):>12}"
        )


def main() -> None:
    if len(sys.argv) != 7:
        print(
            "Usage: python3 analysis/analyze_payout_signal_rank45_vs_rank3.py "
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

    print("=" * 124)
    print("配当サイン固定：AI3 4～5位穴頭とAI3 3位艇の差分診断")
    print("=" * 124)
    print(f"DEV     : {dev_common['p1_start']} ～ {dev_common['p2_end']}")
    print(f"CONFIRM : {confirm_common['p2_start']} ～ {confirm_common['p2_end']} ※既観察期間")
    print("対象     : primary=イン崩壊 かつ実1C敗戦。AI3 Top2は固定して考える")
    print("目的     : AI3 3位を常時足すより良い『条件付き置換』の材料があるか確認")
    print("本番変更 : なし。閾値固定・買い目変更もしない")

    print_rank_distribution("DEV", dev)
    print_rank_distribution("CONFIRM", confirm)
    print_pair_table("DEV", dev)
    print_pair_table("CONFIRM", confirm)
    print_disagreement_table("DEV", dev)
    print_disagreement_table("CONFIRM", confirm)
    print_course_disagreement("DEV", dev, ("win_p", "motor", "inner_move_count20", "inner_x_self_mz"))
    print_course_disagreement("CONFIRM", confirm, ("win_p", "motor", "inner_move_count20", "inner_x_self_mz"))

    print("\n【判断方針】")
    print("1. AI3 3位艇は強い基準なので、候補が違う時にDEV/CONFIRM双方で純増する特徴だけ残す")
    print("2. 実AI3 4～5位勝者が3位艇より一貫して強い特徴があるかを見る")
    print("3. コース別で逆転するなら全コース共通置換にしない")
    print("4. ここでは閾値を掘らず、次段で1～2個の粗い条件だけ事前定義する")
    print("5. 最終候補は9/7以降の新しい未使用期間で前方検証する")
    print("6. 穴目方式が固定するまで本命/対抗には接続しない")

    print(f"\njoin/skip DEV    : {dict(dev_skip)}")
    print(f"join/skip CONFIRM: {dict(confirm_skip)}")
    print("=" * 124)
    print(f"総所要時間 : {format_elapsed(time.perf_counter() - t0)}")


if __name__ == "__main__":
    main()
