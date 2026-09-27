#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""2026年のAI1着率・補正後1着率を完全共通母集団で比較する。

AIは月ごとに当月以前だけで学習し直す。補正後率は評価開始前180日の
スリットbuffだけを使い、各レースの他の履歴も当該レース以前に限定する。
"""

from __future__ import annotations

import sys
from datetime import date, timedelta
from pathlib import Path

import numpy as np

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "forecast"))
sys.path.insert(0, str(ROOT / "analysis"))

import corrected_winrate_credibility_validate as cred  # noqa: E402
from ai_winrate_features import normalized_race_probabilities  # noqa: E402
from base_winrate_slit_compare import (  # noqa: E402
    SLIT_BUFF_DAYS,
    build_slit_records,
    inclusive_window_start,
    learn_buff,
    load_lane_to_ex_course,
)
from train_ai_winrate import (  # noqa: E402
    COURSE, IS_WINNER, PLACE_CODE, RACE_CODE, RACE_DATE,
    build_training_matrix, load_rows, make_model,
)

START = date(2026, 1, 1)
END_EXCLUSIVE = date(2026, 9, 23)


def next_month(value: date) -> date:
    return date(value.year + (value.month == 12), (value.month % 12) + 1, 1)


def final_corrected_hits(attached, buff, eligible_codes: set[str]) -> dict[str, int]:
    hits: dict[str, int] = {}
    for item in attached:
        snap = item[0]
        code = str(snap.race_code)
        if code not in eligible_codes:
            continue
        boats = sorted(snap.boats, key=lambda boat: boat.lane)
        probabilities = cred.probs_for_stage(item, "FINAL", buff)
        if probabilities is None:
            continue
        top = max(range(6), key=lambda index: (probabilities[index], -boats[index].lane))
        hits[code] = int(boats[top].y == 1)
    return hits


def main() -> int:
    print("AI対象レースを確定しています…", flush=True)
    all_rows = load_rows("2025-01-01", END_EXCLUSIVE.isoformat())
    eligible_codes = {
        str(row[RACE_CODE]) for row in all_rows
        if START.isoformat() <= str(row[RACE_DATE]) < END_EXCLUSIVE.isoformat()
    }
    del all_rows

    print("補正後1着率を当時の履歴だけで再現しています…", flush=True)
    eval_end = END_EXCLUSIVE - timedelta(days=1)
    normal, special, _, _ = cred.load_exact_snapshots(START, eval_end)
    buff_end = START - timedelta(days=1)
    buff_start = inclusive_window_start(buff_end, SLIT_BUFF_DAYS)
    records, _, _ = build_slit_records(buff_start, eval_end)
    buff, _, _ = learn_buff(records, buff_start, buff_end)
    course_map = load_lane_to_ex_course(START, eval_end)
    attached, _, _ = cred.attach_exact(normal, special, records, course_map, START, eval_end)
    corrected_by_code = final_corrected_hits(attached, buff, eligible_codes)
    common_codes = set(corrected_by_code)
    print(f"共通母集団: {len(common_codes)}R。月別AIを再学習しています…", flush=True)

    monthly = []
    contingency = {
        "both_hit": 0,
        "ai_only_hit": 0,
        "corrected_only_hit": 0,
        "both_miss": 0,
    }
    current = START
    while current < END_EXCLUSIVE:
        month_end = min(next_month(current), END_EXCLUSIVE)
        rows = load_rows("2025-01-01", month_end.isoformat())
        train_rows = [row for row in rows if str(row[RACE_DATE]) < current.isoformat()]
        test_rows = [
            row for row in rows
            if current.isoformat() <= str(row[RACE_DATE]) < month_end.isoformat()
            and str(row[RACE_CODE]) in common_codes
        ]
        places = sorted({str(row[PLACE_CODE]) for row in rows})
        place_to_id = {place: index + 1 for index, place in enumerate(places)}
        model = make_model()
        model.fit(
            build_training_matrix(train_rows, place_to_id),
            np.asarray([bool(row[IS_WINNER]) for row in train_rows], dtype=np.int8),
        )
        matrix = build_training_matrix(test_rows, place_to_id)
        raw = model.predict_proba(matrix)[:, 1]
        codes = [str(row[RACE_CODE]) for row in test_rows]
        probs = normalized_race_probabilities(raw, codes)
        labels = np.asarray([bool(row[IS_WINNER]) for row in test_rows], dtype=np.int8)
        by_code: dict[str, list[int]] = {}
        for index, code in enumerate(codes):
            by_code.setdefault(code, []).append(index)

        ai_hits = course1_hits = corrected_hits = 0
        for code, indices in by_code.items():
            top = max(indices, key=lambda index: (probs[index], -int(test_rows[index][COURSE])))
            ai_hit = int(labels[top])
            ai_hits += ai_hit
            course1 = next(index for index in indices if int(test_rows[index][COURSE]) == 1)
            course1_hits += int(labels[course1])
            corrected_hit = corrected_by_code[code]
            corrected_hits += corrected_hit
            if ai_hit and corrected_hit:
                contingency["both_hit"] += 1
            elif ai_hit:
                contingency["ai_only_hit"] += 1
            elif corrected_hit:
                contingency["corrected_only_hit"] += 1
            else:
                contingency["both_miss"] += 1

        races = len(by_code)
        record = {
            "month": current.strftime("%Y-%m"), "races": races,
            "ai_hits": ai_hits, "corrected_hits": corrected_hits, "course1_hits": course1_hits,
        }
        monthly.append(record)
        print(
            f"{record['month']} {races}R  AI {ai_hits/races*100:.2f}%  "
            f"補正 {corrected_hits/races*100:.2f}%  1C {course1_hits/races*100:.2f}%",
            flush=True,
        )
        current = month_end

    races = sum(row["races"] for row in monthly)
    output = {
        "races": races,
        "monthly": monthly,
        "ai_rate": sum(row["ai_hits"] for row in monthly) / races,
        "corrected_rate": sum(row["corrected_hits"] for row in monthly) / races,
        "course1_rate": sum(row["course1_hits"] for row in monthly) / races,
        "ai_vs_corrected_contingency": contingency,
    }
    print("RESULT_JSON=" + __import__("json").dumps(output, ensure_ascii=False), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
