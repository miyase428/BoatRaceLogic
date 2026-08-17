#!/usr/bin/env python3
import json
import math
import sys
from collections import Counter, defaultdict
from datetime import datetime, timedelta
from pathlib import Path
from statistics import pstdev

REPO_ROOT = Path(__file__).resolve().parent.parent
THEORY_DIR = REPO_ROOT / "theories" / "course_correction"
sys.path.insert(0, str(THEORY_DIR))
sys.path.insert(0, str(Path(__file__).resolve().parent))

from classify_slit_pattern import classify_slit_pattern
from slit_validate_v2 import connect_db

PATTERN_NAMES = {
    1: "通常型", 2: "横一線", 3: "1・2先行", 4: "スロー先行",
    5: "壁なし", 6: "2・3遅れ", 7: "中凹み", 8: "3号艇攻め",
    9: "中ぶくれ", 10: "1号艇遅れ", 11: "外側先行", 12: "ダッシュ先行",
}

METHODS = {
    "A_EX": "展示STのみ",
    "B_COURSE_ST": "展示ST＋コース平均ST",
    "C_ST_RANK": "B＋コース平均ST順位",
    "D_RELIABLE": "C＋進入回数で信頼度補正",
}

K_ENTRY = 20.0


def load_settings():
    with (THEORY_DIR / "venue_slit_settings.json").open(encoding="utf-8") as f:
        return json.load(f)["default"]


def term_info_for_date(dt):
    """レース時点で利用する期情報。

    1-4月   -> 前年10月期（例: 2026-03 -> 2510）
    5-10月  -> 当年04月期（例: 2026-07 -> 2604）
    11-12月 -> 当年10月期（例: 2026-11 -> 2610）
    """
    yy = dt.year % 100
    if dt.month <= 4:
        return f"{(dt.year - 1) % 100:02d}10"
    if dt.month <= 10:
        return f"{yy:02d}04"
    return f"{yy:02d}10"


def required_terms(start_date, end_date):
    d = start_date
    terms = set()
    while d <= end_date:
        terms.add(term_info_for_date(d))
        d += timedelta(days=1)
    return sorted(terms)


def as_float(v):
    if v is None or v == "":
        return None
    try:
        x = float(v)
    except (TypeError, ValueError):
        return None
    return x if math.isfinite(x) else None


def as_int(v):
    x = as_float(v)
    if x is None:
        return None
    try:
        return int(x)
    except (TypeError, ValueError):
        return None


def load_racer_results(terms):
    cols = ["term_info", "player_id", "average_start"]
    for c in range(1, 7):
        cols += [
            f"course{c}_entry",
            f"course{c}_average_start",
            f"course{c}_average_rank",
        ]

    sql = f"""
        SELECT {', '.join(cols)}
        FROM boat_race.racer_results
        WHERE term_info::text = ANY(%s)
    """

    out = {}
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (terms,))
            for row in cur.fetchall():
                term = str(row[0]).strip()
                pid = str(row[1]).strip()
                overall_st = as_float(row[2])
                idx = 3
                courses = {}
                for c in range(1, 7):
                    entry = as_int(row[idx]); idx += 1
                    avg_st = as_float(row[idx]); idx += 1
                    avg_rank = as_float(row[idx]); idx += 1
                    courses[c] = {
                        "entry": max(0, entry or 0),
                        "avg_st": avg_st,
                        "avg_rank": avg_rank,
                    }
                out[(term, pid)] = {
                    "overall_st": overall_st,
                    "courses": courses,
                }
    return out


