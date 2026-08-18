#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
補正後1着率：raw合計を残す100%較正方式のホールドアウト比較。

目的
----
現行FINALの順位は変えず、表示確率の尖りだけを調整する。
基本1着率の正規化前6艇合計(raw_total)が持つ「レース確信度」を
100%化後にも残すことで、Brier / LogLoss / ECE が改善するかを見る。

比較
----
1) CURRENT
   現行FINALそのまま。

2) GLOBAL_TEMP
   p'_i ∝ p_i ** tau
   全レース共通tau。直前31日で選択。
   tau < 1 なら全体を平らに、tau > 1 なら尖らせる。

3) RAW_TEMP
   tau_race = clamp(global_tau + k * ln(raw_total), 0.45, 1.60)
   p'_i ∝ p_i ** tau_race

   raw_total < 1 → tauが下がり平らになる
   raw_total > 1 → tauが上がり尖る

   kも評価期間より前の直前31日だけで選択する。
   k=0 は GLOBAL_TEMP と同じなので、RAW_TEMP がさらに改善するなら
   raw_total 自体に一律の過信補正を超える情報がある。

重要
----
- すべての方式で6艇合計は100%。
- 温度変換は単調変換なので、艇の順位は原則変わらない。
- 本番の beta=0.10 / SUM gamma=2.0 / slit alpha=0.25 は変更しない。
- 本番Webロジックは変更しない。読み取り検証のみ。
- パラメータ選択に評価期間の結果は使わない。

Usage:
  python3 analysis/corrected_winrate_rawtotal_temperature_compare.py 2026-06-15 2026-07-14
  python3 analysis/corrected_winrate_rawtotal_temperature_compare.py 2026-07-15 2026-08-14
  python3 analysis/corrected_winrate_rawtotal_temperature_compare.py 2026-07-15 2026-08-14 31
