#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
展示進入変更が現行Webロジックへ与える影響を診断する。

目的:
- 過去の展示進入変更レースを抽出する。
- 艇番 -> 展示進入コース と コース -> 艇番 の両方を明示する。
- 現行 fetchTenjiTest() の引数の意味が、進入変更時に正しいか確認する。
- 現行 PredictionLogic の決まり手参照（艇番=コース前提）が何艇ずれるか確認する。

このスクリプトは診断のみで、本番ロジックは変更しない。

Usage:
    python3 analysis/diagnose_entry_change_impact.py 2026-07-15 2026-08-14
    python3 analysis/diagnose_entry_change_impact.py 2026-07-15 2026-08-14 30
"""

from __future__ import annotations

import sys
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from slit_validate_v2 import connect_db  # noqa: E402


def parse_date(value: str):
    return datetime.strptime(value, "%Y-%m-%d").date()


def parse_args():
    if len(sys.argv) not in (3, 4):
        print(
            "Usage: python3 analysis/diagnose_entry_change_impact.py "
            "YYYY-MM-DD YYYY-MM-DD [MAX_EXAMPLES]"
        )
        sys.exit(1)

    start = parse_date(sys.argv[1])
    end = parse_date(sys.argv[2])
    if start > end:
        raise RuntimeError("開始日が終了日より後です")

    max_examples = int(sys.argv[3]) if len(sys.argv) == 4 else 20
    if max_examples < 0:
        raise RuntimeError("MAX_EXAMPLESは0以上にしてください")

    return start, end, max_examples


def place_of(race_code: str) -> str:
    code = str(race_code)
    return code[8:11] if len(code) >= 11 else "???"


def mapping_string(mapping: dict[int, int]) -> str:
    return "".join(str(mapping[i]) for i in range(1, 7))


def moved_boats(lane_to_course: dict[int, int]) -> list[int]:
    return [lane for lane in range(1, 7) if lane_to_course[lane] != lane]


def cycle_type(lane_to_course: dict[int, int]) -> str:
    """艇番->コースの置換を循環長で簡易分類する。"""
    visited = set()
    cycles = []

    for start in range(1, 7):
        if start in visited:
            continue

        cur = start
        cycle = []
        while cur not in visited:
            visited.add(cur)
            cycle.append(cur)
            cur = lane_to_course[cur]

        if len(cycle) > 1:
            cycles.append(len(cycle))

    if not cycles:
        return "identity"

    cycles.sort(reverse=True)
    if cycles == [2]:
        return "1-swap"
    if cycles == [2, 2]:
        return "2-swaps"
    if cycles == [2, 2, 2]:
        return "3-swaps"
    if cycles == [3]:
        return "3-cycle"
    if cycles == [4]:
        return "4-cycle"
    if cycles == [5]:
        return "5-cycle"
    if cycles == [6]:
        return "6-cycle"

    return "+".join(str(x) for x in cycles) + "-cycles"


def load_races(start, end):
    sql = """
        SELECT
            rm.race_date,
            re.race_code,
            re.lane_number,
            re.player_id::text,
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
          AND EXISTS (
                SELECT 1
                FROM boat_race.race_result_detail winner
                WHERE winner.race_code = re.race_code
                  AND winner.rank = '1'
          )
        ORDER BY rm.race_date, re.race_code, re.lane_number
    """

    races = defaultdict(list)
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (start.isoformat(), end.isoformat()))
            for race_date, race_code, lane, player_id, entry_course in cur.fetchall():
                races[str(race_code)].append(
                    {
                        "date": race_date,
                        "lane": lane,
                        "player_id": str(player_id or "").strip(),
                        "course": entry_course,
                    }
                )

    return races


def prepare_mapping(rows):
    if len(rows) != 6:
        return None, "entry_not_6"

    lane_to_course = {}
    seen_courses = set()

    for row in rows:
        try:
            lane = int(row["lane"])
            course = int(row["course"])
        except (TypeError, ValueError):
            return None, "exhibition_course_missing"

        if lane not in range(1, 7):
            return None, "lane_invalid"
        if course not in range(1, 7):
            return None, "course_invalid"
        if lane in lane_to_course:
            return None, "lane_duplicate"
        if course in seen_courses:
            return None, "course_duplicate"

        lane_to_course[lane] = course
        seen_courses.add(course)

    if set(lane_to_course) != set(range(1, 7)):
        return None, "lane_incomplete"
    if seen_courses != set(range(1, 7)):
        return None, "course_incomplete"

    return lane_to_course, None


def main():
    start, end, max_examples = parse_args()
    races = load_races(start, end)

    valid = []
    skipped = Counter()

    for race_code, rows in races.items():
        lane_to_course, err = prepare_mapping(rows)
        if err:
            skipped[err] += 1
            continue

        course_to_lane = {course: lane for lane, course in lane_to_course.items()}
        moved = moved_boats(lane_to_course)

        # 現行 ApiClient::fetchTenjiTest() が渡している値。
        # tenji1..6 = 各「艇番」の展示進入コース。
        current_tenji_test_args = [lane_to_course[lane] for lane in range(1, 7)]

        # tenji_test.php が実際に期待している値。
        # tenji1..6 = 各「コース」にいる艇番。
        required_tenji_test_args = [course_to_lane[course] for course in range(1, 7)]

        valid.append(
            {
                "race_code": race_code,
                "place": place_of(race_code),
                "lane_to_course": lane_to_course,
                "course_to_lane": course_to_lane,
                "moved": moved,
                "cycle": cycle_type(lane_to_course),
                "current_tenji_test_args": current_tenji_test_args,
                "required_tenji_test_args": required_tenji_test_args,
                "tenji_test_correct": current_tenji_test_args == required_tenji_test_args,
            }
        )

    changed = [r for r in valid if r["moved"]]
    unchanged = [r for r in valid if not r["moved"]]

    tenji_test_wrong = [r for r in changed if not r["tenji_test_correct"]]
    tenji_test_accidentally_ok = [r for r in changed if r["tenji_test_correct"]]

    moved_cell_total = sum(len(r["moved"]) for r in changed)
    cycle_counts = Counter(r["cycle"] for r in changed)
    place_counts = Counter(r["place"] for r in changed)

    print("=" * 104)
    print("展示進入変更 → 現行Webロジック影響診断")
    print("=" * 104)
    print(f"期間                         : {start} ～ {end}")
    print(f"完了レース取得               : {len(races)}")
    print(f"展示進入1～6が完全なレース    : {len(valid)}")
    print(f"進入変更なし                 : {len(unchanged)}")
    print(
        f"進入変更あり                 : {len(changed)} "
        f"({(len(changed) / len(valid) * 100 if valid else 0):.2f}%)"
    )
    print()

    print("【最終予想・決まり手】")
    print("現行 PredictionLogic は kimarite_data[艇番] を参照している。")
    print("正しくは kimarite_data[展示進入コース] を艇ごとに参照する必要がある。")
    print(f"進入変更で決まり手参照がずれるレース : {len(changed)}")
    print(f"ずれる艇セル合計                     : {moved_cell_total}")
    if changed:
        print(f"1レース平均の移動艇数                 : {moved_cell_total / len(changed):.2f}")
    print()

    print("【tenji_test.php 3連対率マッピング】")
    print("現行caller : tenji1..6 = 艇番ごとの展示コース")
    print("APIの意味   : tenji1..6 = コースごとの艇番")
    print(
        f"進入変更でも偶然一致するレース         : {len(tenji_test_accidentally_ok)}"
    )
    print(
        f"実際に別選手を参照しうるレース         : {len(tenji_test_wrong)}"
    )
    if changed:
        print(
            "進入変更Rに占めるtenji_test不一致率     : "
            f"{len(tenji_test_wrong) / len(changed) * 100:.2f}%"
        )
    print()

    print("【SUM / スリット】")
    print("SUM     : tenji_course（展示進入）でコース別マスタを参照 → 計算は進入対応済み")
    print("スリット: exhibition_live.entry_course順で予測 → 計算は進入対応済み")
    print("残課題  : 艇番⇔コースの画面表示と『○号艇』表記を『○コース』へ整理")
    print()

    print("【進入変更パターン】")
    if cycle_counts:
        for name, n in cycle_counts.most_common():
            print(f"{name:<18}: {n:>5}")
    else:
        print("なし")
    print()

    print("【進入変更が多い場・参考】")
    if place_counts:
        for place, n in place_counts.most_common(24):
            print(f"{place:<6}: {n:>5}")
    else:
        print("なし")

    if max_examples > 0:
        print("\n【進入変更レース例】")
        print(
            "race_code       場   艇→C    C→艇    cycle       tenji_test  "
            "現caller  正caller  決まり手ズレ艇"
        )
        print("-" * 104)

        # tenji_test不一致を優先して例示する。
        examples = sorted(
            changed,
            key=lambda r: (r["tenji_test_correct"], r["race_code"]),
        )[:max_examples]

        for r in examples:
            l2c = mapping_string(r["lane_to_course"])
            c2l = mapping_string(r["course_to_lane"])
            current = "".join(str(x) for x in r["current_tenji_test_args"])
            required = "".join(str(x) for x in r["required_tenji_test_args"])
            moved = "".join(str(x) for x in r["moved"])
            tt = "OK" if r["tenji_test_correct"] else "NG"
            print(
                f"{r['race_code']:<15} {r['place']:<4} {l2c:<7} {c2l:<7} "
                f"{r['cycle']:<11} {tt:<10} {current:<8} {required:<8} {moved}"
            )

    print("\n【次の修正候補】")
    print("1. 展示取得後に lane→course / course→lane の進入マップを1回だけ構築")
    print("2. kimarite_api へ展示進入 lane→course を自動反映し、最終予想は各艇のcourseで参照")
    print("3. tenji_test は course→lane を渡すか、API自体を player_id基準へ変更")
    print("4. SUM/スリットは計算維持、画面だけ艇番⇔コース対応を明示")
    print("5. 進入変更なしレースは現行結果と完全一致することを回帰確認")

    print("\n【skip】")
    if skipped:
        for key in sorted(skipped):
            print(f"{key:<30}: {skipped[key]}")
    else:
        print("なし")

    print("=" * 104)


if __name__ == "__main__":
    main()
