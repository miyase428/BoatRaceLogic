#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
穴目予想：展開候補（3～5C攻め起点）をTRAIN/P3で検証する。

目的
----
既存の穴警戒HIGHを固定したまま、point-in-time決まり手率から
「どのコースが攻め起点になりそうか」を1つだけ出せるか確認する。

固定する穴警戒HIGH（C2）
-------------------------
- AI本命 = 1C
- CURRENT本命 != 1C
- イン補正後1着率 < 50%

展開候補の作り方（今回固定）
----------------------------
- 対象コース: 3C / 4C / 5C
- 6month point-in-time sample_n >= 10 のみ候補
- 攻め率 = まくり + まくり差し
- 攻め率が最大のコースを「攻め起点」候補とする
- 表示技は、そのコースの まくり / まくり差し の高い方
- 同率時は内側コース優先、技同率は「まくり」優先

評価
----
- HIGH全体 / HIGHかつ1C敗戦時で候補コースの実1着率・実3連対率
- 実勝者コースが3～5Cだったレースで候補コースが一致した率
- 候補コース + 表示技が勝者コース/決まり手と同時一致した率（参考）
- 候補攻め率帯 <15 / 15-20 / 20-30 / >=30% で再現性を見る
- 候補コース分布、表示技分布

重要
----
- actual_1st_course / winner_technique は評価ラベルにのみ使う。
- P3を見て対象コース、sample条件、攻め率定義、帯境界を変更しない。
- 穴頭候補とは別物。本命頭の差し替えや自動買い目には接続しない。

Usage:
python3 analysis/upset_attack_scenario_validate.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv \
  analysis/output/final_prediction_boats_fast_cached_20260815_20260822.csv \
  analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
  analysis/output/kimarite_analysis_dataset_20260815_20260822.csv
