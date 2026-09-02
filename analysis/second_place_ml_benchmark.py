#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
共通2着確率③ AI_FINAL に対する、ML版2着モデルの初回ベンチマーク。

狙い
----
- 既採用の③ AI_FINALを置き換える前に、MLが本当に上回れるか比較する。
- MLは「現行本命が1着だったとき、残り5艇のどれが2着か」を学習する。
- 本命、kiru、3着候補の扱いは既存の共通2着検証に合わせる。

期間
----
TRAIN : 2026-06-15 ～ 2026-08-14（旧P1+P2）
VALID : 2026-08-15 ～ 2026-08-22（F1）
FINAL : 2026-08-23 ～ 2026-08-31（F2）

重要
----
- select モードではTRAINで学習し、VALIDだけでモデル選定する。
- F2は select モードの評価には使わない。
- holdout モードは、select結果を見てモデル名を固定してから実行する。
- F2を見た後にモデルや特徴量を調整して再評価しない。
- 本番Webは変更しない。

モデル
------
CHAMPION_AI_FINAL
    現在採用済みの共通2着確率③。

LOGIT
    候補艇ごとの2着/非2着をロジスティック回帰で学習し、レース内5艇で再正規化。

HGB
    HistGradientBoostingClassifier。非線形の相互作用を見るための木モデル。

特徴量
------
- ③ AI_FINAL確率
- 120通り補正前の条件付き2着確率
- 補正後1着率相当 / AI3連対率相当
- 一次 / 二次 / final3 / 各順位
- 本命との差
- 本命艇の一次 / 二次 / final3
- 頭条件の確率mass
- 艇番 / 今回コース / 本命コース / レース番号 / 場

Usage
-----
# モデル選定（F2は評価しない）
python3 analysis/second_place_ml_benchmark.py select

