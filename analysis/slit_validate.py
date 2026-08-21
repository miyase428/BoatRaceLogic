#!/usr/bin/env python3
import json
import sys
from collections import defaultdict, Counter
from datetime import datetime
from pathlib import Path

import psycopg2

REPO_ROOT = Path(__file__).resolve().parent.parent
THEORY_DIR = REPO_ROOT / "theories" / "course_correction"
sys.path.insert(0, str(REPO_ROOT))
sys.path.insert(0, str(THEORY_DIR))

from common.db_config import load_db_config
from classify_slit_pattern import classify_slit_pattern

DB_CONFIG = load_db_config()

PATTERN_NAMES = {
    1: "通常型",
    2: "横一線",
    3: "1・2先行",
    4: "スロー先行",
    5: "壁なし",
    6: "2・3遅れ",
    7: "中凹み",
    8: "3号艇攻め",
    9: "中ぶくれ",
    10: "1号艇遅れ",
    11: "外側先行",
    12: "ダッシュ先行",
}

BOOL_FEATURES = [
    "inside_fast",
    "wall_none",
    "middle_attack",
    "dash_fast",
    "inside_late",
    "line_abreast",
    "two_three_late",
    "middle_hollow",
    "middle_bulge",
    "one_two_fast",
    "outside_attack",
]


def connect_db():
    return psycopg2.connect(**DB_CONFIG)


def ymd(value):
    return datetime.strptime(value, "%Y-%m-%d").strftime("%Y%m%d")


def normalize_rank(rank):
    if rank is None or rank == "":
        return 5.5
    try:
        value = float(rank)
    except (TypeError, ValueError):
        return None
    if 1 <= value <= 6:
        return value
    return None


def load_settings():
    path = THEORY_DIR / "venue_slit_settings.json"
    with path.open(encoding="utf-8") as f:
        return json.load(f)["default"]


def load_rows(end_ymd):
    sql = """
        SELECT
            e.race_code,
            e.player_id,
            e.entry_course,
            e.start_timing AS exhibition_st,
            r.start_timing AS real_st,
            r.rank
        FROM boat_race.exhibition_live e
        JOIN boat_race.race_result_detail r
          ON r.race_code = e.race_code
         AND r.player_id = e.player_id
        WHERE SUBSTRING(e.race_code, 1, 8) <= %s
        ORDER BY e.race_code, e.entry_course
    """
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (end_ymd,))
            return cur.fetchall()


def group_races(rows):
    races = defaultdict(list)
    for race_code, player_id, entry_course, ex_st, real_st, rank in rows:
        try:
            course = int(entry_course)
        except (TypeError, ValueError):
            continue
        if not 1 <= course <= 6:
            continue
        races[race_code].append(
            {
                "player_id": str(player_id),
                "course": course,
                "ex_st": float(ex_st) if ex_st is not None else None,
                "real_st": float(real_st) if real_st is not None else None,
                "rank": normalize_rank(rank),
            }
        )
    return races


def new_lane_counts():
    return {
        lane: {"n": 0, "win": 0, "top3": 0, "rank_sum": 0.0}
        for lane in range(1, 7)
    }


def add_lane_result(counts, lane, rank):
    bucket = counts[lane]
    bucket["n"] += 1
    bucket["rank_sum"] += rank
    if rank == 1.0:
        bucket["win"] += 1
    if rank <= 3.0:
        bucket["top3"] += 1


def rate(value, n):
    return value / n if n else 0.0


def pct(value):
    return f"{value * 100:6.2f}%"


def pp(value):
    return f"{value * 100:+6.2f}"


