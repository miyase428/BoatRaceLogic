#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
穴目予想：イン敗戦時の「①残り」構造を検証する。

目的
----
既存の穴警戒HIGHを固定したまま、インが1着を逃したときに
2・3着へ残るのか、3着外へ飛ぶのかをレース前情報で分けられるか確認する。

固定する穴警戒HIGH（C2）
-------------------------
- AI本命 = 1C
- CURRENT本命 != 1C
- イン補正後1着率 < 50%

今回見る候補材料
----------------
1. イン艇のAI3連対率
2. イン補正後1着率（<40 / 40-50）
3. イン艇の一次順位
4. イン艇の二次順位

重要
----
- 対象はHIGHのうち「実際にインが1着を逃したレース」。
- ①残り = イン艇が実2着または3着。
- 飛び   = イン艇が実4着以下。
- 閾値はこの結果を見て動かさない。まず固定帯でTRAIN/P3の再現性を見る。
- 本番Web/PredictionLogicは変更しない。

Usage:
python3 analysis/upset_in_remaining_validate.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv \
  analysis/output/final_prediction_boats_fast_cached_20260815_20260822.csv
"""

from __future__ import annotations

import sys
from collections import Counter
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import final_prediction_ai_opponent_compare as b2
import final_prediction_ai_bet_integration_compare as b4
import trifecta_probability_order_compare as step3
import upset_probability_validate as c1


TRIO_BANDS = (
    (None, 0.50, "<50%"),
    (0.50, 0.60, "50-60%"),
    (0.60, 0.70, "60-70%"),
    (0.70, None, ">=70%"),
)

WIN_BANDS = (
    (None, 0.40, "<40%"),
    (0.40, 0.50, "40-50%"),
)


def band(value, bands):
    x = float(value)
    for lo, hi, label in bands:
        if lo is not None and x < lo:
            continue
        if hi is not None and x >= hi:
            continue
        return label
    return "?"


def rank_group(value):
    try:
        r = int(value)
    except (TypeError, ValueError):
        return "?"
    if r <= 0:
        return "?"
    if r >= 4:
        return "4+"
    return str(r)


def build_rows(records, boats_map):
    rows = []
    skip = Counter()

    for record in records:
        code = str(record["race_code"])
        boats = boats_map.get(code)
        if boats is None or set(boats) != set(range(1, 7)):
            skip["csv_missing"] += 1
            continue

        f = c1.make_features(record, boats)
        if f is None:
            skip["feature_invalid"] += 1
            continue

        in_lane = int(f["in_lane"])
        current = int(f["current_head"])
        ai_head = int(f["ai_head"])
        in_win_p = float(f["values"]["in_win_p"])

        # C2で固定済みのHIGH条件。ここでは変更しない。
        if not (ai_head == in_lane and current != in_lane and in_win_p < 0.50):
            skip["not_high"] += 1
            continue

        actual = b4.actual_trifecta(boats)
        if actual is None:
            skip["actual_invalid"] += 1
            continue

        actual = tuple(int(x) for x in actual)
        in_failed = actual[0] != in_lane
        in_remain = in_failed and in_lane in actual[1:3]
        in_fly = in_failed and not in_remain

        in_boat = boats.get(in_lane, {})
        in_trio_p = float(f["trio"].get(in_lane, 0.0))

        rows.append({
            "race_code": code,
            "in_lane": in_lane,
            "in_failed": in_failed,
            "in_remain": in_remain,
            "in_fly": in_fly,
            "in_win_p": in_win_p,
            "in_trio_p": in_trio_p,
            "primary_rank": rank_group(in_boat.get("first_rank")),
            "secondary_rank": rank_group(in_boat.get("second_rank")),
        })
        skip["ready"] += 1

    return rows, skip


def aggregate(rows):
    n = len(rows)
    if n == 0:
        return {
            "n": 0,
            "in_fail": 0,
            "fail_rate": 0.0,
            "remain": 0,
            "fly": 0,
            "remain_rate_fail": 0.0,
            "fly_rate_fail": 0.0,
        }

    fail = [r for r in rows if r["in_failed"]]
    remain = sum(1 for r in fail if r["in_remain"])
    fly = sum(1 for r in fail if r["in_fly"])
    fn = len(fail)

    return {
        "n": n,
        "in_fail": fn,
        "fail_rate": fn / n,
        "remain": remain,
        "fly": fly,
        "remain_rate_fail": remain / fn if fn else 0.0,
        "fly_rate_fail": fly / fn if fn else 0.0,
    }


def print_overall(title, rows):
    s = aggregate(rows)
    print(f"\n【{title} HIGH全体】")
    print(
        f"N={s['n']} / イン敗戦={s['in_fail']} ({s['fail_rate']*100:.2f}%) / "
        f"敗戦時①残={s['remain']} ({s['remain_rate_fail']*100:.2f}%) / "
        f"飛={s['fly']} ({s['fly_rate_fail']*100:.2f}%)"
    )
    return s


def print_band_table(title, rows, key, bands, label):
    fail = [r for r in rows if r["in_failed"]]
    print(f"\n【{title}: {label}】")
    print("帯             敗戦R   ①残R   飛R   敗戦時①残率   敗戦時飛率")
    print("-" * 70)
    out = {}
    for _lo, _hi, band_label in bands:
        part = [r for r in fail if band(r[key], bands) == band_label]
        n = len(part)
        remain = sum(1 for r in part if r["in_remain"])
        fly = sum(1 for r in part if r["in_fly"])
        rr = remain / n if n else 0.0
        fr = fly / n if n else 0.0
        out[band_label] = (n, rr, fr)
        if n == 0:
            print(f"{band_label:<12} {0:>5d}      -     -          -            -")
        else:
            print(
                f"{band_label:<12} {n:>5d}  {remain:>5d} {fly:>5d}"
                f"      {rr*100:>7.2f}%      {fr*100:>7.2f}%"
            )
    return out


def print_rank_table(title, rows, key, label):
    fail = [r for r in rows if r["in_failed"]]
    print(f"\n【{title}: {label}】")
    print("順位           敗戦R   ①残R   飛R   敗戦時①残率   敗戦時飛率")
    print("-" * 70)
    out = {}
    for g in ("1", "2", "3", "4+"):
        part = [r for r in fail if r[key] == g]
        n = len(part)
        remain = sum(1 for r in part if r["in_remain"])
        fly = sum(1 for r in part if r["in_fly"])
        rr = remain / n if n else 0.0
        fr = fly / n if n else 0.0
        out[g] = (n, rr, fr)
        if n == 0:
            print(f"{g:<12} {0:>5d}      -     -          -            -")
        else:
            print(
                f"{g:<12} {n:>5d}  {remain:>5d} {fly:>5d}"
                f"      {rr*100:>7.2f}%      {fr*100:>7.2f}%"
            )
    return out


def build_all(p1_csv, p2_csv, p3_csv):
    train_data = step3.build_common_records(p1_csv, p2_csv)
    future_data = step3.build_common_records(p2_csv, p3_csv)

    p1_records = train_data["records"]["P1"]
    p2_records = train_data["records"]["P2"]
    p3_records = future_data["records"]["P2"]

    boats_map = b2.load_boats(p1_csv, p2_csv, p3_csv)

    p1_rows, p1_skip = build_rows(p1_records, boats_map)
    p2_rows, p2_skip = build_rows(p2_records, boats_map)
    p3_rows, p3_skip = build_rows(p3_records, boats_map)

    return {
        "train": p1_rows + p2_rows,
        "p3": p3_rows,
        "p1": p1_rows,
        "p2": p2_rows,
        "train_start": train_data["p1_start"],
        "train_end": train_data["p2_end"],
        "p3_start": future_data["p2_start"],
        "p3_end": future_data["p2_end"],
        "skip": {"P1": p1_skip, "P2": p2_skip, "P3": p3_skip},
    }


def main():
    if len(sys.argv) != 4:
        print("Usage: python3 analysis/upset_in_remaining_validate.py P1_BOATS_CSV P2_BOATS_CSV P3_BOATS_CSV")
        sys.exit(1)

    p1_csv, p2_csv, p3_csv = sys.argv[1], sys.argv[2], sys.argv[3]

    print("穴警戒HIGH内で、イン敗戦時の『①残り / 飛び』構造を検証中...")
    data = build_all(p1_csv, p2_csv, p3_csv)

    print("=" * 108)
    print("穴目予想：イン敗戦時の①残り構造 TRAIN / 完全未来検証")
    print("=" * 108)
    print(f"TRAIN : {data['train_start']} ～ {data['train_end']}")
    print(f"P3    : {data['p3_start']} ～ {data['p3_end']} 完全未来")
    print("HIGH  : AI本命=1C / CURRENT本命!=1C / イン補正後1着率<50%（固定）")
    print("①残り: HIGHかつイン敗戦時にイン艇が実2・3着")
    print("飛び  : HIGHかつイン敗戦時にイン艇が実4着以下")
    print("本番Web変更: なし")

    print_overall("TRAIN", data["train"])
    print_overall("P3完全未来", data["p3"])

    print_band_table("TRAIN", data["train"], "in_trio_p", TRIO_BANDS, "インAI3連対率帯")
    print_band_table("P3完全未来", data["p3"], "in_trio_p", TRIO_BANDS, "インAI3連対率帯")

    print_band_table("TRAIN", data["train"], "in_win_p", WIN_BANDS, "インAI1着率帯")
    print_band_table("P3完全未来", data["p3"], "in_win_p", WIN_BANDS, "インAI1着率帯")

    print_rank_table("TRAIN", data["train"], "primary_rank", "イン一次順位")
    print_rank_table("P3完全未来", data["p3"], "primary_rank", "イン一次順位")

    print_rank_table("TRAIN", data["train"], "secondary_rank", "イン二次順位")
    print_rank_table("P3完全未来", data["p3"], "secondary_rank", "イン二次順位")

    print("\n【判断ポイント】")
    print("1. 最優先はインAI3連対率帯で①残り率が単調に上がるか")
    print("2. TRAINとP3完全未来で同じ方向に再現するか")
    print("3. 一次/二次順位は補助材料。小標本セルを見て条件を追加しない")
    print("4. この結果から帯境界を動かさず、再現するなら①残り表示候補を固定する")
    print("5. 本命頭の差し替えや自動買い目には接続しない")
    print("=" * 108)


if __name__ == "__main__":
    main()
