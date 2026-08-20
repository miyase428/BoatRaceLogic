#!/usr/bin/env python3
# -*- coding: utf-8 -*-

from __future__ import annotations

import json
import sys
import time
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
FORECAST_DIR = REPO_ROOT / "forecast"
sys.path.insert(0, str(FORECAST_DIR))

import corrected_winrate_live_exact as exact  # noqa: E402

live = exact.live
SPECIAL = {"AMG", "TKY"}


def timed(label, fn, rows):
    t0 = time.perf_counter()
    value = fn()
    sec = time.perf_counter() - t0
    rows.append((label, sec))
    print(f"{label:<34} {sec * 1000:10.1f} ms  ({sec:6.2f} sec)")
    return value


def main() -> int:
    if len(sys.argv) != 2:
        print("Usage: python3 analysis/corrected_winrate_stage_profile.py YYYYMMDDXXXRR", file=sys.stderr)
        return 1

    race_code = sys.argv[1].strip().upper()
    if len(race_code) < 13:
        print("race_codeが不正です", file=sys.stderr)
        return 1

    place_code = race_code[8:11]
    if place_code in SPECIAL:
        # import時にAMG/TKY専用の展示・SUM関数へ追加差し替えされる。
        import corrected_winrate_live_exact_amg_tky  # noqa: F401,E402

    rows = []
    all0 = time.perf_counter()

    print("=" * 90)
    print("補正後1着率 Python内部 段階計測")
    print("=" * 90)
    print(f"race_code : {race_code}")
    print(f"place     : {place_code}\n")

    with live.connect_db() as conn:
        target = timed(
            "1 load_target",
            lambda: live.load_target(conn, race_code),
            rows,
        )
        target_date, detected_place, stadium_name, boats = target

        exhibition = timed(
            "2 load_current_exhibition",
            lambda: live.load_current_exhibition(conn, race_code),
            rows,
        )

        base_remap, base_detail = timed(
            "3 build_remapped_base",
            lambda: live.build_remapped_base(
                conn, race_code, target_date, detected_place, boats, exhibition
            ),
            rows,
        )

        venue_avg_ex = timed(
            "4 venue_exhibition_average",
            lambda: live.venue_exhibition_average(
                conn, race_code, target_date, detected_place
            ),
            rows,
        )

        ex_scores, ex_detail = timed(
            "5 calc_ex_total_scores",
            lambda: live.calc_ex_total_scores(exhibition, venue_avg_ex),
            rows,
        )
        ex_probs = live.apply_centered_score(base_remap, ex_scores, live.EX_TOTAL_BETA)
        if ex_probs is None:
            raise RuntimeError("EX_TOTAL補正を正規化できません")

        features = live.load_sum_features()
        feature_cols = features.get(detected_place)
        if not isinstance(feature_cols, list) or len(feature_cols) != 3:
            raise RuntimeError(f"SUM features設定がありません: {detected_place}")

        sum_stats = timed(
            "6 load_sum_stats",
            lambda: live.load_sum_stats(
                conn, race_code, target_date, detected_place, feature_cols
            ),
            rows,
        )

        sum_scores, sum_detail = timed(
            "7 current_sum_scores",
            lambda: live.current_sum_scores(exhibition, feature_cols, sum_stats),
            rows,
        )
        sum_probs = live.apply_centered_score(ex_probs, sum_scores, live.SUM_GAMMA)
        if sum_probs is None:
            raise RuntimeError("SUM補正を正規化できません")

    predict, buff_data, slit_buff = timed(
        "8 slit_prediction_and_buff",
        lambda: live.slit_prediction_and_buff(race_code, target_date),
        rows,
    )

    final_probs, raw_buffs = timed(
        "9 apply_slit",
        lambda: live.apply_slit(sum_probs, exhibition, slit_buff),
        rows,
    )

    total = time.perf_counter() - all0
    sum_sec = sum(sec for _, sec in rows)

    print("\n" + "-" * 90)
    print("重い順")
    for label, sec in sorted(rows, key=lambda x: x[1], reverse=True):
        pct = sec / sum_sec * 100.0 if sum_sec > 0 else 0.0
        print(f"{label:<34} {sec:8.2f} sec  {pct:5.1f}%")
    print("-" * 90)
    print(f"段階計測合計 : {sum_sec:.2f} sec")
    print(f"実測全体     : {total:.2f} sec")
    print(f"SUM features : {feature_cols}")
    print(f"slit PID     : {predict.get('pattern_id')}")
    print(f"final total  : {sum(final_probs) * 100.0:.6f}%")
    print("=" * 90)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
