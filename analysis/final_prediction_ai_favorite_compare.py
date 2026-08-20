#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
最終予想 AI活用 STEP B1：本命選定

目的
----
現在の本命選定と、すでに検証済みのAI情報を比較し、
「本命をAI側へ変更すると改善する条件」があるかをP1/P2で検証する。

比較する本命
------------
CURRENT
    CSVのfinal_rank=1を基礎に、現在の本番ルール
      一次差 5～10 × 二次差 1～2 → 一次1位へ変更
    を再現した現行本命。

WIN_TOP1
    採用済み「補正後1着率」の1位。

OUTCOME_HEAD_TOP1
    採用済み最終出目モデル
      VENUE_K3000
      + 補正後1着率 alpha=1.00
      + AI3連対率 beta=1.25
      + 2/3着順序 delta=0.25 / gamma=0.25
    の120通りを1着艇別に合算した「1着周辺確率」の1位。

TRIO_TOP1
    ENTRY_MODE AI3連対率1位。頭モデルではないため参考表示。

AI_CONSENSUS_OVERRIDE
    WIN_TOP1 と OUTCOME_HEAD_TOP1 が同じ艇で、CURRENTと違うときだけ
    AI側へ本命変更する。
    さらに両方の1位-2位差が threshold 以上のときだけ変更する。

thresholdはP1だけで選び、P2では固定する。

