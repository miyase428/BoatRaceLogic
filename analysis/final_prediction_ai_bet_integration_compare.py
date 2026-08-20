#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
最終予想 AI活用 STEP B4-1：3連単買い目統合

目的
----
STEP B1～B3で有望だったAI情報を、実際の本命3連単買い目へ順番に反映し、
的中率・平均点数・回収率がどう変わるかをP1/P2で比較する。

比較
----
CURRENT
    現行本命 + 現行相手候補 + 現行kiru。

WIN_HEAD
    本命だけ補正後1着率1位へ変更。相手候補は現行順位ベース。

WIN_HEAD_OUTCOME_AITE
    本命=補正後1着率1位。
    2着候補=STEP3出目モデル P(2着|本命頭) 上位最大3艇。
    kiruは現行固定（ただし本命艇自身がkiruなら頭なので自動解除）。

AI_FULL_R45 / R50 / R55 / R60
    上記に加え、現行kiru艇のうちSTEP3出目モデル艇別3連対周辺確率が
    threshold以上の艇だけ救済する。

投資評価
--------
1) 100円/点
   実際の購入点数に応じて投資額が増える従来健康診断方式。

2) 1R=1000円均等（理論値）
   そのレースの全買い目へ1000円を均等配分したと仮定。
   100円単位丸めは行わない。点数増加の影響を公平に比較するための指標。

重要
----
- P1/P2共通AI母集団のみで比較する。
- 3連単払戻はboat_race.race_payouts.trifecta_payoutを使用する。
- 本番Web/PredictionLogicは変更しない。
- cut救済thresholdはB4結果を見るまでは本番採用しない。

