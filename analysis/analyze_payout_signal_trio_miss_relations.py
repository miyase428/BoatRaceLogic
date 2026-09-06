#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
配当サイン固定後の「AI3連対率Top2で拾えなかった穴頭」を、
2～6コース同士の展開関係まで広げて診断する。

目的
----
前段で primary=イン崩壊 かつ実1C敗戦では、非イン5艇のAI3連対率Top2が
DEV/CONFIRMとも約70%の実頭を捕捉した。一方、全体を一律に複合スコア化すると
AI3単独を安定して上回れなかった。

そこで今回は、強いAI3 Top2を壊さずに、残り約30%の「Top2外の実頭」に絞って、
2～6コースの他艇との展開関係から救済候補の共通点を探す。

見る展開関係
------------
- 自艇の攻め力
  * 2C: 差し率
  * 3～6C: まくり+まくり差し
- 自艇より内側（1Cを除く）の他艇
  * 攻め力最大 / 合計 / 20%以上艇数
- 自艇より外側の他艇
  * 攻め力最大 / 合計
  * 外圧が弱いほど高評価する指標
- 組み合わせ
  * 内側最大攻め × 自艇まくり差し
  * 内側最大攻め × 自艇(差し+まくり差し)
  * 内側攻め合計 - 外側最大攻め
  * 自艇攻め - 外側最大攻め

重要
----
- 荒れ判定条件は一切変更しない。
- AI3 Top2をこの段階で置き換えない。
- 実結果は評価ラベルにのみ使う。
- DEV/CONFIRMは既に観察済みなので、ここでは救済ルールを最終固定しない。
- 9/7以降を新しい未使用前方検証に残す。
- 本命/対抗/PredictionLogic/本番買い目は変更しない。

Usage:
python3 analysis/analyze_payout_signal_trio_miss_relations.py \
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
import compare_payout_signal_head_rules as cmp


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


def move_strength(boat: dict):
    """展開を動かす力。2Cは差し、3～6Cはまくり+まくり差し。"""
    course = int(boat.get("course") or 0)
    if course == 2:
        return rel.safe_float(boat.get("self_sashi"))
    if 3 <= course <= 6:
        return rel.safe_float(boat.get("self_attack"))
    return None


def relation_features(row: dict, lane: int) -> dict[str, float | None]:
    boats = row["features"]
    mine = boats[lane]
    course = int(mine.get("course") or 0)
    in_lane = int(row["in_lane"])

    own_move = move_strength(mine)
    own_sashi = rel.safe_float(mine.get("self_sashi"))
    own_mz = rel.safe_float(mine.get("self_mz"))
    own_finish = None
    if own_sashi is not None and own_mz is not None:
        own_finish = own_sashi + own_mz

    inner_vals = []
    outer_vals = []
    for other_lane in range(1, 7):
        if other_lane in (lane, in_lane):
            continue
        other = boats[other_lane]
        other_course = int(other.get("course") or 0)
        if other_course <= 1:
            continue
        v = move_strength(other)
        if v is None:
            continue
        if other_course < course:
            inner_vals.append(float(v))
        elif other_course > course:
            outer_vals.append(float(v))

    expected_inner = max(course - 2, 0)
    expected_outer = max(6 - course, 0)

    if expected_inner == 0:
        inner_max = 0.0
        inner_sum = 0.0
        inner_count20 = 0.0
    elif inner_vals:
        inner_max = max(inner_vals)
        inner_sum = sum(inner_vals)
        inner_count20 = float(sum(1 for x in inner_vals if x >= 20.0))
    else:
        inner_max = None
        inner_sum = None
        inner_count20 = None

    if expected_outer == 0:
        outer_max = 0.0
        outer_sum = 0.0
    elif outer_vals:
        outer_max = max(outer_vals)
        outer_sum = sum(outer_vals)
    else:
        outer_max = None
        outer_sum = None

    def mul(a, b):
        return a * b if a is not None and b is not None else None

    def sub(a, b):
        return a - b if a is not None and b is not None else None

    return {
        "self_move": own_move,
        "inner_move_max": inner_max,
        "inner_move_sum": inner_sum,
        "inner_move_count20": inner_count20,
        # 外側が弱いほど良いので符号を反転する。
        "outer_safety_max": -outer_max if outer_max is not None else None,
        "outer_safety_sum": -outer_sum if outer_sum is not None else None,
        "inner_x_self_mz": mul(inner_max, own_mz),
        "inner_x_self_finish": mul(inner_max, own_finish),
        "inner_minus_outer": sub(inner_sum, outer_max),
        "self_minus_outer": sub(own_move, outer_max),
        # 既存の自力・AIを救済候補比較の基準として併記する。
        "win_p": rel.safe_float(mine.get("win_p")),
        "primary": rel.safe_float(mine.get("primary")),
        "motor": rel.safe_float(mine.get("motor")),
    }