def compute(start_date, end_date):
    start_ymd = ymd(start_date)
    end_ymd = ymd(end_date)
    settings = load_settings()
    races = group_races(load_rows(end_ymd))

    corr_sum = defaultdict(float)
    corr_n = defaultdict(int)

    target_total = 0
    processed = 0
    skip_not6 = 0
    skip_missing_st = 0
    skip_missing_rank = 0

    baseline = new_lane_counts()
    actual_counts = {pid: new_lane_counts() for pid in range(1, 13)}
    exhibition_counts = {pid: new_lane_counts() for pid in range(1, 13)}
    predicted_counts = {pid: new_lane_counts() for pid in range(1, 13)}

    actual_freq = Counter()
    exhibition_freq = Counter()
    predicted_freq = Counter()

    exhibition_match = 0
    predicted_match = 0
    exhibition_predicted_same = 0

    feature_counts = {name: new_lane_counts() for name in BOOL_FEATURES}
    feature_races = Counter()

    for race_code in sorted(races.keys()):
        boats = races[race_code]
        race_ymd = race_code[:8]
        is_target = start_ymd <= race_ymd <= end_ymd
        if is_target:
            target_total += 1

        by_course = {}
        for boat in boats:
            by_course[boat["course"]] = boat

        valid_six = len(by_course) == 6 and set(by_course.keys()) == set(range(1, 7))
        complete_st = valid_six and all(
            by_course[c]["ex_st"] is not None
            and by_course[c]["real_st"] is not None
            for c in range(1, 7)
        )

        if is_target:
            if not valid_six:
                skip_not6 += 1
            elif not complete_st:
                skip_missing_st += 1
            elif any(by_course[c]["rank"] is None for c in range(1, 7)):
                skip_missing_rank += 1
            else:
                exhibition_st = [by_course[c]["ex_st"] for c in range(1, 7)]
                real_st = [by_course[c]["real_st"] for c in range(1, 7)]
                predicted_st = []

                for c in range(1, 7):
                    player_id = by_course[c]["player_id"]
                    correction = (
                        corr_sum[player_id] / corr_n[player_id]
                        if corr_n[player_id]
                        else 0.0
                    )
                    predicted_st.append(by_course[c]["ex_st"] + correction)

                actual_pid, _ = classify_slit_pattern(real_st, settings)
                exhibition_pid, _ = classify_slit_pattern(exhibition_st, settings)
                predicted_pid, predicted_features = classify_slit_pattern(
                    predicted_st, settings
                )

                actual_freq[actual_pid] += 1
                exhibition_freq[exhibition_pid] += 1
                predicted_freq[predicted_pid] += 1

                exhibition_match += int(exhibition_pid == actual_pid)
                predicted_match += int(predicted_pid == actual_pid)
                exhibition_predicted_same += int(exhibition_pid == predicted_pid)

                for c in range(1, 7):
                    rank = by_course[c]["rank"]
                    add_lane_result(baseline, c, rank)
                    add_lane_result(actual_counts[actual_pid], c, rank)
                    add_lane_result(exhibition_counts[exhibition_pid], c, rank)
                    add_lane_result(predicted_counts[predicted_pid], c, rank)

                for feature in BOOL_FEATURES:
                    if predicted_features.get(feature) is True:
                        feature_races[feature] += 1
                        for c in range(1, 7):
                            add_lane_result(
                                feature_counts[feature], c, by_course[c]["rank"]
                            )

                processed += 1

        if complete_st:
            for c in range(1, 7):
                boat = by_course[c]
                player_id = boat["player_id"]
                corr_sum[player_id] += boat["real_st"] - boat["ex_st"]
                corr_n[player_id] += 1

    return {
        "start": start_date,
        "end": end_date,
        "target_total": target_total,
        "processed": processed,
        "skip_not6": skip_not6,
        "skip_missing_st": skip_missing_st,
        "skip_missing_rank": skip_missing_rank,
        "baseline": baseline,
        "actual_counts": actual_counts,
        "exhibition_counts": exhibition_counts,
        "predicted_counts": predicted_counts,
        "actual_freq": actual_freq,
        "exhibition_freq": exhibition_freq,
        "predicted_freq": predicted_freq,
        "exhibition_match": exhibition_match,
        "predicted_match": predicted_match,
        "exhibition_predicted_same": exhibition_predicted_same,
        "feature_counts": feature_counts,
        "feature_races": feature_races,
    }


def lane_metrics(counts, baseline, lane):
    current = counts[lane]
    base = baseline[lane]
    n = current["n"]
    base_n = base["n"]
    win = rate(current["win"], n)
    top3 = rate(current["top3"], n)
    avg_rank = current["rank_sum"] / n if n else 0.0
    base_win = rate(base["win"], base_n)
    base_top3 = rate(base["top3"], base_n)
    return n, win, top3, avg_rank, win - base_win, top3 - base_top3


def print_pattern_table(title, freq, counts, baseline, processed):
    print(f"\n【{title}】")
    print(
        "PID 名称             R数    構成比 | "
        "1号艇 1着/lift 3連/lift | 最強外艇(2-6) 1着/lift"
    )

    for pid in range(1, 13):
        race_n = freq[pid]
        if race_n == 0:
            print(f"{pid:>2}  {PATTERN_NAMES[pid]:<14} {0:>5} {0:>7.2f}% | -")
            continue

        _, win1, top31, _, lift_win1, lift_top31 = lane_metrics(
            counts[pid], baseline, 1
        )

        best = None
        for lane in range(2, 7):
            _, win, _, _, lift_win, _ = lane_metrics(counts[pid], baseline, lane)
            candidate = (lift_win, win, lane)
            if best is None or candidate > best:
                best = candidate

        best_lift, best_win, best_lane = best

        print(
            f"{pid:>2}  {PATTERN_NAMES[pid]:<14} {race_n:>5} "
            f"{race_n / processed * 100:>7.2f}% | "
            f"{pct(win1)}/{pp(lift_win1)} {pct(top31)}/{pp(lift_top31)} | "
            f"{best_lane}号艇 {pct(best_win)}/{pp(best_lift)}"
        )


