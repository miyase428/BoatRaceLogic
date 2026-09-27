#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""AI最終予想：現行スコア買い目との同点数比較と特徴量アブレーション。

検証上の固定条件
----------------
- 頭は全方式でAI1着率 v5の上位2艇に固定する。
- CURRENT_SCOREは現行の一次・二次・三次順位とcutで相手を選ぶ。
- AI系は各頭についてCURRENT_SCOREと同じ点数だけ、条件付き
  AI2着・AI3着確率の積が高い3連単を選ぶ。
- 学習、校正、評価は時系列順に分離する。
- 本番モデルとWeb表示は変更しない。

比較するAI特徴量
----------------
FULL
    現行AI2着・AI3着 v1と同じ特徴量。
NO_PRIMARY
    一次総合スコアと一次順位を除外。
NO_SECONDARY
    二次スコア、二次順位、三次スコア、最終順位を除外。
NO_PRIMARY_SECONDARY
    上記をすべて除外。
"""

from __future__ import annotations

import csv
import json
import math
import sys
from collections import defaultdict
from dataclasses import dataclass
from datetime import date
from pathlib import Path

import numpy as np

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "analysis"))
sys.path.insert(0, str(ROOT / "forecast"))

import train_ai_place_v1 as place_v1  # noqa: E402
from ai_place_features import feature_names, vector  # noqa: E402
from final_prediction_ai_bet_integration_compare import load_payouts  # noqa: E402


REPORT_PATH = ROOT / "analysis/output/ai_final_bet_same_points_ablation_2026.json"
EPS = 1.0e-12
TEMPERATURES = tuple(float(x) for x in np.linspace(0.50, 1.80, 27))
ALPHAS = (0.25, 0.50, 0.75, 1.00)
BOOTSTRAP_SAMPLES = 3000


@dataclass(frozen=True)
class Fold:
    name: str
    train_end: date
    valid_start: date
    valid_end: date
    test_start: date
    test_end: date


FOLDS = (
    Fold(
        "F1",
        date(2026, 6, 30),
        date(2026, 7, 1),
        date(2026, 7, 14),
        date(2026, 7, 15),
        date(2026, 8, 14),
    ),
    Fold(
        "F2",
        date(2026, 7, 31),
        date(2026, 8, 1),
        date(2026, 8, 14),
        date(2026, 8, 15),
        date(2026, 8, 31),
    ),
    Fold(
        "F3",
        date(2026, 8, 31),
        date(2026, 9, 1),
        date(2026, 9, 10),
        date(2026, 9, 11),
        date(2026, 9, 21),
    ),
)


VARIANTS = {
    "FULL": (False, False),
    "NO_PRIMARY": (True, False),
    "NO_SECONDARY": (False, True),
    "NO_PRIMARY_SECONDARY": (True, True),
}


def fnum(value, default=0.0):
    try:
        out = float(value)
        return out if math.isfinite(out) else default
    except (TypeError, ValueError):
        return default


def inum(value, default=0):
    try:
        return int(float(value))
    except (TypeError, ValueError):
        return default


def load_export_rows():
    """現行買い目の順位・cutを再現するため、学習用CSVの補助列を読む。"""
    rows = {}
    for path in place_v1.FINAL_FILES:
        with path.open("r", encoding="utf-8-sig", newline="") as handle:
            for raw in csv.DictReader(handle):
                code = str(raw.get("race_code", "")).strip()
                lane = inum(raw.get("lane_number"))
                if not code or lane not in range(1, 7):
                    continue
                rows[(code, lane)] = {
                    "kiru": inum(raw.get("kiru")),
                    "first_rank": inum(raw.get("first_rank"), 99),
                    "final_rank": inum(raw.get("final_rank"), 99),
                    "final3": fnum(raw.get("final3")),
                    "rate6": fnum(raw.get("three_in_rate_6m")),
                    "rate3": fnum(raw.get("three_in_rate_3m")),
                }
    return rows


def selected_columns(target: str, remove_primary: bool, remove_secondary: bool):
    names = feature_names(target == "third")
    keep = []
    for index, name in enumerate(names):
        if remove_primary and ("first_score" in name or "first_rank" in name):
            continue
        if remove_secondary and any(
            token in name
            for token in ("second_score", "second_rank", "final3", "final_rank")
        ):
            continue
        keep.append(index)
    return np.asarray(keep, dtype=np.int32), [names[index] for index in keep]


def training_matrix(races, target, columns):
    x, y, groups = place_v1.matrices(races, target)
    return x[:, columns], y, groups


def normalize(values):
    total = sum(max(float(value), EPS) for value in values.values())
    return {key: max(float(value), EPS) / total for key, value in values.items()}


def softmax(values, temperature):
    z = np.asarray(values, dtype=np.float64) / max(float(temperature), 1.0e-6)
    z -= z.max()
    out = np.exp(z)
    return out / out.sum()


def blend(base, ml, alpha):
    score = {
        boat: (1.0 - alpha) * math.log(max(base[boat], EPS))
        + alpha * math.log(max(ml[boat], EPS))
        for boat in base
    }
    top = max(score.values())
    return normalize({boat: math.exp(value - top) for boat, value in score.items()})


def context_vectors(race, target, head, second, columns):
    candidates = [boat for boat in range(1, 7) if boat != head and boat != second]
    x = np.vstack([
        vector(
            race["boats"][candidate],
            race["boats"][head],
            race["race_number"],
            race["place_id"],
            race["boats"][second] if second is not None else None,
        )
        for candidate in candidates
    ])
    return candidates, x[:, columns]


def raw_actual_contexts(model, races, target, columns):
    out = []
    for race in races:
        head, second, third = race["actual"]
        context_second = second if target == "third" else None
        candidates, x = context_vectors(
            race, target, head, context_second, columns
        )
        raw = np.asarray(model.predict(x), dtype=np.float64)
        actual = second if target == "second" else third
        base = normalize({
            boat: race["boats"][boat]["v5_probability"]
            for boat in candidates
        })
        out.append((candidates, raw, base, actual))
    return out


def probability_metrics(contexts, temperature, alpha):
    nll = []
    brier = []
    ranks = []
    for candidates, raw, base, actual in contexts:
        ml_values = softmax(raw, temperature)
        ml = {boat: float(value) for boat, value in zip(candidates, ml_values)}
        probabilities = blend(base, ml, alpha)
        nll.append(-math.log(max(probabilities[actual], EPS)))
        brier.append(sum(
            (value - (1.0 if boat == actual else 0.0)) ** 2
            for boat, value in probabilities.items()
        ) / len(probabilities))
        order = sorted(probabilities, key=lambda boat: (-probabilities[boat], boat))
        ranks.append(order.index(actual) + 1)
    return {
        "n": len(contexts),
        "nll": float(np.mean(nll)),
        "brier": float(np.mean(brier)),
        "top1": float(np.mean(np.asarray(ranks) <= 1)),
        "top2": float(np.mean(np.asarray(ranks) <= 2)),
        "top3": float(np.mean(np.asarray(ranks) <= 3)),
    }


def fit_target(train, valid, test, target, columns):
    x, y, groups = training_matrix(train, target, columns)
    model = place_v1.make_ranker()
    model.fit(x, y, group=groups)

    valid_contexts = raw_actual_contexts(model, valid, target, columns)
    best = None
    for temperature in TEMPERATURES:
        for alpha in ALPHAS:
            metrics = probability_metrics(valid_contexts, temperature, alpha)
            key = (metrics["nll"], metrics["brier"], -metrics["top1"])
            if best is None or key < best[0]:
                best = (key, temperature, alpha, metrics)

    _, temperature, alpha, valid_metrics = best
    test_contexts = raw_actual_contexts(model, test, target, columns)
    return {
        "model": model,
        "columns": columns,
        "temperature": float(temperature),
        "alpha": float(alpha),
        "valid": valid_metrics,
        "test": probability_metrics(test_contexts, temperature, alpha),
    }


def conditional(spec, race, target, head, second=None):
    candidates, x = context_vectors(
        race, target, head, second, spec["columns"]
    )
    raw = np.asarray(spec["model"].predict(x), dtype=np.float64)
    ml_values = softmax(raw, spec["temperature"])
    ml = {boat: float(value) for boat, value in zip(candidates, ml_values)}
    base = normalize({
        boat: race["boats"][boat]["v5_probability"]
        for boat in candidates
    })
    return blend(base, ml, spec["alpha"])


def current_original_cut(boats, export_rows):
    """R3_ONLY適用前の対抗用cutを、現在の本番条件から再構築する。"""
    final3 = sorted(float(export_rows[boat]["final3"]) for boat in range(1, 7))
    median = (final3[2] + final3[3]) / 2.0
    cut = set()
    for boat in range(1, 7):
        row = export_rows[boat]
        # ApiClientProductionでは2・4号艇を親ロジックのcutから保護する。
        protected = boat in (2, 4)
        if (
            not protected
            and float(row["final3"]) < median
            and (float(row["rate6"]) < 0.5 or float(row["rate3"]) < 0.5)
        ):
            cut.add(boat)
    return cut


def expand_formation(head, second_candidates, third_candidates):
    return {
        (head, second, third)
        for second in second_candidates
        for third in third_candidates
        if len({head, second, third}) == 3
    }


def current_head_bets(race, export_rows, head, is_honmei):
    order = sorted(
        range(1, 7),
        key=lambda boat: (export_rows[boat]["final_rank"], boat),
    )
    if is_honmei:
        cut = {
            boat for boat in range(1, 7)
            if int(export_rows[boat]["kiru"]) == 1
        }
    else:
        cut = current_original_cut(race["boats"], export_rows)
    cut.discard(head)
    eligible = [boat for boat in order if boat != head and boat not in cut]
    second = eligible[: min(3, len(eligible))]
    third = list(eligible)
    return expand_formation(head, second, third)


def ml_head_bets(probability_cache, race_code, head, ticket_count):
    second_prob = probability_cache["second"][(race_code, head)]
    candidates = []
    for second, p2 in second_prob.items():
        third_prob = probability_cache["third"][(race_code, head, second)]
        for third, p3 in third_prob.items():
            candidates.append(((head, second, third), float(p2) * float(p3)))
    candidates.sort(key=lambda row: (-row[1], row[0]))
    return {combination for combination, _ in candidates[:ticket_count]}


def batch_conditional_cache(races, spec, target):
    """全評価レース・全必要条件を1回のpredictで推論する。"""
    vectors = []
    metadata = []
    for race in races:
        v5_order = sorted(
            range(1, 7),
            key=lambda boat: (-race["boats"][boat]["v5_probability"], boat),
        )
        for head in v5_order[:2]:
            seconds = [None] if target == "second" else [
                boat for boat in range(1, 7) if boat != head
            ]
            for second in seconds:
                candidates = [
                    boat for boat in range(1, 7)
                    if boat != head and boat != second
                ]
                start = len(vectors)
                vectors.extend([
                    vector(
                        race["boats"][candidate],
                        race["boats"][head],
                        race["race_number"],
                        race["place_id"],
                        race["boats"][second] if second is not None else None,
                    )[spec["columns"]]
                    for candidate in candidates
                ])
                metadata.append((
                    race["race_code"], head, second, candidates, start, len(vectors), race
                ))

    raw_all = np.asarray(spec["model"].predict(np.vstack(vectors)), dtype=np.float64)
    cache = {}
    for code, head, second, candidates, start, end, race in metadata:
        ml_values = softmax(raw_all[start:end], spec["temperature"])
        ml = {boat: float(value) for boat, value in zip(candidates, ml_values)}
        base = normalize({
            boat: race["boats"][boat]["v5_probability"]
            for boat in candidates
        })
        key = (code, head) if target == "second" else (code, head, second)
        cache[key] = blend(base, ml, spec["alpha"])
    return cache


def build_variant_probability_cache(races, specs):
    out = {}
    for variant, target_specs in specs.items():
        out[variant] = {
            "second": batch_conditional_cache(races, target_specs["second"], "second"),
            "third": batch_conditional_cache(races, target_specs["third"], "third"),
        }
    return out


def make_race_comparison(race, export, probability_caches):
    v5_order = sorted(
        range(1, 7),
        key=lambda boat: (-race["boats"][boat]["v5_probability"], boat),
    )
    heads = v5_order[:2]
    current_by_head = {
        heads[0]: current_head_bets(race, export, heads[0], True),
        heads[1]: current_head_bets(race, export, heads[1], False),
    }
    current = set().union(*current_by_head.values())
    if not current or any(not bets for bets in current_by_head.values()):
        return None

    scenarios = {"CURRENT_SCORE": current}
    for name, probability_cache in probability_caches.items():
        by_head = {
            head: ml_head_bets(
                probability_cache,
                race["race_code"],
                head,
                len(current_by_head[head]),
            )
            for head in heads
        }
        bets = set().union(*by_head.values())
        if len(bets) != len(current):
            raise RuntimeError(
                f"{race['race_code']} {name}: 同点数になりません "
                f"current={len(current)} ai={len(bets)}"
            )
        scenarios[name] = bets

    return {
        "race_code": race["race_code"],
        "race_date": race["race_date"].isoformat(),
        "actual": tuple(race["actual"]),
        "points": len(current),
        "scenarios": scenarios,
    }


def evaluate(rows, scenario, payouts):
    hits = 0
    points = 0
    returned = 0.0
    payout_races = 0
    payout_investment = 0.0
    for row in rows:
        bets = row["scenarios"][scenario]
        hit = row["actual"] in bets
        hits += int(hit)
        points += len(bets)
        payout = payouts.get(row["race_code"])
        if payout is not None:
            payout_races += 1
            payout_investment += len(bets) * 100.0
            if hit:
                returned += float(payout)
    return {
        "races": len(rows),
        "hits": hits,
        "hit_rate": hits / len(rows) if rows else 0.0,
        "points": points,
        "avg_points": points / len(rows) if rows else 0.0,
        "payout_races": payout_races,
        "investment_100_per_point": payout_investment,
        "return_100_per_point": returned,
        "roi_100_per_point": returned / payout_investment if payout_investment else None,
    }


def hit_comparison(rows, scenario):
    gain = loss = both = neither = changed = 0
    for row in rows:
        current = row["scenarios"]["CURRENT_SCORE"]
        candidate = row["scenarios"][scenario]
        changed += int(current != candidate)
        c = row["actual"] in current
        a = row["actual"] in candidate
        if a and not c:
            gain += 1
        elif c and not a:
            loss += 1
        elif a and c:
            both += 1
        else:
            neither += 1
    return {
        "changed_races": changed,
        "gained_hits": gain,
        "lost_hits": loss,
        "both_hit": both,
        "both_miss": neither,
        "net_hits": gain - loss,
    }


def bootstrap_hit_probability(rows, candidate, reference, seed):
    if not rows:
        return None
    delta = np.asarray([
        int(row["actual"] in row["scenarios"][candidate])
        - int(row["actual"] in row["scenarios"][reference])
        for row in rows
    ], dtype=np.float64)
    rng = np.random.default_rng(seed)
    sampled = delta[rng.integers(0, len(delta), size=(BOOTSTRAP_SAMPLES, len(delta)))]
    means = sampled.mean(axis=1)
    return {
        "mean_hit_rate_delta": float(delta.mean()),
        "improvement_probability": float(np.mean(means > 0.0)),
        "non_worse_probability": float(np.mean(means >= 0.0)),
        "ci95": [float(np.quantile(means, 0.025)), float(np.quantile(means, 0.975))],
    }


def print_fold(fold, rows, results, comparisons):
    print(f"\n【{fold.name} TEST {fold.test_start}～{fold.test_end}】 {len(rows):,}R")
    print("方式                         R数   平均点数    的中       的中率    ROI(100円/点)  拾い 失い 純増")
    print("-" * 108)
    for name in ("CURRENT_SCORE", *VARIANTS):
        row = results[name]
        compare = comparisons.get(name, {})
        roi = row["roi_100_per_point"]
        roi_text = "-" if roi is None else f"{roi*100:8.2f}%"
        print(
            f"{name:<29} {row['races']:>5d}   {row['avg_points']:>7.2f}  "
            f"{row['hits']:>5d}   {row['hit_rate']*100:7.2f}%    {roi_text:>10}  "
            f"{compare.get('gained_hits', 0):>4d} {compare.get('lost_hits', 0):>4d} "
            f"{compare.get('net_hits', 0):>+4d}"
        )


def main():
    print("AI最終予想 同点数比較用データを構築中…", flush=True)
    races, _place_to_id, skips = place_v1.build_races()
    export_rows_flat = load_export_rows()
    print(f"学習可能={len(races):,}R / 範囲={min(r['race_date'] for r in races)}～{max(r['race_date'] for r in races)} / skip={skips}")

    payouts = load_payouts(
        min(fold.test_start for fold in FOLDS),
        max(fold.test_end for fold in FOLDS),
    )
    print(f"払戻取得={len(payouts):,}R", flush=True)

    report = {
        "name": "ai_final_bet_same_points_ablation_2026",
        "generated_for": "2026",
        "rules": {
            "heads": "all methods use AI win rate v5 top-2 boats",
            "current": "legacy final-rank/cut formation after AI head replacement",
            "ai": "top joint conditional P(second|head)*P(third|head,second), same tickets per head",
            "stake": "100 yen per ticket",
            "production_changed": False,
        },
        "variants": {
            "FULL": "一次・二次・三次スコアを含む現行AI着順v1特徴量",
            "NO_PRIMARY": "一次総合スコア・一次順位を除外",
            "NO_SECONDARY": "二次スコア・二次順位・三次スコア・最終順位を除外",
            "NO_PRIMARY_SECONDARY": "一次・二次・三次系スコアと順位をすべて除外",
        },
        "folds": [],
        "aggregate": {},
    }

    aggregate_rows = []
    for fold_index, fold in enumerate(FOLDS, start=1):
        train = [race for race in races if race["race_date"] <= fold.train_end]
        valid = [
            race for race in races
            if fold.valid_start <= race["race_date"] <= fold.valid_end
        ]
        test = [
            race for race in races
            if fold.test_start <= race["race_date"] <= fold.test_end
        ]
        if not train or not valid or not test:
            raise RuntimeError(f"{fold.name}: 時系列分割後のデータが不足しています")
        print(
            f"\n{fold.name}: train={len(train):,} valid={len(valid):,} test={len(test):,}",
            flush=True,
        )

        specs = {}
        feature_report = {}
        for variant, (remove_primary, remove_secondary) in VARIANTS.items():
            specs[variant] = {}
            feature_report[variant] = {}
            for target in ("second", "third"):
                columns, names = selected_columns(
                    target, remove_primary, remove_secondary
                )
                spec = fit_target(train, valid, test, target, columns)
                specs[variant][target] = spec
                feature_report[variant][target] = {
                    "feature_count": len(names),
                    "temperature": spec["temperature"],
                    "alpha": spec["alpha"],
                    "valid": spec["valid"],
                    "test": spec["test"],
                }
                print(
                    f"  {variant:<22} {target:<6} features={len(names):>2d} "
                    f"T={spec['temperature']:.2f} alpha={spec['alpha']:.2f} "
                    f"TEST NLL={spec['test']['nll']:.5f} Br={spec['test']['brier']:.5f}",
                    flush=True,
                )

        print("  評価期間の全買い目確率を一括推論中…", flush=True)
        probability_caches = build_variant_probability_cache(test, specs)

        fold_rows = []
        missing_export = 0
        for race in test:
            export = {
                boat: export_rows_flat.get((race["race_code"], boat))
                for boat in range(1, 7)
            }
            if any(row is None for row in export.values()):
                missing_export += 1
                continue
            row = make_race_comparison(race, export, probability_caches)
            if row is not None:
                fold_rows.append(row)
        if not fold_rows:
            raise RuntimeError(f"{fold.name}: 買い目評価レースがありません")

        scenario_results = {
            name: evaluate(fold_rows, name, payouts)
            for name in ("CURRENT_SCORE", *VARIANTS)
        }
        comparisons = {
            name: hit_comparison(fold_rows, name)
            for name in VARIANTS
        }
        print_fold(fold, fold_rows, scenario_results, comparisons)

        report["folds"].append({
            "fold": fold.name,
            "train": [min(r["race_date"] for r in train).isoformat(), fold.train_end.isoformat()],
            "valid": [fold.valid_start.isoformat(), fold.valid_end.isoformat()],
            "test": [fold.test_start.isoformat(), fold.test_end.isoformat()],
            "counts": {
                "train": len(train),
                "valid": len(valid),
                "test": len(test),
                "evaluated": len(fold_rows),
                "missing_export": missing_export,
            },
            "features": feature_report,
            "bets": scenario_results,
            "vs_current": comparisons,
        })
        aggregate_rows.extend(fold_rows)

    aggregate_results = {
        name: evaluate(aggregate_rows, name, payouts)
        for name in ("CURRENT_SCORE", *VARIANTS)
    }
    aggregate_comparisons = {
        name: hit_comparison(aggregate_rows, name)
        for name in VARIANTS
    }
    aggregate_bootstrap = {
        "FULL_vs_CURRENT_SCORE": bootstrap_hit_probability(
            aggregate_rows, "FULL", "CURRENT_SCORE", 20260925
        ),
        "FULL_vs_NO_PRIMARY": bootstrap_hit_probability(
            aggregate_rows, "FULL", "NO_PRIMARY", 20260926
        ),
        "FULL_vs_NO_SECONDARY": bootstrap_hit_probability(
            aggregate_rows, "FULL", "NO_SECONDARY", 20260927
        ),
        "FULL_vs_NO_PRIMARY_SECONDARY": bootstrap_hit_probability(
            aggregate_rows, "FULL", "NO_PRIMARY_SECONDARY", 20260928
        ),
    }
    report["aggregate"] = {
        "bets": aggregate_results,
        "vs_current": aggregate_comparisons,
        "bootstrap": aggregate_bootstrap,
    }

    print("\n【3期間合算】")
    print_fold(
        Fold("ALL", date.min, date.min, date.min, FOLDS[0].test_start, FOLDS[-1].test_end),
        aggregate_rows,
        aggregate_results,
        aggregate_comparisons,
    )
    print("\nBootstrap（的中率差）")
    for name, row in aggregate_bootstrap.items():
        print(
            f"  {name:<34} 差={row['mean_hit_rate_delta']*100:+.3f}pt "
            f"改善確率={row['improvement_probability']*100:.1f}% "
            f"95%CI=[{row['ci95'][0]*100:+.3f}, {row['ci95'][1]*100:+.3f}]pt"
        )

    REPORT_PATH.parent.mkdir(parents=True, exist_ok=True)
    REPORT_PATH.write_text(
        json.dumps(report, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    print(f"\n保存: {REPORT_PATH}")


if __name__ == "__main__":
    main()
