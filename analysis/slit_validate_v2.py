#!/usr/bin/env python3
import json
import sys
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path

import psycopg2

REPO_ROOT = Path(__file__).resolve().parent.parent
THEORY_DIR = REPO_ROOT / "theories" / "course_correction"
sys.path.insert(0, str(THEORY_DIR))
from classify_slit_pattern import classify_slit_pattern

DB_CONFIG = {
    "host": "192.168.0.208",
    "port": 5432,
    "dbname": "devdb",
    "user": "miyase428",
    "password": "herunia0113",
}

PATTERN_NAMES = {
    1: "通常型", 2: "横一線", 3: "1・2先行", 4: "スロー先行",
    5: "壁なし", 6: "2・3遅れ", 7: "中凹み", 8: "3号艇攻め",
    9: "中ぶくれ", 10: "1号艇遅れ", 11: "外側先行", 12: "ダッシュ先行",
}

BOOL_FEATURES = [
    "inside_fast", "wall_none", "middle_attack", "dash_fast",
    "inside_late", "line_abreast", "two_three_late", "middle_hollow",
    "middle_bulge", "one_two_fast", "outside_attack",
]


def connect_db():
    return psycopg2.connect(**DB_CONFIG)


def normalize_rank(rank, has_result):
    if not has_result:
        return None
    if rank is None or rank == "":
        return 5.5
    try:
        v = float(rank)
    except (TypeError, ValueError):
        return None
    return v if 1 <= v <= 6 else None


def load_settings():
    with (THEORY_DIR / "venue_slit_settings.json").open(encoding="utf-8") as f:
        return json.load(f)["default"]


def load_rows(end_date):
    sql = """
        SELECT
            re.race_code,
            re.player_id,
            el.entry_course,
            el.start_timing AS exhibition_st,
            rrd.start_timing AS real_st,
            rrd.rank,
            (rrd.race_code IS NOT NULL) AS has_result
        FROM boat_race.race_entry re
        LEFT JOIN boat_race.exhibition_live el
          ON el.race_code = re.race_code
         AND el.player_id = re.player_id
        LEFT JOIN boat_race.race_result_detail rrd
          ON rrd.race_code = re.race_code
         AND rrd.player_id = re.player_id
        WHERE re.race_date <= %s
        ORDER BY re.race_code, el.entry_course NULLS LAST, re.player_id
    """
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (end_date,))
            return cur.fetchall()


def group_races(rows):
    races = defaultdict(list)
    for race_code, player_id, entry_course, ex_st, real_st, rank, has_result in rows:
        course = None
        if entry_course is not None:
            try:
                c = int(entry_course)
                if 1 <= c <= 6:
                    course = c
            except (TypeError, ValueError):
                pass
        races[race_code].append({
            "player_id": str(player_id),
            "course": course,
            "ex_st": float(ex_st) if ex_st is not None else None,
            "real_st": float(real_st) if real_st is not None else None,
            "rank": normalize_rank(rank, bool(has_result)),
            "has_result": bool(has_result),
        })
    return races


def new_lane_counts():
    return {c: {"n": 0, "win": 0, "top3": 0, "rank_sum": 0.0} for c in range(1, 7)}


def add_result(counts, lane, rank):
    b = counts[lane]
    b["n"] += 1
    b["rank_sum"] += rank
    if rank == 1.0:
        b["win"] += 1
    if rank <= 3.0:
        b["top3"] += 1


def rate(x, n):
    return x / n if n else 0.0


def pct(x):
    return f"{x * 100:6.2f}%"


def pp(x):
    return f"{x * 100:+6.2f}"