FEATURES = (
    ("TRIO_RANK3", "AI3の3位をそのまま追加"),
    ("win_p", "補正後1着率"),
    ("primary", "一次評価"),
    ("motor", "モーター2連率"),
    ("self_move", "自艇の攻め力"),
    ("inner_move_max", "内側(1C除外)の攻め最大"),
    ("inner_move_sum", "内側(1C除外)の攻め合計"),
    ("inner_move_count20", "内側20%以上攻め艇数"),
    ("outer_safety_max", "外側最大攻めが弱い"),
    ("outer_safety_sum", "外側総攻めが弱い"),
    ("inner_x_self_mz", "内側最大攻め×自艇まくり差し"),
    ("inner_x_self_finish", "内側最大攻め×自艇差し/まくり差し"),
    ("inner_minus_outer", "内側攻め合計-外側最大攻め"),
    ("self_minus_outer", "自艇攻め-外側最大攻め"),
)


def trio_order(row: dict) -> list[int]:
    return cmp.method_order(row, "TRIO")


def rescue_order(row: dict, feature: str, rescue_pool: list[int]) -> list[int]:
    base = trio_order(row)
    base_pos = {lane: i for i, lane in enumerate(base)}

    if feature == "TRIO_RANK3":
        return sorted(rescue_pool, key=lambda lane: base_pos[lane])

    scored = []
    for lane in rescue_pool:
        v = relation_features(row, lane).get(feature)
        scored.append((lane, v))

    # 欠損艇を候補から消さず、値がある艇を優先。全欠損ならAI3順位順へ戻る。
    return [lane for lane, _ in sorted(
        scored,
        key=lambda x: (
            0 if x[1] is not None else 1,
            -(float(x[1]) if x[1] is not None else 0.0),
            base_pos[x[0]],
        ),
    )]


def winner_trio_rank(rows: list[dict]) -> Counter:
    c = Counter()
    for r in rows:
        winner = int(r["actual_first"])
        order = trio_order(r)
        c[order.index(winner) + 1] += 1
    return c


def eval_rescue(rows: list[dict], feature: str) -> dict:
    total = len(rows)
    misses = []
    for r in rows:
        winner = int(r["actual_first"])
        if winner not in trio_order(r)[:2]:
            misses.append(r)

    hit1 = 0
    hit2 = 0
    winner_rank_sum = 0.0
    valid = 0
    by_course = defaultdict(lambda: {"n": 0, "hit1": 0})

    for r in misses:
        winner = int(r["actual_first"])
        base = trio_order(r)
        pool = base[2:]
        order = rescue_order(r, feature, pool)
        if winner not in order:
            continue
        valid += 1
        rank = order.index(winner) + 1
        winner_rank_sum += rank
        h1 = rank == 1
        h2 = rank <= 2
        hit1 += 1 if h1 else 0
        hit2 += 1 if h2 else 0
        c = int(r["actual_course"])
        by_course[c]["n"] += 1
        by_course[c]["hit1"] += 1 if h1 else 0

    base_top2 = total - len(misses)
    add_capture = base_top2 + hit1
    return {
        "total": total,
        "miss_n": len(misses),
        "valid": valid,
        "hit1": hit1,
        "hit2": hit2,
        "hit1_rate": pct(hit1, valid),
        "hit2_rate": pct(hit2, valid),
        "avg_rank": winner_rank_sum / valid if valid else 0.0,
        "add3_capture": pct(add_capture, total),
        "by_course": by_course,
    }


def print_rank_distribution(title: str, rows: list[dict]) -> None:
    c = winner_trio_rank(rows)
    n = len(rows)
    print(f"\n【{title}: 実勝者のAI3順位】")
    print(" / ".join(f"{rank}位={c.get(rank,0)}R({pct(c.get(rank,0),n):.1f}%)" for rank in range(1, 6)))
    miss = sum(c.get(rank, 0) for rank in (3, 4, 5))
    print(f"AI3 Top2外 = {miss}R / {n}R = {pct(miss,n):.2f}%")
    if miss:
        print("Top2外内訳   : " + " / ".join(
            f"{rank}位={c.get(rank,0)}R({pct(c.get(rank,0),miss):.1f}%)" for rank in (3, 4, 5)
        ))


