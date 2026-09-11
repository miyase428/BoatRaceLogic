#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
多摩川4コース No.4：4の攻め条件によって5コースがどの程度展開をもらうかを見る。

目的:
- 4コースの過去まくり率が高いほど、5コースの1/2/3着率がどう変わるか
- 4が3より平均ST順位上の時に、その傾向が強まるか
- 4-5 / 5-4 がどの条件で増えるか
- 4が実際に1着になった時、5が2着/3着へ来る割合
- 5が実際に1着になった時、4が2着/3着へ残る割合
- 参考として、4の実際の決まり手が「まくり」「まくり差し」の時の5の残り方

注意:
- 過去profileは対象レース当日を含めない。
- 12ヶ月 / 6ヶ月profileを別々に集計する。
- 「展開をもらう」は因果推定ではなく、結果分布の観察として扱う。

Usage:
  python3 analysis/analyze_tamagawa_lane4_lane5_development.py 2025-09-01 2026-09-09
"""

from __future__ import annotations

import csv
import sys
from collections import Counter, defaultdict
from datetime import date
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from analyze_tamagawa_boaters_hypothesis import (  # noqa: E402
    HISTORY_MONTHS,
    TechniqueHistoryIndex,
    load_history,
    load_racer_results,
    parse_date,
    rate_band,
    relation_label,
    required_terms,
    term_info_for_date,
)
from analyze_tamagawa_lane4_exacta_structure import (  # noqa: E402
    BANDS,
    RELATIONS,
    load_targets,
    pct,
)
from slit_validate_v2 import connect_db  # noqa: E402

VENUE_CODE = "TMG"
STRONG_RELATION = "内側より上"
STRONG_MAKURI_RATE = 15.0


def normalize_technique(value) -> str:
    s = str(value or "").strip()
    return s if s else "不明"


def load_winner_techniques(start_date: date, end_date: date) -> dict[str, str]:
    sql = """
WITH target_races AS (
    SELECT rm.race_code
    FROM boat_race.race_master rm
    WHERE rm.race_date BETWEEN %s::date AND %s::date
      AND SUBSTRING(rm.race_code, 9, 3) = %s
)
SELECT DISTINCT ON (rrd.race_code)
    rrd.race_code,
    TRIM(COALESCE(rrd.technique, '')) AS technique
