#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
決まり手再構築の最終予想への影響を、旧方式 vs 再構築方式で比較する健康診断。

- CSVの一次スコア・二次スコア・実着順は固定。
- 決まり手履歴だけを各レース日より前6ヶ月で再計算する。
- 旧方式: race_result_detail本人行ベース。
- 新方式: race_entry母集団 + 完了レース + result_detail優先 / 欠損時exhibition_live。
- typeBonusを旧/新で差し替え、現行STEP4を両方式へ同条件で再適用する。
- 本番ロジックは変更しない。調整・閾値変更もしない。

使い方:
  python3 analysis/kimarite_rebuild_final_rank_validate.py \
    analysis/output/final_prediction_boats_20260715_20260814.csv
"""

from __future__ import annotations

import sys
from collections import Counter
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from compare_kimarite_bonus_final_rank import (  # noqa: E402
    add_head,
    apply_step4,
    as_float,
    blank_head_stat,
    build_finish,
    load_csv,
    load_entry_players,
    pct,
    pp,
    summarize_head,
)
from kimarite_rebuild_two_period_validate import (  # noqa: E402
    HISTORY_END,
    HISTORY_START,
    HistoryIndex,
    decide_type,
    load_old_history,
    load_rebuilt_history,
)
from slit_validate_v2 import connect_db  # noqa: E402


def load_entry_courses(start_date: str, end_date: str) -> dict[tuple[str, str], int]:
    """対象期間の race_code×player_id -> 展示進入コース。"""
    sql = """
        SELECT DISTINCT ON (re.race_code, re.player_id)
            re.race_code,
            re.player_id::text,
            el.entry_course::integer
        FROM boat_race.race_entry re
        JOIN boat_race.race_master rm
          ON rm.race_code = re.race_code
        JOIN boat_race.exhibition_live el
          ON el.race_code = re.race_code
         AND el.player_id = re.player_id
        WHERE rm.race_date BETWEEN %s::date AND %s::date
          AND el.entry_course BETWEEN 1 AND 6
        ORDER BY re.race_code, re.player_id
    """
    out = {}
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (start_date, end_date))
            for race_code, player_id, course in cur.fetchall():
                out[(str(race_code), str(player_id).strip())] = int(course)
    return out


def load_race_dates(start_date: str, end_date: str) -> dict[str, object]:
    sql = """
        SELECT race_code, race_date
        FROM boat_race.race_master
        WHERE race_date BETWEEN %s::date AND %s::date
    """
    out = {}
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (start_date, end_date))
            for race_code, race_date in cur.fetchall():
                out[str(race_code)] = race_date
    return out


def head_summary_line(label: str, stat: dict) -> str:
    s = summarize_head(stat)
    return (
        f"{label:<14} {pct(s['win'])}  {pct(s['top2'])}  "
        f"{pct(s['top3'])}    {s['avg_rank']:.3f}"
    )


def main() -> None:
    if len(sys.argv) != 2:
        print(
            "Usage: python3 analysis/kimarite_rebuild_final_rank_validate.py "
            "analysis/output/final_prediction_boats_YYYYMMDD_YYYYMMDD.csv"
        )
        sys.exit(1)

    csv_path = Path(sys.argv[1])
    if not csv_path.exists():
        raise RuntimeError(f"CSVがありません: {csv_path}")

    races, start_date, end_date = load_csv(csv_path)
    start_d = datetime.strptime(start_date, "%Y-%m-%d").date()
    end_d = datetime.strptime(end_date, "%Y-%m-%d").date()
    history_start_d = datetime.strptime(HISTORY_START, "%Y-%m-%d").date()
    history_end_d = datetime.strptime(HISTORY_END, "%Y-%m-%d").date()

    if end_d > history_end_d:
        raise RuntimeError(
            f"この検証の履歴固定範囲は {HISTORY_START}～{HISTORY_END} です。"
            f" CSV終端 {end_date} は範囲外です。"
        )
    if start_d < history_start_d:
        raise RuntimeError("CSV開始日が履歴固定範囲より前です")

    print("対象レースの選手・展示進入を読み込み中...", flush=True)
    entry_players = load_entry_players(start_date, end_date)
    entry_courses = load_entry_courses(start_date, end_date)
    race_dates = load_race_dates(start_date, end_date)

    print("旧決まり手履歴を1回だけ読み込み中...", flush=True)
    with connect_db() as conn:
        old_rows = load_old_history(conn)
    print(f"  旧履歴行: {len(old_rows)}", flush=True)

    print("再構築決まり手履歴を1回だけ読み込み中...", flush=True)
    with connect_db() as conn:
        new_rows = load_rebuilt_history(conn)
    print(f"  新履歴行: {len(new_rows)}", flush=True)

    print("履歴インデックスを作成中...", flush=True)
    old_idx = HistoryIndex(old_rows)
    new_idx = HistoryIndex(new_rows)

    stats = {
        "OLD": blank_head_stat(),
        "NEW": blank_head_stat(),
    }
    pos_stats = {
        key: {p: blank_head_stat() for p in range(1, 7)}
        for key in stats
    }
    winner_capture = {
        key: {1: 0, 2: 0, 3: 0}
        for key in stats
    }
    top3_overlap_sum = {key: 0 for key in stats}
    mae_sum = {key: 0.0 for key in stats}

    skip = Counter()
    bonus_change = Counter()
    type_change = Counter()
    old_bonus_dist = Counter()
    new_bonus_dist = Counter()

    processed = 0
    any_order_changed = 0
    head_changed = 0
    second_changed = 0
    head_h2h = Counter()
    second_h2h = Counter()
    win_switch = Counter()
    top3_switch = Counter()
    override_count = 0

    for race_code in sorted(races):
        raw_rows = races[race_code]
        if len(raw_rows) != 6:
            skip["not_6_csv_rows"] += 1
            continue

        rows = {}
        invalid = False
        for row in raw_rows:
            lane_f = as_float(row.get("lane_number"))
            if lane_f is None:
                invalid = True
                break
            lane = int(lane_f)
            if lane not in range(1, 7) or lane in rows:
                invalid = True
                break
            rows[lane] = row
        if invalid or set(rows) != set(range(1, 7)):
            skip["bad_lane_rows"] += 1
            continue

        finish = build_finish(rows)
        if finish is None:
            skip["bad_result"] += 1
            continue

        race_date = race_dates.get(race_code)
        if race_date is None:
            skip["missing_race_date"] += 1
            continue

        first_scores = {}
        second_scores = {}
        old_bonus_by_lane = {}
        new_bonus_by_lane = {}
        missing = False

        for lane in range(1, 7):
            first = as_float(rows[lane].get("first_total_score"))
            second = as_float(rows[lane].get("second_score"))
            if first is None or second is None:
                missing = True
                break

            pid = str(rows[lane].get("player_id") or "").strip()
            if not pid:
                pid = entry_players.get((race_code, lane), "")
            if not pid:
                missing = True
                break

            course = entry_courses.get((race_code, pid))
            if course not in range(1, 7):
                missing = True
                break

            old_profile = old_idx.profile(pid, course, race_date)
            new_profile = new_idx.profile(pid, course, race_date)
            old_type, old_bonus = decide_type(course, old_profile["rates"])
            new_type, new_bonus = decide_type(course, new_profile["rates"])

            first_scores[lane] = first
            second_scores[lane] = second
            old_bonus_by_lane[lane] = old_bonus
            new_bonus_by_lane[lane] = new_bonus
            old_bonus_dist[old_bonus] += 1
            new_bonus_dist[new_bonus] += 1

            if old_bonus != new_bonus:
                bonus_change[(old_bonus, new_bonus)] += 1
            if old_type != new_type:
                type_change[(old_type, new_type)] += 1

        if missing:
            skip["missing_score_or_history"] += 1
            continue

        score_old = {
            lane: second_scores[lane] + old_bonus_by_lane[lane]
            for lane in range(1, 7)
        }
        score_new = {
            lane: second_scores[lane] + new_bonus_by_lane[lane]
            for lane in range(1, 7)
        }

        rank_old, ov_old = apply_step4(score_old, first_scores, second_scores)
        rank_new, ov_new = apply_step4(score_new, first_scores, second_scores)
        if ov_old or ov_new:
            override_count += 1

        processed += 1
        if rank_old != rank_new:
            any_order_changed += 1
        if rank_old[0] != rank_new[0]:
            head_changed += 1
        if rank_old[1] != rank_new[1]:
            second_changed += 1

        variants = {"OLD": rank_old, "NEW": rank_new}
        actual_winner = next(l for l, r in finish.items() if r == 1.0)
        actual_top3 = {l for l, r in finish.items() if r <= 3.0}

        for key, ranking in variants.items():
            head = ranking[0]
            add_head(stats[key], finish[head])

            pred_rank = {lane: idx + 1 for idx, lane in enumerate(ranking)}
            mae_sum[key] += sum(
                abs(pred_rank[lane] - finish[lane]) for lane in range(1, 7)
            ) / 6.0

            for pos, lane in enumerate(ranking, start=1):
                add_head(pos_stats[key][pos], finish[lane])

            for k in (1, 2, 3):
                if actual_winner in ranking[:k]:
                    winner_capture[key][k] += 1

            top3_overlap_sum[key] += len(set(ranking[:3]) & actual_top3)

        if rank_old[0] != rank_new[0]:
            r_old = finish[rank_old[0]]
            r_new = finish[rank_new[0]]
            if r_new < r_old:
                head_h2h["NEW_better"] += 1
            elif r_old < r_new:
                head_h2h["OLD_better"] += 1
            else:
                head_h2h["tie"] += 1

            if r_new == 1.0 and r_old != 1.0:
                win_switch["NEW_gain"] += 1
            elif r_old == 1.0 and r_new != 1.0:
                win_switch["OLD_gain"] += 1

            if r_new <= 3.0 and r_old > 3.0:
                top3_switch["NEW_gain"] += 1
            elif r_old <= 3.0 and r_new > 3.0:
                top3_switch["OLD_gain"] += 1

        if rank_old[1] != rank_new[1]:
            r_old2 = finish[rank_old[1]]
            r_new2 = finish[rank_new[1]]
            if r_new2 < r_old2:
                second_h2h["NEW_better"] += 1
            elif r_old2 < r_new2:
                second_h2h["OLD_better"] += 1
            else:
                second_h2h["tie"] += 1

    if processed == 0:
        raise RuntimeError("比較できるレースが0件です")

    old_head = summarize_head(stats["OLD"])
    new_head = summarize_head(stats["NEW"])
    old_second = summarize_head(pos_stats["OLD"][2])
    new_second = summarize_head(pos_stats["NEW"][2])

    print("\n" + "=" * 118)
    print("決まり手再構築 最終予想健康診断（旧方式 vs 再構築方式）")
    print("=" * 118)
    print(f"CSV          : {csv_path}")
    print(f"期間         : {start_date} ～ {end_date}")
    print(f"処理レース   : {processed}")
    print("履歴期間     : 各レース当日を除く直前6ヶ月")
    print("固定項目     : 一次スコア / 二次スコア / STEP4条件 / 実着順")
    print("変更項目     : 決まり手集計方式による typeBonus のみ")
    print("本番変更     : なし（健康診断のみ）")

    print("\n【skip】")
    for key in [
        "not_6_csv_rows", "bad_lane_rows", "bad_result",
        "missing_race_date", "missing_score_or_history",
    ]:
        print(f"{key:<30}: {skip[key]}")

    print("\n【typeBonus分布】")
    print(
        f"旧方式  +1:{old_bonus_dist[1]:>7}  0:{old_bonus_dist[0]:>7}  -1:{old_bonus_dist[-1]:>7}"
    )
    print(
        f"再構築  +1:{new_bonus_dist[1]:>7}  0:{new_bonus_dist[0]:>7}  -1:{new_bonus_dist[-1]:>7}"
    )
    print("変更内訳:")
    if bonus_change:
        for (old_b, new_b), n in bonus_change.most_common():
            print(f"  {old_b:+d} → {new_b:+d}: {n}")
    else:
        print("  なし")

    print("\n【順位変更件数】")
    print(f"順位列のどこか変更 : {any_order_changed:>6} / {processed} ({any_order_changed/processed*100:.2f}%)")
    print(f"本命(最終1位)変更  : {head_changed:>6} / {processed} ({head_changed/processed*100:.2f}%)")
    print(f"対抗(最終2位)変更  : {second_changed:>6} / {processed} ({second_changed/processed*100:.2f}%)")
    print(f"STEP4発動対象      : {override_count:>6}")

    print("\n【本命・最終1位の実成績】")
    print("方式             1着      2連対      3連対    平均着順")
    print(head_summary_line("旧方式", stats["OLD"]))
    print(head_summary_line("再構築", stats["NEW"]))
    print(
        f"差(新-旧)       {pp(new_head['win']-old_head['win'])} / "
        f"{pp(new_head['top2']-old_head['top2'])} / "
        f"{pp(new_head['top3']-old_head['top3'])} / "
        f"{new_head['avg_rank']-old_head['avg_rank']:+.3f}着"
    )

    print("\n【対抗・最終2位の実成績】")
    print("方式             1着      2連対      3連対    平均着順")
    print(head_summary_line("旧方式", pos_stats["OLD"][2]))
    print(head_summary_line("再構築", pos_stats["NEW"][2]))
    print(
        f"差(新-旧)       {pp(new_second['win']-old_second['win'])} / "
        f"{pp(new_second['top2']-old_second['top2'])} / "
        f"{pp(new_second['top3']-old_second['top3'])} / "
        f"{new_second['avg_rank']-old_second['avg_rank']:+.3f}着"
    )

    print("\n【順位全体の品質】")
    print("方式          勝者TOP1   勝者TOP2   勝者TOP3   予想TOP3∩実TOP3   順位MAE")
    for key, label in [("OLD", "旧方式"), ("NEW", "再構築")]:
        print(
            f"{label:<10}  {pct(winner_capture[key][1]/processed)}  "
            f"{pct(winner_capture[key][2]/processed)}  {pct(winner_capture[key][3]/processed)}  "
            f"{top3_overlap_sum[key]/processed:.3f}/3          {mae_sum[key]/processed:.3f}"
        )

    print("\n【本命が変わったレースだけ直接比較】")
    print(f"対象             : {head_changed}")
    print(f"再構築の方が好着 : {head_h2h['NEW_better']}")
    print(f"旧方式の方が好着 : {head_h2h['OLD_better']}")
    print(f"同着扱い         : {head_h2h['tie']}")
    print(f"1着を新たに獲得  : {win_switch['NEW_gain']}")
    print(f"1着を失った      : {win_switch['OLD_gain']}")
    print(f"3連対を新たに獲得: {top3_switch['NEW_gain']}")
    print(f"3連対を失った    : {top3_switch['OLD_gain']}")

    print("\n【対抗が変わったレースだけ直接比較】")
    print(f"対象             : {second_changed}")
    print(f"再構築の方が好着 : {second_h2h['NEW_better']}")
    print(f"旧方式の方が好着 : {second_h2h['OLD_better']}")
    print(f"同着扱い         : {second_h2h['tie']}")

    print("\n【予想順位別の実成績】")
    print("順位 | 旧方式: 1着/3連/平均着順          | 再構築: 1着/3連/平均着順")
    for pos in range(1, 7):
        a = summarize_head(pos_stats["OLD"][pos])
        b = summarize_head(pos_stats["NEW"][pos])
        print(
            f" {pos}位 | {pct(a['win'])}/{pct(a['top3'])}/{a['avg_rank']:.3f}"
            f"       | {pct(b['win'])}/{pct(b['top3'])}/{b['avg_rank']:.3f}"
        )

    print("\n【健康診断の見方】")
    print("・これは再調整用ではなく、採用済みの母数修正が最終順位を壊していないかの確認。")
    print("・本命/対抗の変更レース直接比較と、順位MAE・勝者TOP3を重視する。")
    print("・結果を見て閾値や期間は動かさない。")
    print("=" * 118)


if __name__ == "__main__":
    main()
