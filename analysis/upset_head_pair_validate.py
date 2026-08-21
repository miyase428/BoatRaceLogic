#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
STEP E1：穴目本命・穴目対抗候補の比較検証

目的
----
配当帯を先に当てに行くのではなく、C2固定の穴警戒HIGHに対して
「穴目として狙うならこの2艇」という表示を作るための検証。

穴目本命はC3/C5/C6で固定した TRIO_OUTER（イン以外でAI3連対率1位）。
穴目対抗は、穴目本命と異なる艇を各指標から1艇選び、
穴目本命 + 穴目対抗の2艇で1C敗退時の実頭をどこまで拾えるか比較する。

対抗候補
--------
TRIO2              : AI3連対率の外艇2位
CURRENT_OR_TRIO2    : 現行本命が穴本命と異なれば現行本命、同じならTRIO2
WIN_ALT             : 補正後1着率で穴本命を除いた外艇最上位
OUTCOME_ALT         : STEP3頭確率で穴本命を除いた外艇最上位
PRIMARY_ALT         : 一次評価で穴本命を除いた外艇最上位
SECONDARY_ALT       : 二次評価で穴本命を除いた外艇最上位
FINAL3_ALT          : final3で穴本命を除いた外艇最上位

評価
----
- 穴本命単独の1C敗退時頭捕捉
- 穴本命+対抗の2艇頭捕捉
- 対抗追加による純増
- TRAINで最良だった対抗をP3へ固定
- STRONG / MIDDLE / WEAK別でも補助的に確認

重要
----
- 配当帯は使わない。
- 本番Web/PredictionLogic/買い目は変更しない。
- P3を見て候補方式を再調整しない。

Usage:
python3 analysis/upset_head_pair_validate.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv \
  analysis/output/final_prediction_boats_20260815_20260820.csv
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
import upset_head_confidence_tier_validate as tier_mod


METHODS = (
    "TRIO2",
    "CURRENT_OR_TRIO2",
    "WIN_ALT",
    "OUTCOME_ALT",
    "PRIMARY_ALT",
    "SECONDARY_ALT",
    "FINAL3_ALT",
)


def format_elapsed(seconds: float) -> str:
    sec = max(0, int(round(seconds)))
    m, s = divmod(sec, 60)
    h, m = divmod(m, 60)
    if h:
        return f"{h}時間{m}分{s}秒"
    if m:
        return f"{m}分{s}秒"
    return f"{s}秒"


def stage(label: str, fn):
    print(f"{label}...", flush=True)
    t0 = time.perf_counter()
    result = fn()
    dt = time.perf_counter() - t0
    print(f"{label} 完了 ({format_elapsed(dt)})", flush=True)
    return result


def ranked(values, eligible):
    return sorted(
        eligible,
        key=lambda lane: (-float(values.get(lane, 0.0)), int(lane)),
    )


def first_distinct(order, honmei):
    for lane in order:
        lane = int(lane)
        if lane != int(honmei):
            return lane
    return 0


def build_row(record, boats, payout):
    if boats is None or set(boats) != set(range(1, 7)):
        return None
    if payout is None or int(payout) <= 0:
        return None

    f = c1.make_features(record, boats)
    if f is None:
        return None

    current = int(f["current_head"])
    ai_head = int(f["ai_head"])
    in_lane = int(f["in_lane"])
    in_win_p = float(f["values"]["in_win_p"])

    # C2固定HIGH条件。
    if not (ai_head == in_lane and current != in_lane and in_win_p < 0.50):
        return None

    actual = b4.actual_trifecta(boats)
    if actual is None:
        return None
    actual_first = int(actual[0])

    eligible = [lane for lane in range(1, 7) if lane != in_lane]

    trio_order = ranked(f["trio"], eligible)
    if len(trio_order) < 2:
        return None

    honmei = int(trio_order[0])
    trio2 = int(trio_order[1])

    primary = {lane: float(boats[lane].get("first_score", 0.0)) for lane in eligible}
    secondary = {lane: float(boats[lane].get("second_score", 0.0)) for lane in eligible}
    final3 = {lane: float(boats[lane].get("final3", 0.0)) for lane in eligible}

    opponents = {
        "TRIO2": trio2,
        "CURRENT_OR_TRIO2": current if current != honmei else trio2,
        "WIN_ALT": first_distinct(ranked(f["win"], eligible), honmei),
        "OUTCOME_ALT": first_distinct(ranked(f["outcome_head"], eligible), honmei),
        "PRIMARY_ALT": first_distinct(ranked(primary, eligible), honmei),
        "SECONDARY_ALT": first_distinct(ranked(secondary, eligible), honmei),
        "FINAL3_ALT": first_distinct(ranked(final3, eligible), honmei),
    }

    # tier_mod.tier() が要求する最低限のキーを合わせる。
    tier_row = {
        "trio": f["trio"],
        "trio1": honmei,
        "trio2": trio2,
        "current": current,
    }

    return {
        "race_code": str(record["race_code"]),
        "actual_first": actual_first,
        "in_lane": in_lane,
        "in_failed": actual_first != in_lane,
        "honmei": honmei,
        "opponents": opponents,
        "tier": tier_mod.tier(tier_row),
    }


def build_rows(records, boats_map, payouts):
    rows = []
    skip = defaultdict(int)
    for record in records:
        code = str(record["race_code"])
        row = build_row(record, boats_map.get(code), payouts.get(code))
        if row is None:
            skip["not_ready_or_not_high"] += 1
            continue
        rows.append(row)
        skip["ready_high"] += 1
    return rows, skip