FROM boat_race.race_result_detail rrd
JOIN target_races tr ON tr.race_code = rrd.race_code
WHERE TRIM(rrd.rank::text) = '1'
ORDER BY rrd.race_code
    """
    out = {}
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (start_date, end_date, VENUE_CODE))
            for race_code, technique in cur.fetchall():
                out[str(race_code)] = normalize_technique(technique)
    return out


def blank_stat() -> dict:
    return {
        "n": 0,
        "lane5_first": 0,
        "lane5_second": 0,
        "lane5_third": 0,
        "lane5_top2": 0,
        "lane5_top3": 0,
        "exacta_4_5": 0,
        "exacta_5_4": 0,
        "lane4_win_n": 0,
        "lane4_win_lane5_second": 0,
        "lane4_win_lane5_third": 0,
        "lane5_win_n": 0,
        "lane5_win_lane4_second": 0,
        "lane5_win_lane4_third": 0,
        "trifecta_4_5": Counter(),
        "trifecta_5_4": Counter(),
    }


def add_outcome(stat: dict, first: int, second: int, third: int | None) -> None:
    stat["n"] += 1

    if first == 5:
        stat["lane5_first"] += 1
    if second == 5:
        stat["lane5_second"] += 1
    if third == 5:
        stat["lane5_third"] += 1
    if first == 5 or second == 5:
        stat["lane5_top2"] += 1
    if first == 5 or second == 5 or third == 5:
        stat["lane5_top3"] += 1

    if first == 4 and second == 5:
        stat["exacta_4_5"] += 1
        if third in range(1, 7):
            stat["trifecta_4_5"][f"4-5-{third}"] += 1
    if first == 5 and second == 4:
        stat["exacta_5_4"] += 1
        if third in range(1, 7):
            stat["trifecta_5_4"][f"5-4-{third}"] += 1

    if first == 4:
        stat["lane4_win_n"] += 1
        if second == 5:
            stat["lane4_win_lane5_second"] += 1
        if third == 5:
            stat["lane4_win_lane5_third"] += 1

    if first == 5:
        stat["lane5_win_n"] += 1
        if second == 4:
            stat["lane5_win_lane4_second"] += 1
        if third == 4:
            stat["lane5_win_lane4_third"] += 1


def print_stat(label: str, stat: dict, show_pairs: bool = False) -> None:
    n = stat["n"]
    print(
        f"{label:<26} N={n:4d}  "
        f"5頭={pct(stat['lane5_first'], n):6.2f}%  "
        f"52着={pct(stat['lane5_second'], n):6.2f}%  "
        f"53着={pct(stat['lane5_third'], n):6.2f}%  "
        f"5-2連対={pct(stat['lane5_top2'], n):6.2f}%  "
        f"5-3連対={pct(stat['lane5_top3'], n):6.2f}%  "
        f"4-5={pct(stat['exacta_4_5'], n):6.2f}%  "
        f"5-4={pct(stat['exacta_5_4'], n):6.2f}%"
    )

    if not show_pairs or n <= 0:
        return

    n4 = stat["lane4_win_n"]
    n5 = stat["lane5_win_n"]
    print(
        f"  4頭 N={n4:3d}: 5が2着={pct(stat['lane4_win_lane5_second'], n4):5.1f}%"
        f"({stat['lane4_win_lane5_second']}) | 5が3着={pct(stat['lane4_win_lane5_third'], n4):5.1f}%"
        f"({stat['lane4_win_lane5_third']})"
    )
    print(
        f"  5頭 N={n5:3d}: 4が2着={pct(stat['lane5_win_lane4_second'], n5):5.1f}%"
        f"({stat['lane5_win_lane4_second']}) | 4が3着={pct(stat['lane5_win_lane4_third'], n5):5.1f}%"
        f"({stat['lane5_win_lane4_third']})"
    )

    if stat["trifecta_4_5"]:
        top = sorted(stat["trifecta_4_5"].items(), key=lambda kv: (-kv[1], kv[0]))[:6]
        print("  4-5-X: " + " | ".join(f"{k}={v}" for k, v in top))
    if stat["trifecta_5_4"]:
        top = sorted(stat["trifecta_5_4"].items(), key=lambda kv: (-kv[1], kv[0]))[:6]
        print("  5-4-X: " + " | ".join(f"{k}={v}" for k, v in top))


def analyze(start_date: date, end_date: date):
    print("多摩川 対象レースを読み込み中...", flush=True)
    races = load_targets(start_date, end_date)
    print(f"  対象レース候補: {len(races)}", flush=True)

    target_pids = sorted({b["player_id"] for r in races.values() for b in r["boats"]})
    terms = required_terms(start_date, end_date)
    print(f"racer_results 読み込み中... terms={','.join(terms)}", flush=True)
    racer = load_racer_results(terms)

    print("4コース決まり手履歴を一括読み込み中...", flush=True)
    history = load_history(start_date, end_date, target_pids)
    print(f"  決まり手履歴行: {len(history)}", flush=True)
    hist_index = TechniqueHistoryIndex(history)

    print("実際の勝者決まり手を読み込み中...", flush=True)
    winner_tech = load_winner_techniques(start_date, end_date)
    print(f"  決まり手取得レース: {len(winner_tech)}", flush=True)

    baseline = {m: blank_stat() for m in HISTORY_MONTHS}
    by_band = {(m, b): blank_stat() for m in HISTORY_MONTHS for b in BANDS}
    by_cross = {
        (m, rel, b): blank_stat()
        for m in HISTORY_MONTHS
        for rel in RELATIONS
        for b in BANDS
    }
    strong = {m: blank_stat() for m in HISTORY_MONTHS}

    # 実際に4が勝った時の決まり手別は期間全体で1回だけ集計。
    actual4 = defaultdict(blank_stat)

    skips = Counter()
    processed = 0
    rows = []

    for race_code, race in races.items():
        boats = race["boats"]
        if len(boats) != 6:
            skips["not_6_boats"] += 1
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
            skips["bad_entry_course"] += 1
            continue

        race_date = race["date"]
        term = term_info_for_date(race_date)
        ranks = {}
        missing = False
        for c in (3, 4):
            rr = racer.get((term, by_course[c]["player_id"]))
            if rr is None or rr[c]["avg_rank"] is None:
                missing = True
                break
            ranks[c] = float(rr[c]["avg_rank"])
        if missing:
            skips["missing_course_avg_rank"] += 1
            continue

        relation = relation_label(ranks[4], ranks[3])
        first, second, third = race["first"], race["second"], race["third"]
        processed += 1

        # 実際に4が1着なら、実決まり手別に5の残り方も見る。
        if first == 4:
            technique = winner_tech.get(str(race_code), "不明")
            add_outcome(actual4[technique], first, second, third)

        for months in HISTORY_MONTHS:
            p4 = hist_index.profile(by_course[4]["player_id"], 4, race_date, months)
            if p4["n"] <= 0:
                skips[f"history0_{months}m"] += 1
                continue

            makuri_rate = pct(p4["tech"]["まくり"], p4["n"])
            band = rate_band(makuri_rate)

            add_outcome(baseline[months], first, second, third)
            add_outcome(by_band[(months, band)], first, second, third)
            add_outcome(by_cross[(months, relation, band)], first, second, third)

            is_strong = relation == STRONG_RELATION and makuri_rate >= STRONG_MAKURI_RATE
            if is_strong:
                add_outcome(strong[months], first, second, third)

            rows.append({
                "history_months": months,
                "race_code": race_code,
                "race_date": race_date.isoformat(),
                "lane4_makuri_rate": makuri_rate,
                "lane4_makuri_band": band,
                "start_relation": relation,
                "is_strong": int(is_strong),
                "first_course": first,
                "second_course": second,
                "third_course": third if third is not None else "",
                "lane5_finish": 1 if first == 5 else 2 if second == 5 else 3 if third == 5 else 4,
                "exacta_4_5": int(first == 4 and second == 5),
                "exacta_5_4": int(first == 5 and second == 4),
                "winner_technique": winner_tech.get(str(race_code), "不明"),
            })

    return processed, skips, baseline, by_band, by_cross, strong, actual4, rows


def write_csv(start_date: date, end_date: date, rows: list[dict]) -> Path:
    out_dir = Path(__file__).resolve().parent / "output"
    out_dir.mkdir(parents=True, exist_ok=True)
    label = f"{start_date:%Y%m%d}_{end_date:%Y%m%d}"
    path = out_dir / f"tamagawa_lane4_lane5_development_{label}.csv"
    fields = [
        "history_months", "race_code", "race_date", "lane4_makuri_rate",
        "lane4_makuri_band", "start_relation", "is_strong",
        "first_course", "second_course", "third_course", "lane5_finish",
        "exacta_4_5", "exacta_5_4", "winner_technique",
    ]
    with path.open("w", newline="", encoding="utf-8-sig") as f:
        w = csv.DictWriter(f, fieldnames=fields)
        w.writeheader()
        w.writerows(rows)
    return path


def main() -> None:
    if len(sys.argv) != 3:
        print(
            "Usage: python3 analysis/analyze_tamagawa_lane4_lane5_development.py START_DATE END_DATE",
            file=sys.stderr,
        )
        sys.exit(1)

    start_date = parse_date(sys.argv[1])
    end_date = parse_date(sys.argv[2])
    if start_date > end_date:
        raise RuntimeError("開始日が終了日より後です")

    processed, skips, baseline, by_band, by_cross, strong, actual4, rows = analyze(start_date, end_date)

    print("\n" + "=" * 132)
    print("多摩川4コース No.4：4の攻め条件 → 5コースの展開利 / 4-5・5-4 構造")
    print("表示: 5頭 / 5の2着 / 5の3着 / 5の2連対 / 5の3連対 / 4-5 / 5-4")
    print("=" * 132)
    print(f"対象期間   : {start_date} ～ {end_date}")
    print(f"採用候補   : {processed}")
    if skips:
        print("スキップ   : " + ", ".join(f"{k}={v}" for k, v in skips.items()))

    for months in HISTORY_MONTHS:
        print("\n" + "-" * 132)
        print(f"【過去{months}ヶ月profile】")
        print("-" * 132)

        print("\n■ 全体基準")
        print_stat("BASELINE", baseline[months], show_pairs=True)

        print("\n■ 4コースまくり率帯：全ST順位関係")
        for band in BANDS:
            print_stat(f"4まくり {band}", by_band[(months, band)])

        print("\n■ 4が3より平均ST順位『上』 × 4コースまくり率帯")
        for band in BANDS:
            print_stat(f"4まくり {band} / ST上", by_cross[(months, STRONG_RELATION, band)])

        print("\n■ 強条件：4まくり15%以上 × ST上")
        print_stat("STRONG", strong[months], show_pairs=True)

    print("\n" + "-" * 132)
    print("【参考：4が実際に1着になった時の実決まり手別 → 5コース】")
    print("-" * 132)
    for technique in ("まくり", "まくり差し", "差し", "抜き", "恵まれ", "不明"):
        s = actual4[technique]
        if s["n"] > 0:
            print_stat(f"4実勝ち={technique}", s, show_pairs=True)

    path = write_csv(start_date, end_date, rows)
    print("\n" + "=" * 132)
    print(f"CSV出力: {path}")
    print("※ CSVには各レースを12ヶ月/6ヶ月profile別に出力しています。")
    print("=" * 132)


if __name__ == "__main__":
    main()