def compute(start_date, end_date):
    start_ymd = datetime.strptime(start_date, "%Y-%m-%d").strftime("%Y%m%d")
    end_ymd = datetime.strptime(end_date, "%Y-%m-%d").strftime("%Y%m%d")
    settings = load_settings()
    races = group_races(load_rows(end_date))

    corr_sum = defaultdict(float)
    corr_n = defaultdict(int)

    baseline = new_lane_counts()
    actual_counts = {pid: new_lane_counts() for pid in range(1, 13)}
    exhibition_counts = {pid: new_lane_counts() for pid in range(1, 13)}
    predicted_counts = {pid: new_lane_counts() for pid in range(1, 13)}
    feature_counts = {name: new_lane_counts() for name in BOOL_FEATURES}

    actual_freq = Counter()
    exhibition_freq = Counter()
    predicted_freq = Counter()
    feature_races = Counter()

    result = {
        "target_total": 0, "processed": 0,
        "skip_not6_entry": 0, "skip_not6_exhibition": 0,
        "skip_missing_st": 0, "skip_missing_result": 0,
        "exhibition_match": 0, "predicted_match": 0,
        "exhibition_predicted_same": 0,
    }

    for race_code in sorted(races):
        boats = races[race_code]
        race_ymd = race_code[:8]
        is_target = start_ymd <= race_ymd <= end_ymd
        if is_target:
            result["target_total"] += 1

        # race_entry should normally be six unique players.
        if len(boats) != 6:
            if is_target:
                result["skip_not6_entry"] += 1
            continue

        by_course = {}
        duplicate_course = False
        for b in boats:
            c = b["course"]
            if c is None:
                continue
            if c in by_course:
                duplicate_course = True
            by_course[c] = b

        valid_exhibition = (
            not duplicate_course
            and len(by_course) == 6
            and set(by_course) == set(range(1, 7))
        )
        if not valid_exhibition:
            if is_target:
                result["skip_not6_exhibition"] += 1
            continue

        complete_st = all(
            by_course[c]["ex_st"] is not None and by_course[c]["real_st"] is not None
            for c in range(1, 7)
        )
        complete_result = all(by_course[c]["rank"] is not None for c in range(1, 7))

        if is_target:
            if not complete_st:
                result["skip_missing_st"] += 1
            elif not complete_result:
                result["skip_missing_result"] += 1
            else:
                ex_st = [by_course[c]["ex_st"] for c in range(1, 7)]
                real_st = [by_course[c]["real_st"] for c in range(1, 7)]
                pred_st = []
                for c in range(1, 7):
                    pid = by_course[c]["player_id"]
                    corr = corr_sum[pid] / corr_n[pid] if corr_n[pid] else 0.0
                    pred_st.append(by_course[c]["ex_st"] + corr)

                actual_pid, _ = classify_slit_pattern(real_st, settings)
                exhibition_pid, _ = classify_slit_pattern(ex_st, settings)
                predicted_pid, predicted_features = classify_slit_pattern(pred_st, settings)

                actual_freq[actual_pid] += 1
                exhibition_freq[exhibition_pid] += 1
                predicted_freq[predicted_pid] += 1
                result["exhibition_match"] += int(exhibition_pid == actual_pid)
                result["predicted_match"] += int(predicted_pid == actual_pid)
                result["exhibition_predicted_same"] += int(exhibition_pid == predicted_pid)

                for c in range(1, 7):
                    rank = by_course[c]["rank"]
                    add_result(baseline, c, rank)
                    add_result(actual_counts[actual_pid], c, rank)
                    add_result(exhibition_counts[exhibition_pid], c, rank)
                    add_result(predicted_counts[predicted_pid], c, rank)

                for name in BOOL_FEATURES:
                    if predicted_features.get(name) is True:
                        feature_races[name] += 1
                        for c in range(1, 7):
                            add_result(feature_counts[name], c, by_course[c]["rank"])

                result["processed"] += 1

        # Leak-free rolling correction: update only after current race evaluation.
        if complete_st:
            for c in range(1, 7):
                b = by_course[c]
                pid = b["player_id"]
                corr_sum[pid] += b["real_st"] - b["ex_st"]
                corr_n[pid] += 1

    result.update({
        "start": start_date, "end": end_date,
        "baseline": baseline,
        "actual_counts": actual_counts,
        "exhibition_counts": exhibition_counts,
        "predicted_counts": predicted_counts,
        "feature_counts": feature_counts,
        "actual_freq": actual_freq,
        "exhibition_freq": exhibition_freq,
        "predicted_freq": predicted_freq,
        "feature_races": feature_races,
    })
    return result


def lane_metrics(counts, baseline, lane):
    c = counts[lane]
    b = baseline[lane]
    n = c["n"]
    bn = b["n"]
    win = rate(c["win"], n)
    top3 = rate(c["top3"], n)
    avg = c["rank_sum"] / n if n else 0.0
    bwin = rate(b["win"], bn)
    btop3 = rate(b["top3"], bn)
    return n, win, top3, avg, win - bwin, top3 - btop3


