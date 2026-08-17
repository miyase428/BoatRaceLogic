#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
補正後1着率 STEP 8-3: EX_TOTAL + SUM_RAW 補正後へスリットbuffを追加する効果比較

固定済み基準:
- 基本1着率: BB_MEDIUM Kpc=20 / Kpvc=10
- 展示進入へリマップ
- EX_TOTAL beta=+0.100
- SUM_RAW gamma=+2.0

スリット:
- 現行C_ST_RANK予測PID（6艇プロフィール不足時は展示STのみへfallback）
- PID×展示進入コースのwin liftを K=40 で縮小、cap ±0.08
- buffは評価期間より前だけで学習

過学習回避:
1) 直前31日のさらに前180日で旧buffを学習
2) 直前31日で slit scale(alpha) を調整
3) 評価直前180日でbuffを再学習
4) 評価期間へ適用

Usage:
  python3 analysis/base_winrate_slit_compare.py 2026-06-15 2026-07-14
  python3 analysis/base_winrate_slit_compare.py 2026-07-15 2026-08-14
"""

from __future__ import annotations

import math
import sys
from collections import Counter, defaultdict
from datetime import datetime, timedelta
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
THEORY_DIR = REPO_ROOT / "theories" / "course_correction"
sys.path.insert(0, str(Path(__file__).resolve().parent))
sys.path.insert(0, str(THEORY_DIR))

from slit_validate_v2 import connect_db
from classify_slit_pattern import classify_slit_pattern
from slit_racer_compare import (
    load_racer_results,
    load_races,
    required_terms,
    term_info_for_date,
    safe_course_profile,
    make_method_st,
    build_finish,
    load_settings,
)
from slit_buff_rebuild_validate import (
    calc_baseline,
    calc_pattern_rates,
    calc_buff,
)
from base_winrate_sum_compare import (
    load_snapshots,
    apply_centered_score,
    normalize,
)

SUM_GAMMA = 2.0
SLIT_BUFF_DAYS = 180
DEFAULT_CALIB_DAYS = 31
ALPHA_GRID = (0.0, 0.25, 0.50, 0.75, 1.00, 1.25, 1.50)

PATTERN_NAMES = {
    1: "通常型", 2: "横一線", 3: "1・2先行", 4: "スロー先行",
    5: "壁なし", 6: "2・3遅れ", 7: "中凹み", 8: "3号艇攻め",
    9: "中ぶくれ", 10: "1号艇遅れ", 11: "外側先行", 12: "ダッシュ先行",
}


def parse_date(s):
    return datetime.strptime(s, "%Y-%m-%d").date()


def parse_args():
    if len(sys.argv) not in (3, 4):
        print("Usage: python3 analysis/base_winrate_slit_compare.py YYYY-MM-DD YYYY-MM-DD [CALIB_DAYS]")
        sys.exit(1)
    eval_start = parse_date(sys.argv[1])
    eval_end = parse_date(sys.argv[2])
    if eval_start > eval_end:
        raise RuntimeError("開始日が終了日より後です")
    calib_days = int(sys.argv[3]) if len(sys.argv) == 4 else DEFAULT_CALIB_DAYS
    if calib_days < 7:
        raise RuntimeError("CALIB_DAYSは7日以上にしてください")
    calib_start = eval_start - timedelta(days=calib_days)
    calib_end = eval_start - timedelta(days=1)
    return eval_start, eval_end, calib_start, calib_end, calib_days


def inclusive_window_start(end_date, days):
    return end_date - timedelta(days=days - 1)


def race_date_from_code(race_code):
    return datetime.strptime(str(race_code)[:8], "%Y%m%d").date()


def build_slit_records(start_date, end_date):
    """期間内レースの予測PIDと結果をまとめる。予測は本番fallbackを再現。"""
    terms = required_terms(start_date, end_date)
    racer = load_racer_results(terms)
    races = load_races(start_date.strftime("%Y%m%d"), end_date.strftime("%Y%m%d"))
    settings = load_settings()

    records = {}
    skip = Counter()
    method_counts = Counter()

    for race_code in sorted(races):
        boats = races[race_code]
        if len(boats) != 6 or len({b["player_id"] for b in boats}) != 6:
            skip["not_6_entry"] += 1
            continue

        by_course = {}
        bad = False
        for b in boats:
            c = b["course"]
            if c not in range(1, 7) or c in by_course:
                bad = True
                break
            by_course[c] = b
        if bad or set(by_course) != set(range(1, 7)):
            skip["not_6_exhibition"] += 1
            continue
        if any(by_course[c]["ex_st"] is None for c in range(1, 7)):
            skip["missing_ex_st"] += 1
            continue

        race_dt = race_date_from_code(race_code)
        term = term_info_for_date(race_dt)
        profiles = []
        missing_profile = False
        for c in range(1, 7):
            rr = racer.get((term, by_course[c]["player_id"]))
            p = safe_course_profile(rr, c) if rr is not None else None
            profiles.append(p)
            if p is None:
                missing_profile = True

        ex_st = [by_course[c]["ex_st"] for c in range(1, 7)]
        if missing_profile:
            predicted_st = list(ex_st)
            method = "A_EX_FALLBACK"
        else:
            predicted_st = make_method_st(ex_st, profiles)["C_ST_RANK"]
            method = "C_ST_RANK"

        pattern_id, _ = classify_slit_pattern(predicted_st, settings)
        finish = build_finish(by_course)
        if finish is None:
            skip["bad_result_for_buff"] += 1

        records[str(race_code)] = {
            "date": race_dt,
            "pid": int(pattern_id),
            "method": method,
            "finish": finish,
        }
        method_counts[method] += 1

    return records, skip, method_counts


def rows_for_period(records, start_date, end_date):
    rows = []
    freq = Counter()
    for race_code, r in records.items():
        if not (start_date <= r["date"] <= end_date):
            continue
        if r["finish"] is None:
            continue
        rows.append((race_code, r["pid"], r["finish"]))
        freq[r["pid"]] += 1
    return rows, freq


def learn_buff(records, start_date, end_date):
    rows, freq = rows_for_period(records, start_date, end_date)
    if not rows:
        raise RuntimeError(f"スリットbuff学習レースが0件です: {start_date} ～ {end_date}")
    baseline, _ = calc_baseline(rows)
    pattern_rates, pattern_counts = calc_pattern_rates(rows)
    buff = calc_buff(pattern_rates, baseline, pattern_counts)
    return buff, rows, freq


def load_lane_to_ex_course(start_date, end_date):
    sql = """
        SELECT
            re.race_code,
            re.lane_number,
            el.entry_course
        FROM boat_race.race_entry re
        JOIN boat_race.race_master rm
          ON rm.race_code = re.race_code
        LEFT JOIN LATERAL (
            SELECT x.entry_course
            FROM boat_race.exhibition_live x
            WHERE x.race_code = re.race_code
              AND x.player_id = re.player_id
            LIMIT 1
        ) el ON TRUE
        WHERE rm.race_date BETWEEN %s::date AND %s::date
        ORDER BY re.race_code, re.lane_number
    """
    out = defaultdict(dict)
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (start_date.isoformat(), end_date.isoformat()))
            for race_code, lane, ex_course in cur.fetchall():
                try:
                    lane_i = int(lane)
                    course_i = int(ex_course)
                except (TypeError, ValueError):
                    continue
                if lane_i in range(1, 7) and course_i in range(1, 7):
                    out[str(race_code)][lane_i] = course_i
    return out


def sum_corrected_probs(snapshot):
    boats = sorted(snapshot.boats, key=lambda b: b.lane)
    base = [b.ex_total_prob for b in boats]
    scores = [b.sum_scores["SUM_RAW"] for b in boats]
    return apply_centered_score(base, scores, SUM_GAMMA)


def attach_races(snapshots, records, course_map, start_date, end_date):
    attached = []
    skip = Counter()
    methods = Counter()
    for snap in snapshots:
        if not (start_date <= snap.race_date <= end_date):
            continue
        rec = records.get(str(snap.race_code))
        if rec is None:
            skip["slit_prediction_missing"] += 1
            continue
        cmap = course_map.get(str(snap.race_code), {})
        if set(cmap) != set(range(1, 7)) or set(cmap.values()) != set(range(1, 7)):
            skip["ex_course_map_incomplete"] += 1
            continue
        p = sum_corrected_probs(snap)
        if p is None:
            skip["sum_normalize_failed"] += 1
            continue
        attached.append((snap, rec["pid"], rec["method"], cmap, p))
        methods[rec["method"]] += 1
    return attached, skip, methods


def apply_slit_buff(base_probs, boats, pid, cmap, buff, alpha):
    adjusted = []
    for idx, b in enumerate(boats):
        course = cmap[b.lane]
        delta = float(buff[pid][course]["win"])
        adjusted.append(max(1e-6, base_probs[idx] + alpha * delta))
    return normalize(adjusted)


def evaluate(attached, buff=None, alpha=0.0):
    if not attached:
        return None
    brier = 0.0
    logloss = 0.0
    top1 = 0
    n = 0

    for snap, pid, method, cmap, base_probs in attached:
        boats = sorted(snap.boats, key=lambda b: b.lane)
        probs = base_probs if buff is None else apply_slit_buff(base_probs, boats, pid, cmap, buff, alpha)
        if probs is None:
            continue
        for p, b in zip(probs, boats):
            cp = min(max(p, 1e-9), 1.0 - 1e-9)
            brier += (p - b.y) ** 2
            logloss += -(b.y * math.log(cp) + (1 - b.y) * math.log(1 - cp))
        best = sorted(range(6), key=lambda i: (-probs[i], boats[i].lane))[0]
        if boats[best].y == 1:
            top1 += 1
        n += 1

    if n == 0:
        return None
    return {
        "races": n,
        "brier": brier / (n * 6),
        "logloss": logloss / (n * 6),
        "top1": top1 / n,
    }


def tune_alpha(calib_attached, old_buff):
    base = evaluate(calib_attached)
    best = None
    table = []
    for alpha in ALPHA_GRID:
        m = evaluate(calib_attached, old_buff, alpha)
        table.append((alpha, m))
        key = (m["brier"], m["logloss"], abs(alpha - 1.0), alpha)
        if best is None or key < best[0]:
            best = (key, alpha, m)
    return best[1], best[2], base, table


def fmt_delta(value, base):
    if base == 0:
        return "-"
    return f"{(value - base) / base * 100:+.3f}%"


def main():
    eval_start, eval_end, calib_start, calib_end, calib_days = parse_args()

    old_buff_end = calib_start - timedelta(days=1)
    old_buff_start = inclusive_window_start(old_buff_end, SLIT_BUFF_DAYS)
    final_buff_end = eval_start - timedelta(days=1)
    final_buff_start = inclusive_window_start(final_buff_end, SLIT_BUFF_DAYS)
    record_start = min(old_buff_start, calib_start)

    print("補正後1着率 STEP8-3 スリット追加効果の時系列データを構築しています...")

    snapshots, sum_skip, _ = load_snapshots(calib_start, eval_end)
    records, slit_skip, _ = build_slit_records(record_start, eval_end)
    course_map = load_lane_to_ex_course(calib_start, eval_end)

    old_buff, old_rows, old_freq = learn_buff(records, old_buff_start, old_buff_end)
    final_buff, final_rows, final_freq = learn_buff(records, final_buff_start, final_buff_end)

    calib_attached, calib_skip, calib_methods = attach_races(
        snapshots, records, course_map, calib_start, calib_end
    )
    eval_attached, eval_skip, eval_methods = attach_races(
        snapshots, records, course_map, eval_start, eval_end
    )

    if not calib_attached:
        raise RuntimeError("scale調整期間の評価可能レースが0件です")
    if not eval_attached:
        raise RuntimeError("評価期間の評価可能レースが0件です")

    alpha, alpha_train, calib_base, alpha_table = tune_alpha(calib_attached, old_buff)

    eval_base = evaluate(eval_attached)
    eval_scale1 = evaluate(eval_attached, final_buff, 1.0)
    eval_tuned = evaluate(eval_attached, final_buff, alpha)

    print("=" * 136)
    print("補正後1着率 STEP 8-3：EX_TOTAL + SUM_RAW 補正後 + スリット補正 比較")
    print("=" * 136)
    print(f"旧buff学習        : {old_buff_start} ～ {old_buff_end} ({SLIT_BUFF_DAYS}日)")
    print(f"scale調整          : {calib_start} ～ {calib_end} ({calib_days}日)")
    print(f"最終buff学習      : {final_buff_start} ～ {final_buff_end} ({SLIT_BUFF_DAYS}日)")
    print(f"評価期間          : {eval_start} ～ {eval_end}")
    print(f"旧buff学習レース  : {len(old_rows)}")
    print(f"最終buff学習レース: {len(final_rows)}")
    print(f"scale調整レース   : {len(calib_attached)}")
    print(f"評価レース        : {len(eval_attached)}")
    print("基準補正          : 展示進入リマップ + EX_TOTAL beta=+0.100 + SUM_RAW gamma=+2.0")
    print("スリットbuff      : predicted PID × 展示進入C win lift × n/(n+40), cap=±0.08")
    print("PID予測            : C_ST_RANK / プロフィール不足時 A_EX_FALLBACK")
    print("本番変更          : なし")

    print("\n【直前期間 scale(alpha) 比較】")
    print("alpha       Brier      vs 基準       LogLoss     Top1率")
    print("-" * 80)
    for a, m in alpha_table:
        print(
            f"{a:>5.2f}    {m['brier']:.6f}   {fmt_delta(m['brier'], calib_base['brier']):>10}   "
            f"{m['logloss']:.6f}   {m['top1']*100:>6.2f}%"
        )
    print(f"採用scale          : alpha={alpha:.2f}")

    print("\n【評価期間 比較】")
    print("方式              alpha       Brier      vs 基準       LogLoss     Top1率")
    print("-" * 100)
    rows = [
        ("EX_SUM_ONLY", "-", eval_base),
        ("SLIT_SCALE1", "1.00", eval_scale1),
        ("SLIT_TUNED", f"{alpha:.2f}", eval_tuned),
    ]
    for name, atext, m in sorted(rows, key=lambda x: x[2]["brier"]):
        print(
            f"{name:<18} {atext:<8} {m['brier']:.6f}   {fmt_delta(m['brier'], eval_base['brier']):>10}   "
            f"{m['logloss']:.6f}   {m['top1']*100:>6.2f}%"
        )

    print("\n【PID予測方式・評価期間】")
    total_methods = sum(eval_methods.values())
    for k in ("C_ST_RANK", "A_EX_FALLBACK"):
        n = eval_methods[k]
        pct = n / total_methods * 100 if total_methods else 0.0
        print(f"{k:<16}: {n:>5} ({pct:6.2f}%)")

    print("\n【最終buff学習 PID件数】")
    for pid in range(1, 13):
        print(f"{pid:>2} {PATTERN_NAMES[pid]:<12}: {final_freq[pid]:>5}")

    print("\n【skip】")
    merged = Counter()
    merged.update({f"sum_{k}": v for k, v in sum_skip.items()})
    merged.update({f"slit_{k}": v for k, v in slit_skip.items()})
    merged.update({f"calib_{k}": v for k, v in calib_skip.items()})
    merged.update({f"eval_{k}": v for k, v in eval_skip.items()})
    for k in sorted(merged):
        print(f"{k:<36}: {merged[k]}")

    print("\n【判定の見方】")
    print("・最重要はEX_SUM_ONLYよりSLIT_TUNEDのBrierが下がるか")
    print("・alpha=0なら、EX_TOTAL+SUMまで入れた後にはスリット追加価値がほぼない")
    print("・SLIT_SCALE1も改善するなら、現行buffをそのまま確率へ足しても安定しやすい")
    print("・2期間とも改善し、alphaも大きくぶれなければ補正後1着率へ採用候補")
    print("・片期間だけ改善なら、スリットは既存どおり補助表示に留める")
    print("=" * 136)


if __name__ == "__main__":
    main()
