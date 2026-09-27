#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""AI 3連単120通りから4種類の買い方を作る初回固定検証。

方式（結果を見る前に固定）
--------------------------
HIT_FOCUS
    AI確率上位20点。純粋に的中を優先する。
BALANCE
    AI確率上位40点の中から p * sqrt(odds) 上位12点。
    確率と配当の両方を見る。
ONE_SHOT
    既存の荒れ警戒対象レースだけ、100倍以上かつ p>=0.2% の中から
    AI確率上位10点。大穴候補を明示的に狙う。
SELECTIVE
    p>=0.5% かつ p*odds>=1.15 の候補から期待値上位6点。
    条件を満たさないレースは見送る。

注意
----
- AI1着率v5 × 条件付きAI2着率v1 × 条件付きAI3着率v1 で120通りを作る。
- 学習・校正・評価は日付順に分離する。
- オッズは保存済みの公式最終オッズ（late replay）。締切前オッズではないため、
  回収率は候補比較用であり前方成績とは区別する。
- 本番Web・アプリ・買い目は変更しない。
"""

from __future__ import annotations

import csv
import json
import math
import sys
from collections import defaultdict
from dataclasses import dataclass
from datetime import date
from itertools import permutations
from pathlib import Path

import numpy as np
import psycopg2

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "analysis"))
sys.path.insert(0, str(ROOT / "forecast"))
sys.path.insert(0, str(ROOT / "common"))

import ai_final_bet_same_points_ablation_2026 as ablation  # noqa: E402
import train_ai_place_v1 as place_v1  # noqa: E402
import validate_payout_signal_hole_bet_candidates as hole_signal  # noqa: E402
from ai_place_features import feature_names, vector  # noqa: E402
from db_config import load_db_config  # noqa: E402


REPORT_PATH = ROOT / "analysis/output/ai_bet_strategy_modes_2026.json"
KIMARITE_PATH = ROOT / "analysis/output/kimarite_analysis_dataset_20260901_20260905.csv"
HOLE_FORWARD_DIR = ROOT / "analysis/output/hole_forward/20260921"
EPS = 1.0e-12


@dataclass(frozen=True)
class Window:
    name: str
    train_end: date
    valid_start: date
    valid_end: date
    target_start: date
    target_end: date


WINDOWS = (
    Window(
        "HISTORICAL_0901_0905",
        date(2026, 7, 31),
        date(2026, 8, 1),
        date(2026, 8, 14),
        date(2026, 9, 1),
        date(2026, 9, 5),
    ),
    Window(
        "FORWARD_0921",
        date(2026, 8, 31),
        date(2026, 9, 1),
        date(2026, 9, 10),
        date(2026, 9, 21),
        date(2026, 9, 21),
    ),
)


def load_official_final_odds():
    """対象期間の最新公式最終オッズと払戻を読む。"""
    sql = """
        SELECT DISTINCT ON (o.race_code)
            o.race_code,
            o.race_date,
            o.payload->'odds' AS odds,
            p.trifecta_payout
        FROM boat_race.prediction_forward_snapshots o
        JOIN boat_race.race_payouts p ON p.race_code = o.race_code
        WHERE o.stage = 'exhibition'
          AND o.component = 'trifecta_odds'
          AND o.validation_mode = 'late_replay'
          AND o.payload->>'odds_kind' = 'official_final_historical'
          AND (
              o.race_date BETWEEN DATE '2026-09-01' AND DATE '2026-09-05'
              OR o.race_date = DATE '2026-09-21'
          )
        ORDER BY o.race_code, o.captured_at DESC
    """
    out = {}
    with psycopg2.connect(**load_db_config()) as conn:
        with conn.cursor() as cursor:
            cursor.execute(sql)
            for race_code, race_date, odds, payout in cursor.fetchall():
                clean = {
                    tuple(int(x) for x in str(combo).split("-")): float(value)
                    for combo, value in dict(odds or {}).items()
                    if float(value) > 0.0
                }
                if len(clean) != 120 or payout is None or int(payout) <= 0:
                    continue
                out[str(race_code)] = {
                    "race_date": race_date,
                    "odds": clean,
                    "payout": int(payout),
                }
    return out


def load_existing_hole_alerts():
    """現行の荒れ判定を、評価結果を参照せず再現・読込する。"""
    alerts = {}
    if KIMARITE_PATH.is_file():
        with KIMARITE_PATH.open("r", encoding="utf-8-sig", newline="") as handle:
            for raw in csv.DictReader(handle):
                row = hole_signal.classify_kimarite_row(raw)
                if row is None:
                    continue
                alerts[str(row["race_code"])] = str(row.get("primary") or "平常")

    # 9/21は実運用時に凍結した荒れ予測があるレースだけを対象にする。
    if HOLE_FORWARD_DIR.is_dir():
        staged = defaultdict(list)
        for path in sorted(HOLE_FORWARD_DIR.glob("*.json")):
            try:
                payload = json.loads(path.read_text(encoding="utf-8"))
            except (OSError, json.JSONDecodeError):
                continue
            code = str(payload.get("race_code") or "")
            stage = str(payload.get("stage") or "")
            if code:
                staged[code].append(stage)
        for code, stages in staged.items():
            alerts[code] = "前方荒れ予測（展示）" if "exhibition" in stages else "前方荒れ予測（暫定）"
    return alerts


def fit_full_specs(train, valid, target):
    specs = {}
    details = {}
    for kind in ("second", "third"):
        columns = np.arange(len(feature_names(kind == "third")), dtype=np.int32)
        spec = ablation.fit_target(train, valid, target, kind, columns)
        specs[kind] = spec
        details[kind] = {
            "feature_count": int(len(columns)),
            "temperature": spec["temperature"],
            "alpha": spec["alpha"],
            "valid": spec["valid"],
            "target": spec["test"],
        }
    return specs, details


def all_head_conditional_cache(races, spec, target):
    vectors = []
    metadata = []
    for race in races:
        for head in range(1, 7):
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
                    race["race_code"], head, second, candidates,
                    start, len(vectors), race,
                ))

    raw_all = np.asarray(spec["model"].predict(np.vstack(vectors)), dtype=np.float64)
    cache = {}
    for code, head, second, candidates, start, end, race in metadata:
        ml_values = ablation.softmax(raw_all[start:end], spec["temperature"])
        ml = {boat: float(value) for boat, value in zip(candidates, ml_values)}
        base = ablation.normalize({
            boat: race["boats"][boat]["v5_probability"]
            for boat in candidates
        })
        key = (code, head) if target == "second" else (code, head, second)
        cache[key] = ablation.blend(base, ml, spec["alpha"])
    return cache


def trifecta_probabilities(race, second_cache, third_cache):
    first = ablation.normalize({
        boat: race["boats"][boat]["v5_probability"]
        for boat in range(1, 7)
    })
    out = {}
    for head, second, third in permutations(range(1, 7), 3):
        out[(head, second, third)] = (
            first[head]
            * second_cache[(race["race_code"], head)][second]
            * third_cache[(race["race_code"], head, second)][third]
        )
    total = sum(out.values())
    return {combo: value / total for combo, value in out.items()}


def choose_modes(probabilities, odds, has_hole_alert):
    rows = [
        {
            "combo": combo,
            "p": float(probabilities[combo]),
            "odds": float(odds[combo]),
            "ev": float(probabilities[combo]) * float(odds[combo]),
        }
        for combo in probabilities
        if combo in odds
    ]
    by_probability = sorted(rows, key=lambda row: (-row["p"], row["combo"]))

    hit_focus = by_probability[:20]

    balance_pool = by_probability[:40]
    balance = sorted(
        balance_pool,
        key=lambda row: (-(row["p"] * math.sqrt(row["odds"])), -row["p"], row["combo"]),
    )[:12]

    one_shot = []
    if has_hole_alert:
        one_shot = sorted(
            [row for row in rows if row["odds"] >= 100.0 and row["p"] >= 0.002],
            key=lambda row: (-row["p"], -row["ev"], row["combo"]),
        )[:10]

    selective = sorted(
        [row for row in rows if row["p"] >= 0.005 and row["ev"] >= 1.15],
        key=lambda row: (-row["ev"], -row["p"], row["combo"]),
    )[:6]

    modes = {
        "HIT_FOCUS": hit_focus,
        "BALANCE": balance,
        "ONE_SHOT": one_shot,
        "SELECTIVE": selective,
    }

    # 初回固定4方式の結果を受けた次段の候補。主結果とは分けて保存する。
    # オッズ指数を弱めたバランス型と、確率集中度だけで見送る絞り込み型を比較する。
    for gamma in (0.0, 0.10, 0.20, 0.30):
        name = f"RESEARCH_BALANCE_G{int(gamma * 100):02d}"
        modes[name] = sorted(
            balance_pool,
            key=lambda row: (-(row["p"] * (row["odds"] ** gamma)), -row["p"], row["combo"]),
        )[:12]
    for cap in (6, 8):
        name = f"RESEARCH_ONE_SHOT_{cap}"
        modes[name] = one_shot[:cap]
    top6 = by_probability[:6]
    top6_mass = sum(row["p"] for row in top6)
    for threshold in (0.30, 0.35, 0.40, 0.45):
        name = f"RESEARCH_SELECT_P6_M{int(threshold * 100):02d}"
        modes[name] = top6 if top6_mass >= threshold else []
    return modes


def evaluate(rows, mode):
    races = len(rows)
    bet_races = hits = points = returned = manshu_hits = 0
    probability_mass = []
    for row in rows:
        selected = row["modes"][mode]
        if not selected:
            continue
        bet_races += 1
        points += len(selected)
        bets = {item["combo"] for item in selected}
        probability_mass.append(sum(item["p"] for item in selected))
        if row["actual"] in bets:
            hits += 1
            returned += row["payout"]
            manshu_hits += int(row["payout"] >= 10000)
    investment = points * 100
    return {
        "races": races,
        "bet_races": bet_races,
        "bet_rate": bet_races / races if races else 0.0,
        "points": points,
        "avg_points_all_races": points / races if races else 0.0,
        "avg_points_bet_races": points / bet_races if bet_races else 0.0,
        "hits": hits,
        "hit_rate_all_races": hits / races if races else 0.0,
        "hit_rate_bet_races": hits / bet_races if bet_races else 0.0,
        "manshu_hits": manshu_hits,
        "investment_100_per_point": investment,
        "return_100_per_point": returned,
        "roi_100_per_point": returned / investment if investment else None,
        "avg_selected_probability_mass": float(np.mean(probability_mass)) if probability_mass else 0.0,
    }


def summarize_ai_manshu(rows):
    values = [row["ai_manshu_probability"] for row in rows]
    actuals = [row["payout"] >= 10000 for row in rows]
    bands = (
        (0.00, 0.05, "0-5%"),
        (0.05, 0.10, "5-10%"),
        (0.10, 0.20, "10-20%"),
        (0.20, 1.01, "20%以上"),
    )
    band_rows = []
    for low, high, label in bands:
        indexes = [i for i, value in enumerate(values) if low <= value < high]
        band_rows.append({
            "band": label,
            "races": len(indexes),
            "mean_prediction": float(np.mean([values[i] for i in indexes])) if indexes else None,
            "actual_manshu_rate": float(np.mean([actuals[i] for i in indexes])) if indexes else None,
        })
    return {
        "races": len(rows),
        "mean_ai_manshu_probability": float(np.mean(values)) if values else None,
        "actual_manshu_rate": float(np.mean(actuals)) if actuals else None,
        "bands": band_rows,
    }


def print_table(title, rows, results):
    print(f"\n【{title}】 {len(rows):,}R")
    print("方式          購入R/全R   購入率  平均点数  的中   的中率(購入R)  万舟的中   回収率")
    print("-" * 94)
    for mode in ("HIT_FOCUS", "BALANCE", "ONE_SHOT", "SELECTIVE"):
        value = results[mode]
        roi = value["roi_100_per_point"]
        roi_text = "-" if roi is None else f"{roi*100:7.2f}%"
        print(
            f"{mode:<13} {value['bet_races']:>4d}/{value['races']:<4d} "
            f"{value['bet_rate']*100:7.2f}%  {value['avg_points_bet_races']:>8.2f}  "
            f"{value['hits']:>4d}   {value['hit_rate_bet_races']*100:10.2f}%  "
            f"{value['manshu_hits']:>6d}   {roi_text:>8}"
        )


def print_research_table(title, rows, results):
    print(f"\n  {title}（次段候補・主結果とは別枠）")
    print("候補                       購入R  平均点数  的中率(購入R)  万舟   回収率")
    print("  " + "-" * 78)
    for mode, value in results.items():
        roi = value["roi_100_per_point"]
        roi_text = "-" if roi is None else f"{roi*100:7.2f}%"
        print(
            f"  {mode:<27} {value['bet_races']:>4d}  "
            f"{value['avg_points_bet_races']:>8.2f}  "
            f"{value['hit_rate_bet_races']*100:12.2f}%  "
            f"{value['manshu_hits']:>4d}  {roi_text:>8}"
        )


def main():
    print("AI買い方4方式：時系列検証データを構築中…", flush=True)
    races, _place_to_id, skips = place_v1.build_races()
    odds_map = load_official_final_odds()
    hole_alerts = load_existing_hole_alerts()
    print(
        f"学習可能={len(races):,}R / 公式最終オッズ={len(odds_map):,}R / "
        f"荒れ判定={sum(1 for code in odds_map if hole_alerts.get(code, '平常') != '平常')}R / skip={skips}",
        flush=True,
    )

    report = {
        "name": "ai_bet_strategy_modes_2026",
        "generated_for": "2026-09-25",
        "production_changed": False,
        "odds_caveat": "official final historical odds; not strict pre-deadline odds",
        "rules_fixed_before_evaluation": {
            "HIT_FOCUS": "AI probability top 20",
            "BALANCE": "within probability top 40, top 12 by p*sqrt(odds)",
            "ONE_SHOT": "existing hole alert only; odds>=100 and p>=0.2%; probability top 10",
            "SELECTIVE": "p>=0.5% and p*odds>=1.15; EV top 6; otherwise skip",
            "stake": "100 yen per selected ticket",
        },
        "windows": [],
    }

    all_rows = []
    research_names = (
        "RESEARCH_BALANCE_G00",
        "RESEARCH_BALANCE_G10",
        "RESEARCH_BALANCE_G20",
        "RESEARCH_BALANCE_G30",
        "RESEARCH_ONE_SHOT_6",
        "RESEARCH_ONE_SHOT_8",
        "RESEARCH_SELECT_P6_M30",
        "RESEARCH_SELECT_P6_M35",
        "RESEARCH_SELECT_P6_M40",
        "RESEARCH_SELECT_P6_M45",
    )
    for window in WINDOWS:
        train = [race for race in races if race["race_date"] <= window.train_end]
        valid = [
            race for race in races
            if window.valid_start <= race["race_date"] <= window.valid_end
        ]
        target = [
            race for race in races
            if window.target_start <= race["race_date"] <= window.target_end
            and race["race_code"] in odds_map
        ]
        print(
            f"\n{window.name}: train={len(train):,} / valid={len(valid):,} / odds target={len(target):,}",
            flush=True,
        )
        if not train or not valid or not target:
            raise RuntimeError(f"{window.name}: 時系列分割後のデータが不足しています")

        specs, model_details = fit_full_specs(train, valid, target)
        print(
            "  条件付きAIを全6頭・120通りへ一括展開中…",
            flush=True,
        )
        second_cache = all_head_conditional_cache(target, specs["second"], "second")
        third_cache = all_head_conditional_cache(target, specs["third"], "third")

        rows = []
        for race in target:
            odds_info = odds_map[race["race_code"]]
            probabilities = trifecta_probabilities(race, second_cache, third_cache)
            has_hole_alert = hole_alerts.get(race["race_code"], "平常") != "平常"
            modes = choose_modes(probabilities, odds_info["odds"], has_hole_alert)
            ai_manshu_probability = sum(
                probability for combo, probability in probabilities.items()
                if odds_info["odds"].get(combo, 0.0) >= 100.0
            )
            rows.append({
                "race_code": race["race_code"],
                "race_date": race["race_date"].isoformat(),
                "actual": tuple(race["actual"]),
                "payout": odds_info["payout"],
                "hole_alert": hole_alerts.get(race["race_code"], "平常"),
                "ai_manshu_probability": ai_manshu_probability,
                "modes": modes,
            })

        results = {
            mode: evaluate(rows, mode)
            for mode in ("HIT_FOCUS", "BALANCE", "ONE_SHOT", "SELECTIVE")
        }
        research_results = {
            mode: evaluate(rows, mode)
            for mode in research_names
        }
        print_table(window.name, rows, results)
        print_research_table(window.name, rows, research_results)
        report["windows"].append({
            "name": window.name,
            "train_end": window.train_end.isoformat(),
            "valid": [window.valid_start.isoformat(), window.valid_end.isoformat()],
            "target": [window.target_start.isoformat(), window.target_end.isoformat()],
            "model": model_details,
            "results": results,
            "candidate_research": research_results,
            "ai_manshu_probability": summarize_ai_manshu(rows),
        })
        all_rows.extend(rows)

    aggregate = {
        mode: evaluate(all_rows, mode)
        for mode in ("HIT_FOCUS", "BALANCE", "ONE_SHOT", "SELECTIVE")
    }
    aggregate_research = {
        mode: evaluate(all_rows, mode)
        for mode in research_names
    }
    print_table("合計", all_rows, aggregate)
    print_research_table("合計", all_rows, aggregate_research)
    manshu = summarize_ai_manshu(all_rows)
    print(
        f"\nAI万舟率 平均={manshu['mean_ai_manshu_probability']*100:.2f}% / "
        f"実際の万舟率={manshu['actual_manshu_rate']*100:.2f}%"
    )
    report["aggregate"] = aggregate
    report["candidate_research"] = aggregate_research
    report["ai_manshu_probability"] = manshu

    # JSONへは巨大な120通りを保存せず、検証条件と集計だけを残す。
    REPORT_PATH.write_text(
        json.dumps(report, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    print(f"\n保存: {REPORT_PATH}")


if __name__ == "__main__":
    main()
