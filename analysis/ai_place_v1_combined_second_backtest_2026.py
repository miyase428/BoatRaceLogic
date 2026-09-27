#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""本命2着v1を固定し、対抗2着を従来版からv1へ変える合算買い目検証。"""

from __future__ import annotations

import json
import random
from collections import defaultdict
from pathlib import Path

import numpy as np

import ai_place_v1_final_second_backtest_2026 as base


OUTPUT_PATH = Path(__file__).resolve().parent / "output/ai_place_v1_combined_second_backtest_2026.json"
BOOTSTRAP_N = 5000
BOOTSTRAP_SEED = 428


def combine_rows(honmei_rows, taikou_rows):
    honmei_by_code = {row["race_code"]: row for row in honmei_rows}
    taikou_by_code = {row["race_code"]: row for row in taikou_rows}
    rows = []
    for code in sorted(set(honmei_by_code) & set(taikou_by_code)):
        honmei = honmei_by_code[code]
        taikou = taikou_by_code[code]
        current_tickets = honmei["ml_tickets"] | taikou["current_tickets"]
        candidate_tickets = honmei["ml_tickets"] | taikou["ml_tickets"]
        rows.append({
            "race_code": code,
            "race_date": honmei["race_date"],
            "actual": honmei["actual"],
            "payout": honmei["payout"],
            "taikou_head_course": taikou["head_course"],
            "current_tickets": current_tickets,
            "candidate_tickets": candidate_tickets,
            "current_unique_points": len(current_tickets),
            "candidate_unique_points": len(candidate_tickets),
            "gross_points": honmei["points"] + taikou["points"],
        })
    return rows


def evaluate(rows, method):
    tickets_key = f"{method}_tickets"
    points_key = f"{method}_unique_points"
    points = sum(row[points_key] for row in rows)
    hits = sum(row["actual"] in row[tickets_key] for row in rows)
    payout = sum(
        row["payout"]
        for row in rows
        if row["actual"] in row[tickets_key]
    )
    investment = points * 100.0
    return {
        "races": len(rows),
        "unique_points": points,
        "average_unique_points": points / len(rows) if rows else 0.0,
        "trifecta_hits": hits,
        "trifecta_hit_rate": hits / len(rows) if rows else 0.0,
        "return_yen": payout,
        "investment_yen": investment,
        "roi": payout / investment if investment else 0.0,
    }


def pair_change(rows):
    changed = gained = lost = 0
    gained_payouts = []
    lost_payouts = []
    for row in rows:
        before = row["actual"] in row["current_tickets"]
        after = row["actual"] in row["candidate_tickets"]
        changed += int(row["current_tickets"] != row["candidate_tickets"])
        gained += int(after and not before)
        lost += int(before and not after)
        if after and not before:
            gained_payouts.append(float(row["payout"]))
        if before and not after:
            lost_payouts.append(float(row["payout"]))
    return {
        "changed": changed,
        "gained": gained,
        "lost": lost,
        "net": gained - lost,
        "gained_return_yen": sum(gained_payouts),
        "lost_return_yen": sum(lost_payouts),
        "return_net_yen": sum(gained_payouts) - sum(lost_payouts),
        "gained_payout_median_yen": float(np.median(gained_payouts)) if gained_payouts else 0.0,
        "lost_payout_median_yen": float(np.median(lost_payouts)) if lost_payouts else 0.0,
        "gained_payout_max_yen": max(gained_payouts, default=0.0),
        "lost_payout_max_yen": max(lost_payouts, default=0.0),
    }


def summarize(rows):
    current = evaluate(rows, "current")
    candidate = evaluate(rows, "candidate")
    return {
        "current_production": current,
        "candidate_all_v1": candidate,
        "change": pair_change(rows),
        "delta": {
            "unique_points": candidate["unique_points"] - current["unique_points"],
            "trifecta_hits": candidate["trifecta_hits"] - current["trifecta_hits"],
            "trifecta_hit_rate": candidate["trifecta_hit_rate"] - current["trifecta_hit_rate"],
            "roi": candidate["roi"] - current["roi"],
        },
    }


def bootstrap(rows):
    by_day = defaultdict(list)
    for row in rows:
        by_day[row["race_date"]].append(row)
    days = sorted(by_day)
    rng = random.Random(BOOTSTRAP_SEED)
    hit_deltas = []
    roi_deltas = []
    for _ in range(BOOTSTRAP_N):
        sample = []
        for _index in range(len(days)):
            sample.extend(by_day[rng.choice(days)])
        result = summarize(sample)["delta"]
        hit_deltas.append(result["trifecta_hit_rate"])
        roi_deltas.append(result["roi"])
    return {
        "days": len(days),
        "iterations": BOOTSTRAP_N,
        "trifecta_hit_rate_delta": {
            "median": float(np.median(hit_deltas)),
            "ci95": [float(np.quantile(hit_deltas, 0.025)), float(np.quantile(hit_deltas, 0.975))],
            "improvement_probability": sum(value > 0 for value in hit_deltas) / len(hit_deltas),
        },
        "roi_delta": {
            "median": float(np.median(roi_deltas)),
            "ci95": [float(np.quantile(roi_deltas, 0.025)), float(np.quantile(roi_deltas, 0.975))],
            "improvement_probability": sum(value > 0 for value in roi_deltas) / len(roi_deltas),
        },
    }