# select後、モデル名を固定してF2を1回だけ評価
python3 analysis/second_place_ml_benchmark.py holdout LOGIT
python3 analysis/second_place_ml_benchmark.py holdout HGB
"""

from __future__ import annotations

import math
import sys
from collections import Counter
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

try:
    from sklearn.ensemble import HistGradientBoostingClassifier
    from sklearn.linear_model import LogisticRegression
    from sklearn.pipeline import Pipeline
    from sklearn.preprocessing import StandardScaler
except ImportError as exc:
    raise SystemExit(
        "scikit-learn が必要です。確認: python3 -c \"import sklearn; print(sklearn.__version__)\"\n"
        "未導入ならUbuntu側のPython環境へscikit-learnを追加してから再実行してください。"
    ) from exc

import final_prediction_ai_opponent_compare as final_aite
import final_prediction_second_engine_all_head_compare as common2
import trifecta_probability_order_compare as step3


TRAIN_P1 = "analysis/output/final_prediction_boats_20260615_20260714_OLD.csv"
TRAIN_P2 = "analysis/output/final_prediction_boats_20260715_20260814_OLD.csv"
VALID_F1 = "analysis/output/final_prediction_boats_fast_cached_20260815_20260822.csv"
HOLDOUT_F2 = "analysis/output/final_prediction_boats_fast_cached_20260823_20260831.csv"

EPS = 1e-12
RANDOM_STATE = 428
MODEL_NAMES = ("LOGIT", "HGB")


def ensure_files():
    paths = (TRAIN_P1, TRAIN_P2, VALID_F1, HOLDOUT_F2)
    missing = [p for p in paths if not Path(p).exists()]
    if missing:
        raise FileNotFoundError("必要CSVがありません: " + ", ".join(missing))


def actual_top3(boats):
    first = [lane for lane, b in boats.items() if float(b.get("actual_rank", 99)) == 1.0]
    second = [lane for lane, b in boats.items() if float(b.get("actual_rank", 99)) == 2.0]
    third = [lane for lane, b in boats.items() if float(b.get("actual_rank", 99)) == 3.0]
    if len(first) != 1 or len(second) != 1 or len(third) != 1:
        return None
    return int(first[0]), int(second[0]), int(third[0])


def venue_from_code(code):
    code = str(code)
    return code[8:11] if len(code) >= 11 else "UNK"


def race_no_from_code(code):
    try:
        return int(str(code)[-2:])
    except (TypeError, ValueError):
        return 0


def normalized_map(values):
    total = sum(max(0.0, float(v)) for v in values.values())
    if total <= 0.0:
        return {int(k): 0.0 for k in values}
    return {int(k): max(0.0, float(v)) / total for k, v in values.items()}


def one_hot(value, values):
    return [1.0 if value == v else 0.0 for v in values]


def build_schema(train_races):
    venues = sorted({venue_from_code(r["race_code"]) for r in train_races})
    return {"venues": venues}


def vector_for_candidate(race, boat, schema):
    boats = race["boats"]
    head = race["head"]
    candidate = boats[boat]
    h = boats[head]

    ai_p = float(race["ai_probs"].get(boat, 0.0))
    base_p = float(race["base_probs"].get(boat, 0.0))
    win_p = float(race["win_probs"].get(boat, 0.0))
    trio_p = float(race["trio_probs"].get(boat, 0.0))

    cand_first = float(candidate.get("first_score", 0.0))
    cand_second = float(candidate.get("second_score", 0.0))
    cand_final3 = float(candidate.get("final3", 0.0))
    head_first = float(h.get("first_score", 0.0))
    head_second = float(h.get("second_score", 0.0))
    head_final3 = float(h.get("final3", 0.0))

    vals = [
        ai_p,
        math.log(max(ai_p, EPS)),
        base_p,
        win_p,
        trio_p,
        cand_first,
        cand_second,
        cand_final3,
        float(candidate.get("first_rank", 0)) / 6.0,
        float(candidate.get("second_rank", 0)) / 6.0,
        float(candidate.get("final_rank", 0)) / 6.0,
        cand_first - head_first,
        cand_second - head_second,
        cand_final3 - head_final3,
        head_first,
        head_second,
        head_final3,
        float(race["head_mass"]),
        float(race_no_from_code(race["race_code"])) / 12.0,
        float(boat) / 6.0,
        float(head) / 6.0,
    ]

    vals.extend(one_hot(int(race["course_map"][boat]), range(1, 7)))
    vals.extend(one_hot(int(race["head_course"]), range(1, 7)))
    vals.extend(one_hot(venue_from_code(race["race_code"]), schema["venues"]))
    return vals


def prepare_period(records, boats_by_code, exhibition_maps, label):
    races = []
    skip = Counter()

    for record in records:
        code = str(record["race_code"])
        boats = boats_by_code.get(code)
        if boats is None or set(boats) != set(range(1, 7)):
            skip["csv_missing"] += 1
            continue

        rank_boats, head = final_aite.current_order_and_head(boats)
        if not rank_boats or head is None:
            skip["head_invalid"] += 1
            continue
        head = int(head)

        course_map = exhibition_maps.get(code)
        if course_map is None:
            course_map = common2.valid_course_map(record.get("course_by_lane", {}))
        if course_map is None:
            skip["course_invalid"] += 1
            continue

        aligned = common2.align_record_to_course_map(record, course_map)
        if aligned is None:
            skip["align_invalid"] += 1
            continue

        ai_probs, head_course, head_mass = common2.ai_probs_by_boat(record, course_map, head)
        if ai_probs is None or head_course is None:
            skip["ai_invalid"] += 1
            continue

        base_course, _ = common2.conditional_second_by_course(aligned["probs"], head_course)
        if base_course is None:
            skip["base_invalid"] += 1
            continue
        base_probs = {
            lane: float(base_course[int(course_map[lane])])
            for lane in range(1, 7) if lane != head
        }

        raw_win, raw_trio = common2.recover_target_lane_probs(aligned)
        win_probs = normalized_map({lane: raw_win[lane] for lane in range(1, 7)})
        trio_probs = normalized_map({lane: raw_trio[lane] for lane in range(1, 7)})

        actual = actual_top3(boats)
        if actual is None:
            skip["actual_invalid"] += 1
            continue

        kiru = {lane for lane, b in boats.items() if int(b.get("kiru", 0)) == 1}
        eligible = [lane for lane in range(1, 7) if lane != head and lane not in kiru]
        if not eligible:
            skip["eligible_empty"] += 1
            continue

        races.append({
            "period": label,
            "race_code": code,
            "boats": boats,
            "rank_boats": rank_boats,
            "head": head,
            "head_course": int(head_course),
            "head_mass": float(head_mass),
            "course_map": dict(course_map),
            "ai_probs": dict(ai_probs),
            "base_probs": base_probs,
            "win_probs": win_probs,
            "trio_probs": trio_probs,
            "kiru": kiru,
            "eligible": eligible,
            "actual": actual,
            "head_won": int(actual[0]) == head,
            "actual_second": int(actual[1]),
            "actual_second_cut": int(actual[1]) in kiru,
        })
        skip["ready"] += 1

    return races, skip


def load_datasets():
    # 旧P1/P2: 学習専用
    old = step3.build_common_records(TRAIN_P1, TRAIN_P2)
    old_boats = final_aite.load_boats(TRAIN_P1, TRAIN_P2)
    old_maps = common2.load_exhibition_course_maps(old["p1_start"], old["p2_end"])
    train_a, skip_a = prepare_period(old["records"]["P1"], old_boats, old_maps, "TRAIN_P1")
    train_b, skip_b = prepare_period(old["records"]["P2"], old_boats, old_maps, "TRAIN_P2")

    # F1/F2: selectではF1だけ評価。F2はholdoutモードまで評価しない。
    forward = step3.build_common_records(VALID_F1, HOLDOUT_F2)
    forward_boats = final_aite.load_boats(VALID_F1, HOLDOUT_F2)
    forward_maps = common2.load_exhibition_course_maps(forward["p1_start"], forward["p2_end"])
    valid, skip_v = prepare_period(forward["records"]["P1"], forward_boats, forward_maps, "VALID_F1")
    holdout, skip_h = prepare_period(forward["records"]["P2"], forward_boats, forward_maps, "HOLDOUT_F2")

    return {
        "train": train_a + train_b,
        "valid": valid,
        "holdout": holdout,
        "skip": {
            "TRAIN_P1": skip_a,
            "TRAIN_P2": skip_b,
            "VALID_F1": skip_v,
            "HOLDOUT_F2": skip_h,
        },
    }


def make_training_matrix(races, schema):
    x, y = [], []
    race_count = 0
    for race in races:
        if not race["head_won"]:
            continue
        actual_second = int(race["actual_second"])
        if actual_second == int(race["head"]):
            continue
        race_count += 1
        for boat in range(1, 7):
            if boat == int(race["head"]):
                continue
            x.append(vector_for_candidate(race, boat, schema))
            y.append(1 if boat == actual_second else 0)
    return x, y, race_count


def build_models():
    return {
        "LOGIT": Pipeline([
            ("scale", StandardScaler()),
            ("model", LogisticRegression(
                C=1.0,
                max_iter=2500,
                solver="lbfgs",
                random_state=RANDOM_STATE,
            )),
        ]),
        "HGB": HistGradientBoostingClassifier(
            learning_rate=0.05,
            max_iter=220,
            max_leaf_nodes=15,
            min_samples_leaf=25,
            l2_regularization=1.0,
            random_state=RANDOM_STATE,
        ),
    }


def predict_race(model, race, schema):
    boats = [b for b in range(1, 7) if b != int(race["head"])]
    x = [vector_for_candidate(race, b, schema) for b in boats]
    raw = model.predict_proba(x)[:, 1]
    total = float(sum(max(0.0, float(v)) for v in raw))
    if total <= 0.0:
        return {b: 1.0 / len(boats) for b in boats}
    return {b: max(0.0, float(v)) / total for b, v in zip(boats, raw)}


def model_predictions(model, races, schema):
    return {r["race_code"]: predict_race(model, r, schema) for r in races}


def champion_predictions(races):
    return {r["race_code"]: dict(r["ai_probs"]) for r in races}


def ranked_eligible(race, probs):
    return sorted(
        race["eligible"],
        key=lambda b: (-float(probs.get(int(b), 0.0)), int(b)),
    )


def probability_metrics(races, pred):
    ll = []
    brier = []
    actual_ps = []
    for r in races:
        if not r["head_won"]:
            continue
        probs = pred[r["race_code"]]
        candidates = [b for b in range(1, 7) if b != int(r["head"])]
        actual_second = int(r["actual_second"])
        p_actual = max(float(probs.get(actual_second, 0.0)), EPS)
        ll.append(-math.log(p_actual))
        actual_ps.append(p_actual)
        row_brier = 0.0
        for b in candidates:
            y = 1.0 if b == actual_second else 0.0
            row_brier += (float(probs.get(b, 0.0)) - y) ** 2
        brier.append(row_brier / len(candidates))
    n = len(ll)
    return {
        "n": n,
        "logloss": sum(ll) / n if n else 0.0,
        "brier5": sum(brier) / n if n else 0.0,
        "actual_p": sum(actual_ps) / n if n else 0.0,
    }


def ranking_metrics(races, pred):
    eval_rows = [r for r in races if r["head_won"] and not r["actual_second_cut"]]
    counts = [0, 0, 0]
    for r in eval_rows:
        order = ranked_eligible(r, pred[r["race_code"]])
        try:
            pos = order.index(int(r["actual_second"])) + 1
        except ValueError:
            pos = 999
        for k in range(1, 4):
            if pos <= k:
                counts[k - 1] += 1
    n = len(eval_rows)
    return {
        "n": n,
        "top1": counts[0] / n if n else 0.0,
        "top2": counts[1] / n if n else 0.0,
        "top3": counts[2] / n if n else 0.0,
    }


def trifecta_hit_metrics(races, pred):
    hit = 0
    for r in races:
        order = ranked_eligible(r, pred[r["race_code"]])
        seconds = order[: min(3, len(order))]
        thirds = list(r["eligible"])
        actual = tuple(int(x) for x in r["actual"])
        if actual[0] != int(r["head"]):
            continue
        if actual[1] in seconds and actual[2] in thirds and actual[1] != actual[2]:
            hit += 1
    n = len(races)
    return {"n": n, "hit": hit, "rate": hit / n if n else 0.0}


def evaluate_method(name, races, pred):
    p = probability_metrics(races, pred)
    r = ranking_metrics(races, pred)
    t = trifecta_hit_metrics(races, pred)
    return {
        "name": name,
        **p,
        **{f"rank_{k}": v for k, v in r.items()},
        **{f"tri_{k}": v for k, v in t.items()},
    }


def print_table(title, results):
    print(f"\n【{title}】")
    print("方式                    ProbN  LogLoss   Brier5  実2着平均P   RankN   Top1    Top2    Top3   3連単的中")
    print("-" * 116)
    for x in results:
        print(
            f"{x['name']:<23} "
            f"{x['n']:>5d}  {x['logloss']:.6f}  {x['brier5']:.6f}   {x['actual_p']*100:>7.3f}%   "
            f"{x['rank_n']:>5d}  {x['rank_top1']*100:>6.2f}% {x['rank_top2']*100:>6.2f}% {x['rank_top3']*100:>6.2f}%  "
            f"{x['tri_hit']:>4d}/{x['tri_n']:<4d} {x['tri_rate']*100:>6.2f}%"
        )


def print_delta(champion, result):
    print(
        f"{result['name']:<8} - CHAMPION : "
        f"LogLoss={result['logloss']-champion['logloss']:+.6f} / "
        f"Brier5={result['brier5']-champion['brier5']:+.6f} / "
        f"Top1={(result['rank_top1']-champion['rank_top1'])*100:+.2f}pt / "
        f"Top2={(result['rank_top2']-champion['rank_top2'])*100:+.2f}pt / "
        f"Top3={(result['rank_top3']-champion['rank_top3'])*100:+.2f}pt / "
        f"3連単={(result['tri_rate']-champion['tri_rate'])*100:+.2f}pt"
    )


def fit_models(train_races, schema, names=MODEL_NAMES):
    x, y, train_head_won = make_training_matrix(train_races, schema)
    if not x or not y:
        raise RuntimeError("ML学習データが空です")
    print(
        f"ML学習: head的中レース={train_head_won}R / 候補行={len(y)} "
        f"/ positive={sum(y)} / feature={len(x[0])}"
    )
    pool = build_models()
    fitted = {}
    for name in names:
        print(f"  fitting {name} ...")
        model = pool[name]
        model.fit(x, y)
        fitted[name] = model
    return fitted


def run_select(data):
    train = data["train"]
    valid = data["valid"]
    schema = build_schema(train)
    fitted = fit_models(train, schema)

    results = []
    champion_pred = champion_predictions(valid)
    champion = evaluate_method("CHAMPION_AI_FINAL", valid, champion_pred)
    results.append(champion)

    for name in MODEL_NAMES:
        pred = model_predictions(fitted[name], valid, schema)
        results.append(evaluate_method(name, valid, pred))

    print_table("VALID F1：2026-08-15～08-22（モデル選定用）", results)
    print("\n【CHAMPIONとの差】 ※LogLoss/Brierはマイナス、Top系/3連単はプラスがML改善")
    for result in results[1:]:
        print_delta(champion, result)

    ml_sorted = sorted(results[1:], key=lambda x: (x["logloss"], x["brier5"], -x["rank_top1"]))
    best = ml_sorted[0]
    print("\n【select判定】")
    print(f"ML内の暫定1位（LogLoss優先）: {best['name']}")
    if best["logloss"] < champion["logloss"] and best["brier5"] <= champion["brier5"]:
        print("暫定: MLが確率品質でCHAMPIONを上回る。モデル名を固定してholdoutへ進める候補。")
    else:
        print("暫定: MLはCHAMPIONを確率品質で明確に超えていない。F2を消費する前に採用は保留。")
    print("※このselect結果を見た後は、特徴量/ハイパーパラメータを変えずにholdoutを1回だけ実行する。")


def run_holdout(data, model_name):
    model_name = model_name.upper()
    if model_name not in MODEL_NAMES:
        raise SystemExit(f"holdoutモデルは {', '.join(MODEL_NAMES)} のどれかを指定してください")

    # F1までを学習へ追加し、selectで固定した同一仕様を再学習。
    train_plus_valid = data["train"] + data["valid"]
    holdout = data["holdout"]
    schema = build_schema(train_plus_valid)
    fitted = fit_models(train_plus_valid, schema, names=(model_name,))

    champion_pred = champion_predictions(holdout)
    ml_pred = model_predictions(fitted[model_name], holdout, schema)
    champion = evaluate_method("CHAMPION_AI_FINAL", holdout, champion_pred)
    ml = evaluate_method(model_name, holdout, ml_pred)

    print_table("FINAL HOLDOUT F2：2026-08-23～08-31（1回だけ）", [champion, ml])
    print("\n【CHAMPIONとの差】")
    print_delta(champion, ml)
    print("\n【最終判断ルール】")
    print("1. LogLoss/Brier5の両方を最優先。")
    print("2. Top1/Top2/Top3が同方向ならML採用根拠が強い。")
    print("3. 1期間だけの3連単/ROI的な上振れは採用理由にしない。")
    print("4. F2結果を見た後の再調整は別モデル扱いにし、新しい未来期間が必要。")


def print_skips(skip):
    print("\n【データ準備】")
    for label, counter in skip.items():
        ready = int(counter.get("ready", 0))
        others = {k: v for k, v in counter.items() if k != "ready" and v}
        tail = " / " + ", ".join(f"{k}={v}" for k, v in sorted(others.items())) if others else ""
        print(f"{label:<12}: ready={ready}{tail}")


def main():
    ensure_files()
    mode = sys.argv[1].lower() if len(sys.argv) >= 2 else "select"
    if mode not in ("select", "holdout"):
        raise SystemExit("Usage: python3 analysis/second_place_ml_benchmark.py select | holdout MODEL")

    print("=" * 124)
    print("共通2着確率③ AI_FINAL vs ML 初回ベンチマーク")
    print("=" * 124)
    print(f"TRAIN : {TRAIN_P1} + {TRAIN_P2}")
    print(f"VALID : {VALID_F1}")
    print(f"FINAL : {HOLDOUT_F2}")
    print("本命/kiru/3着候補 : 現行固定")
    print("ML目的 : P(2着艇 | 現行本命が1着) の5択")
    print("本番Web変更 : なし")

    data = load_datasets()
    print_skips(data["skip"])

    if mode == "select":
        print("\nF2は評価せず、F1だけでモデル選定します。")
        run_select(data)
    else:
        if len(sys.argv) != 3:
            raise SystemExit("Usage: python3 analysis/second_place_ml_benchmark.py holdout LOGIT|HGB")
        run_holdout(data, sys.argv[2])


if __name__ == "__main__":
    main()
