#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
イン1着時の2連単分布を、場平均ベースと最終AI出目モデルで比較する。

目的
----
Webのメイン表示候補として、1コース頭を前提にした2着候補(2C～6C)を
5通り100%で表示する価値があるか確認する。

比較
----
VENUE_BASE
    STEP1で採用した VENUE_K3000 の120通り出目確率から、
    1C頭の組だけを抽出し、2着コース別に合算して100%正規化する。
    これは「場×コース出目履歴を平滑化した場平均」に相当する。

AI_FINAL
    STEP3の最終出目モデル
      VENUE_K3000
      + 補正後1着率 alpha=1.00
      + AI3連対率 beta=1.25
      + 2/3着順序補正 delta=0.25, gamma=0.25
    から同様に1C頭だけを条件付けし、2着コース別へ集約する。

評価母集団
----------
実際の1着コースが1Cだったレースのみ。
※ここでは公式決まり手の「逃げ」判定ではなく、まず「1Cが1着」を条件とする。
  Web表示名は検証後に「1C頭時」または「イン逃げ想定」を決める。

評価指標
--------
- 5クラス LogLoss
- 5クラス Brier
- 正解2着へ割り当てた平均確率
- Top1 / Top2 / Top3 的中率
- 正解2着の平均順位

Usage
-----
python3 analysis/head1_exacta_probability_validate.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv
"""

from __future__ import annotations

import math
import statistics
import sys
from dataclasses import dataclass, field
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import base_trifecta_probability_compare as base_outcome
import trifecta_probability_order_compare as step3


FINAL_DELTA = 0.25
FINAL_GAMMA = 0.25
SECOND_COURSES = (2, 3, 4, 5, 6)
EPS = 1e-15


@dataclass
class FiveClassMetrics:
    races: int = 0
    logloss_sum: float = 0.0
    brier_sum: float = 0.0
    actual_prob_sum: float = 0.0
    top_hits: dict = field(default_factory=lambda: {1: 0, 2: 0, 3: 0})
    ranks: list = field(default_factory=list)
    prob_sum_error_max: float = 0.0

    def add(self, probs_by_second, actual_second):
        probs = [float(probs_by_second[c]) for c in SECOND_COURSES]
        total = sum(probs)
        self.prob_sum_error_max = max(self.prob_sum_error_max, abs(total - 1.0))

        actual_idx = SECOND_COURSES.index(int(actual_second))
        p_actual = min(max(probs[actual_idx], EPS), 1.0)
        self.logloss_sum += -math.log(p_actual)
        self.actual_prob_sum += probs[actual_idx]

        brier = 0.0
        for i, p in enumerate(probs):
            y = 1.0 if i == actual_idx else 0.0
            brier += (p - y) ** 2
        self.brier_sum += brier

        ordered = sorted(
            SECOND_COURSES,
            key=lambda c: (-float(probs_by_second[c]), c),
        )
        rank = ordered.index(int(actual_second)) + 1
        self.ranks.append(rank)
        for n in (1, 2, 3):
            if rank <= n:
                self.top_hits[n] += 1

        self.races += 1

    def summary(self):
        if self.races <= 0:
            return None
        return {
            "races": self.races,
            "logloss": self.logloss_sum / self.races,
            "brier": self.brier_sum / self.races,
            "actual_prob": self.actual_prob_sum / self.races,
            "top1": self.top_hits[1] / self.races,
            "top2": self.top_hits[2] / self.races,
            "top3": self.top_hits[3] / self.races,
            "mean_rank": sum(self.ranks) / len(self.ranks),
            "median_rank": statistics.median(self.ranks),
            "max_sum_error": self.prob_sum_error_max,
        }


def conditional_second_distribution(probs):
    """120通りから P(second_course | first_course=1) を作る。"""
    out = {c: 0.0 for c in SECOND_COURSES}
    head1_mass = 0.0

    for idx, pattern in enumerate(base_outcome.PATTERNS):
        head, second, _third = pattern
        if head != 1:
            continue
        p = float(probs[idx])
        head1_mass += p
        if second in out:
            out[second] += p

    if head1_mass <= 0:
        return None, 0.0

    for c in SECOND_COURSES:
        out[c] /= head1_mass

    # 浮動小数だけ整える。
    total = sum(out.values())
    if total <= 0:
        return None, head1_mass
    for c in SECOND_COURSES:
        out[c] /= total

    return out, head1_mass


def evaluate_period(records):
    base_metric = FiveClassMetrics()
    ai_metric = FiveClassMetrics()
    actual_counts = {c: 0 for c in SECOND_COURSES}
    base_head1_mass = []
    ai_head1_mass = []
    skipped = 0

    for record in records:
        actual_pattern = base_outcome.PATTERNS[int(record["actual_idx"])]
        actual_head, actual_second, _ = actual_pattern
        if actual_head != 1:
            continue
        if actual_second not in SECOND_COURSES:
            skipped += 1
            continue

        base_probs = list(record["probs"])
        ai_probs = step3.order_adjusted_probs(record, FINAL_DELTA, FINAL_GAMMA)

        base_dist, base_mass = conditional_second_distribution(base_probs)
        ai_dist, ai_mass = conditional_second_distribution(ai_probs)
        if base_dist is None or ai_dist is None:
            skipped += 1
            continue

        base_metric.add(base_dist, actual_second)
        ai_metric.add(ai_dist, actual_second)
        actual_counts[actual_second] += 1
        base_head1_mass.append(base_mass)
        ai_head1_mass.append(ai_mass)

    return {
        "base": base_metric,
        "ai": ai_metric,
        "actual_counts": actual_counts,
        "skipped": skipped,
        "base_head1_mass": sum(base_head1_mass) / len(base_head1_mass) if base_head1_mass else 0.0,
        "ai_head1_mass": sum(ai_head1_mass) / len(ai_head1_mass) if ai_head1_mass else 0.0,
    }


def print_table(title, result):
    print(f"\n【{title}】")
    print(
        "方式          R数   LogLoss   Brier5   正解平均P   Top1    Top2    Top3   平均順位  中央順位"
    )
    print("-" * 110)
    for name, metric in (("VENUE_BASE", result["base"]), ("AI_FINAL", result["ai"])):
        s = metric.summary()
        if s is None:
            continue
        print(
            f"{name:<12} {s['races']:>5d}  {s['logloss']:.6f}  {s['brier']:.6f}  "
            f"{s['actual_prob']*100:>8.3f}%  {s['top1']*100:>6.2f}%  "
            f"{s['top2']*100:>6.2f}%  {s['top3']*100:>6.2f}%  "
            f"{s['mean_rank']:>7.2f}  {s['median_rank']:>7.1f}"
        )

    total = sum(result["actual_counts"].values())
    print("\n実際の1C頭時 2着分布:")
    parts = []
    for c in SECOND_COURSES:
        n = result["actual_counts"][c]
        pct = n / total * 100.0 if total else 0.0
        parts.append(f"{c}C={pct:.1f}%({n})")
    print(" / ".join(parts))
    print(
        f"平均P(1C頭) : VENUE_BASE={result['base_head1_mass']*100:.2f}%"
        f" / AI_FINAL={result['ai_head1_mass']*100:.2f}%"
    )


def print_delta(base_metric, ai_metric):
    b = base_metric.summary()
    a = ai_metric.summary()
    print(f"LogLoss差       : {a['logloss'] - b['logloss']:+.6f} （マイナスが改善）")
    print(f"Brier5差        : {a['brier'] - b['brier']:+.6f} （マイナスが改善）")
    print(f"正解平均P差     : {(a['actual_prob'] - b['actual_prob'])*100:+.3f}pt")
    print(f"Top1差          : {(a['top1'] - b['top1'])*100:+.2f}pt")
    print(f"Top2差          : {(a['top2'] - b['top2'])*100:+.2f}pt")
    print(f"Top3差          : {(a['top3'] - b['top3'])*100:+.2f}pt")
    print(f"平均順位差      : {a['mean_rank'] - b['mean_rank']:+.2f} （マイナスが改善）")
    print(f"5通り合計誤差max: {a['max_sum_error']:.3e}")


def main():
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/head1_exacta_probability_validate.py "
            "P1_BOATS_CSV P2_BOATS_CSV"
        )
        sys.exit(1)

    p1_csv, p2_csv = sys.argv[1], sys.argv[2]
    print("最終出目モデルを再構築し、1C頭時の2着5クラスへ集約中...")

    data = step3.build_common_records(p1_csv, p2_csv)
    records = data["records"]
    if not records["P1"] or not records["P2"]:
        raise RuntimeError("P1/P2共通評価レースがありません")

    p1 = evaluate_period(records["P1"])
    p2 = evaluate_period(records["P2"])

    print("=" * 126)
    print("イン1着時 2連単分布：場平均ベース vs AI最終出目モデル")
    print("=" * 126)
    print(f"P1                  : {data['p1_start']} ～ {data['p1_end']}")
    print(f"P2完全ホールドアウト: {data['p2_start']} ～ {data['p2_end']}")
    print("評価条件             : 実際の1着コース=1C")
    print("場平均               : VENUE_K3000を1C頭条件で2着別へ集約")
    print("AI                   : STEP3最終モデルを1C頭条件で2着別へ集約")
    print("5通り                : 1C-2C / 1C-3C / 1C-4C / 1C-5C / 1C-6C = 100%")
    print("注意                 : 今回は公式決まり手『逃げ』ではなく1Cが1着したレースで評価")
    print("本番Web変更          : なし")

    print_table("P1 参考", p1)
    print_table("P2 ホールドアウト（最重要）", p2)

    print("\n【最重要: P2 VENUE_BASE → AI_FINAL】")
    print_delta(p2["base"], p2["ai"])

    print("\n【判断方針】")
    print("1. P2でLogLoss/Brier5が両方改善するか")
    print("2. Top1/Top2と正解平均Pが改善するか")
    print("3. 改善が確認できればWebメインに『場平均 vs AI』の5通りを表示する")
    print("4. 現在の120通り出目表は削除せず、参考情報側へ残す")
    print("5. 必要なら次段階で公式決まり手『逃げ』だけへ限定した差も確認する")
    print("=" * 126)


if __name__ == "__main__":
    main()
