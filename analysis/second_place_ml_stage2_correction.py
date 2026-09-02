#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
第二世代ML 2着補正の開発用ベンチマーク。

考え方
------
③ AI_FINAL を土台として固定し、第一世代HGBが出す分布は
「置き換え先」ではなく「補正方向」としてだけ使う。

最終確率は log-space で
    log p_final = log p_ai + alpha * (log p_hgb - log p_ai)
とし、alpha=0 なら完全に③、alpha=1 なら第一世代HGBそのもの。

さらに DISAGREE_ONLY では、③とHGBのTop1が違うレースだけ補正する。
つまり「③が外しやすそうだとMLが判断したケースだけ触る」ための
かなり保守的な第二世代案。

重要
----
- F1/F2はすでに第一世代MLの開発・確認で見ているため、ここではどちらも
  DEVELOPMENT REFERENCE として扱う。最終ホールドアウトではない。
- ここで候補を決めても本番Webは変更しない。
- 本採用判定は 2026-09-02 以降に新しく貯める F3 で行う。

Usage
-----
python3 analysis/second_place_ml_stage2_correction.py
"""

from __future__ import annotations

import math
from pathlib import Path

import second_place_ml_benchmark as ml1


ALPHAS = (0.15, 0.25, 0.35, 0.50, 0.75, 1.00)
MODES = ("BLEND", "DISAGREE_ONLY")
EPS = 1e-12


def fit_hgb(train_races, schema):
    x, y, train_head_won = ml1.make_training_matrix(train_races, schema)
    if not x or not y:
        raise RuntimeError("HGB学習データが空です")

    model = ml1.build_models()["HGB"]
    print(
        f"HGB学習: head的中={train_head_won}R / 候補行={len(y)} "
        f"/ positive={sum(y)} / feature={len(x[0])}"
    )
    model.fit(x, y)
    return model


def all_candidate_order(race, probs):
    head = int(race["head"])
    boats = [b for b in range(1, 7) if b != head]
    return sorted(boats, key=lambda b: (-float(probs.get(b, 0.0)), b))


def corrected_distribution(race, base_probs, ml_probs, alpha, mode):
    head = int(race["head"])
    boats = [b for b in range(1, 7) if b != head]

    if mode == "DISAGREE_ONLY":
        if all_candidate_order(race, base_probs)[0] == all_candidate_order(race, ml_probs)[0]:
            return {b: float(base_probs.get(b, 0.0)) for b in boats}

    scores = {}
    for b in boats:
        p0 = max(float(base_probs.get(b, 0.0)), EPS)
        p1 = max(float(ml_probs.get(b, 0.0)), EPS)
        scores[b] = math.log(p0) + float(alpha) * (math.log(p1) - math.log(p0))

    max_score = max(scores.values())
    exp_scores = {b: math.exp(v - max_score) for b, v in scores.items()}
    total = sum(exp_scores.values())
    if total <= 0.0:
        return {b: 1.0 / len(boats) for b in boats}
    return {b: exp_scores[b] / total for b in boats}


def build_corrected_predictions(races, base_pred, ml_pred, alpha, mode):
    out = {}
    for race in races:
        code = race["race_code"]
        out[code] = corrected_distribution(
            race,
            base_pred[code],
            ml_pred[code],
            alpha,
            mode,
        )
    return out


def top1_change_stats(races, base_pred, corrected_pred):
    n = 0
    changed = 0
    for race in races:
        if not race["head_won"] or race["actual_second_cut"]:
            continue
        n += 1
        a = ml1.ranked_eligible(race, base_pred[race["race_code"]])
        b = ml1.ranked_eligible(race, corrected_pred[race["race_code"]])
        if a and b and a[0] != b[0]:
            changed += 1
    return changed, n


def evaluate_grid(label, races, base_pred, ml_pred):
    champion = ml1.evaluate_method("CHAMPION_AI_FINAL", races, base_pred)
    rows = []

    for mode in MODES:
        for alpha in ALPHAS:
            pred = build_corrected_predictions(races, base_pred, ml_pred, alpha, mode)
            result = ml1.evaluate_method(f"{mode}_a{alpha:.2f}", races, pred)
            changed, change_n = top1_change_stats(races, base_pred, pred)
            result["mode"] = mode
            result["alpha"] = alpha
            result["changed"] = changed
            result["change_n"] = change_n
            rows.append(result)

    print(f"\n【{label}】")
    print(
        "方式               a     LogLoss    ΔLL       Brier5     ΔBr      "
        "Top1    ΔT1    Top2    Top3   3連単   Top1変更"
    )
    print("-" * 132)
    print(
        f"{'CHAMPION_AI_FINAL':<18} {'-':>4}  "
        f"{champion['logloss']:.6f}  {'-':>8}  {champion['brier5']:.6f}  {'-':>8}  "
        f"{champion['rank_top1']*100:>6.2f}% {'-':>6}  "
        f"{champion['rank_top2']*100:>6.2f}% {champion['rank_top3']*100:>6.2f}% "
        f"{champion['tri_rate']*100:>6.2f}% {'-':>9}"
    )

    for r in rows:
        dll = r["logloss"] - champion["logloss"]
        dbr = r["brier5"] - champion["brier5"]
        dt1 = (r["rank_top1"] - champion["rank_top1"]) * 100.0
        print(
            f"{r['mode']:<18} {r['alpha']:>4.2f}  "
            f"{r['logloss']:.6f}  {dll:+.6f}  {r['brier5']:.6f}  {dbr:+.6f}  "
            f"{r['rank_top1']*100:>6.2f}% {dt1:+6.2f}  "
            f"{r['rank_top2']*100:>6.2f}% {r['rank_top3']*100:>6.2f}% "
            f"{r['tri_rate']*100:>6.2f}% {r['changed']:>4d}/{r['change_n']:<4d}"
        )

    return champion, rows


def print_cross_period_candidates(f1_champion, f1_rows, f2_champion, f2_rows):
    by_key_f2 = {(r["mode"], r["alpha"]): r for r in f2_rows}
    passed = []

    for r1 in f1_rows:
        key = (r1["mode"], r1["alpha"])
        r2 = by_key_f2[key]
        f1_ok = r1["logloss"] < f1_champion["logloss"] and r1["brier5"] < f1_champion["brier5"]
        f2_ok = r2["logloss"] < f2_champion["logloss"] and r2["brier5"] < f2_champion["brier5"]
        if f1_ok and f2_ok:
            passed.append((
                r1["logloss"] - f1_champion["logloss"]
                + r2["logloss"] - f2_champion["logloss"],
                key,
                r1,
                r2,
            ))

    print("\n【F1/F2 両方で確率品質が改善した候補】")
    print("※F2は既に消費済みなので、ここは開発参考。採用判定ではありません。")
    if not passed:
        print("該当なし。今回の保守的補正でも③を安定して上回る形は見つからない。")
        return

    passed.sort(key=lambda x: x[0])
    for _, key, r1, r2 in passed:
        mode, alpha = key
        print(
            f"{mode} alpha={alpha:.2f} : "
            f"F1 ΔLL={r1['logloss']-f1_champion['logloss']:+.6f} / "
            f"ΔBr={r1['brier5']-f1_champion['brier5']:+.6f} / "
            f"ΔTop1={(r1['rank_top1']-f1_champion['rank_top1'])*100:+.2f}pt | "
            f"F2 ΔLL={r2['logloss']-f2_champion['logloss']:+.6f} / "
            f"ΔBr={r2['brier5']-f2_champion['brier5']:+.6f} / "
            f"ΔTop1={(r2['rank_top1']-f2_champion['rank_top1'])*100:+.2f}pt"
        )

    best = passed[0]
    mode, alpha = best[1]
    print(f"\n開発上の暫定候補: {mode} alpha={alpha:.2f}")
    print("この候補を本番へ入れるのではなく、仕様を凍結して新しいF3で初めて最終判定する。")


def main():
    ml1.ensure_files()
    print("=" * 132)
    print("第二世代ML：③ AI_FINALを土台にした保守的補正ベンチマーク")
    print("=" * 132)
    print("③を置き換えず、HGBとの差だけをalphaで縮めて補正します。")
    print("DISAGREE_ONLY = ③とHGBのTop1が違うレースだけ補正。")
    print("F1/F2は DEVELOPMENT REFERENCE。最終採用は新しいF3まで行いません。")
    print("本番Web変更: なし")

    data = ml1.load_datasets()
    ml1.print_skips(data["skip"])

    train = data["train"]
    valid = data["valid"]
    holdout = data["holdout"]

    # F1: 旧TRAINだけでHGBを学習
    schema_f1 = ml1.build_schema(train)
    hgb_f1 = fit_hgb(train, schema_f1)
    f1_base = ml1.champion_predictions(valid)
    f1_hgb = ml1.model_predictions(hgb_f1, valid, schema_f1)
    f1_champion, f1_rows = evaluate_grid(
        "F1 2026-08-15～08-22（開発参考）",
        valid,
        f1_base,
        f1_hgb,
    )

    # F2: 前回と同じくTRAIN+F1で同一HGB仕様を再学習
    train_plus_f1 = train + valid
    schema_f2 = ml1.build_schema(train_plus_f1)
    hgb_f2 = fit_hgb(train_plus_f1, schema_f2)
    f2_base = ml1.champion_predictions(holdout)
    f2_hgb = ml1.model_predictions(hgb_f2, holdout, schema_f2)
    f2_champion, f2_rows = evaluate_grid(
        "F2 2026-08-23～08-31（開発参考・既消費）",
        holdout,
        f2_base,
        f2_hgb,
    )

    print_cross_period_candidates(
        f1_champion,
        f1_rows,
        f2_champion,
        f2_rows,
    )

    print("\n【位置づけ】")
    print("1. alpha=0相当は現在の③ AI_FINAL。")
    print("2. alpha=1.00のBLENDは第一世代HGBと同等。")
    print("3. 小さいalphaほど③を強く残し、MLは補正役に限定される。")
    print("4. DISAGREE_ONLYは、③とMLが一致するレースを一切触らない。")
    print("5. ここで良くても本番採用はせず、新しいF3で再現性を確認する。")


if __name__ == "__main__":
    main()
