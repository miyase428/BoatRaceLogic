#!/usr/bin/env python3
"""現行イン飛び警報と、AI1着率v5系の候補を同程度の警報件数で比較する。"""

from __future__ import annotations

import json
import math
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
from lightgbm import LGBMClassifier

from final_prediction_ai_bet_integration_compare import load_payouts


ROOT = Path(__file__).resolve().parents[1]
PREDICTIONS = ROOT / "analysis/output/ai_winrate_rating_ensemble_2026_predictions.csv.gz"
OUTPUT = ROOT / "analysis/output/upset_alert_v5_compare_2026.json"
MODEL_OUTPUT = ROOT / "forecast/models/upset_alert_v1.joblib"
PROBABILITY_COLUMN = "trio_v2_15_base_21_pair_64_course_s500"

FIT_START = "2026-08-15"
FIT_END = "2026-08-22"
CAL_START = "2026-08-23"
CAL_END = "2026-08-31"
TEST_START = "2026-09-01"
TEST_END = "2026-09-21"
TEST_WINDOWS = (
    ("P3A", "2026-09-01", "2026-09-05"),
    ("P3B", "2026-09-06", "2026-09-14"),
    ("P3C", "2026-09-15", "2026-09-21"),
    ("P3_ALL", TEST_START, TEST_END),
)

# 既存警報が8/15～8/31に出た割合。候補の比較点数をそろえるため固定する。
TARGET_ALERT_RATE = 355 / 2208


def load_races() -> pd.DataFrame:
    usecols = ["race_code", "race_date", "place", "course", "label", PROBABILITY_COLUMN]
    raw = pd.read_csv(PREDICTIONS, usecols=usecols, parse_dates=["race_date"])
    raw = raw[(raw["race_date"] >= FIT_START) & (raw["race_date"] <= TEST_END)].copy()
    prob = raw.pivot(index="race_code", columns="course", values=PROBABILITY_COLUMN)
    label = raw.pivot(index="race_code", columns="course", values="label")
    meta = raw.groupby("race_code", as_index=True).agg(race_date=("race_date", "first"), place=("place", "first"))
    out = meta.join(prob.add_prefix("p"))
    out["in_loss"] = 1 - label[1].astype(int)
    out["winner_course"] = label.idxmax(axis=1).astype(int)
    out = out.dropna(subset=[f"p{i}" for i in range(1, 7)]).copy()
    payouts = load_payouts(FIT_START, TEST_END)
    out["payout"] = [int(payouts.get(str(code), 0)) for code in out.index]
    return out[out["payout"] > 0].copy()


def features(df: pd.DataFrame) -> pd.DataFrame:
    ps = df[[f"p{i}" for i in range(1, 7)]].to_numpy(dtype=float)
    clipped = np.clip(ps, 1e-12, 1.0)
    outer = ps[:, 1:]
    ordered = np.sort(ps, axis=1)[:, ::-1]
    x = pd.DataFrame(index=df.index)
    for i in range(6):
        x[f"p{i + 1}"] = ps[:, i]
    x["in_margin"] = ps[:, 0] - outer.max(axis=1)
    x["outer_mass"] = outer.sum(axis=1)
    x["dash_mass"] = ps[:, 3:].sum(axis=1)
    x["top_gap"] = ordered[:, 0] - ordered[:, 1]
    x["entropy"] = -(clipped * np.log(clipped)).sum(axis=1)
    return x


def threshold_for_rate(scores: pd.Series, rate: float) -> float:
    return float(scores.quantile(1.0 - rate, interpolation="higher"))


def summarize(df: pd.DataFrame, selected: pd.Series) -> dict:
    part = df.loc[selected]
    n = len(part)
    if n == 0:
        return {"n": 0, "share": 0.0, "in_loss_rate": 0.0, "payout_5000_rate": 0.0,
                "payout_10000_rate": 0.0, "payout_20000_rate": 0.0, "avg_payout": 0.0,
                "median_payout": 0.0}
    return {
        "n": int(n),
        "share": float(n / len(df)),
        "in_loss_rate": float(part["in_loss"].mean()),
        "payout_5000_rate": float((part["payout"] >= 5000).mean()),
        "payout_10000_rate": float((part["payout"] >= 10000).mean()),
        "payout_20000_rate": float((part["payout"] >= 20000).mean()),
        "avg_payout": float(part["payout"].mean()),
        "median_payout": float(part["payout"].median()),
    }


