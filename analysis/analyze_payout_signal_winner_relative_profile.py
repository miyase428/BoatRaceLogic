#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
現行の配当サイン/荒れ方判定を固定したまま、
「荒れるとしたら1着はどの艇か」をレース相対・展開の観点で探索する。

今回の主対象
------------
- PayoutSignalClassifier v1相当の primary=イン崩壊
- その中で実際に1C以外が勝ったレース
- 実勝者を、同じレースの他の非イン艇と比較する

見るもの
--------
自力      : モーター / ボート / 一次 / 二次 / 最終
AI        : 補正後1着率 / AI3連対率 / 120通り頭確率
自艇技    : 6m差し / まくり / まくり差し / 攻め率
展開      : 1つ内艇のまくり・まくり差し・攻め率
            自艇より内側の攻め率最大
相互作用  : 内艇攻め × 自艇まくり差し等

重要
----
- 荒れ判定の閾値は変更しない。
- この結果だけで穴頭方式を固定しない。まず共通点/差を探す段階。
- 9/1～9/5は既に確認済み期間なので「完全未使用」ではなく再現確認扱い。
- 9/6は実結果を目視済みのため、今回の方式選択には使わない。
- 本命/対抗/PredictionLogic/本番買い目は変更しない。

Usage:
python3 analysis/analyze_payout_signal_winner_relative_profile.py \
  analysis/output/final_prediction_boats_fast_cached_20260815_20260822.csv \
  analysis/output/final_prediction_boats_fast_cached_20260823_20260831.csv \
  analysis/output/final_prediction_boats_fast_cached_20260901_20260905.csv \
  analysis/output/kimarite_analysis_dataset_20260815_20260822.csv \
  analysis/output/kimarite_analysis_dataset_20260823_20260831.csv \
  analysis/output/kimarite_analysis_dataset_20260901_20260905.csv