def print_pattern_table(title, freq, counts, baseline, processed):
    print(f"\n【{title}】")
    print("PID 名称             R数    構成比 | 1号艇 1着/lift 3連/lift | 最強外艇(2-6) 1着/lift")
    for pid in range(1, 13):
        n = freq[pid]
        if n == 0:
            print(f"{pid:>2}  {PATTERN_NAMES[pid]:<14} {0:>5} {0:>7.2f}% | -")
            continue
        _, w1, t31, _, lw1, lt31 = lane_metrics(counts[pid], baseline, 1)
        best = None
        for lane in range(2, 7):
            _, w, _, _, lw, _ = lane_metrics(counts[pid], baseline, lane)
            cand = (lw, w, lane)
            if best is None or cand > best:
                best = cand
        bl, bw, lane = best
        print(
            f"{pid:>2}  {PATTERN_NAMES[pid]:<14} {n:>5} {n/processed*100:>7.2f}% | "
            f"{pct(w1)}/{pp(lw1)} {pct(t31)}/{pp(lt31)} | "
            f"{lane}号艇 {pct(bw)}/{pp(bl)}"
        )


def print_features(r):
    print("\n【予測ST 特徴フラグ別】")
    print("特徴                  R数 | 1号艇 1着/lift 3連/lift | 最強外艇(2-6) 1着/lift")
    for name in BOOL_FEATURES:
        n = r["feature_races"][name]
        if n == 0:
            print(f"{name:<22} {0:>5} | -")
            continue
        counts = r["feature_counts"][name]
        _, w1, t31, _, lw1, lt31 = lane_metrics(counts, r["baseline"], 1)
        best = None
        for lane in range(2, 7):
            _, w, _, _, lw, _ = lane_metrics(counts, r["baseline"], lane)
            cand = (lw, w, lane)
            if best is None or cand > best:
                best = cand
        bl, bw, lane = best
        print(
            f"{name:<22} {n:>5} | {pct(w1)}/{pp(lw1)} {pct(t31)}/{pp(lt31)} | "
            f"{lane}号艇 {pct(bw)}/{pp(bl)}"
        )


def print_result(r):
    p = r["processed"]
    print("=" * 72)
    print("スリット体系 健康診断 v2（race_entry母集団 / player_id対応）")
    print("=" * 72)
    print(f"期間                 : {r['start']} ～ {r['end']}")
    print(f"対象レース           : {r['target_total']}")
    print(f"処理レース           : {p}")
    print(f"not_6 race_entry      : {r['skip_not6_entry']}")
    print(f"not_6 exhibition      : {r['skip_not6_exhibition']}")
    print(f"missing ST            : {r['skip_missing_st']}")
    print(f"missing result        : {r['skip_missing_result']}")
    print("対応                 : race_entry → exhibition/result を race_code + player_id")
    print("補正履歴             : 対象レースより前だけを累積（リークなし）")
    print("着外処理             : 結果行あり + rank NULL/空 = 5.5")
    if p == 0:
        return

    print("\n【予測パターン再現率】")
    print(f"展示STそのまま → 本番pattern一致 : {r['exhibition_match']}/{p} = {r['exhibition_match']/p*100:.2f}%")
    print(f"展示ST+選手補正 → 本番pattern一致 : {r['predicted_match']}/{p} = {r['predicted_match']/p*100:.2f}%")
    print(f"展示pattern と補正patternの一致   : {r['exhibition_predicted_same']}/{p} = {r['exhibition_predicted_same']/p*100:.2f}%")
    print(f"選手補正による一致率差             : {(r['predicted_match']-r['exhibition_match'])/p*100:+.2f}pt")

    print("\n【コース基準】")
    for lane in range(1, 7):
        b = r["baseline"][lane]
        n = b["n"]
        print(f"{lane}号艇 N={n:>5} 1着={pct(rate(b['win'],n))} 3連={pct(rate(b['top3'],n))} 平均={b['rank_sum']/n if n else 0:.3f}")

    print_pattern_table("本番STで分類（理論そのもの）", r["actual_freq"], r["actual_counts"], r["baseline"], p)
    print_pattern_table("展示STのみで分類（比較用）", r["exhibition_freq"], r["exhibition_counts"], r["baseline"], p)
    print_pattern_table("展示ST＋選手補正で分類（Web想定）", r["predicted_freq"], r["predicted_counts"], r["baseline"], p)
    print_features(r)

    print("\n" + "=" * 72)
    print("見るポイント")
    print("=" * 72)
    print("・v1より not_6 が大幅に減るか")
    print("・本番STの強いパターン差が2期間再現するか")
    print("・展示ST+補正の一致率が展示ST単体より改善するか")
    print("・予測pattern/特徴フラグの方向性が2期間で再現するか")
    print("=" * 72)


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/slit_validate_v2.py YYYY-MM-DD YYYY-MM-DD")
        sys.exit(1)
    start_date, end_date = sys.argv[1], sys.argv[2]
    datetime.strptime(start_date, "%Y-%m-%d")
    datetime.strptime(end_date, "%Y-%m-%d")
    print_result(compute(start_date, end_date))


if __name__ == "__main__":
    main()