def print_features(result):
    print("\n【予測ST 特徴フラグ別】")
    print(
        "特徴                  R数 | "
        "1号艇 1着/lift 3連/lift | 最強外艇(2-6) 1着/lift"
    )

    for feature in BOOL_FEATURES:
        race_n = result["feature_races"][feature]
        if race_n == 0:
            print(f"{feature:<22} {0:>5} | -")
            continue

        counts = result["feature_counts"][feature]
        _, win1, top31, _, lift_win1, lift_top31 = lane_metrics(
            counts, result["baseline"], 1
        )

        best = None
        for lane in range(2, 7):
            _, win, _, _, lift_win, _ = lane_metrics(
                counts, result["baseline"], lane
            )
            candidate = (lift_win, win, lane)
            if best is None or candidate > best:
                best = candidate

        best_lift, best_win, best_lane = best

        print(
            f"{feature:<22} {race_n:>5} | "
            f"{pct(win1)}/{pp(lift_win1)} {pct(top31)}/{pp(lift_top31)} | "
            f"{best_lane}号艇 {pct(best_win)}/{pp(best_lift)}"
        )


def print_result(result):
    processed = result["processed"]

    print("=" * 72)
    print("スリット体系 健康診断 v1（リークなし player_id 対応）")
    print("=" * 72)
    print(f"期間                 : {result['start']} ～ {result['end']}")
    print(f"対象レース           : {result['target_total']}")
    print(f"処理レース           : {processed}")
    print(
        f"not_6 / missing ST   : "
        f"{result['skip_not6']} / {result['skip_missing_st']}"
    )
    print(f"missing rank         : {result['skip_missing_rank']}")
    print("結果対応             : exhibition_live ↔ result を race_code + player_id")
    print("補正履歴             : 対象レースより前の展示ST→本番ST差だけを累積使用")
    print("着外処理             : rank NULL/空 = 5.5")

    if processed == 0:
        print("処理可能レースがありません。")
        return

    print("\n【予測パターン再現率】")
    print(
        f"展示STそのまま → 本番pattern一致 : "
        f"{result['exhibition_match']}/{processed} = "
        f"{result['exhibition_match'] / processed * 100:.2f}%"
    )
    print(
        f"展示ST+選手補正 → 本番pattern一致 : "
        f"{result['predicted_match']}/{processed} = "
        f"{result['predicted_match'] / processed * 100:.2f}%"
    )
    print(
        f"展示pattern と補正patternの一致   : "
        f"{result['exhibition_predicted_same']}/{processed} = "
        f"{result['exhibition_predicted_same'] / processed * 100:.2f}%"
    )
    print(
        f"選手補正による一致率差             : "
        f"{(result['predicted_match'] - result['exhibition_match']) / processed * 100:+.2f}pt"
    )

    print("\n【コース基準】")
    for lane in range(1, 7):
        current = result["baseline"][lane]
        n = current["n"]
        print(
            f"{lane}号艇 N={n:>5} "
            f"1着={pct(rate(current['win'], n))} "
            f"3連={pct(rate(current['top3'], n))} "
            f"平均={current['rank_sum'] / n:.3f}"
        )

    print_pattern_table(
        "本番STで分類（理論そのもの）",
        result["actual_freq"],
        result["actual_counts"],
        result["baseline"],
        processed,
    )
    print_pattern_table(
        "展示STのみで分類（比較用）",
        result["exhibition_freq"],
        result["exhibition_counts"],
        result["baseline"],
        processed,
    )
    print_pattern_table(
        "展示ST＋選手補正で分類（Web想定）",
        result["predicted_freq"],
        result["predicted_counts"],
        result["baseline"],
        processed,
    )
    print_features(result)

    print("\n" + "=" * 72)
    print("見るポイント")
    print("=" * 72)
    print("・本番ST分類でパターンごとのコース成績差が明確か")
    print("・展示ST+補正のpattern一致率が展示ST単体より改善しているか")
    print("・予測patternでも本番ST分類と同じ方向のliftが再現するか")
    print("・特徴フラグごとの方向性が2期間で再現するか")
    print("=" * 72)


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/slit_validate.py YYYY-MM-DD YYYY-MM-DD")
        sys.exit(1)

    result = compute(sys.argv[1], sys.argv[2])
    print_result(result)


if __name__ == "__main__":
    main()