def print_row(name: str, row: dict) -> None:
    print(
        f"{name:<18} {row['n']:>4} {row['share']*100:>6.2f}% "
        f"{row['in_loss_rate']*100:>8.2f}% {row['payout_5000_rate']*100:>8.2f}% "
        f"{row['payout_10000_rate']*100:>8.2f}% {row['payout_20000_rate']*100:>8.2f}% "
        f"{row['avg_payout']:>9,.0f} {row['median_payout']:>9,.0f}"
    )


def main() -> None:
    races = load_races()
    fit = races[(races["race_date"] >= FIT_START) & (races["race_date"] <= FIT_END)].copy()
    cal = races[(races["race_date"] >= CAL_START) & (races["race_date"] <= CAL_END)].copy()
    test = races[(races["race_date"] >= TEST_START) & (races["race_date"] <= TEST_END)].copy()

    x_fit, x_cal, x_test = features(fit), features(cal), features(test)
    model = LGBMClassifier(
        objective="binary", n_estimators=180, learning_rate=0.035, num_leaves=15,
        max_depth=5, min_child_samples=45, subsample=0.9, colsample_bytree=0.9,
        reg_alpha=0.2, reg_lambda=1.5, random_state=20260926, verbosity=-1,
    )
    model.fit(x_fit, fit["in_loss"].astype(int))

    score_sets = {
        "AI1_v5_low": {
            "cal": 1.0 - cal["p1"],
            "test": 1.0 - test["p1"],
        },
        "AI1_v5_outer_ratio": {
            "cal": cal[[f"p{i}" for i in range(2, 7)]].max(axis=1) / cal["p1"].clip(lower=1e-9),
            "test": test[[f"p{i}" for i in range(2, 7)]].max(axis=1) / test["p1"].clip(lower=1e-9),
        },
        "IN_LOSS_ML_v1": {
            "cal": pd.Series(model.predict_proba(x_cal)[:, 1], index=cal.index),
            "test": pd.Series(model.predict_proba(x_test)[:, 1], index=test.index),
        },
    }

    results = {
        "periods": {"fit": [FIT_START, FIT_END], "calibration": [CAL_START, CAL_END], "test": [TEST_START, TEST_END]},
        "counts": {"fit": len(fit), "calibration": len(cal), "test": len(test)},
        "target_alert_rate": TARGET_ALERT_RATE,
        "test_all": summarize(test, pd.Series(True, index=test.index)),
        "current_rule_reference": {
            "n": 118, "share": 118 / 564, "in_loss_rate": 76 / 118,
            "payout_5000_rate": 47 / 118, "payout_10000_rate": 24 / 118,
            "payout_20000_rate": 9 / 118, "avg_payout": 7044.0, "median_payout": 3305.0,
            "note": "upset_alert_rule_validate.py exact reproduction",
        },
        "candidates": {},
    }

    for name, scores in score_sets.items():
        threshold = threshold_for_rate(scores["cal"], TARGET_ALERT_RATE)
        selected = scores["test"] >= threshold
        windows = {}
        for label, start, end in TEST_WINDOWS:
            mask = (test["race_date"] >= start) & (test["race_date"] <= end)
            window_df = test.loc[mask]
            window_scores = scores["test"].loc[mask]
            windows[label] = summarize(window_df, window_scores >= threshold)
        results["candidates"][name] = {
            "threshold": threshold,
            "calibration": summarize(cal, scores["cal"] >= threshold),
            "test": summarize(test, selected),
            "test_windows": windows,
        }

    OUTPUT.write_text(json.dumps(results, ensure_ascii=False, indent=2), encoding="utf-8")
    MODEL_OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    joblib.dump({
        "version": "upset_alert_v1_candidate", "model": model,
        "features": list(x_fit.columns), "fit_period": [FIT_START, FIT_END],
        "calibration_period": [CAL_START, CAL_END],
        "target_alert_rate": TARGET_ALERT_RATE,
        "threshold": results["candidates"]["IN_LOSS_ML_v1"]["threshold"],
    }, MODEL_OUTPUT)

    print("イン飛び警報候補 同警報量比較（TEST完全未来）")
    print("方式                 R数   構成比  1C敗退    >=5千    >=1万    >=2万     平均払戻  中央払戻")
    print("-" * 108)
    print_row("ALL", results["test_all"])
    print_row("CURRENT", results["current_rule_reference"])
    for name, block in results["candidates"].items():
        print_row(name, block["test"])
        for label, row in block["test_windows"].items():
            if label != "P3_ALL":
                print_row(f"  {label}", row)
    print(f"\nJSON: {OUTPUT}")
    print(f"MODEL: {MODEL_OUTPUT}")


if __name__ == "__main__":
    main()
