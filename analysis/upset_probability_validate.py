#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
STEP C1：高配当レースの共通特徴 + 荒れ確率プロトタイプ

目的
----
B5-2で「AI本命は的中率を上げる一方、高配当の穴頭を一部失う」ことが分かった。
そこで、本命/対抗ロジックは壊さず、別系統で

  1. 高配当レースにどんな共通特徴があるか
  2. レース前情報だけで「荒れ確率」を推定できるか

を検証する。

荒れラベル
----------
3連単払戻を使い、以下を別々に検証する。
- 5,000円以上  : 中穴以上
- 10,000円以上 : 荒れ（主指標）
- 20,000円以上 : 大荒れ

学習/検証
---------
TRAIN : P1 + P2（例 2026-06-15～2026-08-14）
TEST  : P3完全未来（例 2026-08-15～2026-08-19）

重要
----
- P3では係数・閾値を再調整しない。
- オッズは使わない。Webですでに持っている予想情報だけを使う。
- 荒れ確率は本命予想の代替ではなく「穴狙い用の別指標」候補。
- 本番Web/PredictionLogicは変更しない。

Usage:
python3 analysis/upset_probability_validate.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv \
  analysis/output/final_prediction_boats_20260815_20260819.csv
"""

from __future__ import annotations

import math
import statistics
import sys
from collections import defaultdict
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import base_trifecta_probability_compare as base_outcome
import final_prediction_ai_favorite_compare as b1
import final_prediction_ai_opponent_compare as b2
import final_prediction_ai_bet_integration_compare as b4
import trifecta_probability_order_compare as step3


PAYOUT_THRESHOLDS = (5000, 10000, 20000)
PRIMARY_THRESHOLD = 10000
EPS = 1e-12

# すべてレース前に取得できる情報だけ。
FEATURE_NAMES = (
    "in_win_p",              # イン艇の補正後1着率
    "in_margin",             # イン艇1着率 - イン以外最高1着率
    "win_top_p",             # AI1着率1位の確率
    "win_gap",               # AI1着率1位-2位差
    "base_in_win_p",         # 場平均ベースのイン頭確率
    "dash_win_mass",         # 4～6Cの補正後1着率合計
    "trio_gap",              # AI3連対率1位-2位差
    "outcome_head_gap",      # STEP3頭確率1位-2位差
    "primary_gap",           # 一次1位-2位差
    "secondary_gap",         # 二次1位-2位差
    "final_gap",             # 現行final3 1位-2位差
    "current_ai_disagree",   # 現行本命とAI本命が不一致
    "current_is_in",         # 現行本命がイン艇
    "ai_is_in",              # AI本命がイン艇
    "cut_count",             # 現行cut艇数
)


def safe_float(v, default=0.0):
    try:
        if v is None or str(v).strip() == "":
            return default
        return float(v)
    except (TypeError, ValueError):
        return default


def sigmoid(x):
    if x >= 0:
        z = math.exp(-min(x, 60.0))
        return 1.0 / (1.0 + z)
    z = math.exp(max(x, -60.0))
    return z / (1.0 + z)


def solve_linear(a, b):
    """小さい連立一次方程式をガウス消去で解く。"""
    n = len(b)
    m = [list(a[i]) + [float(b[i])] for i in range(n)]
    for col in range(n):
        pivot = max(range(col, n), key=lambda r: abs(m[r][col]))
        if abs(m[pivot][col]) < 1e-10:
            m[pivot][col] = 1e-10
        if pivot != col:
            m[col], m[pivot] = m[pivot], m[col]
        div = m[col][col]
        for j in range(col, n + 1):
            m[col][j] /= div
        for r in range(n):
            if r == col:
                continue
            factor = m[r][col]
            if abs(factor) < 1e-18:
                continue
            for j in range(col, n + 1):
                m[r][j] -= factor * m[col][j]
    return [m[i][n] for i in range(n)]


class LogisticModel:
    """外部ライブラリ不要のL2付きロジスティック回帰（IRLS/Newton）。"""

    def __init__(self, l2=1.0, max_iter=35):
        self.l2 = float(l2)
        self.max_iter = int(max_iter)
        self.means = []
        self.stds = []
        self.coef = []

    def _standardize_fit(self, xs):
        p = len(xs[0])
        self.means = []
        self.stds = []
        for j in range(p):
            vals = [float(row[j]) for row in xs]
            mu = sum(vals) / len(vals)
            var = sum((v - mu) ** 2 for v in vals) / max(len(vals), 1)
            sd = math.sqrt(var)
            if sd < 1e-9:
                sd = 1.0
            self.means.append(mu)
            self.stds.append(sd)

    def _zrow(self, row):
        return [1.0] + [
            (float(row[j]) - self.means[j]) / self.stds[j]
            for j in range(len(row))
        ]

    def fit(self, xs, ys):
        if not xs:
            raise RuntimeError("学習データがありません")
        self._standardize_fit(xs)
        z = [self._zrow(row) for row in xs]
        d = len(z[0])
        beta = [0.0] * d

        # 切片初期値をベースレートに合わせる。
        base = (sum(ys) + 0.5) / (len(ys) + 1.0)
        beta[0] = math.log(base / max(1.0 - base, EPS))

        for _ in range(self.max_iter):
            grad = [0.0] * d
            h = [[0.0] * d for _ in range(d)]

            for row, y in zip(z, ys):
                eta = sum(beta[j] * row[j] for j in range(d))
                p = sigmoid(eta)
                w = max(p * (1.0 - p), 1e-6)
                diff = float(y) - p
                for j in range(d):
                    grad[j] += row[j] * diff
                for j in range(d):
                    rj = row[j] * w
                    for k in range(j, d):
                        h[j][k] += rj * row[k]

            # 対称化 + L2。切片は罰しない。
            for j in range(d):
                for k in range(j):
                    h[j][k] = h[k][j]
                if j > 0:
                    h[j][j] += self.l2
                    grad[j] -= self.l2 * beta[j]

            step = solve_linear(h, grad)
            max_change = max(abs(v) for v in step)
            # 極端なNewton stepを抑える。
            scale = 1.0 if max_change <= 2.0 else 2.0 / max_change
            beta = [beta[j] + scale * step[j] for j in range(d)]
            if max_change * scale < 1e-6:
                break

        self.coef = beta
        return self

    def predict_proba(self, row):
        z = self._zrow(row)
        return sigmoid(sum(self.coef[j] * z[j] for j in range(len(z))))


def course_lane_map(record):
    mapping = {}
    for pattern, lanes in zip(base_outcome.PATTERNS, record["pattern_lanes"]):
        for course, lane in zip(pattern, lanes):
            course = int(course)
            lane = int(lane)
            prev = mapping.get(course)
            if prev is not None and prev != lane:
                raise RuntimeError(f"進入コース対応が不整合: course={course}")
            mapping[course] = lane
    if set(mapping) != set(range(1, 7)):
        raise RuntimeError("1～6Cの艇対応を復元できません")
    return mapping


def rank_gap(values):
    ranked = sorted((float(v) for v in values), reverse=True)
    if len(ranked) < 2:
        return 0.0
    return ranked[0] - ranked[1]


def score_gap(boats, score_key, rank_key):
    r1 = next((b for b in boats.values() if int(b.get(rank_key, 0)) == 1), None)
    r2 = next((b for b in boats.values() if int(b.get(rank_key, 0)) == 2), None)
    if r1 is None or r2 is None:
        return 0.0
    return float(r1.get(score_key, 0.0)) - float(r2.get(score_key, 0.0))


def make_features(record, boats):
    rank_boats, current_head = b2.current_order_and_head(boats)
    if not rank_boats or current_head is None:
        return None

    win, trio, outcome_head = b1.marginal_signals(record)
    ai_head, win_top_p, win_gap_pt = b1.top_info(win)
    _trio_head, _trio_top_p, trio_gap_pt = b1.top_info(trio)
    _out_head, _out_top_p, outcome_gap_pt = b1.top_info(outcome_head)

    c2l = course_lane_map(record)
    in_lane = int(c2l[1])
    in_win_p = float(win[in_lane])
    outer_best = max(float(win[lane]) for lane in range(1, 7) if lane != in_lane)
    in_margin = in_win_p - outer_best

    # STEP1場平均ベースの艇別1着周辺確率。
    q_win = {lane: 0.0 for lane in range(1, 7)}
    for idx, lanes in enumerate(record["pattern_lanes"]):
        q_win[int(lanes[0])] += float(record["probs"][idx])
    base_in_win_p = float(q_win[in_lane])

    dash_win_mass = sum(float(win[int(c2l[c])]) for c in (4, 5, 6))

    primary_gap = score_gap(boats, "first_score", "first_rank")
    secondary_gap = score_gap(boats, "second_score", "second_rank")

    final_vals = sorted(
        [float(b.get("final3", 0.0)) for b in boats.values()],
        reverse=True,
    )
    final_gap = final_vals[0] - final_vals[1] if len(final_vals) >= 2 else 0.0
    cut_count = sum(1 for b in boats.values() if int(b.get("kiru", 0)) == 1)

    values = {
        "in_win_p": in_win_p,
        "in_margin": in_margin,
        "win_top_p": float(win_top_p),
        "win_gap": float(win_gap_pt) / 100.0,
        "base_in_win_p": base_in_win_p,
        "dash_win_mass": dash_win_mass,
        "trio_gap": float(trio_gap_pt) / 100.0,
        "outcome_head_gap": float(outcome_gap_pt) / 100.0,
        "primary_gap": primary_gap,
        "secondary_gap": secondary_gap,
        "final_gap": final_gap,
        "current_ai_disagree": 1.0 if int(current_head) != int(ai_head) else 0.0,
        "current_is_in": 1.0 if int(current_head) == in_lane else 0.0,
        "ai_is_in": 1.0 if int(ai_head) == in_lane else 0.0,
        "cut_count": float(cut_count),
    }

    return {
        "x": [values[name] for name in FEATURE_NAMES],
        "values": values,
        "current_head": int(current_head),
        "ai_head": int(ai_head),
        "in_lane": in_lane,
        "course_by_lane": {lane: course for course, lane in c2l.items()},
        "win": win,
        "trio": trio,
        "outcome_head": outcome_head,
    }


def build_rows(records, boats_map, payouts, period):
    rows = []
    skip = defaultdict(int)
    for record in records:
        code = str(record["race_code"])
        boats = boats_map.get(code)
        if boats is None or set(boats) != set(range(1, 7)):
            skip["csv_missing"] += 1
            continue
        payout = payouts.get(code)
        if payout is None or payout <= 0:
            skip["payout_missing"] += 1
            continue
        actual = b4.actual_trifecta(boats)
        if actual is None:
            skip["actual_invalid"] += 1
            continue
        f = make_features(record, boats)
        if f is None:
            skip["feature_invalid"] += 1
            continue

        cur = b4.current_bets(boats)
        win_only = b4.make_win_head_bets(boats, f["ai_head"])
        outcome_top3 = __import__("final_prediction_ai_cut_rescue_compare").outcome_top3_scores(record)
        full = b4.make_outcome_bets(record, boats, f["ai_head"], outcome_top3, None)
        if cur is None or win_only is None or full is None:
            skip["bets_invalid"] += 1
            continue

        rows.append({
            "period": period,
            "race_code": code,
            "payout": int(payout),
            "actual": actual,
            "x": f["x"],
            "values": f["values"],
            "current_head": f["current_head"],
            "ai_head": f["ai_head"],
            "in_lane": f["in_lane"],
            "course_by_lane": f["course_by_lane"],
            "cur_hit": actual in cur["bets"],
            "win_hit": actual in win_only["bets"],
            "full_hit": actual in full["bets"],
        })
        skip["ready"] += 1
    return rows, skip


def metrics(rows, probs, threshold):
    ys = [1 if r["payout"] >= threshold else 0 for r in rows]
    n = len(ys)
    if n == 0:
        return None
    base = sum(ys) / n
    brier = sum((p - y) ** 2 for p, y in zip(probs, ys)) / n
    ll = 0.0
    for p, y in zip(probs, ys):
        p = min(max(p, EPS), 1.0 - EPS)
        ll += -(y * math.log(p) + (1 - y) * math.log(1.0 - p))
    ll /= n

    # AUC: 全positive-negative pairの順位比較。P3は数百Rなので十分軽い。
    pos = [p for p, y in zip(probs, ys) if y == 1]
    neg = [p for p, y in zip(probs, ys) if y == 0]
    auc_num = 0.0
    for pp in pos:
        for pn in neg:
            if pp > pn:
                auc_num += 1.0
            elif pp == pn:
                auc_num += 0.5
    auc = auc_num / (len(pos) * len(neg)) if pos and neg else 0.5
    return {"n": n, "events": sum(ys), "rate": base, "brier": brier, "logloss": ll, "auc": auc}


def print_calibration(rows, probs, threshold, buckets=5):
    pairs = sorted(zip(probs, rows), key=lambda x: x[0])
    n = len(pairs)
    print("予測確率帯（低→高）        R数   平均予測   実荒れ率   件数")
    print("-" * 72)
    for b in range(buckets):
        lo = n * b // buckets
        hi = n * (b + 1) // buckets
        part = pairs[lo:hi]
        if not part:
            continue
        avgp = sum(p for p, _ in part) / len(part)
        ev = sum(1 for _, r in part if r["payout"] >= threshold)
        rate = ev / len(part)
        print(f"Q{b+1:<2}                    {len(part):>4d}   {avgp*100:>7.2f}%   {rate*100:>7.2f}%   {ev:>4d}")


def feature_profile(rows, threshold):
    upset = [r for r in rows if r["payout"] >= threshold]
    normal = [r for r in rows if r["payout"] < threshold]
    out = []
    for name in FEATURE_NAMES:
        a = [float(r["values"][name]) for r in upset]
        b = [float(r["values"][name]) for r in normal]
        if not a or not b:
            continue
        ma = sum(a) / len(a)
        mb = sum(b) / len(b)
        allv = a + b
        sd = statistics.pstdev(allv) if len(allv) > 1 else 0.0
        effect = (ma - mb) / sd if sd > 1e-9 else 0.0
        out.append((abs(effect), effect, name, ma, mb))
    return sorted(out, reverse=True)


def fmt_feature(name, v):
    if name in {
        "in_win_p", "in_margin", "win_top_p", "win_gap", "base_in_win_p",
        "dash_win_mass", "trio_gap", "outcome_head_gap",
    }:
        return f"{v*100:.1f}%"
    if name in {"current_ai_disagree", "current_is_in", "ai_is_in"}:
        return f"{v*100:.1f}%"
    return f"{v:.2f}"


def print_feature_profile(title, rows, threshold):
    print(f"\n【{title}: 払戻{threshold:,}円以上 vs 未満】")
    print("特徴                     荒れ平均      非荒れ平均    標準化差")
    print("-" * 74)
    for _abs_eff, eff, name, ma, mb in feature_profile(rows, threshold)[:12]:
        print(f"{name:<24} {fmt_feature(name, ma):>10}   {fmt_feature(name, mb):>10}   {eff:+8.3f}")


def print_coefficients(model):
    rows = []
    for i, name in enumerate(FEATURE_NAMES, start=1):
        c = model.coef[i]
        rows.append((abs(c), c, name))
    rows.sort(reverse=True)
    print("特徴                     標準化係数   荒れ方向")
    print("-" * 60)
    for _abs, c, name in rows:
        arrow = "↑荒れ" if c > 0 else "↓荒れ"
        print(f"{name:<24} {c:+10.4f}   {arrow}")


def print_high_loss(rows, model, threshold, mode):
    if mode == "WIN_HEAD":
        selected = [r for r in rows if r["cur_hit"] and not r["win_hit"] and r["payout"] >= threshold]
    else:
        selected = [r for r in rows if r["cur_hit"] and not r["full_hit"] and r["payout"] >= threshold]
    selected.sort(key=lambda r: r["payout"], reverse=True)
    print(f"\n【P3 高配当LOSS詳細: {mode} / {threshold:,}円以上】")
    print("race_code          払戻   実3連単  荒れP  IN勝率  IN差  AI頭差  一次差 二次差  現本命C AI本命C")
    print("-" * 116)
    for r in selected[:20]:
        p = model.predict_proba(r["x"])
        v = r["values"]
        actual = "-".join(str(x) for x in r["actual"])
        cur_c = r["course_by_lane"].get(r["current_head"], 0)
        ai_c = r["course_by_lane"].get(r["ai_head"], 0)
        print(
            f"{r['race_code']:<18} {r['payout']:>7,d}  {actual:<7} "
            f"{p*100:>6.1f}% {v['in_win_p']*100:>6.1f}% {v['in_margin']*100:>+6.1f} "
            f"{v['win_gap']*100:>6.1f} {v['primary_gap']:>6.2f} {v['secondary_gap']:>6.2f} "
            f"{cur_c:>7d}C {ai_c:>7d}C"
        )
    if not selected:
        print("該当なし")


def main():
    if len(sys.argv) != 4:
        print("Usage: python3 analysis/upset_probability_validate.py P1_BOATS_CSV P2_BOATS_CSV P3_BOATS_CSV")
        sys.exit(1)

    p1_csv, p2_csv, p3_csv = sys.argv[1], sys.argv[2], sys.argv[3]

    print("P1/P2を学習期間、P3を完全未来期間としてAI特徴・払戻を再構築中...")
    train_data = step3.build_common_records(p1_csv, p2_csv)
    future_data = step3.build_common_records(p2_csv, p3_csv)

    p1_records = train_data["records"]["P1"]
    p2_records = train_data["records"]["P2"]
    p3_records = future_data["records"]["P2"]  # 第2引数側をP3として利用

    boats_map = b2.load_boats(p1_csv, p2_csv, p3_csv)
    start_date = train_data["p1_start"]
    end_date = future_data["p2_end"]
    payouts = b4.load_payouts(start_date, end_date)

    p1_rows, s1 = build_rows(p1_records, boats_map, payouts, "P1")
    p2_rows, s2 = build_rows(p2_records, boats_map, payouts, "P2")
    p3_rows, s3 = build_rows(p3_records, boats_map, payouts, "P3")
    train_rows = p1_rows + p2_rows

    print("=" * 126)
    print("STEP C1：高配当レースの共通特徴 + 荒れ確率プロトタイプ")
    print("=" * 126)
    print(f"TRAIN : {train_data['p1_start']} ～ {train_data['p2_end']} (P1+P2)")
    print(f"P3    : {future_data['p2_start']} ～ {future_data['p2_end']} 完全未来")
    print(f"母集団 : TRAIN={len(train_rows)}R / P3={len(p3_rows)}R / 払戻取得={len(payouts)}R")
    print("荒れ定義: 5,000円以上 / 10,000円以上（主） / 20,000円以上")
    print("使用情報: オッズなし。既存AI・一次/二次・最終評価・進入・cutのみ")
    print("本番Web変更: なし")

    print("\n【実際の高配当率】")
    print("閾値          TRAIN                 P3完全未来")
    print("-" * 68)
    for th in PAYOUT_THRESHOLDS:
        tr = sum(1 for r in train_rows if r["payout"] >= th)
        te = sum(1 for r in p3_rows if r["payout"] >= th)
        print(
            f">={th:>6,d}円    {tr:>4d}/{len(train_rows):<4d} {tr/len(train_rows)*100:>6.2f}%     "
            f"{te:>4d}/{len(p3_rows):<4d} {te/len(p3_rows)*100:>6.2f}%"
        )

    print_feature_profile("TRAIN共通特徴", train_rows, PRIMARY_THRESHOLD)
    print_feature_profile("P3再現確認", p3_rows, PRIMARY_THRESHOLD)

    models = {}
    for th in PAYOUT_THRESHOLDS:
        xs = [r["x"] for r in train_rows]
        ys = [1 if r["payout"] >= th else 0 for r in train_rows]
        model = LogisticModel(l2=2.0, max_iter=35).fit(xs, ys)
        models[th] = model

        train_probs = [model.predict_proba(r["x"]) for r in train_rows]
        p3_probs = [model.predict_proba(r["x"]) for r in p3_rows]
        mt = metrics(train_rows, train_probs, th)
        mp = metrics(p3_rows, p3_probs, th)

        print(f"\n{'=' * 126}")
        print(f"荒れ確率モデル：3連単払戻 {th:,}円以上")
        print(f"{'=' * 126}")
        print("期間       R数   荒れ件数  荒れ率   LogLoss   Brier    AUC")
        print("-" * 76)
        for label, m in (("TRAIN", mt), ("P3", mp)):
            print(
                f"{label:<8} {m['n']:>5d} {m['events']:>7d}  {m['rate']*100:>6.2f}%  "
                f"{m['logloss']:.6f}  {m['brier']:.6f}  {m['auc']:.4f}"
            )
        print("\nP3確率帯ごとの実荒れ率")
        print_calibration(p3_rows, p3_probs, th, buckets=5)

        if th == PRIMARY_THRESHOLD:
            print("\n10,000円以上モデルの特徴係数（標準化後）")
            print_coefficients(model)

    primary_model = models[PRIMARY_THRESHOLD]
    print_high_loss(p3_rows, primary_model, PRIMARY_THRESHOLD, "WIN_HEAD")
    print_high_loss(p3_rows, primary_model, PRIMARY_THRESHOLD, "WIN_HEAD_OUTCOME_AITE")

    print("\n【判断方針】")
    print("1. TRAINとP3で高配当側の特徴が同方向に出るか")
    print("2. P3で荒れ確率が高いQほど実際の荒れ率も上昇するか")
    print("3. AUCは0.5=ランダム。0.6台なら弱～中程度、0.7以上ならかなり有望")
    print("4. まず10,000円以上を『荒れ確率』主候補にし、5,000/20,000円は補助で比較する")
    print("5. 高額LOSSが高い荒れ確率帯に集まるなら、AI本命と別に穴頭保険を出す根拠になる")
    print("6. P3で再現しなければ、場特性・展示/ST・オッズなど特徴追加を検討する")
    print("7. この段階では本番Web/最終予想へは組み込まない")
    print("=" * 126)


if __name__ == "__main__":
    main()