"""

from __future__ import annotations

import math
import sys
from collections import Counter
from datetime import datetime, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import corrected_winrate_course_normalization_validate as diag
import corrected_winrate_credibility_validate as cred

CALIB_DAYS_DEFAULT = 31

GLOBAL_TAU_GRID = (
    0.70, 0.75, 0.80, 0.85, 0.90,
    0.95, 1.00, 1.05, 1.10,
)

RAW_K_GRID = (
    0.00, 0.20, 0.40, 0.60, 0.80,
    1.00, 1.20, 1.40, 1.60,
)

TAU_MIN = 0.45
TAU_MAX = 1.60


def parse_date(value: str):
    return datetime.strptime(value, "%Y-%m-%d").date()


def parse_args():
    if len(sys.argv) not in (3, 4):
        print(
            "Usage: python3 analysis/corrected_winrate_rawtotal_temperature_compare.py "
            "YYYY-MM-DD YYYY-MM-DD [CALIB_DAYS]"
        )
        sys.exit(1)

    eval_start = parse_date(sys.argv[1])
    eval_end = parse_date(sys.argv[2])
    if eval_start > eval_end:
        raise RuntimeError("開始日が終了日より後です")

    calib_days = int(sys.argv[3]) if len(sys.argv) == 4 else CALIB_DAYS_DEFAULT
    if calib_days < 14:
        raise RuntimeError("CALIB_DAYSは14日以上にしてください")

    calib_end = eval_start - timedelta(days=1)
    calib_start = eval_start - timedelta(days=calib_days)
    return eval_start, eval_end, calib_start, calib_end, calib_days


def normalize(values):
    vals = [max(float(x), 1.0e-15) for x in values]
    total = sum(vals)
    if total <= 0 or not math.isfinite(total):
        return None
    return [x / total for x in vals]


def temperature_probs(probs, tau):
    tau = float(tau)
    if not math.isfinite(tau) or tau <= 0:
        return None
    powered = [max(float(p), 1.0e-15) ** tau for p in probs]
    return normalize(powered)


def raw_tau(base_tau, k, raw_total):
    if raw_total <= 0 or not math.isfinite(raw_total):
        return float(base_tau)
    tau = float(base_tau) + float(k) * math.log(float(raw_total))
    return min(TAU_MAX, max(TAU_MIN, tau))


def build_rows(start_date, end_date):
    data = diag.build_data(start_date, end_date)
    attached = data[0]
    events = data[1]
    buff = data[2]
    buff_start = data[3]
    buff_end = data[4]
    buff_rows = data[5]

    rows = []
    missing_event = 0

    for item in attached:
        snap = item[0]
        code = str(snap.race_code)
        boats = sorted(snap.boats, key=lambda b: b.lane)
        probs = cred.probs_for_stage(item, "FINAL", buff)
        if probs is None or len(probs) != 6:
            continue

        event = events.get(code, {})
        base = event.get("BASE", {})
        raw_total = base.get("raw_total")
        if raw_total is None:
            missing_event += 1
            continue
        raw_total = float(raw_total)
        if raw_total <= 0 or not math.isfinite(raw_total):
            missing_event += 1
            continue

        ys = [int(b.y) for b in boats]
        if sum(ys) != 1:
            continue

        current_order = sorted(range(6), key=lambda i: (-float(probs[i]), boats[i].lane))
        rows.append({
            "race_code": code,
            "place": diag.place_of(code),
            "raw_total": raw_total,
            "probs": [float(x) for x in probs],
            "ys": ys,
            "lanes": [int(b.lane) for b in boats],
            "current_top": current_order[0],
        })

    meta = {
        "buff_start": buff_start,
        "buff_end": buff_end,
        "buff_rows": buff_rows,
        "missing_event": missing_event,
    }
    return rows, meta


def transformed(row, mode, global_tau=1.0, k=0.0):
    probs = row["probs"]
    if mode == "CURRENT":
        return list(probs), 1.0
    if mode == "GLOBAL":
        tau = float(global_tau)
        return temperature_probs(probs, tau), tau
    if mode == "RAW":
        tau = raw_tau(global_tau, k, row["raw_total"])
        return temperature_probs(probs, tau), tau
    raise RuntimeError(f"unknown mode: {mode}")


def metrics(rows, mode, global_tau=1.0, k=0.0):
    boat_rows = []
    race_n = 0
    brier = 0.0
    logloss = 0.0
    top_hit = 0
    top_prob_sum = 0.0
    top_changed = 0
    winner_prob_sum = 0.0
    winner_nll = 0.0
    taus = []
    tau_min_hits = 0
    tau_max_hits = 0

    for row in rows:
        probs, tau = transformed(row, mode, global_tau, k)
        if probs is None:
            continue

        ys = row["ys"]
        lanes = row["lanes"]
        if abs(sum(probs) - 1.0) > 1.0e-10:
            continue

        for p, y in zip(probs, ys):
            cp = min(max(float(p), 1.0e-12), 1.0 - 1.0e-12)
            brier += (float(p) - int(y)) ** 2
            logloss += -(int(y) * math.log(cp) + (1 - int(y)) * math.log(1 - cp))
            boat_rows.append({"p": float(p), "y": int(y)})

        order = sorted(range(6), key=lambda i: (-probs[i], lanes[i]))
        top_idx = order[0]
        winner_idx = ys.index(1)

        top_hit += int(ys[top_idx] == 1)
        top_prob_sum += float(probs[top_idx])
        top_changed += int(top_idx != row["current_top"])

        wp = min(max(float(probs[winner_idx]), 1.0e-12), 1.0)
        winner_prob_sum += wp
        winner_nll += -math.log(wp)

        taus.append(float(tau))
        if abs(float(tau) - TAU_MIN) < 1.0e-12:
            tau_min_hits += 1
        if abs(float(tau) - TAU_MAX) < 1.0e-12:
            tau_max_hits += 1
        race_n += 1

    if race_n == 0:
        return None

    _, ece, mce = cred.calibration(boat_rows)
    return {
        "races": race_n,
        "brier": brier / (race_n * 6),
        "logloss": logloss / (race_n * 6),
        "ece": ece,
        "mce": mce,
        "top1": top_hit / race_n,
        "top_prob": top_prob_sum / race_n,
        "top_gap": top_hit / race_n - top_prob_sum / race_n,
        "top_changed": top_changed,
        "winner_prob": winner_prob_sum / race_n,
        "winner_nll": winner_nll / race_n,
        "tau_avg": sum(taus) / len(taus),
        "tau_min": min(taus),
        "tau_max": max(taus),
        "tau_min_hits": tau_min_hits,
        "tau_max_hits": tau_max_hits,
    }


def tune_global(rows):
    best = None
    table = []
    for tau in GLOBAL_TAU_GRID:
        m = metrics(rows, "GLOBAL", global_tau=tau)
        if m is None:
            continue
        table.append((tau, m))
        key = (m["brier"], m["logloss"], abs(tau - 1.0), tau)
        if best is None or key < best[0]:
            best = (key, tau, m)
    if best is None:
        raise RuntimeError("GLOBAL_TEMPの較正に使えるレースがありません")
    return best[1], best[2], table


def tune_raw(rows, base_tau):
    best = None
    table = []
    for k in RAW_K_GRID:
        m = metrics(rows, "RAW", global_tau=base_tau, k=k)
        if m is None:
            continue
        table.append((k, m))
        key = (m["brier"], m["logloss"], abs(k), k)
        if best is None or key < best[0]:
            best = (key, k, m)
    if best is None:
        raise RuntimeError("RAW_TEMPの較正に使えるレースがありません")
    return best[1], best[2], table


def fmt_change(value, base):
    if base == 0:
        return "-"
    return f"{(value - base) / base * 100:+.3f}%"


def print_method_row(name, m, current):
    print(
        f"{name:<16} {m['brier']:.6f}  {fmt_change(m['brier'], current['brier']):>9}  "
        f"{m['logloss']:.6f}  {m['ece']*100:>6.3f}pt  "
        f"{m['top_prob']*100:>7.2f}%  {m['top1']*100:>7.2f}%  "
        f"{m['top_gap']*100:+8.2f}pt  {m['top_changed']:>5}"
    )


def print_raw_bins(rows, global_tau, k):
    print("\n【4. raw合計帯別：現行表示 vs RAW_TEMP表示 vs 実Top1率】")
    print("raw合計帯      R数    現行Top1    RAW表示     実Top1率    現行gap    RAWgap    平均tau")
    print("-" * 108)

    for low, high in diag.BASE_TOTAL_BINS:
        selected = [r for r in rows if diag.in_bin(r["raw_total"], low, high)]
        if not selected:
            continue

        current_top = 0.0
        raw_top = 0.0
        hits = 0
        taus = []
        for row in selected:
            cp = row["probs"]
            rp, tau = transformed(row, "RAW", global_tau, k)
            if rp is None:
                continue
            idx = row["current_top"]
            current_top += cp[idx]
            # 温度変換は順位保持なので同じidxを使う。
            raw_top += rp[idx]
            hits += row["ys"][idx]
            taus.append(tau)

        n = len(taus)
        if n == 0:
            continue
        cur_pred = current_top / n
        raw_pred = raw_top / n
        actual = hits / n
        avg_tau = sum(taus) / n
        hi_txt = f"{high*100:.0f}" if high < 2 else "∞"
        print(
            f"{low*100:>3.0f}-{hi_txt:<3}%  {n:>6}    {cur_pred*100:>7.2f}%    "
            f"{raw_pred*100:>7.2f}%    {actual*100:>7.2f}%    "
            f"{(actual-cur_pred)*100:+7.2f}pt  {(actual-raw_pred)*100:+7.2f}pt  {avg_tau:>7.3f}"
        )


def main():
    eval_start, eval_end, calib_start, calib_end, calib_days = parse_args()

    print("raw合計を残す100%較正方式：ホールドアウト検証データを構築しています...")
    print(f"較正期間: {calib_start} ～ {calib_end} ({calib_days}日)")
    print(f"評価期間: {eval_start} ～ {eval_end}")

    print("\n[1/2] 較正期間を構築中...")
    calib_rows, calib_meta = build_rows(calib_start, calib_end)
    if not calib_rows:
        raise RuntimeError("較正期間の評価可能レースが0件です")

    print("\n[2/2] 評価期間を構築中...")
    eval_rows, eval_meta = build_rows(eval_start, eval_end)
    if not eval_rows:
        raise RuntimeError("評価期間の評価可能レースが0件です")

    global_tau, global_train, global_table = tune_global(calib_rows)
    raw_k, raw_train, raw_table = tune_raw(calib_rows, global_tau)

    current = metrics(eval_rows, "CURRENT")
    global_eval = metrics(eval_rows, "GLOBAL", global_tau=global_tau)
    raw_eval = metrics(eval_rows, "RAW", global_tau=global_tau, k=raw_k)

    if current is None or global_eval is None or raw_eval is None:
        raise RuntimeError("評価指標の計算に失敗しました")

    print("\n" + "=" * 128)
    print("補正後1着率：raw合計を残す100%較正方式 ホールドアウト比較")
    print("=" * 128)
    print(f"較正期間          : {calib_start} ～ {calib_end} ({len(calib_rows)}R)")
    print(f"評価期間          : {eval_start} ～ {eval_end} ({len(eval_rows)}R)")
    print(f"GLOBAL tau        : {global_tau:.2f}  ※較正期間Brierで選択")
    print(f"RAW k             : {raw_k:.2f}  ※GLOBAL tau固定後、較正期間Brierで選択")
    print(f"RAW tau式         : clamp({global_tau:.2f} + {raw_k:.2f} * ln(raw_total), {TAU_MIN:.2f}, {TAU_MAX:.2f})")
    print("本番固定値        : beta=0.10 / SUM gamma=2.0 / slit alpha=0.25")
    print("順位              : 温度変換のため保持が原則。実測Top1変更数も表示")
    print("本番変更          : なし")

    print("\n【1. 較正期間でのパラメータ選択】")
    print("GLOBAL tau   Brier      LogLoss    ECE      Top1表示   実Top1")
    print("-" * 80)
    for tau, m in global_table:
        mark = " <-採用" if abs(tau - global_tau) < 1.0e-12 else ""
        print(
            f"{tau:>8.2f}   {m['brier']:.6f}   {m['logloss']:.6f}   "
            f"{m['ece']*100:>6.3f}pt   {m['top_prob']*100:>7.2f}%   {m['top1']*100:>7.2f}%{mark}"
        )

    print("\nRAW k        Brier      LogLoss    ECE      Top1表示   実Top1   平均tau")
    print("-" * 88)
    for k, m in raw_table:
        mark = " <-採用" if abs(k - raw_k) < 1.0e-12 else ""
        print(
            f"{k:>8.2f}   {m['brier']:.6f}   {m['logloss']:.6f}   "
            f"{m['ece']*100:>6.3f}pt   {m['top_prob']*100:>7.2f}%   "
            f"{m['top1']*100:>7.2f}%   {m['tau_avg']:>7.3f}{mark}"
        )

    print("\n【2. 評価期間：3方式比較】")
    print("方式             Brier      vs現行     LogLoss    ECE      Top1表示   実Top1    実績-表示   Top1変更")
    print("-" * 120)
    print_method_row("CURRENT", current, current)
    print_method_row("GLOBAL_TEMP", global_eval, current)
    print_method_row("RAW_TEMP", raw_eval, current)

    print("\n【3. RAW_TEMPの温度分布】")
    print(f"平均tau            : {raw_eval['tau_avg']:.4f}")
    print(f"最小～最大         : {raw_eval['tau_min']:.4f} ～ {raw_eval['tau_max']:.4f}")
    print(f"下限hit            : {raw_eval['tau_min_hits']}R")
    print(f"上限hit            : {raw_eval['tau_max_hits']}R")
    print(f"Top1順位変更       : {raw_eval['top_changed']}R / {raw_eval['races']}R")
    print(f"勝者平均表示 CURRENT: {current['winner_prob']*100:.2f}%")
    print(f"勝者平均表示 RAW   : {raw_eval['winner_prob']*100:.2f}%")
    print(f"勝者NLL CURRENT    : {current['winner_nll']:.5f}")
    print(f"勝者NLL RAW        : {raw_eval['winner_nll']:.5f}")

    print_raw_bins(eval_rows, global_tau, raw_k)

    print("\n【5. 判定の見方】")
    print("・GLOBAL_TEMPが改善 → 現行FINAL全体に過信/過小信の較正余地がある")
    print("・RAW_TEMPがGLOBAL_TEMPよりさらに改善 → raw合計に独立した確信度情報がある")
    print("・BrierとLogLossを最重要、ECEとTop1表示gapを補助確認する")
    print("・Top1変更は0が期待値。順位は変えず確率の信憑性だけ直す狙い")
    print("・同じ方式が固定2期間とも改善してから次工程へ進む")
    print("・この結果だけで本番へ反映しない")
    print("=" * 128)


if __name__ == "__main__":
    main()
