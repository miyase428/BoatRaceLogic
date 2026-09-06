#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
配当傾向サイン連動「穴目」買い目候補の検証。

目的
----
画面上の「本命 / 対抗」に加える第3の買い目として、固定済みの配当傾向・荒れ方サインに
連動した「穴目」買い目を作る価値があるか確認する。

荒れ方ごとの考え方
------------------
イン崩壊:
  1C頭を疑い、非1C頭を使う。
  - TRIO1_OUTCOME      : 非1C AI3連対率1位を頭
  - TRIO_TOP2_OUTCOME  : 非1C AI3連対率上位2艇を頭（和集合）
  - WEB_NON1_UNION     : Web本命/対抗のうち非1Cだけを頭（和集合）

ヒモ荒れ:
  1C頭を維持し、2・3着側を変える。
  - IN_CURRENTLIKE : 1C頭 + 現行順位型相手
  - IN_OUTCOME     : 1C頭 + 120通り P(2着|頭) 上位最大3艇 / 現行cut維持

複合高配当:
  まだ買い方未固定。参考として非1C頭・1C頭の両方向を比較する。

共通
----
- OUTCOME方式の3着側は現行cutを除く対象艇。
- 払戻は race_payouts.trifecta_payout。
- 100円/点ROIと、1R=1000円均等の理論ROIを出す。
- 配当サインの閾値は PayoutSignalClassifier v1 候補と同じで変更しない。
- 2026-08-15～08-31を候補比較、2026-09-01～09-05を固定確認として使う想定。
- このスクリプトは検証のみ。本番買い目は変更しない。

Usage:
python3 analysis/validate_payout_signal_hole_bet_candidates.py \
  analysis/output/final_prediction_boats_fast_cached_20260815_20260822.csv \
  analysis/output/final_prediction_boats_fast_cached_20260823_20260831.csv \
  analysis/output/final_prediction_boats_fast_cached_20260901_20260905.csv \
  analysis/output/kimarite_analysis_dataset_20260815_20260822.csv \
  analysis/output/kimarite_analysis_dataset_20260823_20260831.csv \
  analysis/output/kimarite_analysis_dataset_20260901_20260905.csv
