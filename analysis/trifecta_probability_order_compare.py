#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
出目確率 STEP 3：STEP2の上位3艇構成を保ったまま、2着/3着の順序だけ精密化する。

目的
----
STEP2で採用候補となった
  VENUE_K3000
  + 補正後1着率 alpha=1.00
  + AI3連対率 beta=1.25
を固定し、2着と3着を同じ重みで扱っている部分だけを検証する。

重要な制約
----------
順序補正は、同じ1着艇・同じ2/3着候補の2パターン、例えば
  1-2-6
  1-6-2
の「合計確率」を必ず維持する。

したがって、
- 1着艇の確率
- 上位3艇の組み合わせ確率
はSTEP2から変えず、純粋に2着/3着の順番だけを動かす。

順序補正
--------
STEP2確率を p_step2 とする。
同じhead・同じ2/3着候補の2パターンについて、

  log odds(new)
    = log odds(step2)
      + delta * log(trio_ratio(second) / trio_ratio(third))
      + gamma * log(win_ratio(second) / win_ratio(third))

とする。

- delta > 0 : AI3連対率の上振れが大きい艇を2着寄りにする
- gamma > 0 : 補正後1着率の上振れが大きい艇を2着寄りにする

ペア合計確率はSTEP2と同じままなので、STEP2で得た
「誰が頭か / 誰が3連対するか」の改善を壊しにくい。

比較
----
STEP2_FIXED
TRIO_ORDER
WIN_ORDER
TRIO_PLUS_WIN_ORDER

P1だけでdelta/gammaを選択し、P2は完全ホールドアウト。

追加評価
--------
通常の120クラス指標に加え、実際の2着/3着を入れ替えた2候補だけで
条件付き評価する。

- 2-3順序LogLoss
- 実順序へ割り当てた条件付き平均確率
- 2-3順序正解率