"""

from __future__ import annotations

import csv
import sys
from collections import Counter
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import upset_in_remaining_validate as remain

ATTACK_COURSES = (3, 4, 5)
MIN_SAMPLE = 10
ATTACK_BANDS = (
    (None, 15.0, "<15%"),
    (15.0, 20.0, "15-20%"),
    (20.0, 30.0, "20-30%"),
    (30.0, None, ">=30%"),
)


def to_int(v, default=0):
    try:
        return int(float(v))
    except (TypeError, ValueError):
        return default


def to_float(v, default=0.0):
    try:
        return float(v)
    except (TypeError, ValueError):
        return default


def formal(row):
    return (
        to_int(row.get("result_top3_course_complete")) == 1
        and to_int(row.get("result_boat_match")) == 1
    )


def load_kimarite(path):
    p = Path(path)
    if not p.is_file():
        raise RuntimeError(f"kimarite datasetがありません: {path}")
    out = {}
    with p.open("r", encoding="utf-8-sig", newline="") as fh:
        for row in csv.DictReader(fh):
            code = str(row.get("race_code") or "").strip()
            if code and formal(row):
                out[code] = row
    return out


def normalize_technique(value):
    s = str(value or "").strip().replace(" ", "").replace("　", "")
    s = s.replace("捲り差し", "まくり差し").replace("捲差し", "まくり差し")
    s = s.replace("捲り", "まくり")
    return s


def attack_value(row, course):
    makuri = to_float(row.get(f"c{course}_6m_makuri"))
    mz = to_float(row.get(f"c{course}_6m_makurizashi"))
    return makuri + mz, makuri, mz


def pick_attack(row):
    candidates = []
    for c in ATTACK_COURSES:
        n = to_int(row.get(f"c{c}_6m_sample_n"))
        if n < MIN_SAMPLE:
            continue
        total, makuri, mz = attack_value(row, c)
        technique = "まくり" if makuri >= mz else "まくり差し"
        technique_rate = makuri if makuri >= mz else mz
        candidates.append((total, -c, c, technique, technique_rate, n))

    if not candidates:
        return None

    candidates.sort(reverse=True)
    total, _negc, course, technique, technique_rate, sample_n = candidates[0]
    return {
        "course": int(course),
        "attack_rate": float(total),
        "technique": technique,
        "technique_rate": float(technique_rate),
        "sample_n": int(sample_n),
    }


def attack_band(value):
    x = float(value)
    for lo, hi, label in ATTACK_BANDS:
        if lo is not None and x < lo:
            continue
        if hi is not None and x >= hi:
            continue
        return label
    return "?"


def attach(high_rows, kimarite_map):
    out = []
    skip = Counter()
    for high in high_rows:
        code = str(high["race_code"])
        k = kimarite_map.get(code)
        if k is None:
            skip["kimarite_missing"] += 1
            continue
        attack = pick_attack(k)
        if attack is None:
            skip["attack_sample_missing"] += 1
            continue

        actual1 = to_int(k.get("actual_1st_course"))
        actual2 = to_int(k.get("actual_2nd_course"))
        actual3 = to_int(k.get("actual_3rd_course"))
        winner_tech = normalize_technique(k.get("winner_technique"))
        if actual1 not in range(1, 7):
            skip["actual_missing"] += 1
            continue

        candidate_course = int(attack["course"])
        course_win = actual1 == candidate_course
        course_top3 = candidate_course in (actual1, actual2, actual3)
        outer_winner = actual1 in ATTACK_COURSES
        exact_tech = course_win and winner_tech == attack["technique"]

        out.append({
            **high,
            **attack,
            "actual_1st_course": actual1,
            "actual_2nd_course": actual2,
            "actual_3rd_course": actual3,
            "winner_technique": winner_tech,
            "candidate_course_win": course_win,
            "candidate_course_top3": course_top3,
            "winner_is_3to5": outer_winner,
            "exact_technique_match": exact_tech,
            "attack_band": attack_band(attack["attack_rate"]),
        })
        skip["ready"] += 1
    return out, skip


def metrics(rows):
    n = len(rows)
    if not n:
        return {
            "n": 0, "in_fail": 0, "course_win": 0, "course_top3": 0,
            "fail_course_win": 0, "outer_winner": 0, "outer_match": 0,
            "exact_tech": 0,
        }
    in_fail_rows = [r for r in rows if r["in_failed"]]
    outer_rows = [r for r in rows if r["winner_is_3to5"]]
    return {
        "n": n,
        "in_fail": len(in_fail_rows),
        "course_win": sum(r["candidate_course_win"] for r in rows),
        "course_top3": sum(r["candidate_course_top3"] for r in rows),
        "fail_course_win": sum(r["candidate_course_win"] for r in in_fail_rows),
        "outer_winner": len(outer_rows),
        "outer_match": sum(r["candidate_course_win"] for r in outer_rows),
        "exact_tech": sum(r["exact_technique_match"] for r in rows),
    }


def pct(n, d):
    return 100.0 * n / d if d else 0.0


def print_overall(title, rows):
    m = metrics(rows)
    print(f"\n【{title}】")
    print(f"候補作成R={m['n']} / 1C敗戦={m['in_fail']}")
    print(f"候補コース実1着      : {m['course_win']}/{m['n']} = {pct(m['course_win'], m['n']):.2f}%")
    print(f"候補コース実3連対    : {m['course_top3']}/{m['n']} = {pct(m['course_top3'], m['n']):.2f}%")
    print(f"1C敗戦時候補コース頭 : {m['fail_course_win']}/{m['in_fail']} = {pct(m['fail_course_win'], m['in_fail']):.2f}%")
    print(
        f"実勝者が3～5C時の候補一致: {m['outer_match']}/{m['outer_winner']} = "
        f"{pct(m['outer_match'], m['outer_winner']):.2f}%"
    )
    print(f"候補コース+技まで一致 : {m['exact_tech']}/{m['n']} = {pct(m['exact_tech'], m['n']):.2f}% ※参考")
    return m


def print_band_table(title, rows):
    print(f"\n【{title}: 候補攻め率帯】")
    print("帯          R数  1C敗退率  候補頭率  敗戦時候補頭  候補3連対  3～5C勝者時一致")
    print("-" * 92)
    for _lo, _hi, label in ATTACK_BANDS:
        part = [r for r in rows if r["attack_band"] == label]
        m = metrics(part)
        print(
            f"{label:<10} {m['n']:>4d}  "
            f"{pct(m['in_fail'], m['n']):>8.2f}%  "
            f"{pct(m['course_win'], m['n']):>7.2f}%  "
            f"{pct(m['fail_course_win'], m['in_fail']):>11.2f}%  "
            f"{pct(m['course_top3'], m['n']):>8.2f}%  "
            f"{pct(m['outer_match'], m['outer_winner']):>12.2f}%"
        )


def print_distribution(title, rows):
    course = Counter(int(r["course"]) for r in rows)
    tech = Counter(str(r["technique"]) for r in rows)
    n = len(rows)
    print(f"\n【{title}: 候補分布】")
    print("攻め起点: " + " / ".join(f"{c}C={course[c]}({pct(course[c], n):.1f}%)" for c in ATTACK_COURSES))
    print("表示技  : " + " / ".join(f"{k}={v}({pct(v, n):.1f}%)" for k, v in tech.items()))


def main():
    if len(sys.argv) != 6:
        print(
            "Usage: python3 analysis/upset_attack_scenario_validate.py "
            "P1_BOATS P2_BOATS P3_BOATS TRAIN_KIMARITE P3_KIMARITE"
        )
        sys.exit(1)

    p1, p2, p3, train_k, p3_k = sys.argv[1:]

    print("穴警戒HIGHを固定し、3～5Cのpoint-in-time攻め率から展開候補を検証中...")
    high = remain.build_all(p1, p2, p3)
    train_map = load_kimarite(train_k)
    p3_map = load_kimarite(p3_k)

    train_rows, train_skip = attach(high["train"], train_map)
    p3_rows, p3_skip = attach(high["p3"], p3_map)

    print("=" * 112)
    print("穴目予想：展開候補（3～5C攻め起点） TRAIN / 完全未来検証")
    print("=" * 112)
    print(f"TRAIN : {high['train_start']} ～ {high['train_end']}")
    print(f"P3    : {high['p3_start']} ～ {high['p3_end']} 完全未来")
    print("HIGH  : AI本命=1C / CURRENT本命!=1C / イン補正後1着率<50%（固定）")
    print("候補  : 3～5Cの6m攻め率（まくり+まくり差し）最大 / sample_n>=10")
    print("技表示: 候補コース内で高い方（まくり or まくり差し）")
    print("本番Web変更: なし")

    print_overall("TRAIN", train_rows)
    print_overall("P3完全未来", p3_rows)
    print_band_table("TRAIN", train_rows)
    print_band_table("P3完全未来", p3_rows)
    print_distribution("TRAIN", train_rows)
    print_distribution("P3完全未来", p3_rows)

    print("\n【join参考】")
    print("TRAIN:", dict(train_skip))
    print("P3   :", dict(p3_skip))

    print("\n【判断ポイント】")
    print("1. 最優先はP3で候補攻め率が高い帯ほど1C敗退率・候補コース頭率が上がるか")
    print("2. 実勝者が3～5Cだった時の候補コース一致率がTRAIN/P3で同方向か")
    print("3. 技までの完全一致は参考。展開候補は『攻め起点』を主目的とする")
    print("4. P3を見て対象コース・sample条件・帯境界を変えない")
    print("5. 本命差し替え、自動買い目、穴頭候補ロジックには接続しない")
    print("=" * 112)


if __name__ == "__main__":
    main()