def print_table(title: str, rows: list[dict]) -> dict[str, dict]:
    print(f"\n【{title}: AI3 Top2外の実頭を誰で救うか】")
    print("救済候補                         missR  救済Top1  救済Top2  平均順位  Top2+1頭時の頭捕捉")
    print("-" * 104)
    out = {}
    for feature, label in FEATURES:
        m = eval_rescue(rows, feature)
        out[feature] = m
        print(
            f"{label:<32} {m['miss_n']:>5d}   {m['hit1_rate']:>7.2f}%  {m['hit2_rate']:>7.2f}%"
            f"   {m['avg_rank']:>6.3f}位       {m['add3_capture']:>7.2f}%"
        )
    return out


def print_course_table(title: str, rows: list[dict], selected: list[str]) -> None:
    label_map = dict(FEATURES)
    print(f"\n【{title}: Top2外実勝者コース別・救済Top1】")
    print("方式                           2C        3C        4C       5-6C")
    print("-" * 86)
    for feature in selected:
        m = eval_rescue(rows, feature)
        bc = m["by_course"]

        def cell(courses):
            n = sum(bc[c]["n"] for c in courses)
            h = sum(bc[c]["hit1"] for c in courses)
            return f"{pct(h,n):5.1f}%({n:2d})" if n else "   -   "

        print(
            f"{label_map[feature]:<30} {cell((2,)):>10} {cell((3,)):>10} {cell((4,)):>10} {cell((5,6)):>10}"
        )


def rank_features(results: dict[str, dict]) -> list[str]:
    # rescue Top1 → Top2+1頭時捕捉 → 平均順位。TRIO_RANK3も比較対象に残す。
    return sorted(
        results,
        key=lambda k: (
            -results[k]["hit1_rate"],
            -results[k]["add3_capture"],
            results[k]["avg_rank"],
            k,
        ),
    )


def main():
    if len(sys.argv) != 7:
        print(
            "Usage: python3 analysis/analyze_payout_signal_trio_miss_relations.py "
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
    confirm_all, confirm_skip = profile.build_rows(confirm_records, boats_map, kimarite_map, engine_map, "CONFIRM")
    dev = cmp.target_rows(dev_all)
    confirm = cmp.target_rows(confirm_all)

    print("=" * 122)
    print("配当サイン固定：AI3 Top2外の穴頭を2～6C展開関係から救えるか")
    print("=" * 122)
    print(f"DEV     : {dev_common['p1_start']} ～ {dev_common['p2_end']}")
    print(f"CONFIRM : {confirm_common['p2_start']} ～ {confirm_common['p2_end']} ※既観察期間")
    print("対象     : primary=イン崩壊 かつ実1C敗戦")
    print("基準     : AI3連対率Top2は維持。Top2外の実頭だけを救済候補分析")
    print("展開     : 1Cを除く2～6C同士の内側攻め/外圧/自艇技の組み合わせ")
    print("本番変更 : なし。まだ3頭化・置換・買い目変更はしない")

    print_rank_distribution("DEV", dev)
    print_rank_distribution("CONFIRM", confirm)
    dev_result = print_table("DEV", dev)
    confirm_result = print_table("CONFIRM", confirm)

    dev_rank = rank_features(dev_result)
    print("\n【DEV上位のCONFIRM再現】")
    print("DEV順位 救済候補                         DEV救済Top1   CONFIRM救済Top1   DEV Top2+1   CONFIRM Top2+1")
    print("-" * 110)
    for i, feature in enumerate(dev_rank, 1):
        label = dict(FEATURES)[feature]
        d = dev_result[feature]
        c = confirm_result[feature]
        print(
            f"{i:>3d}    {label:<32} {d['hit1_rate']:>7.2f}%        {c['hit1_rate']:>7.2f}%"
            f"        {d['add3_capture']:>7.2f}%        {c['add3_capture']:>7.2f}%"
        )

    selected = dev_rank[:4]
    if "TRIO_RANK3" not in selected:
        selected.append("TRIO_RANK3")
    print_course_table("DEV", dev, selected)
    print_course_table("CONFIRM", confirm, selected)

    print("\n【判断方針】")
    print("1. AI3 Top2約70%は壊さず、まずTop2外約30%の救済構造だけを見る")
    print("2. TRIO_RANK3を基準に、展開特徴が3位艇を超えて救済できるか確認する")
    print("3. DEVだけ強くCONFIRMで崩れる展開特徴は採用しない")
    print("4. 2C/3C/4C/5-6Cで効き方が逆なら、全コース共通の上乗せにはしない")
    print("5. この結果で有望な特徴を1～2個に絞った後、置換トリガーをDEVで事前定義する")
    print("6. 最終方式は9/7以降の新しい未使用期間で前方検証する")

    print(f"\njoin/skip DEV    : {dict(dev_skip)}")
    print(f"join/skip CONFIRM: {dict(confirm_skip)}")
    print("=" * 122)
    print(f"総所要時間 : {format_elapsed(time.perf_counter() - t0)}")


if __name__ == "__main__":
    main()
