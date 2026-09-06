#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
配当サイン固定後の穴頭研究：AI3 3位艇から別艇へ置換する候補について、
「候補艇自身のコース」というレース前に分かる条件で再現性を確認する。

背景
----
前段 analyze_payout_signal_rank45_vs_rank3.py では、
実勝者コース別に見ると一部の救済候補が良く見える場面があった。
ただし「実勝者コース」は結果ラベルなので、そのまま本番条件には使えない。

今回は、実結果ではなく事前に分かる「救済候補艇のコース」で分け、
AI3 3位艇と救済候補が異なった時にどちらが実際に勝ったかを比較する。

重要
----
- 荒れ判定は変更しない。
- AI3 Top2は固定して考える。
- 閾値は掘らない。候補コースという粗い事前条件だけを見る。
- DEV/CONFIRMは既観察期間なので、この結果だけで最終固定しない。
- 9/7以降を新しい未使用前方検証に残す。
- 本命/対抗/PredictionLogic/本番買い目は変更しない。

Usage:
python3 analysis/analyze_payout_signal_rescue_candidate_course.py \
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
import analyze_payout_signal_rank45_vs_rank3 as diffmod


FEATURES = (
    ("win_p", "補正後1着率"),
    ("motor", "モーター2連率"),
    ("self_move", "自艇の攻め力"),
    ("inner_move_count20", "内側20%以上攻め艇数"),
    ("inner_x_self_mz", "内側最大攻め×自艇まくり差し"),
    ("self_minus_outer", "自艇攻め-外側最大攻め"),
)

COURSE_GROUPS = (
    ("2C", (2,)),
    ("3C", (3,)),
    ("4C", (4,)),
    ("5-6C", (5, 6)),
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


def target_rows(rows: list[dict]) -> list[dict]:
    return [r for r in rows if r["type"] == "イン崩壊" and r["in_failed"]]


def candidate_disagreements(rows: list[dict], feature: str) -> list[dict]:
    out = []
    for r in diffmod.top2_misses(rows):
        winner = int(r["actual_first"])
        base_order = miss.trio_order(r)
        pool = base_order[2:]
        baseline = int(pool[0])
        candidate = int(miss.rescue_order(r, feature, pool)[0])
        if candidate == baseline:
            continue

        candidate_course = int(r["features"][candidate].get("course") or 0)
        baseline_course = int(r["features"][baseline].get("course") or 0)
        candidate_rank = base_order.index(candidate) + 1

        out.append({
            "race_code": r["race_code"],
            "winner": winner,
            "baseline": baseline,
            "candidate": candidate,
            "candidate_course": candidate_course,
            "baseline_course": baseline_course,
            "candidate_ai3_rank": candidate_rank,
            "base_hit": winner == baseline,
            "candidate_hit": winner == candidate,
        })
    return out


def summarize(rows: list[dict]) -> dict:
    n = len(rows)
    base = sum(1 for r in rows if r["base_hit"])
    cand = sum(1 for r in rows if r["candidate_hit"])
    neither = sum(1 for r in rows if not r["base_hit"] and not r["candidate_hit"])
    ranks = Counter(int(r["candidate_ai3_rank"]) for r in rows)
    return {
        "n": n,
        "base": base,
        "cand": cand,
        "net": cand - base,
        "base_rate": pct(base, n),
        "cand_rate": pct(cand, n),
        "neither": neither,
        "ranks": ranks,
    }


def print_feature_table(title: str, rows: list[dict]) -> None:
    print(f"\n【{title}: 救済候補自身のコース別】")
    print("救済候補                         候補C      不一致R   3位艇勝ち   候補勝ち    差    候補AI3順位")
    print("-" * 104)
    for feature, label in FEATURES:
        drows = candidate_disagreements(rows, feature)
        for group_label, courses in COURSE_GROUPS:
            part = [r for r in drows if r["candidate_course"] in courses]
            m = summarize(part)
            ranks = "/".join(f"{k}位:{v}" for k, v in sorted(m["ranks"].items())) or "-"
            print(
                f"{label:<30} {group_label:<7} {m['n']:>7d}   "
                f"{m['base_rate']:>7.2f}%   {m['cand_rate']:>7.2f}%  {m['net']:>+4d}   {ranks}"
            )
        print("-" * 104)


def print_promising_reproduction(dev: list[dict], confirm: list[dict]) -> None:
    print("\n【DEVで候補勝ち>3位艇勝ちだった事前条件のCONFIRM再現】")
    print("救済候補                         候補C      DEV N  DEV 3位/候補    CONF N  CONF 3位/候補")
    print("-" * 108)
    any_row = False
    for feature, label in FEATURES:
        dev_rows = candidate_disagreements(dev, feature)
        con_rows = candidate_disagreements(confirm, feature)
        for group_label, courses in COURSE_GROUPS:
            d = summarize([r for r in dev_rows if r["candidate_course"] in courses])
            if d["n"] < 8 or d["cand"] <= d["base"]:
                continue
            c = summarize([r for r in con_rows if r["candidate_course"] in courses])
            any_row = True
            print(
                f"{label:<30} {group_label:<7} {d['n']:>5d}  "
                f"{d['base_rate']:>5.1f}/{d['cand_rate']:<5.1f}%   "
                f"{c['n']:>6d}  {c['base_rate']:>5.1f}/{c['cand_rate']:<5.1f}%"
            )
    if not any_row:
        print("該当なし")


def main() -> None:
    if len(sys.argv) != 7:
        print(
            "Usage: python3 analysis/analyze_payout_signal_rescue_candidate_course.py "
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

    print("=" * 122)
    print("配当サイン固定：AI3 3位からの救済候補を『候補艇自身のコース』で再現確認")
    print("=" * 122)
    print(f"DEV     : {dev_common['p1_start']} ～ {dev_common['p2_end']}")
    print(f"CONFIRM : {confirm_common['p2_start']} ～ {confirm_common['p2_end']} ※既観察期間")
    print("対象     : primary=イン崩壊 かつ実1C敗戦、さらにAI3 Top2外")
    print("条件     : 事前に分かる救済候補艇のコースだけで分割。結果コースは条件に使わない")
    print("本番変更 : なし。閾値探索・置換固定もしない")

    print_feature_table("DEV", dev)
    print_feature_table("CONFIRM", confirm)
    print_promising_reproduction(dev, confirm)

    print("\n【判断方針】")
    print("1. 前段の実勝者コース別テーブルは結果ラベルなので、そのまま本番条件には使わない")
    print("2. 今回は候補艇自身のコースという事前条件だけで再現するかを見る")
    print("3. DEV/CONFIRM双方で候補勝ち>3位艇勝ち、かつ件数が極端に少なくない条件だけ残す")
    print("4. ここでも安定条件がなければ、AI3 3位を素直な第3頭候補とする方が安全")
    print("5. 条件が残っても最終採用は9/7以降の未使用前方検証で決める")
    print("6. 本命/対抗にはまだ接続しない")

    print("\njoin/skip DEV    :", dict(dev_skip))
    print("join/skip CONFIRM:", dict(confirm_skip))
    print("=" * 122)
    print(f"総所要時間 : {format_elapsed(time.perf_counter()-t0)}")


if __name__ == "__main__":
    main()