Usage
-----
python3 analysis/trifecta_probability_order_compare.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv
"""

from __future__ import annotations

import math
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import base_trifecta_probability_compare as base_outcome
import trifecta_probability_ai_compare as step2


STEP2_ALPHA = 1.00
STEP2_BETA = 1.25
ORDER_GRID = (-1.00, -0.75, -0.50, -0.25, 0.00, 0.25, 0.50, 0.75, 1.00)
EPS = 1e-15


def step2_probs(record):
    return step2.adjusted_probs(record, STEP2_ALPHA, STEP2_BETA)


def order_adjusted_probs(record, delta, gamma):
    """
    STEP2確率から2着/3着の順序だけ調整する。

    (head, second, third) と (head, third, second) の合計確率を保持し、
    2候補間の条件付きoddsだけを更新する。
    """
    base = step2_probs(record)
    out = list(base)

    for idx, pattern in enumerate(base_outcome.PATTERNS):
        head_c, second_c, third_c = pattern
        swapped = (head_c, third_c, second_c)
        swap_idx = base_outcome.PATTERN_INDEX[swapped]
        if idx >= swap_idx:
            continue

        pair_total = float(base[idx]) + float(base[swap_idx])
        if pair_total <= 0:
            continue

        _, second_lane, third_lane = record["pattern_lanes"][idx]

        signal = (
            float(delta)
            * (
                float(record["log_trio_ratio"][second_lane])
                - float(record["log_trio_ratio"][third_lane])
            )
            + float(gamma)
            * (
                float(record["log_win_ratio"][second_lane])
                - float(record["log_win_ratio"][third_lane])
            )
        )

        # pair odds全体に exp(signal) を掛けるため、2候補へ ±signal/2 を配る。
        l1 = math.log(max(float(base[idx]), EPS)) + 0.5 * signal
        l2 = math.log(max(float(base[swap_idx]), EPS)) - 0.5 * signal
        mx = max(l1, l2)
        w1 = math.exp(l1 - mx)
        w2 = math.exp(l2 - mx)
        den = w1 + w2
        if den <= 0:
            continue

        out[idx] = pair_total * w1 / den
        out[swap_idx] = pair_total * w2 / den

    return out


def order_metrics(records, delta, gamma):
    logloss = 0.0
    cond_prob_sum = 0.0
    correct = 0.0
    n = 0
    pair_mass_error_max = 0.0

    for record in records:
        base = step2_probs(record)
        probs = order_adjusted_probs(record, delta, gamma)

        actual_idx = int(record["actual_idx"])
        a, b, c = base_outcome.PATTERNS[actual_idx]
        swap_idx = base_outcome.PATTERN_INDEX[(a, c, b)]

        den = float(probs[actual_idx]) + float(probs[swap_idx])
        if den <= 0:
            continue
        p = float(probs[actual_idx]) / den
        cp = min(max(p, EPS), 1.0)
        logloss += -math.log(cp)
        cond_prob_sum += p
        if p > 0.5:
            correct += 1.0
        elif abs(p - 0.5) <= 1e-15:
            correct += 0.5
        n += 1

        # 全ペアでSTEP2のペア合計が保持されていることを確認。
        for idx, pattern in enumerate(base_outcome.PATTERNS):
            x, y, z = pattern
            swapped = (x, z, y)
            si = base_outcome.PATTERN_INDEX[swapped]
            if idx >= si:
                continue
            before = float(base[idx]) + float(base[si])
            after = float(probs[idx]) + float(probs[si])
            pair_mass_error_max = max(pair_mass_error_max, abs(after - before))

    if n <= 0:
        return None
    return {
        "races": n,
        "logloss": logloss / n,
        "avg_actual_cond_prob": cond_prob_sum / n,
        "accuracy": correct / n,
        "pair_mass_error_max": pair_mass_error_max,
    }


def evaluate(records, delta, gamma):
    metric = base_outcome.Metrics()
    for record in records:
        metric.add(
            order_adjusted_probs(record, delta, gamma),
            int(record["actual_idx"]),
        )
    return metric, order_metrics(records, delta, gamma)


def tune(records):
    table = []
    for delta in ORDER_GRID:
        for gamma in ORDER_GRID:
            metric, om = evaluate(records, delta, gamma)
            s = metric.summary()
            key = (
                s["logloss"],
                om["logloss"],
                s["brier"],
                abs(delta) + abs(gamma),
                delta,
                gamma,
            )
            table.append((key, delta, gamma, metric, om))

    best_all = min(table, key=lambda x: x[0])
    best_trio = min((x for x in table if x[2] == 0.0), key=lambda x: x[0])
    best_win = min((x for x in table if x[1] == 0.0), key=lambda x: x[0])

    return {
        "STEP2_FIXED": (0.0, 0.0),
        "TRIO_ORDER": (best_trio[1], best_trio[2]),
        "WIN_ORDER": (best_win[1], best_win[2]),
        "TRIO_PLUS_WIN_ORDER": (best_all[1], best_all[2]),
    }


def print_metrics(title, rows):
    print(f"\n【{title}】")
    print(
        "方式                    delta gamma   R数   LogLoss   Brier120  正解平均P  "
        "Top1   Top3   Top5   Top10  Top20  平均順位  2-3順序LL  順序P  順序正解"
    )
    print("-" * 174)
    for name, delta, gamma, metric, om in rows:
        s = metric.summary()
        print(
            f"{name:<25} {delta:>5.2f} {gamma:>5.2f}  {s['races']:>5d}  "
            f"{s['logloss']:.6f}  {s['brier']:.6f}  {s['actual_prob']*100:>7.3f}%  "
            f"{s['top'][1]*100:>5.2f}%  {s['top'][3]*100:>5.2f}%  "
            f"{s['top'][5]*100:>5.2f}%  {s['top'][10]*100:>5.2f}%  "
            f"{s['top'][20]*100:>5.2f}%  {s['mean_rank']:>7.2f}  "
            f"{om['logloss']:.6f}  {om['avg_actual_cond_prob']*100:>6.2f}%  "
            f"{om['accuracy']*100:>6.2f}%"
        )


def print_delta(base_metric, base_order, new_metric, new_order):
    b = base_metric.summary()
    n = new_metric.summary()
    print(f"LogLoss差           : {n['logloss'] - b['logloss']:+.6f} （マイナスが改善）")
    print(f"Brier120差          : {n['brier'] - b['brier']:+.6f} （マイナスが改善）")
    print(f"正解平均P差         : {(n['actual_prob'] - b['actual_prob'])*100:+.3f}pt")
    for topn in base_outcome.TOP_NS:
        print(
            f"Top{topn:<2}的中率差       : "
            f"{(n['top'][topn] - b['top'][topn])*100:+.2f}pt"
        )
    print(f"平均順位差           : {n['mean_rank'] - b['mean_rank']:+.2f} （マイナスが改善）")
    print(f"2-3順序LogLoss差    : {new_order['logloss'] - base_order['logloss']:+.6f}")
    print(
        f"実順序条件付きP差   : "
        f"{(new_order['avg_actual_cond_prob'] - base_order['avg_actual_cond_prob'])*100:+.2f}pt"
    )
    print(
        f"2-3順序正解率差     : "
        f"{(new_order['accuracy'] - base_order['accuracy'])*100:+.2f}pt"
    )
    print(f"ペア合計確率誤差max : {new_order['pair_mass_error_max']:.3e}")
    print(f"120通り合計誤差max  : {n['max_sum_error']:.3e}")


def build_common_records(p1_csv, p2_csv):
    p1_meta = step2.trio_secondary.load_feature_csv(p1_csv)
    p2_meta = step2.trio_secondary.load_feature_csv(p2_csv)
    p1_start, p1_end = p1_meta["start"], p1_meta["end"]
    p2_start, p2_end = p2_meta["start"], p2_meta["end"]

    base_map, base_stats = step2.load_base_outcomes(
        p1_start, p1_end, p2_start, p2_end
    )
    win_map, win_stats, win_extra = step2.load_corrected_win_map(
        p1_start, p1_end, p2_start, p2_end
    )
    trio_map, _, _, trio_course_source, trio_snap_stats, trio_join = step2.load_ai_trio_map(
        p1_csv, p2_csv, p1_start, p2_end
    )
    records, miss = step2.build_records(base_map, win_map, trio_map)

    return {
        "records": records,
        "p1_start": p1_start,
        "p1_end": p1_end,
        "p2_start": p2_start,
        "p2_end": p2_end,
        "base_stats": base_stats,
        "win_stats": win_stats,
        "win_extra": win_extra,
        "trio_course_source": trio_course_source,
        "trio_snap_stats": trio_snap_stats,
        "trio_join": trio_join,
        "miss": miss,
    }


def main():
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/trifecta_probability_order_compare.py "
            "P1_BOATS_CSV P2_BOATS_CSV"
        )
        sys.exit(1)

    p1_csv, p2_csv = sys.argv[1], sys.argv[2]
    print("STEP2共通データを再構築し、2着/3着の順序だけを検証中...")

    data = build_common_records(p1_csv, p2_csv)
    records = data["records"]
    if not records["P1"] or not records["P2"]:
        raise RuntimeError("P1/P2共通評価レースがありません")

    selected = tune(records["P1"])

    p1_rows = []
    p2_rows = []
    for name in ("STEP2_FIXED", "TRIO_ORDER", "WIN_ORDER", "TRIO_PLUS_WIN_ORDER"):
        delta, gamma = selected[name]
        p1_metric, p1_order = evaluate(records["P1"], delta, gamma)
        p2_metric, p2_order = evaluate(records["P2"], delta, gamma)
        p1_rows.append((name, delta, gamma, p1_metric, p1_order))
        p2_rows.append((name, delta, gamma, p2_metric, p2_order))

    print("=" * 166)
    print("出目確率 STEP 3：2着/3着 順序条件付き補正")
    print("=" * 166)
    print(f"P1                  : {data['p1_start']} ～ {data['p1_end']}")
    print(f"P2完全ホールドアウト: {data['p2_start']} ～ {data['p2_end']}")
    print("STEP2固定            : VENUE_K3000 + win alpha=1.00 + trio beta=1.25")
    print("順序補正             : 同一head・同一2/3着候補のペア合計確率を維持")
    print("delta                : trio_ratio(second/third) の条件付きodds指数")
    print("gamma                : win_ratio(second/third) の条件付きodds指数")
    print(f"候補                  : {', '.join(f'{x:+.2f}' for x in ORDER_GRID)}")
    print("選択                  : P1 Multiclass LogLoss優先 / P2再調整なし")
    print("本番Web変更           : なし")

    print("\n【共通評価母集団】")
    print(
        f"P1={len(records['P1'])}R / P2={len(records['P2'])}R"
        f" / P1 win不足={data['miss'].get('P1_win_missing', 0)}R"
        f" / P2 win不足={data['miss'].get('P2_win_missing', 0)}R"
    )

    print("\n【P1で選択した順序パラメータ】")
    for name in ("TRIO_ORDER", "WIN_ORDER", "TRIO_PLUS_WIN_ORDER"):
        d, g = selected[name]
        print(f"{name:<25}: delta={d:+.2f} / gamma={g:+.2f}")

    print_metrics("P1 方式選択用", p1_rows)
    print_metrics("P2 ホールドアウト（最重要）", p2_rows)

    p2_dict = {
        name: (metric, om)
        for name, d, g, metric, om in p2_rows
    }
    base_metric, base_order = p2_dict["STEP2_FIXED"]

    print("\n【最重要: P2 STEP2_FIXED → TRIO_PLUS_WIN_ORDER】")
    best_metric, best_order = p2_dict["TRIO_PLUS_WIN_ORDER"]
    print_delta(base_metric, base_order, best_metric, best_order)

    print("\n【P2 TRIO_ORDER の追加価値】")
    m, o = p2_dict["TRIO_ORDER"]
    print_delta(base_metric, base_order, m, o)

    print("\n【P2 WIN_ORDER の追加価値】")
    m, o = p2_dict["WIN_ORDER"]
    print_delta(base_metric, base_order, m, o)

    best_delta, best_gamma = selected["TRIO_PLUS_WIN_ORDER"]
    best_p2_metric, _ = evaluate(records["P2"], best_delta, best_gamma)
    base_outcome.print_calibration("P2 TRIO_PLUS_WIN_ORDER", best_p2_metric)

    print("\n【判断方針】")
    print("1. P1だけでdelta/gammaを選び、P2で選び直さない")
    print("2. ペア合計確率はSTEP2から変えない（純粋な2着/3着順序補正）")
    print("3. P2 Multiclass LogLossと2-3順序LogLossが両方改善するか")
    print("4. Top5/Top10など120通り順位も維持または改善するか")
    print("5. 改善が安定なら順序補正を採用し、次に本番Web用出目確率へ進む")
    print("6. 改善が小さい/不安定ならSTEP2を最終出目モデルとして採用する")
    print("=" * 166)


if __name__ == "__main__":
    main()