def print_summary(label, result):
    current = result["current_production"]
    candidate = result["candidate_all_v1"]
    print(f"\n【{label}】 N={current['races']:,}")
    print("方式                   的中数  的中率  平均点数      ROI")
    for name, row in (("CURRENT_PRODUCTION", current), ("ALL_SECOND_V1", candidate)):
        print(
            f"{name:<22} {row['trifecta_hits']:>5d}  "
            f"{row['trifecta_hit_rate']*100:>6.2f}%  "
            f"{row['average_unique_points']:>7.2f}  "
            f"{row['roi']*100:>7.2f}%"
        )
    change = result["change"]
    print(
        f"拾い/失い : 変更={change['changed']}R / 拾い={change['gained']} / "
        f"失い={change['lost']} / 純増={change['net']:+d}"
    )


def main():
    print("AI着順率v1の8月末時点モデルを再学習しています…", flush=True)
    races, _place_to_id, source_skip = base.place_train.build_races()
    train_races = [race for race in races if race["race_date"] <= base.TRAIN_END]
    test_races = [race for race in races if base.TEST_START <= race["race_date"] <= base.TEST_END]
    x, y, groups = base.place_train.matrices(train_races, "second")
    model = base.place_train.make_ranker()
    model.fit(x, y, group=groups)

    current_rows = base.load_current_races()
    payouts = base.payout_util.load_trifecta_payouts(base.TEST_START, base.TEST_END)
    honmei_rows, honmei_skip = base.build_comparison(model, test_races, current_rows, payouts, "honmei")
    taikou_rows, taikou_skip = base.build_comparison(model, test_races, current_rows, payouts, "taikou")
    rows = combine_rows(honmei_rows, taikou_rows)
    if not rows:
        raise RuntimeError("合算比較可能な完全ホールドアウトレースがありません")

    all_summary = summarize(rows)
    taikou_1c = summarize([row for row in rows if row["taikou_head_course"] == 1])
    taikou_non_1c = summarize([row for row in rows if row["taikou_head_course"] != 1])
    bootstrap_result = bootstrap(rows)
    gross_points = sum(row["gross_points"] for row in rows)
    report = {
        "purpose": "現行本番（本命2着v1＋対抗2着従来）と全2着v1を、本命・対抗の重複除外合算で比較",
        "production_changed": False,
        "periods": {
            "train_end": base.TRAIN_END.isoformat(),
            "tuning": ["2026-09-01", "2026-09-10"],
            "test": [base.TEST_START.isoformat(), base.TEST_END.isoformat()],
        },
        "model": {"temperature": base.TEMPERATURE, "alpha": base.ALPHA},
        "coverage": {
            "feature_test_races": len(test_races),
            "compared_races": len(rows),
            "source_skip": source_skip,
            "honmei_skip": honmei_skip,
            "taikou_skip": taikou_skip,
        },
        "point_definition": {
            "gross_points_each_side_sum": gross_points,
            "gross_points_same": True,
            "evaluation_points": "本命・対抗の重複買い目を除外した実購入点数",
        },
        "all": all_summary,
        "taikou_head_1c": taikou_1c,
        "taikou_head_non_1c": taikou_non_1c,
        "bootstrap": bootstrap_result,
    }
    OUTPUT_PATH.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")

    print("=" * 92)
    print("合算最終買い目：本命2着v1＋対抗2着従来 vs 本命・対抗とも2着v1")
    print("=" * 92)
    print(f"比較可能 {len(rows):,}R / 各側点数合計は同一 / 重複除外点数でROI計算")
    print_summary("ALL", all_summary)
    print_summary("対抗1C頭", taikou_1c)
    print_summary("対抗非1C頭", taikou_non_1c)
    boot = bootstrap_result["trifecta_hit_rate_delta"]
    print(
        "\n日単位bootstrap 的中率差: "
        f"median={boot['median']*100:+.3f}pt / "
        f"95%CI=[{boot['ci95'][0]*100:+.3f}, {boot['ci95'][1]*100:+.3f}]pt / "
        f"改善確率={boot['improvement_probability']*100:.2f}%"
    )
    print(f"保存: {OUTPUT_PATH}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