def load_races(start_ymd, end_ymd):
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
        WHERE SUBSTRING(re.race_code, 1, 8) BETWEEN %s AND %s
        ORDER BY re.race_code, el.entry_course NULLS LAST, re.player_id
    """
    races = defaultdict(list)
    with connect_db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (start_ymd, end_ymd))
            for race_code, player_id, course, ex_st, real_st, rank, has_result in cur.fetchall():
                races[race_code].append({
                    "player_id": str(player_id).strip(),
                    "course": as_int(course),
                    "ex_st": as_float(ex_st),
                    "real_st": as_float(real_st),
                    "rank_raw": rank,
                    "has_result": bool(has_result),
                })
    return races


def build_finish(boats_by_course):
    """1-4着が一意に揃うレースだけ採用。

    5/6着が保存されていれば実順位を使う。
    結果行自体が無い残り艇は、DB仕様に合わせて着外=5.5とする。
    """
    numeric = {}
    for c in range(1, 7):
        b = boats_by_course[c]
        r = as_float(b["rank_raw"]) if b["has_result"] else None
        if r is not None and 1 <= r <= 6:
            numeric[c] = r

    top4 = [numeric.get(c) for c in range(1, 7) if numeric.get(c) in {1.0, 2.0, 3.0, 4.0}]
    if sorted(top4) != [1.0, 2.0, 3.0, 4.0]:
        return None

    finish = {}
    for c in range(1, 7):
        if c in numeric:
            finish[c] = numeric[c]
        else:
            finish[c] = 5.5
    return finish


def safe_course_profile(rr, course):
    overall = rr["overall_st"]
    cp = rr["courses"][course]
    n = cp["entry"] or 0

    avg_st = cp["avg_st"]
    if avg_st is None or avg_st <= 0:
        avg_st = overall
    if avg_st is None or avg_st <= 0:
        return None

    avg_rank = cp["avg_rank"]
    if avg_rank is None or not (1.0 <= avg_rank <= 6.0):
        avg_rank = 3.5

    if overall is None or overall <= 0:
        overall = avg_st

    return {
        "n": n,
        "avg_st": avg_st,
        "avg_rank": avg_rank,
        "overall_st": overall,
    }


def mean(xs):
    return sum(xs) / len(xs)


def rank_component_seconds(ranks, st_values):
    """ST順位を秒スケールへ変換。

    6艇内で、ST順位の1標準偏差をコース平均STの1標準偏差と同じ大きさにする。
    大きいST順位（遅い）ほどプラス秒になる。
    """
    mr = mean(ranks)
    ms = mean(st_values)
    sd_r = pstdev(ranks)
    sd_s = pstdev(st_values)
    if sd_r < 1e-9 or sd_s < 1e-9:
        return [0.0] * 6
    scale = sd_s / sd_r
    return [(r - mr) * scale for r in ranks]


def make_method_st(ex_st, profiles):
    course_st = [p["avg_st"] for p in profiles]
    course_rank = [p["avg_rank"] for p in profiles]

    # A: 展示STそのまま
    a = list(ex_st)

    # B: 普段のコース別平均STが6艇平均との差で速い/遅い分だけ展示STへ足す
    m_course = mean(course_st)
    course_dev = [v - m_course for v in course_st]
    b = [ex_st[i] + course_dev[i] for i in range(6)]

    # C: Bにコース別平均ST順位の相対差を同じ秒スケールで加える
    rank_sec = rank_component_seconds(course_rank, course_st)
    c = [b[i] + rank_sec[i] for i in range(6)]

    # D: コース進入数 n/(n+20) で個人コース値を平滑化してからCと同じ処理
    smooth_st = []
    smooth_rank = []
    for p in profiles:
        w = p["n"] / (p["n"] + K_ENTRY) if p["n"] > 0 else 0.0
        smooth_st.append(p["overall_st"] + w * (p["avg_st"] - p["overall_st"]))
        smooth_rank.append(3.5 + w * (p["avg_rank"] - 3.5))

    m_smooth = mean(smooth_st)
    smooth_dev = [v - m_smooth for v in smooth_st]
    smooth_rank_sec = rank_component_seconds(smooth_rank, smooth_st)
    d = [ex_st[i] + smooth_dev[i] + smooth_rank_sec[i] for i in range(6)]

    return {
        "A_EX": a,
        "B_COURSE_ST": b,
        "C_ST_RANK": c,
        "D_RELIABLE": d,
    }


def new_lane_counts():
    return {c: {"n": 0, "win": 0, "top3": 0, "rank_sum": 0.0} for c in range(1, 7)}


def add_result(counts, course, rank):
    x = counts[course]
    x["n"] += 1
    x["rank_sum"] += rank
    if rank == 1.0:
        x["win"] += 1
    if rank <= 3.0:
        x["top3"] += 1


def rate(x, n):
    return x / n if n else 0.0


def pct(x):
    return f"{x * 100:6.2f}%"


def pp(x):
    return f"{x * 100:+6.2f}"


def metrics(counts, baseline, course):
    c = counts[course]
    b = baseline[course]
    n = c["n"]
    bn = b["n"]
    win = rate(c["win"], n)
    top3 = rate(c["top3"], n)
    bwin = rate(b["win"], bn)
    btop3 = rate(b["top3"], bn)
    return n, win, top3, win - bwin, top3 - btop3


def separation_score(pattern_counts, baseline):
    total_n = 0
    win_sum = 0.0
    top3_sum = 0.0
    for pid in range(1, 13):
        for c in range(1, 7):
            n, _, _, lw, lt = metrics(pattern_counts[pid], baseline, c)
            total_n += n
            win_sum += n * abs(lw)
            top3_sum += n * abs(lt)
    if total_n == 0:
        return 0.0, 0.0
    return win_sum / total_n, top3_sum / total_n


def analyze(start_date, end_date):
    start_dt = datetime.strptime(start_date, "%Y-%m-%d").date()
    end_dt = datetime.strptime(end_date, "%Y-%m-%d").date()
    start_ymd = start_dt.strftime("%Y%m%d")
    end_ymd = end_dt.strftime("%Y%m%d")
    terms = required_terms(start_dt, end_dt)

    settings = load_settings()
    racer = load_racer_results(terms)
    races = load_races(start_ymd, end_ymd)

    baseline = new_lane_counts()
    pattern_counts = {
        m: {pid: new_lane_counts() for pid in range(1, 13)} for m in METHODS
    }
    pattern_freq = {m: Counter() for m in METHODS}
    agreement_a = {m: 0 for m in METHODS}
    fastest = {m: {"n": 0, "win": 0, "top3": 0} for m in METHODS}
    actual_match = {m: 0 for m in METHODS}
    actual_subset = 0

    skip = Counter()
    processed = 0

    for race_code in sorted(races):
        boats = races[race_code]
        if len(boats) != 6 or len({b["player_id"] for b in boats}) != 6:
            skip["not_6_entry"] += 1
            continue

        by_course = {}
        bad_course = False
        for b in boats:
            c = b["course"]
            if c not in range(1, 7) or c in by_course:
                bad_course = True
                break
            by_course[c] = b
        if bad_course or set(by_course) != set(range(1, 7)):
            skip["not_6_exhibition"] += 1
            continue
        if any(by_course[c]["ex_st"] is None for c in range(1, 7)):
            skip["missing_ex_st"] += 1
            continue

        finish = build_finish(by_course)
        if finish is None:
            skip["bad_result"] += 1
            continue

        race_dt = datetime.strptime(race_code[:8], "%Y%m%d").date()
        term = term_info_for_date(race_dt)
        profiles = []
        missing_racer = False
        for c in range(1, 7):
            pid = by_course[c]["player_id"]
            rr = racer.get((term, pid))
            if rr is None:
                missing_racer = True
                break
            p = safe_course_profile(rr, c)
            if p is None:
                missing_racer = True
                break
            profiles.append(p)
        if missing_racer:
            skip["missing_racer_term_or_st"] += 1
            continue

        ex_st = [by_course[c]["ex_st"] for c in range(1, 7)]
        method_st = make_method_st(ex_st, profiles)
        pids = {}

        for c in range(1, 7):
            add_result(baseline, c, finish[c])

        for method, st_list in method_st.items():
            pid, _ = classify_slit_pattern(st_list, settings)
            pids[method] = pid
            pattern_freq[method][pid] += 1
            for c in range(1, 7):
                add_result(pattern_counts[method][pid], c, finish[c])

            fastest_course = min(range(1, 7), key=lambda c: st_list[c - 1])
            fastest[method]["n"] += 1
            if finish[fastest_course] == 1.0:
                fastest[method]["win"] += 1
            if finish[fastest_course] <= 3.0:
                fastest[method]["top3"] += 1

        for method in METHODS:
            agreement_a[method] += int(pids[method] == pids["A_EX"])

        if all(by_course[c]["real_st"] is not None for c in range(1, 7)):
            real_st = [by_course[c]["real_st"] for c in range(1, 7)]
            actual_pid, _ = classify_slit_pattern(real_st, settings)
            actual_subset += 1
            for method in METHODS:
                actual_match[method] += int(pids[method] == actual_pid)

        processed += 1

    return {
        "start": start_date,
        "end": end_date,
        "terms": terms,
        "target": len(races),
        "processed": processed,
        "skip": skip,
        "baseline": baseline,
        "pattern_counts": pattern_counts,
        "pattern_freq": pattern_freq,
        "agreement_a": agreement_a,
        "fastest": fastest,
        "actual_subset": actual_subset,
        "actual_match": actual_match,
    }


def print_result(r):
    p = r["processed"]
    print("=" * 92)
    print("スリット体系 racer_results A/B/C/D 比較")
    print("=" * 92)
    print(f"期間       : {r['start']} ～ {r['end']}")
    print(f"使用期     : {', '.join(r['terms'])}")
    print("期選択規則 : 1-4月=前年10 / 5-10月=当年04 / 11-12月=当年10")
    print(f"対象レース : {r['target']}")
    print(f"処理レース : {p}")
    for k in ["not_6_entry", "not_6_exhibition", "missing_ex_st", "bad_result", "missing_racer_term_or_st"]:
        print(f"skip {k:<25}: {r['skip'][k]}")

    print("\n【方式】")
    print("A_EX        : 展示STのみ")
    print("B_COURSE_ST : 展示ST + (コース平均ST - 今回6艇の平均コースST)")
    print("C_ST_RANK   : B + コース平均ST順位の相対差（ST秒スケールへ変換）")
    print(f"D_RELIABLE  : Cを進入回数 n/(n+{int(K_ENTRY)}) で平滑化")

    if p == 0:
        return

    print("\n【コース基準】")
    for c in range(1, 7):
        b = r["baseline"][c]
        n = b["n"]
        print(
            f"{c}C N={n:>5} 1着={pct(rate(b['win'], n))} "
            f"3連={pct(rate(b['top3'], n))} 平均={b['rank_sum']/n if n else 0:.3f}"
        )

    print("\n【方式サマリ】")
    print("方式            AとPID一致  最速評価艇1着/3連   分離score(1着/3連)   実PID一致※")
    for method in METHODS:
        sep_w, sep_t = separation_score(r["pattern_counts"][method], r["baseline"])
        f = r["fastest"][method]
        actual = (
            f"{r['actual_match'][method]}/{r['actual_subset']}="
            f"{r['actual_match'][method]/r['actual_subset']*100:.2f}%"
            if r["actual_subset"] else "-"
        )
        print(
            f"{method:<15} {r['agreement_a'][method]/p*100:>8.2f}%   "
            f"{pct(rate(f['win'], f['n']))}/{pct(rate(f['top3'], f['n']))}   "
            f"{sep_w*100:>6.2f}/{sep_t*100:>6.2f}pt       {actual}"
        )
    print("※実PID一致は6艇分の本番STがDBに存在するレースだけの補助評価")

    for method, label in METHODS.items():
        print(f"\n【{method}: {label}】")
        print("PID 名称           R数  構成比 | 1C 1着/lift     1C 3連/lift")
        for pid in range(1, 13):
            n = r["pattern_freq"][method][pid]
            if n == 0:
                print(f"{pid:>2}  {PATTERN_NAMES[pid]:<12} {0:>5} {0:>6.2f}% | -")
                continue
            _, w, t, lw, lt = metrics(r["pattern_counts"][method][pid], r["baseline"], 1)
            print(
                f"{pid:>2}  {PATTERN_NAMES[pid]:<12} {n:>5} {n/p*100:>6.2f}% | "
                f"{pct(w)}/{pp(lw)}pt  {pct(t)}/{pp(lt)}pt"
            )

    print("\n見るポイント:")
    print("・B/C/Dで分離scoreがAより上がるか")
    print("・同じPIDの1C liftが独立2期間で同方向に再現するか")
    print("・Cで改善してDでも維持/改善するならST順位＋進入回数補正が有望")
    print("・実PID一致率は補助評価。主評価は予測PID→実着順の分離")
    print("=" * 92)


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/slit_racer_compare.py YYYY-MM-DD YYYY-MM-DD")
        sys.exit(1)
    r = analyze(sys.argv[1], sys.argv[2])
    print_result(r)


if __name__ == "__main__":
    main()