Usage:
python3 analysis/final_prediction_ai_bet_integration_compare.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv
"""

from __future__ import annotations

import sys
from collections import defaultdict
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import final_prediction_ai_favorite_compare as b1
import final_prediction_ai_opponent_compare as b2
import final_prediction_ai_cut_rescue_compare as b3
import trifecta_probability_order_compare as step3
from slit_validate_v2 import connect_db


RESCUE_THRESHOLDS = (0.45, 0.50, 0.55, 0.60)


def load_payouts(start_date, end_date):
    sql = """
        SELECT rp.race_code, rp.trifecta_payout
        FROM boat_race.race_payouts rp
        JOIN boat_race.race_master rm
          ON rm.race_code = rp.race_code
        WHERE rm.race_date >= %s::date
          AND rm.race_date <= %s::date
    """
    out = {}
    with connect_db() as conn:
        cur = conn.cursor()
        cur.execute(sql, (str(start_date), str(end_date)))
        for race_code, payout in cur.fetchall():
            if payout is None:
                continue
            try:
                p = int(payout)
            except (TypeError, ValueError):
                continue
            if p > 0:
                out[str(race_code)] = p
        cur.close()
    return out


def actual_trifecta(boats):
    by_rank = {}
    for rank_no in (1, 2, 3):
        lanes = [
            lane for lane, row in boats.items()
            if float(row.get("actual_rank", 0.0)) == float(rank_no)
        ]
        if len(lanes) != 1:
            return None
        by_rank[rank_no] = int(lanes[0])
    return (by_rank[1], by_rank[2], by_rank[3])


def expand_bets(head, second_candidates, third_candidates):
    bets = set()
    for second in second_candidates:
        for third in third_candidates:
            if head == second or head == third or second == third:
                continue
            bets.add((int(head), int(second), int(third)))
    return bets


def current_cut(boats):
    return {
        int(lane)
        for lane, row in boats.items()
        if int(row.get("kiru", 0)) == 1
    }


def current_bets(boats):
    rank_boats, head = b2.current_order_and_head(boats)
    if not rank_boats or head is None:
        return None
    cut = current_cut(boats)
    cut.discard(int(head))
    eligible = [b for b in rank_boats if b != head and b not in cut]
    second = eligible[: min(3, len(eligible))]
    third = list(eligible)
    return {
        "head": int(head),
        "cut": cut,
        "second": second,
        "third": third,
        "bets": expand_bets(head, second, third),
    }


def ai_signals(record):
    win, trio, outcome_head = b1.marginal_signals(record)
    win_head, _, _ = b1.top_info(win)
    outcome_top3 = b3.outcome_top3_scores(record)
    return win, trio, outcome_head, int(win_head), outcome_top3


def make_win_head_bets(boats, win_head):
    rank_boats, _ = b2.current_order_and_head(boats)
    if not rank_boats:
        return None
    head = int(win_head)
    cut = current_cut(boats)
    cut.discard(head)
    eligible = [b for b in rank_boats if b != head and b not in cut]
    second = eligible[: min(3, len(eligible))]
    third = list(eligible)
    return {
        "head": head,
        "cut": cut,
        "second": second,
        "third": third,
        "bets": expand_bets(head, second, third),
    }


def make_outcome_bets(record, boats, win_head, outcome_top3, rescue_threshold=None):
    head = int(win_head)
    cut = current_cut(boats)
    cut.discard(head)

    if rescue_threshold is not None:
        rescue = {
            lane for lane in list(cut)
            if float(outcome_top3.get(lane, 0.0)) >= float(rescue_threshold)
        }
        cut -= rescue
    else:
        rescue = set()

    eligible = [lane for lane in range(1, 7) if lane != head and lane not in cut]
    if not eligible:
        return None

    second_scores = b2.outcome_second_scores(record, head)
    second = b2.select_score(second_scores, eligible, min(3, len(eligible)))
    third = list(eligible)

    return {
        "head": head,
        "cut": cut,
        "rescued": rescue,
        "second": second,
        "third": third,
        "bets": expand_bets(head, second, third),
    }


def build_rows(common, csv_races, payouts):
    out = {"P1": [], "P2": []}
    skip = defaultdict(int)

    for period in ("P1", "P2"):
        for record in common[period]:
            code = str(record["race_code"])
            boats = csv_races.get(code)
            if boats is None or set(boats) != set(range(1, 7)):
                skip[f"{period}_csv_missing"] += 1
                continue

            actual = actual_trifecta(boats)
            if actual is None:
                skip[f"{period}_actual_invalid"] += 1
                continue

            payout = payouts.get(code)
            if payout is None or payout <= 0:
                skip[f"{period}_payout_missing"] += 1
                continue

            cur = current_bets(boats)
            if cur is None or not cur["bets"]:
                skip[f"{period}_current_empty"] += 1
                continue

            _, _, _, win_head, outcome_top3 = ai_signals(record)
            win_only = make_win_head_bets(boats, win_head)
            outcome = make_outcome_bets(record, boats, win_head, outcome_top3, None)
            if win_only is None or outcome is None or not win_only["bets"] or not outcome["bets"]:
                skip[f"{period}_ai_empty"] += 1
                continue

            scenarios = {
                "CURRENT": cur,
                "WIN_HEAD": win_only,
                "WIN_HEAD_OUTCOME_AITE": outcome,
            }
            for th in RESCUE_THRESHOLDS:
                s = make_outcome_bets(record, boats, win_head, outcome_top3, th)
                if s is None or not s["bets"]:
                    continue
                scenarios[f"AI_FULL_R{int(th * 100)}"] = s

            required = {
                "CURRENT",
                "WIN_HEAD",
                "WIN_HEAD_OUTCOME_AITE",
                *{f"AI_FULL_R{int(th * 100)}" for th in RESCUE_THRESHOLDS},
            }
            if set(scenarios) != required:
                skip[f"{period}_scenario_incomplete"] += 1
                continue

            out[period].append({
                "race_code": code,
                "actual": actual,
                "payout": int(payout),
                "scenarios": scenarios,
            })
            skip[f"{period}_ready"] += 1

    return out, skip


def evaluate(rows, scenario):
    n = 0
    hits = 0
    points = 0
    invest_per_point = 0.0
    return_per_point = 0.0
    invest_fixed = 0.0
    return_fixed = 0.0

    for row in rows:
        s = row["scenarios"][scenario]
        bets = s["bets"]
        cnt = len(bets)
        if cnt <= 0:
            continue

        n += 1
        points += cnt
        invest_per_point += cnt * 100.0
        invest_fixed += 1000.0

        hit = row["actual"] in bets
        if hit:
            hits += 1
            payout = float(row["payout"])
            return_per_point += payout
            stake_each = 1000.0 / cnt
            return_fixed += payout * (stake_each / 100.0)

    return {
        "n": n,
        "hits": hits,
        "hit_rate": hits / n if n else 0.0,
        "avg_points": points / n if n else 0.0,
        "invest_per_point": invest_per_point,
        "return_per_point": return_per_point,
        "roi_per_point": return_per_point / invest_per_point if invest_per_point else 0.0,
        "invest_fixed": invest_fixed,
        "return_fixed": return_fixed,
        "roi_fixed": return_fixed / invest_fixed if invest_fixed else 0.0,
    }


def compare_hits(rows, scenario):
    gained = 0
    lost = 0
    both = 0
    neither = 0
    changed_bets = 0
    for row in rows:
        cur = row["scenarios"]["CURRENT"]["bets"]
        new = row["scenarios"][scenario]["bets"]
        if cur != new:
            changed_bets += 1
        c = row["actual"] in cur
        a = row["actual"] in new
        if a and not c:
            gained += 1
        elif c and not a:
            lost += 1
        elif a and c:
            both += 1
        else:
            neither += 1
    return changed_bets, gained, lost, both, neither


def print_period(title, rows):
    print(f"\n【{title}】")
    print(
        "方式                         R数   平均点数   的中率    100円/点ROI   1000円均等ROI   "
        "買目変更   拾い  失い"
    )
    print("-" * 122)

    scenarios = [
        "CURRENT",
        "WIN_HEAD",
        "WIN_HEAD_OUTCOME_AITE",
        *[f"AI_FULL_R{int(th * 100)}" for th in RESCUE_THRESHOLDS],
    ]
    results = {}
    for name in scenarios:
        r = evaluate(rows, name)
        results[name] = r
        ch, gain, lost, _, _ = compare_hits(rows, name)
        print(
            f"{name:<28} {r['n']:>5d}   {r['avg_points']:>7.2f}   "
            f"{r['hit_rate']*100:>6.2f}%     {r['roi_per_point']*100:>8.2f}%       "
            f"{r['roi_fixed']*100:>8.2f}%   {ch:>7d}  {gain:>5d} {lost:>5d}"
        )

    return results


def select_p1_threshold(p1_results):
    # 実戦の1R予算固定を優先し、同率なら100円/点ROI→的中率→少点数の順。
    candidates = []
    for th in RESCUE_THRESHOLDS:
        name = f"AI_FULL_R{int(th * 100)}"
        r = p1_results[name]
        key = (
            -r["roi_fixed"],
            -r["roi_per_point"],
            -r["hit_rate"],
            r["avg_points"],
            th,
        )
        candidates.append((key, th, name))
    candidates.sort(key=lambda x: x[0])
    return candidates[0][1], candidates[0][2]


def print_delta(label, base, new):
    print(f"\n【{label}】")
    print(f"平均点数差      : {new['avg_points'] - base['avg_points']:+.2f}点/R")
    print(f"的中率差        : {(new['hit_rate'] - base['hit_rate'])*100:+.2f}pt")
    print(f"100円/点ROI差   : {(new['roi_per_point'] - base['roi_per_point'])*100:+.2f}pt")
    print(f"1000円均等ROI差 : {(new['roi_fixed'] - base['roi_fixed'])*100:+.2f}pt")


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/final_prediction_ai_bet_integration_compare.py P1_BOATS_CSV P2_BOATS_CSV")
        sys.exit(1)

    p1_csv, p2_csv = sys.argv[1], sys.argv[2]

    print("STEP3共通AIデータ・現行CSV・3連単払戻を結合中...")
    data = step3.build_common_records(p1_csv, p2_csv)
    csv_races = b2.load_boats(p1_csv, p2_csv)
    payouts = load_payouts(data["p1_start"], data["p2_end"])
    rows, skip = build_rows(data["records"], csv_races, payouts)

    if not rows["P1"] or not rows["P2"]:
        raise RuntimeError("P1/P2の共通評価レースがありません")

    print("=" * 126)
    print("最終予想 AI活用 STEP B4-1：3連単買い目統合")
    print("=" * 126)
    print(f"P1                  : {data['p1_start']} ～ {data['p1_end']}")
    print(f"P2完全ホールドアウト: {data['p2_start']} ～ {data['p2_end']}")
    print("本命AI               : 補正後1着率1位（B1有力候補）")
    print("相手AI               : STEP3 P(2着|本命頭) 上位最大3艇（B2最良）")
    print("cut救済              : STEP3艇別3連対周辺確率 45/50/55/60%")
    print("投資方式             : 100円/点 + 1R1000円均等理論値")
    print("本番Web変更          : なし")
    print(f"\n【共通評価母集団】P1={len(rows['P1'])}R / P2={len(rows['P2'])}R / 払戻取得={len(payouts)}R")

    p1_results = print_period("P1 閾値選択用", rows["P1"])
    selected_th, selected_name = select_p1_threshold(p1_results)
    print(f"\nP1の1R1000円均等ROIを最優先して選択したcut救済閾値: {selected_th*100:.0f}% ({selected_name})")

    p2_results = print_period("P2 完全ホールドアウト（最重要）", rows["P2"])

    print_delta(
        f"P2 CURRENT → {selected_name}（P1選択を固定）",
        p2_results["CURRENT"],
        p2_results[selected_name],
    )
    print_delta(
        "P2 CURRENT → WIN_HEAD_OUTCOME_AITE（cut救済なし）",
        p2_results["CURRENT"],
        p2_results["WIN_HEAD_OUTCOME_AITE"],
    )

    print("\n【判断方針】")
    print("1. B1/B2を積むごとにP2的中率・回収率が改善するか確認")
    print("2. cut救済はP1で選んだthresholdをP2で変更しない")
    print("3. 救済で的中率が増えても、平均点数増に対して1000円均等ROIが悪化するなら採用しない")
    print("4. 100円/点ROIは従来健康診断との比較、1000円均等ROIは点数差を正規化した実戦参考")
    print("5. B4-1確定後、本番ロジックへ入れる前にB4-2でイン1着時2連単の買い方を別検証する")
    print("6. このスクリプトは検証のみで本番PredictionLogic/Webは変更しない")
    print("=" * 126)


if __name__ == "__main__":
    main()