Usage
-----
python3 analysis/final_prediction_ai_favorite_compare.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv
"""

from __future__ import annotations

import csv
import math
import sys
from collections import defaultdict
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import trifecta_probability_order_compare as step3


ORDER_DELTA = 0.25
ORDER_GAMMA = 0.25
THRESHOLDS_PT = (0.0, 1.0, 2.0, 3.0, 5.0, 7.5, 10.0)
EPS = 1e-15


def fnum(value, default=0.0):
    try:
        if value is None or str(value).strip() == "":
            return default
        return float(value)
    except (TypeError, ValueError):
        return default


def inum(value, default=0):
    try:
        if value is None or str(value).strip() == "":
            return default
        return int(float(value))
    except (TypeError, ValueError):
        return default


def load_final_csv(*paths):
    races = {}
    required = {
        "race_code",
        "lane_number",
        "first_total_score",
        "first_rank",
        "second_score",
        "second_rank",
        "final_rank",
        "actual_rank",
    }

    for path in paths:
        with open(path, "r", encoding="utf-8-sig", newline="") as fh:
            reader = csv.DictReader(fh)
            fields = set(reader.fieldnames or [])
            missing = sorted(required - fields)
            if missing:
                raise RuntimeError(f"{path}: 必須列不足: {', '.join(missing)}")

            for row in reader:
                code = str(row.get("race_code", "")).strip()
                lane = inum(row.get("lane_number"))
                if not code or lane < 1 or lane > 6:
                    continue
                race = races.setdefault(code, {})
                race[lane] = {
                    "lane": lane,
                    "first_score": fnum(row.get("first_total_score")),
                    "first_rank": inum(row.get("first_rank")),
                    "second_score": fnum(row.get("second_score")),
                    "second_rank": inum(row.get("second_rank")),
                    "final_rank": inum(row.get("final_rank")),
                    "actual_rank": fnum(row.get("actual_rank"), 0.0),
                }

    return races


def current_favorite(boats):
    if set(boats) != set(range(1, 7)):
        return None

    first1 = next((b for b in boats.values() if b["first_rank"] == 1), None)
    first2 = next((b for b in boats.values() if b["first_rank"] == 2), None)
    second1 = next((b for b in boats.values() if b["second_rank"] == 1), None)
    second2 = next((b for b in boats.values() if b["second_rank"] == 2), None)
    final1 = next((b for b in boats.values() if b["final_rank"] == 1), None)
    if None in (first1, first2, second1, second2, final1):
        return None

    first_gap = first1["first_score"] - first2["first_score"]
    second_gap = second1["second_score"] - second2["second_score"]

    # 現在のProduction buildSummary()で採用済みの一次優勢ルール。
    if 5.0 <= first_gap < 10.0 and 1.0 <= second_gap < 2.0:
        return int(first1["lane"])
    return int(final1["lane"])


def marginal_signals(record):
    # STEP1基礎出目の艇別周辺確率。
    q_win = {lane: 0.0 for lane in range(1, 7)}
    q_trio = {lane: 0.0 for lane in range(1, 7)}
    for idx, lanes in enumerate(record["pattern_lanes"]):
        p = float(record["probs"][idx])
        q_win[lanes[0]] += p
        for lane in lanes:
            q_trio[lane] += p

    # log ratioから採用済み補正後1着率 / AI3連対率を復元。
    win = {
        lane: q_win[lane] * math.exp(float(record["log_win_ratio"][lane]))
        for lane in range(1, 7)
    }
    trio = {
        lane: q_trio[lane] * math.exp(float(record["log_trio_ratio"][lane]))
        for lane in range(1, 7)
    }

    # STEP3最終120通りを艇別1着周辺確率へ集約。
    final_probs = step3.order_adjusted_probs(record, ORDER_DELTA, ORDER_GAMMA)
    outcome_head = {lane: 0.0 for lane in range(1, 7)}
    for idx, lanes in enumerate(record["pattern_lanes"]):
        outcome_head[lanes[0]] += float(final_probs[idx])

    return win, trio, outcome_head


def top_info(values):
    ranked = sorted(values.items(), key=lambda x: (-x[1], x[0]))
    top_lane, top_p = ranked[0]
    second_p = ranked[1][1]
    return int(top_lane), float(top_p), float((top_p - second_p) * 100.0)


def build_eval_records(common, csv_races):
    out = {"P1": [], "P2": []}
    skip = defaultdict(int)

    for period in ("P1", "P2"):
        for record in common[period]:
            code = str(record["race_code"])
            boats = csv_races.get(code)
            if boats is None or set(boats) != set(range(1, 7)):
                skip[f"{period}_csv_missing"] += 1
                continue

            current = current_favorite(boats)
            if current is None:
                skip[f"{period}_current_invalid"] += 1
                continue

            win, trio, outcome_head = marginal_signals(record)
            win_top, win_p, win_gap = top_info(win)
            trio_top, trio_p, trio_gap = top_info(trio)
            head_top, head_p, head_gap = top_info(outcome_head)

            actual = {lane: float(boats[lane]["actual_rank"]) for lane in range(1, 7)}
            if any(v <= 0 for v in actual.values()):
                skip[f"{period}_actual_invalid"] += 1
                continue

            out[period].append({
                "race_code": code,
                "actual": actual,
                "current": current,
                "win_top": win_top,
                "trio_top": trio_top,
                "head_top": head_top,
                "win_gap": win_gap,
                "head_gap": head_gap,
                "win_p": win_p,
                "trio_p": trio_p,
                "head_p": head_p,
            })
            skip[f"{period}_ready"] += 1

    return out, skip


def empty_stats():
    return {"n": 0, "w1": 0, "w2": 0, "w3": 0, "rank_sum": 0.0}


def add_stats(stats, actual_rank):
    if actual_rank <= 0:
        return
    stats["n"] += 1
    stats["rank_sum"] += actual_rank
    if actual_rank <= 1:
        stats["w1"] += 1
    if actual_rank <= 2:
        stats["w2"] += 1
    if actual_rank <= 3:
        stats["w3"] += 1


def summary(stats):
    n = stats["n"]
    if n <= 0:
        return {"n": 0, "r1": 0.0, "r2": 0.0, "r3": 0.0, "avg": 0.0}
    return {
        "n": n,
        "r1": stats["w1"] / n,
        "r2": stats["w2"] / n,
        "r3": stats["w3"] / n,
        "avg": stats["rank_sum"] / n,
    }


def selector_lane(row, name, threshold=0.0):
    if name == "CURRENT":
        return row["current"]
    if name == "WIN_TOP1":
        return row["win_top"]
    if name == "OUTCOME_HEAD_TOP1":
        return row["head_top"]
    if name == "TRIO_TOP1":
        return row["trio_top"]
    if name == "AI_CONSENSUS_OVERRIDE":
        if (
            row["win_top"] == row["head_top"]
            and row["win_top"] != row["current"]
            and row["win_gap"] >= threshold
            and row["head_gap"] >= threshold
        ):
            return row["win_top"]
        return row["current"]
    raise ValueError(name)


def evaluate(rows, name, threshold=0.0):
    stats = empty_stats()
    changed = 0
    better = 0
    worse = 0
    same = 0
    picked_win = 0
    lost_win = 0

    for row in rows:
        lane = selector_lane(row, name, threshold)
        cur = row["current"]
        ar = row["actual"][lane]
        add_stats(stats, ar)

        if lane != cur:
            changed += 1
            car = row["actual"][cur]
            if ar < car:
                better += 1
            elif ar > car:
                worse += 1
            else:
                same += 1
            if ar == 1 and car != 1:
                picked_win += 1
            if car == 1 and ar != 1:
                lost_win += 1

    return {
        "stats": summary(stats),
        "changed": changed,
        "better": better,
        "worse": worse,
        "same": same,
        "picked_win": picked_win,
        "lost_win": lost_win,
    }


def select_threshold(p1_rows):
    candidates = []
    for threshold in THRESHOLDS_PT:
        result = evaluate(p1_rows, "AI_CONSENSUS_OVERRIDE", threshold)
        s = result["stats"]
        # 変更0件の実質CURRENTは選ばない。極端な少数変更も避ける。
        eligible = result["changed"] >= 30
        key = (
            0 if eligible else 1,
            -s["r1"],
            -s["r2"],
            -s["r3"],
            s["avg"],
            threshold,
        )
        candidates.append((key, threshold, result))
    candidates.sort(key=lambda x: x[0])
    return candidates[0][1], candidates


def print_selector_table(title, rows, threshold):
    print(f"\n【{title}】")
    print("方式                     R数    1着率    2連対    3連対   平均着順  変更  良化  悪化  1着拾い 1着失い")
    print("-" * 112)
    methods = [
        ("CURRENT", 0.0),
        ("WIN_TOP1", 0.0),
        ("OUTCOME_HEAD_TOP1", 0.0),
        ("TRIO_TOP1(参考)", 0.0),
        (f"AI_CONSENSUS(t={threshold:.1f}pt)", threshold),
    ]
    for label, th in methods:
        name = "TRIO_TOP1" if label.startswith("TRIO_TOP1") else (
            "AI_CONSENSUS_OVERRIDE" if label.startswith("AI_CONSENSUS") else label
        )
        r = evaluate(rows, name, th)
        s = r["stats"]
        print(
            f"{label:<25} {s['n']:>5d}  {s['r1']*100:>6.2f}%  {s['r2']*100:>6.2f}%  "
            f"{s['r3']*100:>6.2f}%   {s['avg']:>6.3f}  {r['changed']:>4d}  "
            f"{r['better']:>4d}  {r['worse']:>4d}  {r['picked_win']:>6d}  {r['lost_win']:>6d}"
        )


def print_threshold_table(title, rows):
    print(f"\n【{title}】")
    print("閾値      変更    1着率    2連対    3連対   平均着順  良化  悪化  1着拾い 1着失い")
    print("-" * 96)
    for threshold in THRESHOLDS_PT:
        r = evaluate(rows, "AI_CONSENSUS_OVERRIDE", threshold)
        s = r["stats"]
        print(
            f">={threshold:>4.1f}pt  {r['changed']:>4d}  {s['r1']*100:>6.2f}%  {s['r2']*100:>6.2f}%  "
            f"{s['r3']*100:>6.2f}%   {s['avg']:>6.3f}  {r['better']:>4d}  {r['worse']:>4d}  "
            f"{r['picked_win']:>6d}  {r['lost_win']:>6d}"
        )


def print_delta(current, new):
    c = current["stats"]
    n = new["stats"]
    print(f"1着率差     : {(n['r1'] - c['r1'])*100:+.2f}pt")
    print(f"2連対差     : {(n['r2'] - c['r2'])*100:+.2f}pt")
    print(f"3連対差     : {(n['r3'] - c['r3'])*100:+.2f}pt")
    print(f"平均着順差  : {n['avg'] - c['avg']:+.3f} （マイナスが改善）")
    print(f"本命変更     : {new['changed']}R")
    print(f"変更で上位化 : {new['better']}R / 下位化 : {new['worse']}R / 同着 : {new['same']}R")
    print(f"1着を拾う    : {new['picked_win']}R / 1着を失う : {new['lost_win']}R")


def main():
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/final_prediction_ai_favorite_compare.py "
            "P1_BOATS_CSV P2_BOATS_CSV"
        )
        sys.exit(1)

    p1_csv, p2_csv = sys.argv[1], sys.argv[2]

    print("STEP3共通AIデータと現行最終予想CSVを結合中...")
    data = step3.build_common_records(p1_csv, p2_csv)
    csv_races = load_final_csv(p1_csv, p2_csv)
    rows, skip = build_eval_records(data["records"], csv_races)

    if not rows["P1"] or not rows["P2"]:
        raise RuntimeError("P1/P2の共通評価レースがありません")

    selected_threshold, _ = select_threshold(rows["P1"])

    print("=" * 126)
    print("最終予想 AI活用 STEP B1：本命選定")
    print("=" * 126)
    print(f"P1                  : {data['p1_start']} ～ {data['p1_end']}")
    print(f"P2完全ホールドアウト: {data['p2_start']} ～ {data['p2_end']}")
    print("現行本命             : final_rank=1 + 採用済み一次優勢ルールを再現")
    print("WIN_TOP1             : 採用済み補正後1着率1位")
    print("OUTCOME_HEAD_TOP1    : STEP3最終120出目の1着周辺確率1位")
    print("TRIO_TOP1            : ENTRY_MODE AI3連対率1位（参考）")
    print("AI_CONSENSUS         : WIN_TOP1とOUTCOME_HEAD_TOP1が一致した別艇だけ条件付き採用")
    print("閾値選択             : P1のみ / win gap・head gapの両方がthreshold以上")
    print("本番Web変更          : なし")

    print("\n【共通評価母集団】")
    print(
        f"P1={len(rows['P1'])}R / P2={len(rows['P2'])}R"
        f" / P1 AI共通不足={data['miss'].get('P1_win_missing', 0) + data['miss'].get('P1_trio_missing', 0)}R"
        f" / P2 AI共通不足={data['miss'].get('P2_win_missing', 0) + data['miss'].get('P2_trio_missing', 0)}R"
    )
    print(f"P1で選択したAI_CONSENSUS閾値: {selected_threshold:.1f}pt")

    print_threshold_table("P1 AI_CONSENSUS 閾値選択用", rows["P1"])
    print_threshold_table("P2 AI_CONSENSUS ホールドアウト参考（P2で再選択しない）", rows["P2"])

    print_selector_table("P1 本命方式比較", rows["P1"], selected_threshold)
    print_selector_table("P2 ホールドアウト（最重要）", rows["P2"], selected_threshold)

    current_p2 = evaluate(rows["P2"], "CURRENT")
    consensus_p2 = evaluate(rows["P2"], "AI_CONSENSUS_OVERRIDE", selected_threshold)
    win_p2 = evaluate(rows["P2"], "WIN_TOP1")
    head_p2 = evaluate(rows["P2"], "OUTCOME_HEAD_TOP1")

    print("\n【最重要: P2 CURRENT → AI_CONSENSUS_OVERRIDE】")
    print_delta(current_p2, consensus_p2)

    print("\n【参考: P2 CURRENT → WIN_TOP1 全置換】")
    print_delta(current_p2, win_p2)

    print("\n【参考: P2 CURRENT → OUTCOME_HEAD_TOP1 全置換】")
    print_delta(current_p2, head_p2)

    print("\n【判断方針】")
    print("1. まずCURRENTよりWIN_TOP1/OUTCOME_HEAD_TOP1に本命選定能力があるか確認")
    print("2. 全置換ではなく、AI2系統一致時だけのCONSENSUS変更がP2でも改善するか確認")
    print("3. P1で選んだthresholdをP2で変更しない")
    print("4. P2で1着率だけでなく2連対/3連対/平均着順も大崩れしないことを確認")
    print("5. 本命で採用条件が見つかったら、次はSTEP B2 相手候補へのAI3連対率活用へ進む")
    print("6. このスクリプトは検証のみで、本番最終予想ロジックは変更しない")
    print("=" * 126)


if __name__ == "__main__":
    main()