"""

from __future__ import annotations

import csv
import sys
from collections import defaultdict
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import final_prediction_ai_opponent_compare as b2
import final_prediction_ai_bet_integration_compare as b4
import trifecta_probability_order_compare as step3
import upset_probability_validate as c1
import upset_top2_bet_validate as c4


TYPE_METHODS = {
    "イン崩壊": (
        "CURRENT",
        "TRIO1_OUTCOME",
        "TRIO_TOP2_OUTCOME",
        "WEB_NON1_UNION",
    ),
    "ヒモ荒れ": (
        "CURRENT",
        "IN_CURRENTLIKE",
        "IN_OUTCOME",
    ),
    "複合高配当": (
        "CURRENT",
        "TRIO1_OUTCOME",
        "WEB_NON1_UNION",
        "IN_OUTCOME",
    ),
}


def safe_float(v):
    if v is None:
        return None
    s = str(v).strip()
    if s == "":
        return None
    try:
        return float(s)
    except (TypeError, ValueError):
        return None


def safe_int(v):
    x = safe_float(v)
    return int(x) if x is not None else None


def level(score, pairs):
    if pairs:
        return "strong"
    return "watch" if score >= 2 else "low"


def classify_kimarite_row(row):
    code = str(row.get("race_code", "")).strip()
    if len(code) < 2 or not code[-2:].isdigit():
        return None
    race_no = int(code[-2:])

    c1_n = safe_int(row.get("c1_1y_sample_n")) or 0
    nige = safe_float(row.get("c1_1y_nige"))
    if c1_n < 10 or nige is None:
        return None

    attacks = []
    sashis = []
    makuris = []
    wins = []
    for course in range(2, 7):
        n = safe_int(row.get(f"c{course}_1y_sample_n")) or 0
        if n < 10:
            continue
        win = safe_float(row.get(f"c{course}_1y_win"))
        sashi = safe_float(row.get(f"c{course}_1y_sashi"))
        makuri = safe_float(row.get(f"c{course}_1y_makuri"))
        if win is None or sashi is None or makuri is None:
            continue
        wins.append(win)
        sashis.append(sashi)
        makuris.append(makuri)
        attacks.append(sashi + makuri)

    if len(attacks) < 2:
        return None

    attacks_sorted = sorted(attacks, reverse=True)
    attack_max = attacks_sorted[0]
    attack_gap = attacks_sorted[0] - attacks_sorted[1]
    attack_count20 = sum(1 for x in attacks if x >= 20.0)
    sashi_max = max(sashis)
    makuri_max = max(makuris)
    outer_win_max = max(wins)

    honmei = safe_int(row.get("honmei_head"))
    taikou = safe_int(row.get("taikou_head"))
    web_both_non1 = (
        honmei is not None and taikou is not None
        and 1 <= honmei <= 6 and 1 <= taikou <= 6
        and honmei != 1 and taikou != 1
    )
    early = 1 <= race_no <= 4
    late = 9 <= race_no <= 12
    strong_attack = (
        (30.0 <= attack_max < 40.0)
        or (15.0 <= makuri_max < 20.0)
        or (sashi_max >= 25.0)
    )

    medium_flags = (
        nige < 50.0,
        web_both_non1,
        early,
        20.0 <= makuri_max < 30.0,
        attack_count20 == 2,
    )
    medium_score = sum(1 for x in medium_flags if x)

    high_flags = (
        nige < 40.0,
        web_both_non1,
        early,
        strong_attack,
        outer_win_max >= 40.0,
        attack_gap < 3.0,
    )
    high_score = sum(1 for x in high_flags if x)
    high_pairs = [
        (nige < 40.0) and early,
        web_both_non1 and early,
        (nige < 40.0) and web_both_non1,
        web_both_non1 and strong_attack,
    ]
    high_matched = any(high_pairs)

    big_flags = (
        60.0 <= nige < 70.0,
        late,
        15.0 <= makuri_max < 20.0,
        outer_win_max < 15.0,
        attack_count20 == 2,
    )
    big_score = sum(1 for x in big_flags if x)
    big_pairs = [
        (60.0 <= nige < 70.0) and (15.0 <= makuri_max < 20.0),
        late and (15.0 <= makuri_max < 20.0),
        (60.0 <= nige < 70.0) and late,
        late and (outer_win_max < 15.0),
    ]
    big_matched = any(big_pairs)

    medium_level = "strong" if medium_score >= 3 else ("watch" if medium_score >= 2 else "low")
    high_level = level(high_score, high_matched)
    big_level = level(big_score, big_matched)

    in_collapse = (
        (nige < 50.0 and (web_both_non1 or early))
        or medium_score >= 3
    )
    himo_chaos = (
        (late and 60.0 <= nige < 70.0)
        or (late and 15.0 <= makuri_max < 20.0)
        or (big_score >= 2 and not in_collapse)
    )
    compound_high = high_matched

    primary = "平常"
    if himo_chaos and big_level == "strong":
        primary = "ヒモ荒れ"
    elif in_collapse:
        primary = "イン崩壊"
    elif compound_high:
        primary = "複合高配当"
    elif himo_chaos:
        primary = "ヒモ荒れ"

    return {
        "race_code": code,
        "primary": primary,
        "honmei": honmei,
        "taikou": taikou,
        "medium_score": medium_score,
        "medium_level": medium_level,
        "high_score": high_score,
        "high_level": high_level,
        "big_score": big_score,
        "big_level": big_level,
    }


def load_signal_map(*paths):
    out = {}
    for path in paths:
        with open(path, "r", encoding="utf-8-sig", newline="") as f:
            for row in csv.DictReader(f):
                classified = classify_kimarite_row(row)
                if classified is not None:
                    out[classified["race_code"]] = classified
    return out


def make_union(record, boats, heads):
    uniq = []
    for h in heads:
        if h is None:
            continue
        h = int(h)
        if 1 <= h <= 6 and h not in uniq:
            uniq.append(h)
    if not uniq:
        return None
    return c4.make_outcome_union(record, boats, uniq)


def build_row(record, boats, payout, signal):
    if boats is None or set(boats) != set(range(1, 7)):
        return None
    if payout is None or int(payout) <= 0:
        return None
    if signal is None or signal.get("primary") not in TYPE_METHODS:
        return None

    actual = b4.actual_trifecta(boats)
    if actual is None:
        return None

    f = c1.make_features(record, boats)
    if f is None:
        return None

    in_lane = int(f["in_lane"])
    current = int(f["current_head"])
    trio_rank = c4.ranked_outer(f["trio"], in_lane)
    if len(trio_rank) < 2:
        return None
    trio1, trio2 = int(trio_rank[0]), int(trio_rank[1])

    current_bets = b4.current_bets(boats)
    trio1_outcome = make_union(record, boats, (trio1,))
    trio_top2_outcome = make_union(record, boats, (trio1, trio2))
    in_outcome = make_union(record, boats, (in_lane,))
    in_currentlike = b4.make_win_head_bets(boats, in_lane)

    web_heads = []
    for h in (signal.get("honmei"), signal.get("taikou")):
        if h is None:
            continue
        h = int(h)
        if 1 <= h <= 6 and h != in_lane and h not in web_heads:
            web_heads.append(h)
    web_non1 = make_union(record, boats, web_heads)

    scenarios = {}
    for name, scenario in (
        ("CURRENT", current_bets),
        ("TRIO1_OUTCOME", trio1_outcome),
        ("TRIO_TOP2_OUTCOME", trio_top2_outcome),
        ("WEB_NON1_UNION", web_non1),
        ("IN_CURRENTLIKE", in_currentlike),
        ("IN_OUTCOME", in_outcome),
    ):
        if scenario is not None and scenario.get("bets"):
            scenarios[name] = scenario

    if "CURRENT" not in scenarios:
        return None

    return {
        "race_code": str(record["race_code"]),
        "type": signal["primary"],
        "actual": tuple(int(x) for x in actual),
        "actual_first": int(actual[0]),
        "payout": int(payout),
        "in_lane": in_lane,
        "current_head": current,
        "trio1": trio1,
        "trio2": trio2,
        "scenarios": scenarios,
    }


def build_rows(records, boats_map, payouts, signal_map):
    rows = []
    skip = defaultdict(int)
    for record in records:
        code = str(record["race_code"])
        signal = signal_map.get(code)
        if signal is None:
            skip["signal_missing_or_normal"] += 1
            continue
        row = build_row(record, boats_map.get(code), payouts.get(code), signal)
        if row is None:
            skip["not_ready"] += 1
            continue
        rows.append(row)
        skip["ready"] += 1
    return rows, skip


def evaluate(rows, method):
    total = len(rows)
    selected = [r for r in rows if method in r["scenarios"]]
    n = len(selected)
    hits = 0
    head_hits = 0
    points = 0
    invest100 = 0.0
    ret100 = 0.0
    invest_fixed = 0.0
    ret_fixed = 0.0
    hit_payout_sum = 0.0

    for r in selected:
        s = r["scenarios"][method]
        bets = set(s["bets"])
        heads = tuple(int(x) for x in s.get("heads", (s.get("head"),)) if x is not None)
        cnt = len(bets)
        if cnt <= 0:
            continue
        points += cnt
        invest100 += cnt * 100.0
        invest_fixed += 1000.0
        if r["actual_first"] in heads:
            head_hits += 1
        if r["actual"] in bets:
            hits += 1
            payout = float(r["payout"])
            hit_payout_sum += payout
            ret100 += payout
            ret_fixed += payout * ((1000.0 / cnt) / 100.0)

    return {
        "total": total,
        "n": n,
        "coverage": n / total if total else 0.0,
        "head_rate": head_hits / n if n else 0.0,
        "avg_points": points / n if n else 0.0,
        "hit_rate": hits / n if n else 0.0,
        "roi100": ret100 / invest100 if invest100 else 0.0,
        "roi_fixed": ret_fixed / invest_fixed if invest_fixed else 0.0,
        "avg_hit_payout": hit_payout_sum / hits if hits else 0.0,
    }


def compare_current(rows, method):
    eligible = [r for r in rows if method in r["scenarios"] and "CURRENT" in r["scenarios"]]
    gained = lost = both = neither = 0
    for r in eligible:
        cur = r["actual"] in r["scenarios"]["CURRENT"]["bets"]
        new = r["actual"] in r["scenarios"][method]["bets"]
        if new and not cur:
            gained += 1
        elif cur and not new:
            lost += 1
        elif cur and new:
            both += 1
        else:
            neither += 1
    return {"n": len(eligible), "gained": gained, "lost": lost, "both": both, "neither": neither}


def type_profile(rows):
    n = len(rows)
    if not n:
        return "-"
    in_head = sum(1 for r in rows if r["actual_first"] == r["in_lane"])
    return f"1C頭={in_head/n*100:.2f}% / 非1C頭={(n-in_head)/n*100:.2f}%"


def print_period(title, rows, skip):
    print("\n" + "=" * 132)
    print(f"【{title}】 サイン連動穴目候補")
    print("=" * 132)
    print("skip参考:", dict(skip))

    for race_type, methods in TYPE_METHODS.items():
        part = [r for r in rows if r["type"] == race_type]
        print(f"\n--- {race_type}  N={len(part)} / {type_profile(part)} ---")
        if not part:
            continue
        print("方式                    対象N/全体  頭捕捉   平均点数  的中率  100円/点ROI  1000円均等ROI  的中平均払戻  CURRENT比(拾/失)")
        print("-" * 132)
        for method in methods:
            m = evaluate(part, method)
            cmp = compare_current(part, method) if method != "CURRENT" else None
            diff = "-" if cmp is None else f"{cmp['gained']}/{cmp['lost']}"
            print(
                f"{method:<22} {m['n']:>4d}/{m['total']:<4d}  "
                f"{m['head_rate']*100:>6.2f}%   {m['avg_points']:>7.2f}   "
                f"{m['hit_rate']*100:>6.2f}%    {m['roi100']*100:>8.2f}%      "
                f"{m['roi_fixed']*100:>8.2f}%       {m['avg_hit_payout']:>8.0f}円    {diff}"
            )


def main():
    if len(sys.argv) != 7:
        print(
            "Usage: python3 analysis/validate_payout_signal_hole_bet_candidates.py "
            "P1_BOATS P2_BOATS P3_BOATS P1_KIMARITE P2_KIMARITE P3_KIMARITE",
            file=sys.stderr,
        )
        sys.exit(1)

    p1_boats, p2_boats, p3_boats, p1_k, p2_k, p3_k = sys.argv[1:]

    print("共通120通りレコード構築中...", flush=True)
    train = step3.build_common_records(p1_boats, p2_boats)
    future = step3.build_common_records(p2_boats, p3_boats)
    boats_map = b2.load_boats(p1_boats, p2_boats, p3_boats)
    payouts = b4.load_payouts(train["p1_start"], future["p2_end"])
    signal_map = load_signal_map(p1_k, p2_k, p3_k)

    p1_rows, p1_skip = build_rows(train["records"]["P1"], boats_map, payouts, signal_map)
    p2_rows, p2_skip = build_rows(train["records"]["P2"], boats_map, payouts, signal_map)
    p3_rows, p3_skip = build_rows(future["records"]["P2"], boats_map, payouts, signal_map)
    dev_rows = p1_rows + p2_rows
    dev_skip = defaultdict(int)
    for d in (p1_skip, p2_skip):
        for k, v in d.items():
            dev_skip[k] += v

    print("=" * 132)
    print("配当傾向サイン連動『穴目』買い目候補検証")
    print("=" * 132)
    print(f"DEV固定比較 : {train['p1_start']} ～ {train['p2_end']}")
    print(f"前方確認    : {future['p2_start']} ～ {future['p2_end']}")
    print("サイン定義  : PayoutSignalClassifier v1候補と同じ（閾値再調整なし）")
    print("買い目      : 現行cut維持 / OUTCOMEはP(2着|頭)上位最大3艇 / 3着=非cut艇")
    print("本番変更    : なし")

    print_period("DEV 8/15～8/31", dev_rows, dev_skip)
    print_period("前方 9/1～9/5", p3_rows, p3_skip)

    print("\n【判断ポイント】")
    print("1. イン崩壊では、TRIO1_OUTCOME / TRIO_TOP2_OUTCOME / WEB_NON1_UNION のどれがDEV→前方で頭捕捉・的中を再現するか。")
    print("2. ヒモ荒れでは、IN_OUTCOMEがCURRENTより追加的中を拾いつつ点数を増やし過ぎないか。")
    print("3. ROIは率だけでなくNと期間再現を見る。前方Nが小さい方式は観察継続。")
    print("4. 複合高配当はこの段階では探索扱い。DEV/前方で方向が揃うまで穴目方式を固定しない。")
    print("5. 採用する場合も『本命/対抗を置換』ではなく、第3の『穴目』参考買い目として表示する。")
    print("=" * 132)


if __name__ == "__main__":
    main()
