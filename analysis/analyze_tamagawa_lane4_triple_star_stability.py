#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
多摩川4コースの ★ / ★★ / ★★★候補を、長期・短期の両面で安定性確認する。

現行定義:
★
  - 各対象レース時点の過去12ヶ月・4コースまくり率 >= 15%
  - 4コース選手のcourse4平均ST順位が、3コース選手のcourse3平均ST順位より上

★★
  - ★
  - 4号艇の現行二次スコア >= 24
  - 二次トップとの差 <= 5

★★★候補（C5）
  - ★★
  - 4号艇の現行二次スコア >= 30
  - 攻め成分（展示ST点 + 直線点） >= 7

重要:
- 18ヶ月 / 24ヶ月は「集計対象期間」。まくり率profile自体は各レース時点の過去12ヶ月で固定。
- 短期は直近24ヶ月を6ヶ月ごとの非重複4ブロックに分ける。
- 12ヶ月/6ヶ月の重複profile比較ではなく、時系列の再現性を見る。

Usage:
  python3 analysis/analyze_tamagawa_lane4_triple_star_stability.py 2026-09-09

出力:
- 長期: 直近18ヶ月 / 24ヶ月
- 短期: 6ヶ月 × 4ブロック（非重複）
- 各期間で ★ / ★★ / ★★★ のN、4頭、2着、3着、2連対、3連対、1or4頭
- ★★→★★★ の4頭率上昇幅
- CSV: analysis/output/tamagawa_lane4_star_stability_YYYYMMDD.csv
"""

from __future__ import annotations

import csv
import sys
from collections import Counter
from datetime import date, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from analyze_tamagawa_boaters_hypothesis import (  # noqa: E402
    TechniqueHistoryIndex,
    load_history,
    load_racer_results,
    months_ago,
    parse_date,
    relation_label,
    required_terms,
    term_info_for_date,
)
from analyze_tamagawa_lane4_exacta_structure import load_targets, pct  # noqa: E402
from analyze_tamagawa_lane4_strong_condition_second_eval import (  # noqa: E402
    build_second_scores,
    load_avg_exhibition,
    load_exhibition,
)
from slit_validate_v2 import connect_db  # noqa: E402

STAR_MAKURI_RATE = 15.0
STAR_RELATION = "内側より上"
DOUBLE_MIN_SECOND_SCORE = 24.0
DOUBLE_MAX_TOP_GAP = 5.0
TRIPLE_MIN_SECOND_SCORE = 30.0
TRIPLE_MIN_ATTACK = 7.0
PROFILE_MONTHS = 12


def result_data_bounds() -> tuple[date | None, date | None]:
    sql = """
SELECT MIN(rm.race_date), MAX(rm.race_date)
FROM boat_race.race_master rm
JOIN boat_race.race_result_detail rrd
  ON rrd.race_code = rm.race_code