def evaluate(rows, method):
    fail = [r for r in rows if r["in_failed"]]
    n = len(fail)
    honmei_hit = 0
    opponent_hit = 0
    pair_hit = 0
    added = 0

    for r in fail:
        actual = int(r["actual_first"])
        honmei = int(r["honmei"])
        opponent = int(r["opponents"][method])

        h = actual == honmei
        o = actual == opponent
        p = h or o

        honmei_hit += 1 if h else 0
        opponent_hit += 1 if o else 0
        pair_hit += 1 if p else 0
        added += 1 if (o and not h) else 0

    return {
        "n": n,
        "honmei": honmei_hit / n if n else 0.0,
        "opponent": opponent_hit / n if n else 0.0,
        "pair": pair_hit / n if n else 0.0,
        "added": added / n if n else 0.0,
        "added_n": added,
    }


def print_table(title, rows):
    print(f"\n【{title}】")
    fail_n = sum(1 for r in rows if r["in_failed"])
    print(f"HIGH={len(rows)}R / 1C敗退={fail_n}R")
    print("対抗方式               穴本命単独   対抗単独   2艇頭捕捉   純増")
    print("-" * 76)
    out = {}
    for method in METHODS:
        m = evaluate(rows, method)
        out[method] = m
        print(
            f"{method:<22} {m['honmei']*100:>7.2f}%    {m['opponent']*100:>7.2f}%    "
            f"{m['pair']*100:>7.2f}%   +{m['added']*100:>6.2f}pt"
        )
    return out


def select_train(results):
    # 2艇頭捕捉→純増→対抗単独の順。
    return sorted(
        METHODS,
        key=lambda m: (-results[m]["pair"], -results[m]["added"], -results[m]["opponent"], m),
    )[0]


def print_tiers(title, rows, method):
    print(f"\n【{title}：信頼度別 / 対抗={method}】")
    print("信頼度    1C敗退R   穴本命単独   2艇頭捕捉   純増")
    print("-" * 66)
    for tier in ("STRONG", "MIDDLE", "WEAK"):
        part = [r for r in rows if r["tier"] == tier]
        m = evaluate(part, method)
        print(
            f"{tier:<8} {m['n']:>8d}    {m['honmei']*100:>7.2f}%    "
            f"{m['pair']*100:>7.2f}%   +{m['added']*100:>6.2f}pt"
        )


def main():
    if len(sys.argv) != 4:
        print("Usage: python3 analysis/upset_head_pair_validate.py P1_BOATS_CSV P2_BOATS_CSV P3_BOATS_CSV")
        sys.exit(1)

    p1_csv, p2_csv, p3_csv = sys.argv[1], sys.argv[2], sys.argv[3]
    total_t0 = time.perf_counter()
    print(f"開始時刻 : {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}", flush=True)

    train_data, future_data = stage(
        "共通レコード構築",
        lambda: (
            step3.build_common_records(p1_csv, p2_csv),
            step3.build_common_records(p2_csv, p3_csv),
        ),
    )

    def load_inputs():
        boats_map = b2.load_boats(p1_csv, p2_csv, p3_csv)
        payouts = b4.load_payouts(train_data["p1_start"], future_data["p2_end"])
        return boats_map, payouts

    boats_map, payouts = stage("CSV・払戻読込", load_inputs)

    p1_records = train_data["records"]["P1"]
    p2_records = train_data["records"]["P2"]
    p3_records = future_data["records"]["P2"]

    def make_rows():
        p1_rows, _ = build_rows(p1_records, boats_map, payouts)
        p2_rows, _ = build_rows(p2_records, boats_map, payouts)
        p3_rows, _ = build_rows(p3_records, boats_map, payouts)
        return p1_rows + p2_rows, p3_rows

    train_rows, p3_rows = stage("穴警戒HIGH行を構築", make_rows)

    print("=" * 126)
    print("STEP E1：穴目本命・穴目対抗候補の比較検証")
    print("=" * 126)
    print(f"TRAIN : {train_data['p1_start']} ～ {train_data['p2_end']}")
    print(f"P3    : {future_data['p2_start']} ～ {future_data['p2_end']} 完全未来")
    print(f"HIGH対象: TRAIN={len(train_rows)}R / P3={len(p3_rows)}R")
    print("穴目本命: TRIO_OUTER固定 / 配当帯は使わない")
    print("本番Web/PredictionLogic変更: なし")

    train = print_table("TRAIN", train_rows)
    p3 = print_table("P3完全未来", p3_rows)

    selected = select_train(train)
    print("\n【TRAINで選んだ対抗方式をP3へ固定】")
    print(f"選択方式: {selected}")
    print(f"TRAIN 2艇頭捕捉: {train[selected]['pair']*100:.2f}% / 純増 +{train[selected]['added']*100:.2f}pt")
    print(f"P3    2艇頭捕捉: {p3[selected]['pair']*100:.2f}% / 純増 +{p3[selected]['added']*100:.2f}pt")

    print_tiers("TRAIN", train_rows, selected)
    print_tiers("P3完全未来", p3_rows, selected)

    print("\n【判断方針】")
    print("1. 配当帯ではなく『穴目本命+穴目対抗』の2艇頭候補として評価する")
    print("2. 最重要は1C敗退時の2艇頭捕捉と、対抗追加の純増がTRAIN→P3で再現するか")
    print("3. STRONGは穴本命単独でも強いため、対抗追加の必要性を信頼度別に見る")
    print("4. MIDDLE/WEAKで対抗追加の純増が大きければ、Web表示に特に意味がある")
    print("5. 本番表示は検証後。買い目ロジックには接続しない")
    print("=" * 126)

    total_dt = time.perf_counter() - total_t0
    print(f"終了時刻 : {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"総所要時間 : {format_elapsed(total_dt)}")


if __name__ == "__main__":
    main()