"""

from __future__ import annotations

import csv
import statistics
import sys
import time
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import final_prediction_ai_opponent_compare as b2
import final_prediction_ai_bet_integration_compare as b4
import trifecta_probability_order_compare as step3
import validate_payout_signal_hole_bet_candidates as signal_mod
import payout_signal_relative_features as rel

FEATURE_GROUPS = (
    ("自力・既存評価", ("motor", "boat", "primary", "secondary", "final3")),
    ("AI", ("win_p", "trio_p", "outcome_p")),
    ("自艇の決まり手", ("self_sashi", "self_makuri", "self_mz", "self_attack")),
    ("周囲・展開", ("inner_makuri", "inner_mz", "inner_attack", "inside_attack_max")),
    (
        "展開×自艇の相互作用",
        ("inner_attack_x_self_mz", "inner_attack_x_self_sashi_mz", "inside_attack_x_self_mz"),
    ),
)


def pct(n, d):
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


def build_rows(records, boats_map, kimarite_map, engine_map, period):
    rows = []
    skip = defaultdict(int)
    for record in records:
        code = str(record.get("race_code") or "")
        boats = boats_map.get(code)
        k = kimarite_map.get(code)
        if boats is None or k is None:
            skip["input_missing"] += 1
            continue

        signal = signal_mod.classify_kimarite_row(k)
        if signal is None:
            skip["signal_not_ready"] += 1
            continue
        primary_type = str(signal.get("primary") or "平常")
        if primary_type == "平常":
            skip["normal"] += 1
            continue

        actual = b4.actual_trifecta(boats)
        if actual is None:
            skip["actual_missing"] += 1
            continue

        feature = rel.build_features(record, boats, k, engine_map)
        if feature is None:
            skip["relative_feature_missing"] += 1
            continue

        actual_first = int(actual[0])
        in_lane = int(feature["in_lane"])
        rows.append({
            "period": period,
            "race_code": code,
            "type": primary_type,
            "actual_first": actual_first,
            "actual_course": int(feature["course_by_lane"].get(actual_first, 0)),
            "in_lane": in_lane,
            "in_failed": actual_first != in_lane,
            "features": feature["boats"],
        })
        skip["ready"] += 1
    return rows, skip


def print_type_summary(title, rows):
    print(f"\n【{title}: 荒れ方別 実頭】")
    print("荒れ方           R数   非イン頭   非イン頭率   1C頭率")
    print("-" * 66)
    for typ in ("イン崩壊", "ヒモ荒れ", "複合高配当"):
        part = [r for r in rows if r["type"] == typ]
        nonin = sum(1 for r in part if r["in_failed"])
        print(
            f"{typ:<14} {len(part):>5d}   {nonin:>7d}    {pct(nonin, len(part)):>8.2f}%   "
            f"{pct(len(part)-nonin, len(part)):>7.2f}%"
        )


def target_rows(rows):
    return [r for r in rows if r["type"] == "イン崩壊" and r["in_failed"]]


def capture(rows, feature):
    valid = 0
    top1 = 0
    top2 = 0
    rank_sum = 0.0
    above_field = 0
    winner_vals = []
    other_avgs = []

    for r in rows:
        winner = int(r["actual_first"])
        in_lane = int(r["in_lane"])
        eligible = [lane for lane in range(1, 7) if lane != in_lane]
        order = rel.rank_lanes(r["features"], feature, eligible)
        if winner not in order:
            continue
        winner_val = rel.safe_float(r["features"][winner].get(feature))
        if winner_val is None:
            continue

        other_vals = []
        for lane in eligible:
            if lane == winner:
                continue
            v = rel.safe_float(r["features"][lane].get(feature))
            if v is not None:
                other_vals.append(v)
        if not other_vals:
            continue

        valid += 1
        rank = order.index(winner) + 1
        rank_sum += rank
        top1 += 1 if rank <= 1 else 0
        top2 += 1 if rank <= 2 else 0
        other_avg = sum(other_vals) / len(other_vals)
        above_field += 1 if winner_val > other_avg else 0
        winner_vals.append(winner_val)
        other_avgs.append(other_avg)

    return {
        "n": valid,
        "top1": pct(top1, valid),
        "top2": pct(top2, valid),
        "avg_rank": rank_sum / valid if valid else 0.0,
        "above_field": pct(above_field, valid),
        "winner_mean": statistics.fmean(winner_vals) if winner_vals else None,
        "other_mean": statistics.fmean(other_avgs) if other_avgs else None,
    }


def print_capture_table(title, rows):
    print(f"\n【{title}: イン崩壊かつ実非イン頭={len(rows)}R】")
    print("※各指標は『非イン5艇の中で実1着艇が何位だったか』。結果は順位作成に使っていません。")
    for group, features in FEATURE_GROUPS:
        print(f"\n--- {group} ---")
        print("指標                         有効R   Top1     Top2    平均順位  勝者>他艇平均")
        print("-" * 86)
        for feature in features:
            m = capture(rows, feature)
            print(
                f"{rel.FEATURE_LABELS[feature]:<28} {m['n']:>5d}  "
                f"{m['top1']:>6.2f}%  {m['top2']:>6.2f}%   {m['avg_rank']:>6.3f}位    {m['above_field']:>6.2f}%"
            )


def print_value_compare(title, rows):
    focus = (
        "motor", "secondary", "win_p", "trio_p", "outcome_p",
        "self_mz", "self_attack", "inner_makuri", "inner_attack",
        "inner_attack_x_self_mz", "inner_attack_x_self_sashi_mz",
    )
    print(f"\n【{title}: 実1着艇の値 vs 同レース他非イン艇平均】")
    print("指標                         有効R      実1着平均      他艇平均       差")
    print("-" * 88)
    for feature in focus:
        m = capture(rows, feature)
        wm = m["winner_mean"]
        om = m["other_mean"]
        if wm is None or om is None:
            print(f"{rel.FEATURE_LABELS[feature]:<28} {m['n']:>5d}          -             -          -")
            continue
        print(
            f"{rel.FEATURE_LABELS[feature]:<28} {m['n']:>5d}   {wm:>12.4f}   {om:>12.4f}   {wm-om:>+9.4f}"
        )


def print_course_distribution(title, rows):
    c = Counter(int(r["actual_course"]) for r in rows)
    n = len(rows)
    print(f"\n【{title}: イン崩壊で実際に勝った非イン艇のコース】")
    print(" / ".join(f"{course}C={c.get(course,0)}R({pct(c.get(course,0),n):.1f}%)" for course in range(2, 7)))


def write_detail(path: Path, dev_rows, confirm_rows):
    fields = [
        "period", "race_code", "type", "lane", "course", "is_winner", "in_lane",
    ] + list(rel.RANK_FEATURES)
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8-sig", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=fields)
        w.writeheader()
        for r in dev_rows + confirm_rows:
            for lane in range(1, 7):
                f = r["features"][lane]
                row = {
                    "period": r["period"],
                    "race_code": r["race_code"],
                    "type": r["type"],
                    "lane": lane,
                    "course": f["course"],
                    "is_winner": 1 if lane == r["actual_first"] else 0,
                    "in_lane": r["in_lane"],
                }
                for key in rel.RANK_FEATURES:
                    row[key] = f.get(key)
                w.writerow(row)


def main():
    if len(sys.argv) != 7:
        print(
            "Usage: python3 analysis/analyze_payout_signal_winner_relative_profile.py "
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

    print("CSV/決まり手読込中...", flush=True)
    boats_map = b2.load_boats(p1_csv, p2_csv, p3_csv)
    kimarite_map = rel.load_kimarite(k1_csv, k2_csv, k3_csv)
    race_codes = [str(r["race_code"]) for r in dev_records + confirm_records]
    engine_map = rel.load_engine_map(race_codes)

    dev_rows, dev_skip = build_rows(dev_records, boats_map, kimarite_map, engine_map, "DEV_20260815_0831")
    confirm_rows, confirm_skip = build_rows(
        confirm_records, boats_map, kimarite_map, engine_map, "CONFIRM_20260901_0905"
    )

    out_path = Path(__file__).resolve().parent / "output" / "payout_signal_relative_winner_features_20260815_20260905.csv"
    write_detail(out_path, dev_rows, confirm_rows)

    print("=" * 124)
    print("配当サイン固定：荒れるとしたら誰が1着か / 相対・展開特徴プロファイル")
    print("=" * 124)
    print(f"DEV     : {dev_common['p1_start']} ～ {dev_common['p2_end']}")
    print(f"CONFIRM : {confirm_common['p2_start']} ～ {confirm_common['p2_end']} ※既確認期間、完全未使用ではない")
    print("荒れ判定 : 現行PayoutSignalClassifier v1相当を固定（閾値変更なし）")
    print("主対象   : イン崩壊判定のうち、実際に1C以外が勝ったレース")
    print("決まり手 : point-in-time 6m / sample_n>=10")
    print("本番変更 : なし。穴目頭の探索専用。本命/対抗にもまだ接続しない")
    print(f"詳細CSV  : {out_path}")

    print_type_summary("DEV", dev_rows)
    print_type_summary("CONFIRM", confirm_rows)

    dev_target = target_rows(dev_rows)
    confirm_target = target_rows(confirm_rows)
    print_course_distribution("DEV", dev_target)
    print_course_distribution("CONFIRM", confirm_target)

    print_capture_table("DEV", dev_target)
    print_capture_table("CONFIRM", confirm_target)
    print_value_compare("DEV", dev_target)
    print_value_compare("CONFIRM", confirm_target)

    print("\n【読み方】")
    print("1. Top1/Top2がDEV→CONFIRMで同方向の特徴を、穴頭候補の材料として優先する")
    print("2. motor等の自力だけでなく、inner_attack系が再現するかを見る")
    print("3. inner_attack×self_mz等が効けば『内艇が攻めて外艇が展開をもらう』仮説を支持する")
    print("4. 単独で最良の指標を即採用せず、次段で少数の事前定義ルール/スコアを比較する")
    print("5. 最終方式を決めた後は9/7以降の新しい未使用期間で前方検証する")
    print("6. 穴目で有効性を固定できてから、同じ共通特徴量を本命/対抗に影響させず比較する")

    print("\n【join/skip参考】")
    print("DEV    :", dict(dev_skip))
    print("CONFIRM:", dict(confirm_skip))
    print("=" * 124)
    print(f"総所要時間 : {format_elapsed(time.perf_counter()-t0)}")


if __name__ == "__main__":
    main()