WHERE TRIM(rrd.rank::text) = '1'
    """
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql)
            row = cur.fetchone()
    if not row:
        return None, None
    return row[0], row[1]


def blank_stat() -> dict:
    return {
        "n": 0,
        "first": 0,
        "second": 0,
        "third": 0,
        "top2": 0,
        "top3": 0,
        "one_or_four_head": 0,
    }


def add_outcome(stat: dict, first: int, second: int, third: int | None) -> None:
    stat["n"] += 1
    if first == 4:
        stat["first"] += 1
    if second == 4:
        stat["second"] += 1
    if third == 4:
        stat["third"] += 1
    if first == 4 or second == 4:
        stat["top2"] += 1
    if first == 4 or second == 4 or third == 4:
        stat["top3"] += 1
    if first in (1, 4):
        stat["one_or_four_head"] += 1


def calc_windows(end_date: date) -> tuple[list[tuple[str, date, date]], list[tuple[str, date, date]]]:
    # 境界を end+1日 の半開区間で作ると、6ヶ月ブロックを重複なく切れる。
    anchor_exclusive = end_date + timedelta(days=1)

    start_24 = months_ago(anchor_exclusive, 24)
    start_18 = months_ago(anchor_exclusive, 18)

    long_windows = [
        ("直近18ヶ月", start_18, end_date),
        ("直近24ヶ月", start_24, end_date),
    ]

    short_windows = []
    labels = ("直近0-6ヶ月", "6-12ヶ月前", "12-18ヶ月前", "18-24ヶ月前")
    for i, label in enumerate(labels):
        block_end_exclusive = months_ago(anchor_exclusive, 6 * i)
        block_start = months_ago(anchor_exclusive, 6 * (i + 1))
        block_end = block_end_exclusive - timedelta(days=1)
        short_windows.append((label, block_start, block_end))

    return long_windows, short_windows


def tier_name(level: int) -> str:
    return {1: "★", 2: "★★", 3: "★★★"}[level]


def print_stat(label: str, stat: dict) -> None:
    n = stat["n"]
    small = "  [N小]" if 0 < n < 10 else ""
    print(
        f"{label:<6} N={n:4d}  "
        f"4頭={pct(stat['first'], n):6.2f}%  "
        f"42着={pct(stat['second'], n):6.2f}%  "
        f"43着={pct(stat['third'], n):6.2f}%  "
        f"4-2連対={pct(stat['top2'], n):6.2f}%  "
        f"4-3連対={pct(stat['top3'], n):6.2f}%  "
        f"1or4頭={pct(stat['one_or_four_head'], n):6.2f}%{small}"
    )


def aggregate_rows(rows: list[dict], start_date: date, end_date: date) -> dict[int, dict]:
    stats = {1: blank_stat(), 2: blank_stat(), 3: blank_stat()}
    for row in rows:
        d = row["race_date"]
        if d < start_date or d > end_date:
            continue
        level = int(row["star_level"])
        first = int(row["first_course"])
        second = int(row["second_course"])
        third = row["third_course"]
        third = int(third) if third not in (None, "") else None
        # 階層は包含関係。★★★は★★にも★にも含める。
        for threshold in range(1, level + 1):
            add_outcome(stats[threshold], first, second, third)
    return stats


def print_window(label: str, start_date: date, end_date: date, rows: list[dict]) -> None:
    stats = aggregate_rows(rows, start_date, end_date)
    print(f"\n■ {label}  {start_date} ～ {end_date}")
    for level in (1, 2, 3):
        print_stat(tier_name(level), stats[level])

    n2 = stats[2]["n"]
    n3 = stats[3]["n"]
    rate2 = pct(stats[2]["first"], n2)
    rate3 = pct(stats[3]["first"], n3)
    if n2 > 0 and n3 > 0:
        print(f"  ★★→★★★ 4頭率差 = {rate3 - rate2:+.2f}pt  (★★ {rate2:.2f}% → ★★★ {rate3:.2f}%)")
    else:
        print("  ★★→★★★ 4頭率差 = 判定不能（N不足）")


def analyze(end_date: date):
    long_windows, short_windows = calc_windows(end_date)
    target_start = min(w[1] for w in long_windows + short_windows)
    requested_history_start = months_ago(target_start, PROFILE_MONTHS)

    print("多摩川 対象レースを読み込み中...", flush=True)
    races = load_targets(target_start, end_date)
    print(f"  対象期間: {target_start} ～ {end_date}", flush=True)
    print(f"  対象レース候補: {len(races)}", flush=True)

    target_pids = sorted({b["player_id"] for r in races.values() for b in r["boats"]})
    terms = required_terms(target_start, end_date)
    print(f"racer_results 読み込み中... terms={','.join(terms)}", flush=True)
    racer = load_racer_results(terms)

    print("決まり手履歴を一括読み込み中...", flush=True)
    history = load_history(target_start, end_date, target_pids)
    print(f"  履歴行: {len(history)}", flush=True)
    hist_index = TechniqueHistoryIndex(history)

    print("展示データを一括読み込み中...", flush=True)
    ex_by_race = load_exhibition(target_start, end_date)
    avg_exhibition = load_avg_exhibition()
    print(f"  展示レース: {len(ex_by_race)} / 多摩川6ヶ月平均展示={avg_exhibition:.3f}", flush=True)

    db_min, db_max = result_data_bounds()

    rows = []
    skips = Counter()
    processed = 0

    for race_code, race in races.items():
        boats = race["boats"]
        if len(boats) != 6:
            skips["not_6_boats"] += 1
            continue

        by_course = {}
        bad = False
        for boat in boats:
            c = boat["course"]
            if c not in range(1, 7) or c in by_course:
                bad = True
                break
            by_course[c] = boat
        if bad or set(by_course) != set(range(1, 7)):
            skips["bad_entry_course"] += 1
            continue

        race_date = race["date"]
        term = term_info_for_date(race_date)

        ranks = {}
        missing_rank = False
        for c in (3, 4):
            rr = racer.get((term, by_course[c]["player_id"]))
            if rr is None or rr[c]["avg_rank"] is None:
                missing_rank = True
                break
            ranks[c] = float(rr[c]["avg_rank"])
        if missing_rank:
            skips["missing_course_avg_rank"] += 1
            continue

        pid4 = by_course[4]["player_id"]
        p4 = hist_index.profile(pid4, 4, race_date, PROFILE_MONTHS)
        if p4["n"] <= 0:
            skips["history0_12m"] += 1
            continue

        makuri_rate = pct(p4["tech"]["まくり"], p4["n"])
        relation = relation_label(ranks[4], ranks[3])
        if relation != STAR_RELATION or makuri_rate < STAR_MAKURI_RATE:
            continue

        second_map = build_second_scores(ex_by_race.get(race_code, []), avg_exhibition)
        if second_map is None:
            skips["missing_or_bad_exhibition"] += 1
            continue

        lane4_second = second_map.get(pid4)
        if lane4_second is None:
            skips["lane4_second_score_missing"] += 1
            continue

        second_score = float(lane4_second["final_2nd_score"])
        gap = float(lane4_second["gap_to_top"])
        attack = float(lane4_second["attack_potential"])

        level = 1
        if second_score >= DOUBLE_MIN_SECOND_SCORE and gap <= DOUBLE_MAX_TOP_GAP:
            level = 2
            if second_score >= TRIPLE_MIN_SECOND_SCORE and attack >= TRIPLE_MIN_ATTACK:
                level = 3

        first, second, third = race["first"], race["second"], race["third"]
        processed += 1
        rows.append({
            "race_code": race_code,
            "race_date": race_date,
            "star_level": level,
            "lane4_player_id": pid4,
            "lane4_makuri_history_n": p4["n"],
            "lane4_makuri_rate": makuri_rate,
            "lane3_avg_rank": ranks[3],
            "lane4_avg_rank": ranks[4],
            "lane4_second_score": second_score,
            "lane4_gap_to_top": gap,
            "lane4_attack_potential": attack,
            "lane4_st_score": float(lane4_second["st_score"]),
            "lane4_straight_score": float(lane4_second["straight_score"]),
            "first_course": first,
            "second_course": second,
            "third_course": third if third is not None else "",
        })

    return {
        "target_start": target_start,
        "requested_history_start": requested_history_start,
        "long_windows": long_windows,
        "short_windows": short_windows,
        "rows": rows,
        "skips": skips,
        "processed": processed,
        "db_min": db_min,
        "db_max": db_max,
    }


def write_csv(end_date: date, rows: list[dict]) -> Path:
    out_dir = Path(__file__).resolve().parent / "output"
    out_dir.mkdir(parents=True, exist_ok=True)
    path = out_dir / f"tamagawa_lane4_star_stability_{end_date:%Y%m%d}.csv"
    fields = [
        "race_code", "race_date", "star_level", "lane4_player_id",
        "lane4_makuri_history_n", "lane4_makuri_rate",
        "lane3_avg_rank", "lane4_avg_rank",
        "lane4_second_score", "lane4_gap_to_top", "lane4_attack_potential",
        "lane4_st_score", "lane4_straight_score",
        "first_course", "second_course", "third_course",
    ]
    with path.open("w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(f, fieldnames=fields)
        writer.writeheader()
        for row in rows:
            out = dict(row)
            out["race_date"] = row["race_date"].isoformat()
            writer.writerow(out)
    return path


def main() -> None:
    if len(sys.argv) != 2:
        print(
            "Usage: python3 analysis/analyze_tamagawa_lane4_triple_star_stability.py END_DATE",
            file=sys.stderr,
        )
        sys.exit(1)

    end_date = parse_date(sys.argv[1])
    result = analyze(end_date)

    print("\n" + "=" * 144)
    print("多摩川4コース：★ / ★★ / ★★★候補 長期・6ヶ月ブロック安定性検証")
    print("★   = 4まくり率15%以上 + 4が3より平均ST順位上")
    print("★★  = ★ + 二次24以上 + TOP差5以内")
    print("★★★ = ★★ + 二次30以上 + 攻め成分7以上（C5・まだ候補）")
    print("※ まくり率は全期間とも各レース時点の過去12ヶ月profileで固定。18/24ヶ月は集計期間です。")
    print("=" * 144)
    print(f"検証対象      : {result['target_start']} ～ {end_date}")
    print(f"必要履歴開始  : {result['requested_history_start']}（最古対象レースの12ヶ月前）")
    print(f"DB結果データ  : {result['db_min']} ～ {result['db_max']}")
    print(f"★該当行       : {len(result['rows'])}")
    if result["skips"]:
        print("スキップ      : " + ", ".join(f"{k}={v}" for k, v in result["skips"].items()))

    if result["db_min"] is not None and result["db_min"] > result["requested_history_start"]:
        print("【注意】DB結果データ開始日が必要履歴開始日より新しいため、古い期間の12ヶ月profileが欠ける可能性があります。")

    print("\n" + "-" * 144)
    print("【長期確認】")
    print("-" * 144)
    for label, start_date, window_end in result["long_windows"]:
        print_window(label, start_date, window_end, result["rows"])

    print("\n" + "-" * 144)
    print("【短期確認：6ヶ月 × 4 非重複ブロック】")
    print("-" * 144)
    for label, start_date, window_end in result["short_windows"]:
        print_window(label, start_date, window_end, result["rows"])

    path = write_csv(end_date, result["rows"])
    print("\n" + "=" * 144)
    print(f"CSV出力: {path}")
    print("判定ポイント:")
    print("  1) 18ヶ月・24ヶ月でも★★★の4頭率が★★より上か")
    print("  2) 6ヶ月4ブロックのうち何ブロックで★★★が★★を上回るか")
    print("  3) 4頭率だけでなくN・2連対・3連対も崩れていないか")
    print("※ ★★★はこの結果を見てから確定します。表示・買い目・本命ロジックは変更しません。")
    print("=" * 144)


if __name__ == "__main__":
    main()
