#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
補正後1着率の過大/過小評価の原因候補を切り分ける診断。

1) 展示進入コース × FINAL確率帯の calibration
2) 基本1着率の正規化前/後を比較
3) 基本1着率の正規化前6艇合計別に FINAL Top1 の表示率と実勝率を比較
4) EX / SUM / SLIT 各100%正規化直前の合計と倍率を確認

本番ロジックは変更しない。読み取り検証のみ。
本番固定値:
- normal: EX_TOTAL beta=0.10
- AMG/TKY: EX_TOTAL3 beta=0.10
- SUM_RAW gamma=2.0
- slit alpha=0.25

Usage:
  python3 analysis/corrected_winrate_course_normalization_validate.py 2026-06-15 2026-07-14
  python3 analysis/corrected_winrate_course_normalization_validate.py 2026-07-15 2026-08-14
"""

from __future__ import annotations

import inspect
import math
import sys
from collections import Counter, defaultdict
from datetime import datetime, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import base_winrate_sum_compare as sumv
import corrected_winrate_credibility_validate as cred
from base_winrate_amg_tky_final_compare import build_prepared_no_straight
from base_winrate_slit_compare import (
    SLIT_BUFF_DAYS,
    build_slit_records,
    inclusive_window_start,
    learn_buff,
    load_lane_to_ex_course,
)

SPECIAL_PLACES = {"AMG", "TKY"}
SUM_GAMMA = 2.0
SLIT_ALPHA = 0.25

COURSE_BINS = (
    (0.00, 0.10),
    (0.10, 0.20),
    (0.20, 0.30),
    (0.30, 0.40),
    (0.40, 0.50),
    (0.50, 0.60),
    (0.60, 1.0000001),
)

BASE_TOTAL_BINS = (
    (0.00, 0.80),
    (0.80, 0.90),
    (0.90, 0.95),
    (0.95, 1.00),
    (1.00, 1.05),
    (1.05, 1.10),
    (1.10, 1.20),
    (1.20, 10.0),
)


def parse_date(value: str):
    return datetime.strptime(value, "%Y-%m-%d").date()


def place_of(race_code: str) -> str:
    code = str(race_code)
    return code[8:11] if len(code) >= 11 else "???"


def in_bin(value: float, low: float, high: float) -> bool:
    return low <= value < high


def pct(x: float) -> str:
    return f"{x * 100:6.2f}%"


def tracked_load_snapshots(start_date, end_date, special: bool):
    """
    sumv.load_snapshots 内の normalize を一時ラップし、
    BASE直前とEX直前の6艇合計・ベクトルを race_code ごとに記録する。
    """
    original_normalize = sumv.normalize
    original_build_prepared = sumv.build_prepared
    events = defaultdict(dict)

    def tracked_normalize(values):
        vals = [float(v) for v in values]
        out = original_normalize(vals)

        caller = inspect.currentframe().f_back
        caller_name = caller.f_code.co_name if caller is not None else ""
        frame = caller
        race_code = None
        found_process = False
        while frame is not None:
            if frame.f_code.co_name == "process_race" and "race_code" in frame.f_locals:
                race_code = str(frame.f_locals["race_code"])
                found_process = True
                break
            frame = frame.f_back

        if race_code is not None and found_process:
            if caller_name == "process_race":
                stage = "BASE"
            elif caller_name == "apply_centered_score":
                stage = "EX"
            else:
                stage = None

            if stage is not None:
                events[race_code][stage] = {
                    "raw": vals,
                    "raw_total": sum(vals),
                    "normalized": None if out is None else [float(x) for x in out],
                }
        return out

    sumv.normalize = tracked_normalize
    if special:
        sumv.build_prepared = build_prepared_no_straight

    try:
        snapshots, skipped, source = sumv.load_snapshots(start_date, end_date)
    finally:
        sumv.normalize = original_normalize
        sumv.build_prepared = original_build_prepared

    if special:
        snapshots = [s for s in snapshots if place_of(s.race_code) in SPECIAL_PLACES]
        events = {
            code: data for code, data in events.items()
            if place_of(code) in SPECIAL_PLACES
        }
    else:
        snapshots = [s for s in snapshots if place_of(s.race_code) not in SPECIAL_PLACES]
        events = {
            code: data for code, data in events.items()
            if place_of(code) not in SPECIAL_PLACES
        }

    return snapshots, events, skipped, source


def build_data(eval_start, eval_end):
    print("通常22場 snapshot + 正規化前値を構築中...")
    normal, normal_events, normal_skip, _ = tracked_load_snapshots(
        eval_start, eval_end, special=False
    )

    print("AMG/TKY snapshot + 正規化前値を構築中...")
    special, special_events, special_skip, _ = tracked_load_snapshots(
        eval_start, eval_end, special=True
    )

    print("スリットPID/buff構築中...")
    buff_end = eval_start - timedelta(days=1)
    buff_start = inclusive_window_start(buff_end, SLIT_BUFF_DAYS)
    records, slit_skip, _ = build_slit_records(buff_start, eval_end)
    buff, buff_rows, _ = learn_buff(records, buff_start, buff_end)
    course_map = load_lane_to_ex_course(eval_start, eval_end)

    attached, attach_skip, methods = cred.attach_exact(
        normal, special, records, course_map, eval_start, eval_end
    )

    events = dict(normal_events)
    events.update(special_events)
    return (
        attached,
        events,
        buff,
        buff_start,
        buff_end,
        len(buff_rows),
        normal_skip,
        special_skip,
        slit_skip,
        attach_skip,
        methods,
    )


def collect(attached, events, buff):
    boats_out = []
    races_out = []
    base_raw_rows = []

    for item in attached:
        snap, pid, method, cmap, sum_probs = item
        code = str(snap.race_code)
        boats = sorted(snap.boats, key=lambda b: b.lane)
        final_probs = cred.probs_for_stage(item, "FINAL", buff)
        if final_probs is None:
            continue

        event = events.get(code, {})
        base_event = event.get("BASE", {})
        ex_event = event.get("EX", {})
        base_raw = base_event.get("raw")
        base_norm = base_event.get("normalized")
        base_total = base_event.get("raw_total")
        ex_raw_total = ex_event.get("raw_total")

        if (
            not isinstance(base_raw, list) or len(base_raw) != 6
            or not isinstance(base_norm, list) or len(base_norm) != 6
            or base_total is None
        ):
            continue

        # SUM正規化直前
        ex_probs = [float(b.ex_total_prob) for b in boats]
        scores = [float(b.sum_scores["SUM_RAW"]) for b in boats]
        mean_score = sum(scores) / 6.0
        sum_raw_vec = [
            p * math.exp(SUM_GAMMA * (s - mean_score))
            for p, s in zip(ex_probs, scores)
        ]
        sum_raw_total = sum(sum_raw_vec)

        # SLIT正規化直前（本番 apply_slit_buff と同じ）
        slit_raw_vec = []
        for idx, b in enumerate(boats):
            course = int(cmap[b.lane])
            delta = float(buff[pid][course]["win"])
            slit_raw_vec.append(max(1e-6, float(sum_probs[idx]) + SLIT_ALPHA * delta))
        slit_raw_total = sum(slit_raw_vec)

        order = sorted(range(6), key=lambda i: (-final_probs[i], boats[i].lane))
        top_idx = order[0]

        races_out.append({
            "race_code": code,
            "place": place_of(code),
            "base_total": float(base_total),
            "base_scale": 1.0 / float(base_total) if float(base_total) > 0 else float("nan"),
            "ex_raw_total": float(ex_raw_total) if ex_raw_total is not None else float("nan"),
            "sum_raw_total": float(sum_raw_total),
            "slit_raw_total": float(slit_raw_total),
            "top_prob": float(final_probs[top_idx]),
            "top_hit": int(boats[top_idx].y == 1),
        })

        for idx, b in enumerate(boats):
            course = int(cmap[b.lane])
            boats_out.append({
                "race_code": code,
                "place": place_of(code),
                "lane": int(b.lane),
                "course": course,
                "y": int(b.y),
                "p": float(final_probs[idx]),
            })
            base_raw_rows.append({
                "race_code": code,
                "lane": int(b.lane),
                "y": int(b.y),
                "raw": float(base_raw[idx]),
                "norm": float(base_norm[idx]),
            })

    return boats_out, races_out, base_raw_rows


def metric_prob(rows, key):
    if not rows:
        return None
    brier = sum((float(r[key]) - int(r["y"])) ** 2 for r in rows) / len(rows)
    pred = sum(float(r[key]) for r in rows) / len(rows)
    actual = sum(int(r["y"]) for r in rows) / len(rows)
    return brier, pred, actual, actual - pred


def print_course_calibration(rows):
    print("\n【1. 展示進入コース × FINAL補正後1着率帯】")
    print("コース  確率帯       舟数   勝数    平均予測    実1着率    実績-予測")
    print("-" * 88)
    for course in range(1, 7):
        printed = False
        for low, high in COURSE_BINS:
            selected = [
                r for r in rows
                if r["course"] == course and in_bin(r["p"], low, high)
            ]
            if not selected:
                continue
            n = len(selected)
            wins = sum(r["y"] for r in selected)
            pred = sum(r["p"] for r in selected) / n
            actual = wins / n
            gap = actual - pred
            print(
                f" {course}C    {low*100:>2.0f}-{min(high,1.0)*100:<3.0f}%  "
                f"{n:>6} {wins:>6}    {pred*100:>7.2f}%    {actual*100:>7.2f}%    {gap*100:+8.2f}pt"
            )
            printed = True
        if printed:
            print()


def print_base_normalization(base_rows, race_rows):
    raw_m = metric_prob(base_rows, "raw")
    norm_m = metric_prob(base_rows, "norm")

    print("\n【2. 基本1着率：100%正規化前 vs 正規化後】")
    print("方式              Brier      平均表示     実1着率    実績-表示")
    print("-" * 78)
    if raw_m:
        print(
            f"正規化前(raw)     {raw_m[0]:.6f}     {raw_m[1]*100:7.2f}%     "
            f"{raw_m[2]*100:7.2f}%     {raw_m[3]*100:+7.2f}pt"
        )
    if norm_m:
        print(
            f"100%正規化後      {norm_m[0]:.6f}     {norm_m[1]*100:7.2f}%     "
            f"{norm_m[2]*100:7.2f}%     {norm_m[3]*100:+7.2f}pt"
        )

    totals = [r["base_total"] for r in race_rows]
    if totals:
        totals_sorted = sorted(totals)
        mid = len(totals_sorted) // 2
        median = (
            totals_sorted[mid]
            if len(totals_sorted) % 2 == 1
            else (totals_sorted[mid - 1] + totals_sorted[mid]) / 2.0
        )
        print("\n正規化前6艇合計")
        print(f"平均   : {sum(totals)/len(totals)*100:.2f}%")
        print(f"中央値 : {median*100:.2f}%")
        print(f"最小   : {min(totals)*100:.2f}%")
        print(f"最大   : {max(totals)*100:.2f}%")


def print_total_bins(race_rows):
    print("\n【3. 基本1着率の正規化前6艇合計別 → FINAL Top1】")
    print("raw合計帯      R数    平均raw合計   平均倍率   Top1表示    実Top1率    実績-表示")
    print("-" * 96)
    for low, high in BASE_TOTAL_BINS:
        selected = [r for r in race_rows if in_bin(r["base_total"], low, high)]
        if not selected:
            continue
        n = len(selected)
        avg_total = sum(r["base_total"] for r in selected) / n
        avg_scale = sum(r["base_scale"] for r in selected) / n
        pred = sum(r["top_prob"] for r in selected) / n
        actual = sum(r["top_hit"] for r in selected) / n
        gap = actual - pred
        hi_txt = f"{high*100:.0f}" if high < 2 else "∞"
        print(
            f"{low*100:>3.0f}-{hi_txt:<3}%  {n:>6}      {avg_total*100:>7.2f}%     "
            f"x{avg_scale:>6.3f}     {pred*100:>7.2f}%     {actual*100:>7.2f}%    {gap*100:+8.2f}pt"
        )


def summarize_stage_total(race_rows, key, label):
    vals = [r[key] for r in race_rows if math.isfinite(r[key]) and r[key] > 0]
    if not vals:
        return
    scale = [1.0 / x for x in vals]
    print(
        f"{label:<12} raw合計 平均={sum(vals)/len(vals)*100:7.2f}%  "
        f"最小={min(vals)*100:7.2f}%  最大={max(vals)*100:7.2f}%  "
        f"平均100%化倍率=x{sum(scale)/len(scale):.4f}"
    )


def print_stage_normalization(race_rows):
    print("\n【4. 各段階の100%正規化直前合計】")
    summarize_stage_total(race_rows, "base_total", "基本raw")
    summarize_stage_total(race_rows, "ex_raw_total", "EX補正後")
    summarize_stage_total(race_rows, "sum_raw_total", "SUM補正後")
    summarize_stage_total(race_rows, "slit_raw_total", "SLIT補正後")
    print("※EX/SUMは中心化した乗算補正なので、共通倍率は次の正規化で相殺される。")
    print("※SLITは加算型buffを含むため、正規化前合計の影響も別途確認対象。")


def main():
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/corrected_winrate_course_normalization_validate.py "
            "YYYY-MM-DD YYYY-MM-DD"
        )
        sys.exit(1)

    eval_start = parse_date(sys.argv[1])
    eval_end = parse_date(sys.argv[2])
    if eval_start > eval_end:
        raise RuntimeError("開始日が終了日より後です")

    (
        attached,
        events,
        buff,
        buff_start,
        buff_end,
        buff_n,
        normal_skip,
        special_skip,
        slit_skip,
        attach_skip,
        methods,
    ) = build_data(eval_start, eval_end)

    boat_rows, race_rows, base_rows = collect(attached, events, buff)
    if not race_rows:
        raise RuntimeError("評価可能レースが0件です")

    print("\n" + "=" * 118)
    print("補正後1着率：コース別較正 ＋ 100%正規化影響検証")
    print("=" * 118)
    print(f"評価期間          : {eval_start} ～ {eval_end}")
    print(f"評価レース        : {len(race_rows)}")
    print(f"評価舟数          : {len(boat_rows)}")
    print(f"スリットbuff学習 : {buff_start} ～ {buff_end} / {buff_n}R")
    print("本番固定値        : beta=0.10 / SUM gamma=2.0 / slit alpha=0.25")
    print("本番変更          : なし")

    print_course_calibration(boat_rows)
    print_base_normalization(base_rows, race_rows)
    print_total_bins(race_rows)
    print_stage_normalization(race_rows)

    print("\n【見方】")
    print("・コース×確率帯で同じ過大評価が2期間再現すれば、コース固有の較正ズレが有力")
    print("・raw→100%正規化後でBrier/較正が悪化するなら、比例正規化の影響を疑う")
    print("・raw合計が低い群ほどFINAL Top1過大評価が大きければ、正規化仮説を支持")
    print("・逆にraw合計帯と過大評価が無関係なら、主因はコース/基本式/補正強度など別要因")
    print("・この診断だけでは本番定数を変更しない。2期間で再現性を確認する")
    print("=" * 118)


if __name__ == "__main__":
    main()
