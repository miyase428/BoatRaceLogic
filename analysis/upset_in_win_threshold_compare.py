#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
STEP C2-2：イン飛び警報のイン補正後1着率閾値比較

目的
----
C2で固定したイン飛び警報HIGHの条件

    AI本命 = 1C
    CURRENT本命 != 1C
    イン補正後1着率 < 50%

について、最後の閾値だけを 45% / 40% まで厳しくした場合に、
1C敗退率がどの程度上がるか、対象レース数がどの程度減るかを確認する。

比較する累積条件
----------------
- <50%（現行）
- <45%
- <40%

あわせて、閾値差の理由を見るために
<40% / 40-45% / 45-50% の独立帯も表示する。

重要
----
- AI本命=1C、CURRENT本命!=1C は全条件で固定。
- 35%以下は今回比較しない。
- P3はすでに各種検証で参照済みなので、この比較は探索・診断扱い。
- 閾値変更を正式採用する場合は、今後の未使用未来データでも再確認する。
- 本番Web/PredictionLogic/買い目は変更しない。

Usage:
python3 analysis/upset_in_win_threshold_compare.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv \
  analysis/output/final_prediction_boats_20260815_20260820.csv
"""

from __future__ import annotations

import math
import sys
import time
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import upset_alert_rule_validate as c2


THRESHOLDS = (0.50, 0.45, 0.40)
BANDS = (
    (None, 0.40, "<40%"),
    (0.40, 0.45, "40-45%"),
    (0.45, 0.50, "45-50%"),
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


def wilson(success: int, n: int, z: float = 1.959963984540054):
    if n <= 0:
        return 0.0, 0.0
    p = success / n
    z2 = z * z
    den = 1.0 + z2 / n
    center = (p + z2 / (2.0 * n)) / den
    half = z * math.sqrt((p * (1.0 - p) / n) + z2 / (4.0 * n * n)) / den
    return max(0.0, center - half), min(1.0, center + half)


def target_rows(rows):
    return [
        r for r in rows
        if int(r["ai_head"]) == int(r["in_lane"])
        and int(r["current_head"]) != int(r["in_lane"])
    ]


def in_win_p(row):
    return float(row["values"]["in_win_p"])


def is_in_loss(row):
    return int(row["actual"][0]) != int(row["in_lane"])


def summarize(rows):
    n = len(rows)
    loss = sum(1 for r in rows if is_in_loss(r))
    lo, hi = wilson(loss, n)
    return {
        "n": n,
        "loss": loss,
        "loss_rate": loss / n if n else 0.0,
        "ci_lo": lo,
        "ci_hi": hi,
    }


def cumulative_results(rows):
    target = target_rows(rows)
    out = {}
    for th in THRESHOLDS:
        part = [r for r in target if in_win_p(r) < th]
        out[th] = summarize(part)
    return target, out


def band_results(rows):
    target = target_rows(rows)
    out = {}
    for lo, hi, label in BANDS:
        part = []
        for r in target:
            p = in_win_p(r)
            if lo is not None and p < lo:
                continue
            if hi is not None and p >= hi:
                continue
            part.append(r)
        out[label] = summarize(part)
    return out


def print_cumulative(train_rows, p3_rows):
    train_target, tr = cumulative_results(train_rows)
    p3_target, te = cumulative_results(p3_rows)

    tr50 = tr[0.50]
    te50 = te[0.50]

    print("\n【累積閾値比較：AI本命=1C × CURRENT本命!=1C】")
    print(f"対象母集団: TRAIN={len(train_target)}R / P3={len(p3_target)}R")
    print(
        "条件      TRAIN_R  対<50%残存  TRAIN敗退率  95%CI             "
        "P3_R  対<50%残存  P3敗退率  95%CI             TRAIN→P3差"
    )
    print("-" * 142)

    for th in THRESHOLDS:
        a = tr[th]
        b = te[th]
        tr_keep = a["n"] / tr50["n"] if tr50["n"] else 0.0
        te_keep = b["n"] / te50["n"] if te50["n"] else 0.0
        print(
            f"<{int(th*100):>2d}%     {a['n']:>7d}    {tr_keep*100:>7.2f}%      "
            f"{a['loss_rate']*100:>7.2f}%  [{a['ci_lo']*100:>5.1f},{a['ci_hi']*100:>5.1f}]%    "
            f"{b['n']:>5d}    {te_keep*100:>7.2f}%     {b['loss_rate']*100:>7.2f}%  "
            f"[{b['ci_lo']*100:>5.1f},{b['ci_hi']*100:>5.1f}]%      "
            f"{(b['loss_rate']-a['loss_rate'])*100:+7.2f}pt"
        )

    print("\n【<50%から厳しくした時の変化】")
    print("条件      TRAIN敗退率差  TRAIN対象減   P3敗退率差  P3対象減")
    print("-" * 74)
    for th in (0.45, 0.40):
        a = tr[th]
        b = te[th]
        print(
            f"<{int(th*100):>2d}%       {(a['loss_rate']-tr50['loss_rate'])*100:+7.2f}pt    "
            f"{tr50['n']-a['n']:>6d}R      {(b['loss_rate']-te50['loss_rate'])*100:+7.2f}pt    "
            f"{te50['n']-b['n']:>5d}R"
        )

    print("\n【1C敗退レースの拾い残し】")
    print("条件      TRAIN敗退数  <50%比   P3敗退数  <50%比")
    print("-" * 62)
    for th in THRESHOLDS:
        a = tr[th]
        b = te[th]
        tr_cov = a["loss"] / tr50["loss"] if tr50["loss"] else 0.0
        te_cov = b["loss"] / te50["loss"] if te50["loss"] else 0.0
        print(
            f"<{int(th*100):>2d}%       {a['loss']:>7d}    {tr_cov*100:>6.2f}%    "
            f"{b['loss']:>7d}    {te_cov*100:>6.2f}%"
        )

    return tr, te


def print_bands(train_rows, p3_rows):
    tr = band_results(train_rows)
    te = band_results(p3_rows)

    print("\n【独立帯：どの帯でイン飛びが濃いか】")
    print("帯          TRAIN_R  TRAIN敗退率  95%CI             P3_R  P3敗退率  95%CI")
    print("-" * 104)
    for _lo, _hi, label in BANDS:
        a = tr[label]
        b = te[label]
        print(
            f"{label:<10} {a['n']:>7d}    {a['loss_rate']*100:>7.2f}%  "
            f"[{a['ci_lo']*100:>5.1f},{a['ci_hi']*100:>5.1f}]%    "
            f"{b['n']:>5d}    {b['loss_rate']*100:>7.2f}%  "
            f"[{b['ci_lo']*100:>5.1f},{b['ci_hi']*100:>5.1f}]%"
        )


def main():
    if len(sys.argv) != 4:
        print(
            "Usage: python3 analysis/upset_in_win_threshold_compare.py "
            "P1_BOATS_CSV P2_BOATS_CSV P3_BOATS_CSV"
        )
        sys.exit(1)

    p1_csv, p2_csv, p3_csv = sys.argv[1], sys.argv[2], sys.argv[3]
    total_t0 = time.perf_counter()
    print(f"開始時刻 : {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}", flush=True)

    data = stage(
        "C2共通レコード・特徴量構築",
        lambda: c2.build_all_rows(p1_csv, p2_csv, p3_csv),
    )

    print("=" * 144)
    print("STEP C2-2：イン飛び警報 50% / 45% / 40% 閾値比較")
    print("=" * 144)
    print(f"TRAIN : {data['train_start']} ～ {data['train_end']}")
    print(f"P3    : {data['p3_start']} ～ {data['p3_end']}（既参照データのため診断扱い）")
    print("固定条件: AI本命=1C & CURRENT本命!=1C")
    print("比較条件: イン補正後1着率 <50% / <45% / <40%")
    print("35%以下は比較しない / 本番Web・PredictionLogic・買い目変更なし")

    stage("閾値別集計", lambda: print_cumulative(data["train"], data["p3"]))
    print_bands(data["train"], data["p3"])

    print("\n【判断ポイント】")
    print("1. 45%/40%で1C敗退率がTRAINとP3の両方で明確に上がるか")
    print("2. 敗退率上昇に対して対象レース数・1C敗退レースの拾い数を失いすぎないか")
    print("3. <40 / 40-45 / 45-50 の独立帯が、おおむね低1着率ほど高敗退率になっているか")
    print("4. 45%/40%を新しい警報段階に使う場合は、今後の未使用未来データで再確認する")
    print("=" * 144)

    total_dt = time.perf_counter() - total_t0
    print(f"終了時刻 : {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"総所要時間 : {format_elapsed(total_dt)}")


if __name__ == "__main__":
    main()
