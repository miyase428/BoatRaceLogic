#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
RAW_TEMP導入後に、展示進入コース固有の較正ズレが残るかを確認するホールドアウト検証。

目的
----
- CURRENTで見えていた1Cの過大評価が、raw_total連動の温度較正でどこまで縮むか確認する。
- CURRENTとRAW_TEMPを「同じ艇の母集団」で直接比較する。
- RAW_TEMP導入後に実際に表示される確率帯でもcalibrationを確認する。

手順
----
1) 評価期間直前31日で GLOBAL tau と RAW k を選択。
2) 評価期間には固定して適用。
3) 展示進入course × CURRENT確率帯で CURRENT/RAW/実績を同じ艇で比較。
4) 展示進入course × RAW_TEMP表示確率帯でも較正を確認。

本番Webロジックは変更しない。読み取り検証のみ。

Usage:
  python3 analysis/corrected_winrate_rawtemp_course_validate.py 2026-06-15 2026-07-14
  python3 analysis/corrected_winrate_rawtemp_course_validate.py 2026-07-15 2026-08-14
  python3 analysis/corrected_winrate_rawtemp_course_validate.py 2026-07-15 2026-08-14 31
"""

from __future__ import annotations

import math
import sys
from collections import defaultdict
from datetime import datetime, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import corrected_winrate_course_normalization_validate as diag
import corrected_winrate_credibility_validate as cred
import corrected_winrate_rawtotal_temperature_compare as temp

CALIB_DAYS_DEFAULT = 31

COURSE_BINS = (
    (0.00, 0.10),
    (0.10, 0.20),
    (0.20, 0.30),
    (0.30, 0.40),
    (0.40, 0.50),
    (0.50, 0.60),
    (0.60, 1.0000001),
)


def parse_date(value: str):
    return datetime.strptime(value, "%Y-%m-%d").date()


def parse_args():
    if len(sys.argv) not in (3, 4):
        print(
            "Usage: python3 analysis/corrected_winrate_rawtemp_course_validate.py "
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


def in_bin(value: float, low: float, high: float) -> bool:
    return low <= value < high


def band_text(low: float, high: float) -> str:
    hi = min(high, 1.0)
    return f"{low*100:>2.0f}-{hi*100:<3.0f}%"


def build_eval_rows(eval_start, eval_end, global_tau, raw_k):
    data = diag.build_data(eval_start, eval_end)
    attached = data[0]
    events = data[1]
    buff = data[2]
    buff_start = data[3]
    buff_end = data[4]
    buff_rows = data[5]

    boat_rows = []
    race_rows = []
    missing_event = 0
    top_changed = 0

    for item in attached:
        snap, pid, method, cmap, sum_probs = item
        code = str(snap.race_code)
        boats = sorted(snap.boats, key=lambda b: b.lane)
        current = cred.probs_for_stage(item, "FINAL", buff)
        if current is None or len(current) != 6:
            continue

        base = events.get(code, {}).get("BASE", {})
        raw_total = base.get("raw_total")
        if raw_total is None:
            missing_event += 1
            continue
        raw_total = float(raw_total)
        if raw_total <= 0 or not math.isfinite(raw_total):
            missing_event += 1
            continue

        tau = temp.raw_tau(global_tau, raw_k, raw_total)
        raw_probs = temp.temperature_probs(current, tau)
        if raw_probs is None or len(raw_probs) != 6:
            continue

        ys = [int(b.y) for b in boats]
        if sum(ys) != 1:
            continue

        current_order = sorted(range(6), key=lambda i: (-float(current[i]), boats[i].lane))
        raw_order = sorted(range(6), key=lambda i: (-float(raw_probs[i]), boats[i].lane))
        top_changed += int(current_order[0] != raw_order[0])

        race_rows.append({
            "race_code": code,
            "raw_total": raw_total,
            "tau": tau,
            "current_top": float(current[current_order[0]]),
            "raw_top": float(raw_probs[raw_order[0]]),
            "top_hit": int(ys[current_order[0]] == 1),
        })

        for idx, b in enumerate(boats):
            course = int(cmap[b.lane])
            boat_rows.append({
                "race_code": code,
                "course": course,
                "lane": int(b.lane),
                "y": int(b.y),
                "current": float(current[idx]),
                "raw": float(raw_probs[idx]),
                "tau": float(tau),
                "raw_total": raw_total,
            })

    meta = {
        "buff_start": buff_start,
        "buff_end": buff_end,
        "buff_rows": buff_rows,
        "missing_event": missing_event,
        "top_changed": top_changed,
    }
    return boat_rows, race_rows, meta


def metric(rows, key):
    if not rows:
        return None
    n = len(rows)
    brier = sum((float(r[key]) - int(r["y"])) ** 2 for r in rows) / n
    pred = sum(float(r[key]) for r in rows) / n
    actual = sum(int(r["y"]) for r in rows) / n
    return {
        "n": n,
        "brier": brier,
        "pred": pred,
        "actual": actual,
        "gap": actual - pred,
    }


def ece_for(rows, key):
    calib_rows = [{"p": float(r[key]), "y": int(r["y"])} for r in rows]
    _, ece, mce = cred.calibration(calib_rows)
    return ece, mce


def print_same_cohort(rows):
    print("\n【1. course × CURRENT確率帯：同じ艇で CURRENT → RAW_TEMP を直接比較】")
    print(
        "course  CURRENT帯     舟数  勝数   CURRENT平均   RAW平均    実1着率   "
        "CURRENTgap   RAWgap   改善幅"
    )
    print("-" * 118)

    for course in range(1, 7):
        printed = False
        for low, high in COURSE_BINS:
            selected = [
                r for r in rows
                if r["course"] == course and in_bin(r["current"], low, high)
            ]
            if not selected:
                continue
            n = len(selected)
            wins = sum(r["y"] for r in selected)
            cur = sum(r["current"] for r in selected) / n
            raw = sum(r["raw"] for r in selected) / n
            actual = wins / n
            cur_gap = actual - cur
            raw_gap = actual - raw
            improve = abs(cur_gap) - abs(raw_gap)
            print(
                f" {course}C     {band_text(low, high):<9} {n:>6} {wins:>5}   "
                f"{cur*100:>8.2f}%   {raw*100:>8.2f}%   {actual*100:>8.2f}%   "
                f"{cur_gap*100:+9.2f}pt {raw_gap*100:+8.2f}pt {improve*100:+7.2f}pt"
            )
            printed = True
        if printed:
            print()


def print_raw_display_bins(rows):
    print("\n【2. course × RAW_TEMP表示確率帯：導入後の表示値としての較正】")
    print("course  RAW表示帯      舟数  勝数    RAW平均    実1着率    実績-表示")
    print("-" * 82)

    for course in range(1, 7):
        printed = False
        for low, high in COURSE_BINS:
            selected = [
                r for r in rows
                if r["course"] == course and in_bin(r["raw"], low, high)
            ]
            if not selected:
                continue
            n = len(selected)
            wins = sum(r["y"] for r in selected)
            pred = sum(r["raw"] for r in selected) / n
            actual = wins / n
            gap = actual - pred
            print(
                f" {course}C     {band_text(low, high):<9} {n:>6} {wins:>5}    "
                f"{pred*100:>8.2f}%   {actual*100:>8.2f}%   {gap*100:+9.2f}pt"
            )
            printed = True
        if printed:
            print()


def print_course_summary(rows):
    print("\n【3. course別：CURRENT vs RAW_TEMP 全体指標】")
    print("course   舟数    CURRENT Brier   RAW Brier    改善率    CURRENT ECE   RAW ECE   平均tau")
    print("-" * 104)

    for course in range(1, 7):
        selected = [r for r in rows if r["course"] == course]
        if not selected:
            continue
        cur = metric(selected, "current")
        raw = metric(selected, "raw")
        cur_ece, _ = ece_for(selected, "current")
        raw_ece, _ = ece_for(selected, "raw")
        improve = (raw["brier"] - cur["brier"]) / cur["brier"] * 100 if cur["brier"] else 0.0
        avg_tau = sum(r["tau"] for r in selected) / len(selected)
        print(
            f" {course}C   {len(selected):>6}      {cur['brier']:.6f}     {raw['brier']:.6f}   "
            f"{improve:+7.3f}%      {cur_ece*100:>6.3f}pt    {raw_ece*100:>6.3f}pt   {avg_tau:>7.3f}"
        )


def print_one_course_focus(rows):
    one = [r for r in rows if r["course"] == 1]
    cur = metric(one, "current")
    raw = metric(one, "raw")
    cur_ece, _ = ece_for(one, "current")
    raw_ece, _ = ece_for(one, "raw")

    print("\n【4. 1C重点確認】")
    print(f"1C舟数             : {len(one)}")
    print(f"CURRENT Brier       : {cur['brier']:.6f}")
    print(f"RAW_TEMP Brier      : {raw['brier']:.6f}")
    print(f"CURRENT ECE         : {cur_ece*100:.3f}pt")
    print(f"RAW_TEMP ECE        : {raw_ece*100:.3f}pt")
    print(f"CURRENT平均表示     : {cur['pred']*100:.2f}%")
    print(f"RAW_TEMP平均表示    : {raw['pred']*100:.2f}%")
    print(f"1C実1着率           : {cur['actual']*100:.2f}%")
    print(f"CURRENT 実績-表示   : {cur['gap']*100:+.2f}pt")
    print(f"RAW_TEMP 実績-表示  : {raw['gap']*100:+.2f}pt")


def main():
    eval_start, eval_end, calib_start, calib_end, calib_days = parse_args()

    print("RAW_TEMP後のcourse別較正：ホールドアウト検証データを構築しています...")
    print(f"較正期間: {calib_start} ～ {calib_end} ({calib_days}日)")
    print(f"評価期間: {eval_start} ～ {eval_end}")

    print("\n[1/2] 較正期間でtau/kを選択中...")
    calib_rows, calib_meta = temp.build_rows(calib_start, calib_end)
    global_tau, _, global_table = temp.tune_global(calib_rows)
    raw_k, _, raw_table = temp.tune_raw(calib_rows, global_tau)

    print("\n[2/2] 評価期間をcourse付きで構築中...")
    boat_rows, race_rows, eval_meta = build_eval_rows(
        eval_start, eval_end, global_tau, raw_k
    )

    if not boat_rows or not race_rows:
        raise RuntimeError("評価可能データがありません")

    current = metric(boat_rows, "current")
    raw = metric(boat_rows, "raw")
    cur_ece, cur_mce = ece_for(boat_rows, "current")
    raw_ece, raw_mce = ece_for(boat_rows, "raw")

    print("\n" + "=" * 132)
    print("補正後1着率：RAW_TEMP後のcourse別較正 ホールドアウト検証")
    print("=" * 132)
    print(f"較正期間          : {calib_start} ～ {calib_end} ({len(calib_rows)}R)")
    print(f"評価期間          : {eval_start} ～ {eval_end} ({len(race_rows)}R)")
    print(f"GLOBAL tau        : {global_tau:.2f}")
    print(f"RAW k             : {raw_k:.2f}")
    print(
        f"RAW tau式         : clamp({global_tau:.2f} + {raw_k:.2f} * ln(raw_total), "
        f"{temp.TAU_MIN:.2f}, {temp.TAU_MAX:.2f})"
    )
    print(f"Top1順位変更      : {eval_meta['top_changed']}R")
    print("本番変更          : なし")

    print("\n【0. 全体確認】")
    print("方式        Brier      ECE       平均表示     実1着率    実績-表示")
    print("-" * 78)
    print(
        f"CURRENT     {current['brier']:.6f}   {cur_ece*100:>6.3f}pt    "
        f"{current['pred']*100:>7.2f}%    {current['actual']*100:>7.2f}%    {current['gap']*100:+7.2f}pt"
    )
    print(
        f"RAW_TEMP    {raw['brier']:.6f}   {raw_ece*100:>6.3f}pt    "
        f"{raw['pred']*100:>7.2f}%    {raw['actual']*100:>7.2f}%    {raw['gap']*100:+7.2f}pt"
    )

    print_same_cohort(boat_rows)
    print_raw_display_bins(boat_rows)
    print_course_summary(boat_rows)
    print_one_course_focus(boat_rows)

    print("\n【5. 判定の見方】")
    print("・最重要は1CでCURRENTの負gapがRAW_TEMP後に2期間とも縮むか")
    print("・同じCURRENT確率帯の同じ艇を比較する【1】を原因切り分けの主表とする")
    print("・【2】はRAW_TEMPを実際に表示した場合の確率帯calibrationを見る")
    print("・1C Brier/ECEも2期間とも改善するなら、1C専用補正を追加せずRAW_TEMPだけで十分な可能性が高い")
    print("・1Cだけ大きな負gapが残る場合は、raw_totalとは別の1C固有較正を次に検討する")
    print("・この検証だけでは本番へ反映しない。固定2期間を確認してから判断する")
    print("=" * 132)


if __name__ == "__main__":
    main()
